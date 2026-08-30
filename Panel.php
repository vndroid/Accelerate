<?php

use TypechoPlugin\Accelerate\Plugin;
use Utils\Helper;

// 传 true 忽略 enableCache 开关：缓存被关掉之后，管理页仍然要能列出并清理存量数据，
// 否则这里会误报成「Redis 连接失败」。
$redis = Plugin::initRedis(true);
$config = Helper::options()->plugin('Accelerate');
$prefix = Plugin::getPrefix();

/** admin/common.php 已注入全局 $security，这里显式取一次，不依赖包含顺序 */
$security = \Widget\Security::alloc();

/** 本面板自身的地址 */
$panelUrl = Helper::options()->adminUrl . 'extending.php?panel=Accelerate%2FPanel.php';

/** 分页参数。$currentUrl 同时用作删除表单的 action 与删除后的跳转目标 */
$pageSize   = 50;
$page       = max(1, intval($_GET['page'] ?? 1));
$currentUrl = $panelUrl . ($page > 1 ? '&page=' . $page : '');

// 删除动作必须在任何输出之前处理。
// 这个文件原先第 2 行就 include 'header.php'，整个 <head> 已经吐出去了，
// 之后无论是 Security::protect() 的 goBack()、Notice 写的 Set-Cookie，
// 还是完成后的 Location 跳转，都会撞上「headers already sent」。
if (isset($_POST['do']) && $_POST['do'] === 'delete' && !empty($_POST['keys'])) {
    // 请求防伪。$security 由 admin/common.php 注入全局，令牌里含当前管理员的
    // authCode 与 uid（见 Widget\Security::execute），因此是逐人绑定的。
    // 不校验的话，管理员在登录状态下被诱导访问外部页面即可触发批量删除。
    $security->protect();

    if ($redis) {
        $keysToDelete = is_array($_POST['keys']) ? $_POST['keys'] : [$_POST['keys']];

        // 只允许删除「当前前缀下、且符合内容键格式」的键。
        // 提交的键名来自表单，未经校验时可以指向同一个 Redis 库里任何其他
        // 站点或应用的数据，也可以指向本插件的控制键（schema 被删会触发一次
        // 全量作废迁移）。
        $validKeys = [];
        foreach ($keysToDelete as $key) {
            if (is_string($key) && Plugin::isContentCacheKey($key)) {
                $validKeys[] = $key;
            }
        }

        $rejected = count($keysToDelete) - count($validKeys);

        // Redis 可能在页面加载之后才断开、变成只读或报错。管理页的一次删除操作
        // 不该让整个后台请求以异常页收场。
        try {
            $count = $validKeys ? intval($redis->del($validKeys)) : 0;

            \Widget\Notice::alloc()->set(
                $rejected > 0
                    ? _t('成功删除 %d 条缓存，%d 条键名不合法已忽略', $count, $rejected)
                    : _t('成功删除 %d 条缓存', $count),
                $rejected > 0 ? 'notice' : 'success'
            );
        } catch (\Throwable $e) {
            \Widget\Notice::alloc()->set(_t('删除缓存失败：%s', $e->getMessage()), 'error');
        }
    }
    // 原先这里写的是 \Typecho\Response::alloc() —— 该类根本没有 alloc()，
    // 只有 getInstance()，而且它是底层 HTTP 响应对象、也没有 redirect()。
    // 换句话说，删除操作以前每次都会以 Fatal error 收场。
    // 带 redirect() 的是 Typecho\Widget\Response，即 $options->response。
    Helper::options()->response->redirect($currentUrl);
}

// 清空全部内容缓存。
// 有了它，「扫描上限之外的键管不到」就不再是问题 —— 不必为了能删干净而去做
// 真正的 Redis 游标分页（游标分页不能跳页、不能回退，页与页之间还可能重复或遗漏，
// 对一个「看一眼再删几条」的管理页是负收益）。
if (isset($_POST['do']) && $_POST['do'] === 'flush') {
    $security->protect();

    try {
        // flushAll() 用 null 区分「失败」与「确实是 0 条」。不区分的话，
        // Redis 根本没连上时这里会显示成绿色的「已清空，共 0 条」。
        $flushed = Plugin::flushAll('MANUAL FLUSH FROM PANEL');

        if ($flushed === null) {
            \Widget\Notice::alloc()->set(_t('清空缓存失败，请检查 Redis 连接。'), 'error');
        } else {
            \Widget\Notice::alloc()->set(_t('已清空全部内容缓存，共 %d 条', $flushed), 'success');
        }
    } catch (\Throwable $e) {
        \Widget\Notice::alloc()->set(_t('清空缓存失败：%s', $e->getMessage()), 'error');
    }

    Helper::options()->response->redirect($panelUrl);
}

include 'header.php';
include 'menu.php';

/**
 * 收集键名上限。
 *
 * SCAN 不阻塞 Redis，但把几十万个键名一次性收进 PHP 数组同样会撑爆内存，
 * 所以设一个天花板：超出后只展示前 $scanLimit 个并在页面上说明。
 * 要整体清空请用插件设置里的「禁用时清理」，或递增 SCHEMA_VERSION。
 */
$scanLimit = 5000;

$allKeys   = [];
$truncated = false;

if ($redis) {
    try {
        // 用 SCAN 游标迭代替代 KEYS。KEYS 会一次性遍历整个 keyspace 并阻塞
        // Redis 主线程，键一多就足以让全站跟着卡住。
        // SCAN_RETRY：某一轮没有匹配结果时由 phpredis 自动继续迭代，
        // 迭代结束时返回 false。
        $iterator = null;
        $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

        while (($batch = $redis->scan($iterator, $prefix . '*', 500)) !== false) {
            foreach ($batch as $key) {
                // 只列出内容缓存键。控制键（test / schema / schema:lock）与命名空间
                // 之外的键都会被判否 —— 这里和删除校验用的是同一个函数，
                // 因此不会出现「列表里能看到、点删除却被拒绝」的错位。
                //
                // 用键名当数组下标是为了去重：SCAN 只保证「全程存在的元素至少
                // 返回一次」，同一个键在游标推进过程中**可能被返回多次**，
                // 直接 append 会让列表出现重复行、总数也偏大。
                if (Plugin::isContentCacheKey($key)) {
                    $allKeys[$key] = true;
                }
            }

            // 多收一条当哨兵：只有真的存在第 5001 条时才算截断。
            // 写成 >= $scanLimit 的话，总数恰好 5000（一条不多）也会被判成截断。
            if (count($allKeys) > $scanLimit) {
                break;
            }
        }
    } catch (\Throwable $e) {
        $allKeys = [];
    }
}

$allKeys = array_keys($allKeys);
sort($allKeys);

// 上限判断在整批 foreach 之后做，COUNT 500 意味着实际可能多收几百条。
// 「收到的比上限多」才是截断的准确判据，同时把列表裁到准确的上限，
// 免得页面上写着「前 5000 条」却列出更多。
if (count($allKeys) > $scanLimit) {
    $allKeys   = array_slice($allKeys, 0, $scanLimit);
    $truncated = true;
}

$total      = count($allKeys);
$totalPages = max(1, (int) ceil($total / $pageSize));
$page       = min($page, $totalPages);
$currentUrl = $panelUrl . ($page > 1 ? '&page=' . $page : '');
$pageKeys   = array_slice($allKeys, ($page - 1) * $pageSize, $pageSize);

/**
 * 当前页的大小与 TTL 用一次 pipeline 取回。
 *
 * 原先是每个键各发一次 MEMORY USAGE 和一次 TTL —— 一页 50 条就是 100 次往返，
 * 而且 MEMORY USAGE 返回 0 时还会回退到 $redis->get($key)，把整页 HTML 拉进
 * PHP 只为了算个长度。现在改用服务端 STRLEN，返回的是缓存内容本身的字节数
 * （比 MEMORY USAGE 略小，后者含 Redis 自身的对象开销）。
 */
$sizes       = [];
$ttls        = [];
$statsFailed = false;

if ($redis && $pageKeys) {
    try {
        $pipe = $redis->multi(\Redis::PIPELINE);
        foreach ($pageKeys as $key) {
            $pipe->strlen($key);
        }
        foreach ($pageKeys as $key) {
            $pipe->ttl($key);
        }
        $replies = $pipe->exec();

        if (is_array($replies) && count($replies) === count($pageKeys) * 2) {
            $count = count($pageKeys);
            $sizes = array_slice($replies, 0, $count);
            $ttls  = array_slice($replies, $count, $count);
        } else {
            $statsFailed = true;
        }
    } catch (\Throwable $e) {
        $sizes       = [];
        $ttls        = [];
        $statsFailed = true;
    }
}

$cacheItems = [];
foreach ($pageKeys as $i => $key) {
    // isContentCacheKey() 已保证形态为 {type}:{id}:{md5}，列表页的 id 段固定为 0
    $parts = explode(':', substr($key, strlen($prefix)));

    $cacheItems[] = [
        'key'    => $key,
        'type'   => $parts[0],
        'cid'    => $parts[1] === '0' ? '' : $parts[1],
        'md5Key' => $parts[2],
        // pipeline 失败时给 null 而不是缺省值。给 0 / -1 的话，页面会理直气壮地
        // 显示「0.00 KB」和「永久」—— 那是两个看起来很确定、其实完全没有依据的结论。
        'size'   => array_key_exists($i, $sizes) ? intval($sizes[$i]) : null,
        'ttl'    => array_key_exists($i, $ttls) ? intval($ttls[$i]) : null,
    ];
}
?>

<main class="main">
    <div class="body container">
        <div class="typecho-page-title">
            <h2><?php _e('页面缓存管理'); ?></h2>
        </div>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 typecho-list">
                <?php if (!$redis): ?>
                    <div class="message error"><?php _e('Redis 未启动或连接失败，请检查配置。'); ?></div>
                <?php else: ?>
                    <?php if ($truncated): ?>
                        <div class="message notice"><?php _e('缓存键过多，这里只列出扫描到的前 %d 条。', $scanLimit); ?></div>
                    <?php endif; ?>
                    <?php if ($statsFailed): ?>
                        <div class="message error"><?php _e('读取缓存大小与过期时间失败，本页的这两列显示为「—」和「未知」。'); ?></div>
                    <?php endif; ?>
                    <form method="post" name="manage_caches" class="operate-form">
                        <div class="typecho-list-operate clearfix">
                            <div class="operate">
                                <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox" class="typecho-table-select-all" /></label>
                                <div class="btn-group btn-drop">
                                    <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                                    <ul class="dropdown-menu">
                                        <?php /* getTokenUrl() 的令牌绑定的是「当前请求地址」，与这里给的目标地址无关，
                                                 因此带上 &page=N 回到同一页是安全的 */ ?>
                                        <li><a lang="<?php _e('确认要删除这些缓存吗?'); ?>" href="<?php echo $security->getTokenUrl($currentUrl); ?>" class="operate-delete"><?php _e('删除'); ?></a></li>
                                    </ul>
                                </div>
                            </div>
                            <div class="search" role="search">
                                <span class="description"><?php _e('共 %d 条，第 %d / %d 页', $total, $page, $totalPages); ?></span>
                            </div>
                        </div>

                        <div class="typecho-table-wrap">
                            <table class="typecho-list-table mono">
                                <colgroup>
                                    <col width="5%"/>
                                    <col width="15%"/>
                                    <col width="40%"/>
                                    <col width="20%"/>
                                    <col width="20%"/>
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th> </th>
                                        <th><?php _e('缓存类型'); ?></th>
                                        <th><?php _e('缓存键名'); ?></th>
                                        <th><?php _e('缓存大小'); ?></th>
                                        <th><?php _e('过期时间'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($cacheItems)): ?>
                                        <?php foreach ($cacheItems as $item): ?>
                                            <tr id="cache-<?php echo md5($item['key']); ?>">
                                                <td><input type="checkbox" value="<?php echo htmlspecialchars($item['key']); ?>" name="keys[]"/></td>
                                                <td>
                                                    <span class="status"><?php echo htmlspecialchars(strtoupper($item['type'])); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($item['cid'] !== ''): ?><strong>#<?php echo htmlspecialchars($item['cid']); ?></strong> &middot; <?php endif; ?><?php echo htmlspecialchars($item['md5Key']); ?>
                                                </td>
                                                <td><?php echo $item['size'] === null ? '—' : number_format($item['size'] / 1024, 2) . ' KB'; ?></td>
                                                <td><?php
                                                    // phpredis 的 ttl()：-1 = 键存在但没有过期时间，-2 = 键已不存在。
                                                    // 原先两者都落进「永久」分支。内容键一律用 setex 写入，TTL 恒 >= 1，
                                                    // 所以 -2 通常是这个键在 SCAN 与 pipeline 之间刚好过期了，
                                                    // 而 -1 反倒说明有人手工往这个命名空间里塞过键。
                                                    if ($item['ttl'] === null) {
                                                        _e('未知');
                                                    } elseif ($item['ttl'] > 0) {
                                                        echo intval($item['ttl']) . ' 秒';
                                                    } elseif ($item['ttl'] === -1) {
                                                        _e('永久');
                                                    } else {
                                                        _e('已过期');
                                                    }
                                                ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5"><h6 class="typecho-list-table-title"><?php _e('没有任何缓存'); ?></h6></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <input type="hidden" name="do" value="delete" id="do-action" />
                    </form>

                    <form method="post" action="<?php echo $security->getTokenUrl($panelUrl); ?>" class="typecho-list-operate clearfix">
                        <input type="hidden" name="do" value="flush" />
                        <button class="btn btn-s btn-warn" type="submit"
                                onclick="return confirm('<?php _e('确认要清空全部内容缓存吗?'); ?>');"><?php _e('清空全部内容缓存'); ?></button>
                    </form>

                    <?php if ($totalPages > 1): ?>
                        <ul class="typecho-pager">
                            <?php if ($page > 1): ?>
                                <li class="prev"><a href="<?php echo htmlspecialchars($panelUrl . ($page - 1 > 1 ? '&page=' . ($page - 1) : '')); ?>">&laquo; <?php _e('前一页'); ?></a></li>
                            <?php endif; ?>
                            <?php if ($page < $totalPages): ?>
                                <li class="next"><a href="<?php echo htmlspecialchars($panelUrl . '&page=' . ($page + 1)); ?>"><?php _e('后一页'); ?> &raquo;</a></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php
include 'copyright.php';
include 'common-js.php';

$jsFile = 'table-js.php';
if (file_exists($jsFile)) {
    include $jsFile;
}

include 'footer.php';
?>
