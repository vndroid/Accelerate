<?php
include 'header.php';
include 'menu.php';

use TypechoPlugin\RedisCache\Plugin;
use Utils\Helper;

$redis = Plugin::initRedis();
$config = Helper::options()->plugin('RedisCache');
$prefix = isset($config->prefix) ? $config->prefix : 'typecho_cache:';

// Handle delete action
if (isset($_POST['do']) && $_POST['do'] === 'delete' && !empty($_POST['keys'])) {
    if ($redis) {
        $keysToDelete = is_array($_POST['keys']) ? $_POST['keys'] : [$_POST['keys']];
        $count = $redis->del($keysToDelete);
        \Widget\Notice::alloc()->set(_t('成功删除 %d 条缓存', $count), 'success');
    }
    \Typecho\Response::alloc()->redirect(Helper::options()->adminUrl . 'extending.php?panel=RedisCache%2Fpanel.php');
}

$keys = $redis ? $redis->keys($prefix . '*') : [];
$cacheItems = [];
if ($keys) {
    foreach ($keys as $key) {
        // Exclude test keys or logs if any
        if (str_ends_with($key, ':test')) continue;

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

        if (count($parts) === 3 && in_array($parts[0], ['post', 'page', 'list'], true)) {
            $type = $parts[0];
            $cid = $parts[1] === '0' ? '' : $parts[1];
            $md5Key = $parts[2];
        } elseif (count($parts) === 2 && in_array($parts[0], ['post', 'page'], true)) {
            // 兼容 0.1.0 及更早版本生成的、不带 cid 的缓存键
            $type = $parts[0];
            $md5Key = $parts[1];
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
            <h2><?php _e('RedisCache 管理'); ?></h2>
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
                                        <li><a lang="<?php _e('确认要删除这些缓存吗?'); ?>" href="<?php echo Helper::options()->adminUrl . 'extending.php?panel=RedisCache%2Fpanel.php'; ?>" class="operate-delete"><?php _e('删除'); ?></a></li>
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
                                        <th><?php _e('类型'); ?></th>
                                        <th><?php _e('缓存键名'); ?></th>
                                        <th><?php _e('大小'); ?></th>
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
