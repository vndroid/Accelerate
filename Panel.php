<?php
include 'header.php';
include 'menu.php';

use TypechoPlugin\Accelerate\Plugin;
use Utils\Helper;

// 传 true 忽略 enableCache 开关：缓存被关掉之后，管理页仍然要能列出并清理存量数据，
// 否则这里会误报成「Redis 连接失败」。
$redis = Plugin::initRedis(true);
$config = Helper::options()->plugin('Accelerate');
$prefix = Plugin::getPrefix();

/** admin/common.php 已注入全局 $security，这里显式取一次，不依赖包含顺序 */
$security = \Widget\Security::alloc();

/** 本面板自身的地址，同时用作表单 action 与删除后的跳转目标 */
$panelUrl = Helper::options()->adminUrl . 'extending.php?panel=Accelerate%2FPanel.php';

// Handle delete action
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

        $count = $validKeys ? intval($redis->del($validKeys)) : 0;
        $rejected = count($keysToDelete) - count($validKeys);

        \Widget\Notice::alloc()->set(
            $rejected > 0
                ? _t('成功删除 %d 条缓存，%d 条键名不合法已忽略', $count, $rejected)
                : _t('成功删除 %d 条缓存', $count),
            $rejected > 0 ? 'notice' : 'success'
        );
    }
    \Typecho\Response::alloc()->redirect($panelUrl);
}

$keys = $redis ? $redis->keys($prefix . '*') : [];
$cacheItems = [];
if ($keys) {
    foreach ($keys as $key) {
        // 只列出内容缓存键。控制键（test / schema / schema:lock）与命名空间外
        // 的键都会被判否 —— 判定与删除时用的是同一个函数，因此不会出现
        // 「列表里能看到、点删除却被拒绝」的错位。
        if (!Plugin::isContentCacheKey($key)) continue;

        try {
            $size = $redis->rawCommand('MEMORY', 'USAGE', $key);
        } catch (\Exception $e) {
            $size = 0;
        }
        if (!$size) {
            $size = strlen((string)$redis->get($key));
        }
        $ttl = $redis->ttl($key);

        // Remove prefix to parse out type, cid and MD5 hash
        // 统一三段式 {type}:{id}:{md5}，列表页的 id 段固定为 0
        $keyWithoutPrefix = substr($key, strlen($prefix));
        $parts = explode(':', $keyWithoutPrefix);

        $type = 'unknown';
        $cid = '';
        $md5Key = $keyWithoutPrefix;

        // isContentCacheKey() 已保证形态为 {type}:{id}:{md5}
        if (count($parts) === 3) {
            $type = $parts[0];
            $cid = $parts[1] === '0' ? '' : $parts[1];
            $md5Key = $parts[2];
        }

        $cacheItems[] = [
            'key' => $key,
            'type' => $type,
            'cid' => $cid,
            'md5Key' => $md5Key,
            'size' => $size,
            'ttl' => $ttl
        ];
    }
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
                    <form method="post" name="manage_caches" class="operate-form">
                        <div class="typecho-list-operate clearfix">
                            <div class="operate">
                                <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox" class="typecho-table-select-all" /></label>
                                <div class="btn-group btn-drop">
                                    <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                                    <ul class="dropdown-menu">
                                        <li><a lang="<?php _e('确认要删除这些缓存吗?'); ?>" href="<?php echo $security->getTokenUrl($panelUrl); ?>" class="operate-delete"><?php _e('删除'); ?></a></li>
                                    </ul>
                                </div>
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
                                                <td><?php echo number_format($item['size'] / 1024, 2); ?> KB</td>
                                                <td><?php echo $item['ttl'] > 0 ? $item['ttl'] . ' 秒' : '永久'; ?></td>
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
