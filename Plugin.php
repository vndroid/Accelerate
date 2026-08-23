<?php

namespace TypechoPlugin\RedisCache;

use Redis;
use Throwable;
use Typecho\Db\Exception as DbException;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Utils\Helper;
use Widget\Archive;
use Widget\User;
use Widget\Contents\Post\Edit as PostEdit;
use Widget\Contents\Page\Edit as PageEdit;
use Widget\Feedback;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 静态资源缓存插件 via Redis for Typecho
 *
 * @package RedisCache
 * @author Vex
 * @version 0.1.0
 * @link https://github.com/vndroid/RedisCache
 */
class Plugin implements PluginInterface
{
    /**
     * 缓存键结构版本
     *
     * 当 makeCacheKey() 生成的键名结构发生变化时必须递增此版本号。
     * 插件会在下一次连接 Redis 时自动清理所有不符合当前结构的历史缓存，
     * 避免旧键既无法命中、又无法被按 cid 精确清理而长期滞留。
     *
     * v1: {prefix}post:{md5} / {prefix}page:{md5}
     * v2: {prefix}{post|page|list}:{id}:{md5}
     */
    private const SCHEMA_VERSION = '2';

    /**
     * 初始化实例
     */
    private static ?Redis $redis = null;

    /**
     * 统一缓存前缀
     */
    private static string $prefix = '';

    /**
     * Post 缓存过期时间（秒）
     */
    private static int $postExpire = 0;

    /**
     * Page 缓存过期时间（秒）
     */
    private static int $pageExpire = 0;

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @return string
     * @throws PluginException
     */
    public static function activate(): string
    {
        // 检查 PHP 扩展
        if (!extension_loaded('redis')) {
            throw new PluginException(_t('检测到当前 PHP 环境缺失 redis 扩展'));
        }
        // 检查插件目录名称
        if (!str_ends_with(trim(__DIR__, '/\\'), 'RedisCache')) {
            throw new PluginException(_t('插件目录名必须为 RedisCache（区分大小写），请检查插件目录名是否正确'));
        }
        // 在内容渲染前尝试从缓存获取
        Archive::pluginHandle()->beforeRender = [self::class, 'beforeRender'];

        // 在内容渲染后缓存内容
        Archive::pluginHandle()->afterRender = [self::class, 'afterRender'];

        // 当文章更新时清除缓存
        PostEdit::pluginHandle()->finishPublish = [self::class, 'clearCacheOnPublish'];

        // 当页面更新时清除缓存
        PageEdit::pluginHandle()->finishPublish = [self::class, 'clearCacheOnPublish'];

        // 当评论提交时清除缓存
        Feedback::pluginHandle()->finishComment = [self::class, 'clearCacheOnComment'];

        \Typecho\Plugin::factory('admin/footer.php')->begin = [self::class, 'injectFooterJs'];
        \Typecho\Plugin::factory('admin/menu.php')->navBar = [self::class, 'addAdminPageBar'];

        Helper::addPanel(3, 'RedisCache/Panel.php', _t('Redis 缓存'), _t('Redis 缓存管理'), 'administrator');

        $configLink = '<a href="' . Helper::options()->adminUrl('options-plugin.php?config=RedisCache', true) . '">' . _t('前往设置') . '</a>';
        return _t('插件已启用，但缓存功能未启用，请检查缓存 URI ，') . $configLink;
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @throws PluginException
     */
    public static function deactivate(): string
    {
        Helper::removePanel(3, 'RedisCache/Panel.php');

        $config = Helper::options()->plugin(basename(__DIR__));
        $shouldCleanCache = !isset($config->cleanCacheOnDeactivate) || $config->cleanCacheOnDeactivate == '1';

        $cleanCount = 0;

        if ($shouldCleanCache) {
            $redis = self::initRedis();
            if ($redis) {
                $cleanCount = self::deleteByPattern($redis, self::$prefix . '*');
            }
        }

        if ($config->debug == '1' && $cleanCount > 0) {
            self::writeLog(
                'cache-' . date('Y-m-d') . '.log',
                date('[Y-m-d H:i:s]') . ' CACHE: (FLUSHED) REASON: (PLUGIN DEACTIVATED)                               SUM: (TOTAL ' . $cleanCount . ' KEYs)'
            );
        }
        if ($shouldCleanCache && $cleanCount > 0) {
            return _t('插件已禁用，已清理 %d 条缓存', $cleanCount);
        } else {
            return _t('插件已禁用');
        }
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form 配置面板
     */
    public static function config(Form $form): void
    {
        $enableCache = new Radio(
            'enableCache',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '0',
            _t('启用缓存'),
            _t('是否启用 Redis 缓存功能')
        );
        $form->addInput($enableCache);

        $host = new Text(
            'host',
            null,
            '127.0.0.1',
            _t('Redis 服务地址'),
            _t('输入 Redis 服务主机地址，默认 HOST 为 127.0.0.1')
        );
        $form->addInput($host);

        $port = new Text(
            'port',
            null,
            '6379',
            _t('Redis 服务端口'),
            _t('输入 Redis 服务端口，默认 PORT 为 6379')
        );
        $form->addInput($port);

        $enableAuth = new Radio(
            'enableAuth',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '0',
            _t('启用认证'),
            _t('是否启用 Redis 认证')
        );
        $form->addInput($enableAuth);

        $password = new Password(
            'password',
            null,
            '',
            _t('Redis 服务密码'),
            _t('如果 Redis 服务启用了密码，请输入密码，否则留空')
        );
        $form->addInput($password);

        $postExpire = new Text(
            'postExpire',
            null,
            '86400',
            _t('文章缓存时间（秒）'),
            _t('常规文章 TTL 缓存过期时间，默认为一天（86400 秒）')
        );
        $form->addInput($postExpire);

        $pageExpire = new Text(
            'pageExpire',
            null,
            '2592000',
            _t('页面缓存时间（秒）'),
            _t('独立页面 TTL 缓存过期时间，默认为一月（2592000 秒）')
        );
        $form->addInput($pageExpire);

        $prefix = new Text(
            'prefix',
            null,
            'typecho_cache:',
            _t('缓存前缀'),
            _t('缓存键名的前缀，用于区分不同应用的缓存')
        );
        $form->addInput($prefix);

        $uriPrefix = new Text(
            'uriPrefix',
            null,
            '/',
            _t('匹配前缀'),
            _t('按路径前缀进行缓存，防止缓存不需要的页面，多个前缀请用英文逗号分隔')
        );
        $form->addInput($uriPrefix);

        $uriSuffix = new Text(
            'uriSuffix',
            null,
            '',
            _t('匹配后缀'),
            _t('按路径后缀进行缓存，如配置 .html 则只缓存以 .html 结尾的页面；根路径 / 始终被缓存，不受此规则限制；多个后缀请用英文逗号分隔；留空表示不限制；与匹配前缀同时配置时，需同时满足才会缓存')
        );
        $form->addInput($uriSuffix);

        $clearListOnComment = new Radio(
            'clearListOnComment',
            ['1' => _t('清理'), '0' => _t('保留')],
            '1',
            _t('评论时清理列表页缓存'),
            _t('产生新评论时，除该内容自身的缓存外，是否一并清理首页/分类/标签/归档等列表页缓存。若主题会在列表页显示评论数，请选择「清理」；若不显示，选择「保留」可以大幅缩小缓存失效范围')
        );
        $form->addInput($clearListOnComment);

        $debug = new Radio(
            'debug',
            ['1' => _t('启用'), '0' => _t('禁用')],
            '0',
            _t('调试模式'),
            _t('启用后会记录更详细的日志信息')
        );
        $form->addInput($debug);

        $cleanCacheOnDeactivate = new Radio(
            'cleanCacheOnDeactivate',
            ['1' => _t('清理'), '0' => _t('保留')],
            '1',
            _t('禁用时清理缓存'),
            _t('禁用插件时是否清理 Redis 中的所有缓存数据，默认清理')
        );
        $form->addInput($cleanCacheOnDeactivate);
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form): void
    {
    }

    /**
     * 在后台导航栏插件状态显示
     *
     * @throws PluginException
     */
    public static function addAdminPageBar(): void
    {
        $config = Helper::options()->plugin(basename(__DIR__));
        if ($config->enableCache === '1') {
            echo '<span class="message success">' . htmlspecialchars('SRC 已启用') . '</span>';
        } else {
            echo '<span class="message error">' . htmlspecialchars('SRC 未启用') . '</span>';
        }
    }

    /**
     * 在后台页脚注入 JS（jQuery 已加载），仅在插件配置页生效
     * 实现 enableAuth 切换时联动显示/隐藏 password 行
     *
     * @return void
     */
    public static function injectFooterJs(): void
    {
        // 仅在本插件配置页注入：先确认是插件配置页，再确认是 RedisCache
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (!str_contains($requestUri, 'options-plugin.php') || ($_GET['config'] ?? '') !== basename(__DIR__)) {
            return;
        }

        $jsFile = __DIR__ . '/assets/admin-config.js';
        $jsContent = file_get_contents($jsFile);
        if ($jsContent !== false) {
            echo '<script>' . $jsContent . '</script>';
        }
    }

    /**
     * 初始化 Redis 连接
     *
     * @return Redis|null
     * @throws PluginException
     */
    public static function initRedis(): ?Redis
    {
        if (self::$redis !== null) {
            return self::$redis;
        }

        $config = Helper::options()->plugin(basename(__DIR__));

        // 如果禁用缓存，直接返回
        if (isset($config->enableCache) && $config->enableCache == '0') {
            return null;
        }

        // 设置缓存参数，配置为空时使用默认值
        self::$prefix     = $config->prefix     ?: 'typecho_cache:';
        self::$postExpire = intval($config->postExpire) ?: 86400;
        self::$pageExpire = intval($config->pageExpire) ?: 2592000;

        // 创建日志目录（writeLog 会自行处理，此处无需手动创建）
        $logFilename = 'redis-' . date('Y-m-d') . '.log';

        try {
            // 尝试连接 Redis
            $redis     = new Redis();
            $connected = $redis->connect($config->host, intval($config->port), 3);

            if (!$connected) {
                throw new \Exception('无法连接到 Redis 服务');
            }

            // 如果设置了密码，进行验证
            if (!empty($config->password)) {
                $authResult = $redis->auth($config->password);
                if (!$authResult) {
                    throw new \Exception('Redis 服务认证失败');
                }
            }

            // 检查连接
            $pong = $redis->ping();
            if ($pong !== '+PONG' && $pong !== true) {
                throw new \Exception('Redis 服务 PING 失败');
            }

            $logMessage = date('[Y-m-d H:i:s]') . ' redis connect successful: ' . $config->host . ':' . $config->port;

            // 写入测试数据
            $testKey   = self::$prefix . 'test';
            $testValue = 'Hello Typecho! ' . date('Y-m-d H:i:s');
            $redis->set($testKey, $testValue);
            $retrievedValue = $redis->get($testKey);

            if ($retrievedValue !== $testValue) {
                throw new \Exception('缓存测试数据写入失败');
            }

            // $retrievedValue 此处已通过 !== 比较确认为 string
            $logMessage .= "\n" . date('[Y-m-d H:i:s]') . ' redis writable-test successful: ' . $retrievedValue;

            // 删除测试数据
            $redis->del($testKey);

            // 探测 RedisJSON 支持情况并写入日志（不影响主流程）
            try {
                $json        = self::detectRedisJsonSupport($redis);
                $logMessage .= "\n" . date('[Y-m-d H:i:s]') . ' redis json support: ' .
                    ($json['supported'] ? 'YES' : 'NO') .
                    ' via=' . ($json['via'] ?? '-') .
                    (empty($json['module']) ? '' : ' module=' . $json['module']) .
                    (empty($json['version']) ? '' : ' ver=' . $json['version']) .
                    (empty($json['reason']) ? '' : ' reason=' . $json['reason']);
            } catch (Throwable $e) {
                $logMessage .= "\n" . date('[Y-m-d H:i:s]') . ' redis json support: UNKNOWN reason=' . $e->getMessage();
            }

            self::writeLog($logFilename, $logMessage);

            // 键结构迁移：清理不符合当前结构版本的历史缓存（失败不影响主流程）
            try {
                self::migrateSchema($redis, $logFilename);
            } catch (Throwable $e) {
                self::writeLog(
                    $logFilename,
                    date('[Y-m-d H:i:s]') . ' schema migration failed: ' . $e->getMessage()
                );
            }

            self::$redis = $redis;
            return $redis;
        } catch (Throwable $e) {
            self::writeLog($logFilename, date('[Y-m-d H:i:s]') . ' redis connect failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 探测当前 Redis 实例是否支持 RedisJSON（或老版本 ReJSON）
     * 仅支持 Redis 8.0+
     *
     * 返回结构：
     * - supported: bool    是否支持 JSON 命令
     * - via:       string  探测方式（module_list / command_info / error）
     * - module:    ?string 模块名（RedisJSON / ReJSON）
     * - version:   ?string 模块版本
     * - reason:    ?string 不支持或失败原因
     *
     * @param Redis $redis
     * @return array{supported: bool, via: string, module: ?string, version: ?string, reason: ?string}
     */
    private static function detectRedisJsonSupport(Redis $redis): array
    {
        $result = [
            'supported' => false,
            'via'       => null,
            'module'    => null,
            'version'   => null,
            'reason'    => null,
        ];

        // 1) 主推荐：MODULE LIST（Redis 4.0+ 标准方式）
        try {
            if (!method_exists($redis, 'rawCommand')) {
                $result['via']    = 'module_list';
                $result['reason'] = 'rawCommand_not_available';
            } else {
                $modules = $redis->rawCommand('MODULE', 'LIST');

                if (!is_array($modules)) {
                    $result['via']    = 'module_list';
                    $result['reason'] = 'unexpected_reply';
                } else {
                    foreach ($modules as $moduleInfo) {
                        if (!is_array($moduleInfo)) {
                            continue;
                        }

                        $name    = (string) ($moduleInfo['name'] ?? '');
                        $version = (string) ($moduleInfo['ver'] ?? '');

                        // 检查是否为 JSON 模块
                        if (!empty($name) && (strtolower($name) === 'rejson' || strtolower($name) === 'redisjson')) {
                            return [
                                'supported' => true,
                                'via'       => 'module_list',
                                'module'    => $name,
                                'version'   => $version ?: null,
                                'reason'    => null,
                            ];
                        }
                    }

                    $result['via']    = 'module_list';
                    $result['reason'] = 'module_not_loaded';
                }
            }
        } catch (Throwable $e) {
            $result['via']    = 'module_list';
            $result['reason'] = 'module_list_error: ' . $e->getMessage();
        }

        // 2) 备选：COMMAND INFO JSON.GET
        try {
            if (!method_exists($redis, 'rawCommand')) {
                // 如果 rawCommand 不可用，直接返回失败
                $result['reason'] ??= 'rawCommand_unavailable';
                return $result;
            }

            $info = $redis->rawCommand('COMMAND', 'INFO', 'JSON.GET');
            if (is_array($info) && count($info) > 0 && ($info[0] ?? null) !== null && $info !== [false]) {
                return [
                    'supported' => true,
                    'via'       => 'command_info',
                    'module'    => 'RedisJSON',
                    'version'   => null,
                    'reason'    => null,
                ];
            }

            // COMMAND INFO 失败，保留之前的失败原因
            $result['reason'] ??= 'command_not_found';
        } catch (Throwable $e) {
            $result['via']    ??= 'command_info';
            $result['reason'] ??= 'command_info_error: ' . $e->getMessage();
        }

        $result['via']    ??= 'error';
        $result['reason'] ??= 'unknown';
        return $result;
    }

    /**
     * 记录是否已开启输出缓冲
     */
    private static bool $obStarted = false;

    /**
     * 写入日志，带降级功能
     *
     * 优先写入插件 logs/ 目录；若该目录不可写（或创建失败），
     * 自动降级到 /tmp/typecho 目录，确保日志不丢失。
     *
     * @param string $filename 日志文件名，如 redis-2026-03-17.log
     * @param string $message  日志正文（不含末尾换行）
     * @return void
     */
    private static function writeLog(string $filename, string $message): void
    {
        $dirs = [
            __DIR__ . '/logs',
            '/tmp/typecho',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                file_put_contents($dir . '/' . $filename, $message . "\n", FILE_APPEND);
                return;
            }
        }
    }

    /**
     * 根据内容类型生成缓存键
     *
     * 统一为 {type}:{id}:{hash} 三段式：
     *
     * - 文章     → prefix + post:{cid}:md5(uri)
     * - 独立页面 → prefix + page:{cid}:md5(uri)
     * - 列表页   → prefix + list:0:md5(uri)（首页 / 分类 / 标签 / 归档 / 搜索）
     *
     * 把 cid 编进键名，是为了在内容更新或收到评论时能够按 cid 精确清理，
     * 而不必像旧版那样清空全站缓存；列表页不隶属于任何单篇内容，
     * id 段固定为 0，以保证 hash 恒定位于第 3 段。
     *
     * @param string  $requestUri
     * @param Archive $archive
     * @return string
     */
    private static function makeCacheKey(string $requestUri, Archive $archive): string
    {
        $hash = md5($requestUri);

        if ($archive->is('single')) {
            $cid = intval($archive->cid);
            if ($cid > 0) {
                return self::$prefix . ($archive->is('page') ? 'page' : 'post') . ':' . $cid . ':' . $hash;
            }
        }

        return self::$prefix . 'list:0:' . $hash;
    }

    /**
     * 按模式批量删除缓存键
     *
     * 使用 SCAN 游标迭代而非 KEYS，避免键数量较多时阻塞 Redis 主线程。
     *
     * @param Redis  $redis
     * @param string $pattern 完整的键名匹配模式（需自行带上前缀）
     * @return int 实际删除的键数量
     */
    private static function deleteByPattern(Redis $redis, string $pattern): int
    {
        $deleted  = 0;
        $iterator = null;

        // SCAN_RETRY：某一轮没有匹配结果时由 phpredis 自动继续迭代，
        // 迭代结束时返回 false，因此可以安全地用 while 取值
        $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

        while (($keys = $redis->scan($iterator, $pattern, 500)) !== false) {
            if (!empty($keys)) {
                $deleted += intval($redis->del($keys));
            }
        }

        return $deleted;
    }

    /**
     * 键结构迁移
     *
     * 用一个哨兵键记录当前使用的键结构版本，版本不匹配时清理全部历史格式缓存。
     *
     * 之所以放在这里而不是 activate()：Typecho 在禁用插件时会一并删除插件配置
     * （var/Widget/Plugins/Edit.php），activate() 执行时拿不到 Redis 连接参数；
     * 而覆盖文件升级时插件保持启用状态，activate() 根本不会被调用。
     * 挂在连接建立之后，才能覆盖到全部升级路径。
     *
     * @param Redis  $redis
     * @param string $logFilename
     * @return void
     */
    private static function migrateSchema(Redis $redis, string $logFilename): void
    {
        $schemaKey = self::$prefix . 'schema';

        if ($redis->get($schemaKey) === self::SCHEMA_VERSION) {
            return;
        }

        // 抢占迁移锁，避免并发请求同时扫描整个 keyspace；
        // 抢不到说明已有请求在迁移，本次直接跳过即可
        $lockKey = self::$prefix . 'schema:lock';
        if (!$redis->set($lockKey, '1', ['nx', 'ex' => 60])) {
            return;
        }

        $purged = self::purgeLegacyKeys($redis);

        $redis->set($schemaKey, self::SCHEMA_VERSION);
        $redis->del($lockKey);

        self::writeLog(
            $logFilename,
            date('[Y-m-d H:i:s]') . ' schema migrated to v' . self::SCHEMA_VERSION
                . ': purged ' . $purged . ' legacy key(s)'
        );
    }

    /**
     * 清理所有不符合当前键结构的缓存
     *
     * 采用「白名单」策略：只保留符合当前结构的内容键与少量控制键，
     * 其余一律删除。这样将来再次调整键结构时，只需递增 SCHEMA_VERSION，
     * 无需为每一种历史格式单独编写匹配规则。
     *
     * @param Redis $redis
     * @return int 实际删除的键数量
     */
    private static function purgeLegacyKeys(Redis $redis): int
    {
        // 当前内容键结构：{type}:{id}:{md5}
        $currentShape = '/^(post|page|list):\d+:[0-9a-f]{32}$/';

        // 控制键：连接测试、结构版本标记、迁移锁，不属于内容缓存，需保留
        $reserved = ['test', 'schema', 'schema:lock'];

        $purged   = 0;
        $stale    = [];
        $iterator = null;

        $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

        while (($keys = $redis->scan($iterator, self::$prefix . '*', 500)) !== false) {
            foreach ($keys as $key) {
                $name = substr($key, strlen(self::$prefix));

                if (in_array($name, $reserved, true) || preg_match($currentShape, $name)) {
                    continue;
                }

                $stale[] = $key;

                if (count($stale) >= 500) {
                    $purged += intval($redis->del($stale));
                    $stale = [];
                }
            }
        }

        if (!empty($stale)) {
            $purged += intval($redis->del($stale));
        }

        return $purged;
    }

    /**
     * 根据内容类型返回对应的 TTL（秒）
     *
     * - page → $pageExpire (default 30 days)
     * - post → $postExpire (default 1 hour)
     *
     * @param Archive $archive
     * @return int
     */
    private static function getExpireForArchive(Archive $archive): int
    {
        return $archive->is('page') ? self::$pageExpire : self::$postExpire;
    }

    /**
     * 提取并校验当前请求的路径部分
     *
     * 不使用 parse_url()：当 REQUEST_URI 以 // 开头时，parse_url 会按「协议相对
     * URL」解析，把 //evil.com/12.html 的路径识别成 /12.html，于是畸形请求会与
     * 正常页面命中同一个缓存键 —— 这是一条缓存投毒路径。实测：
     *
     *   parse_url('//evil.com/12.html', PHP_URL_PATH)  =>  '/12.html'
     *   parse_url('///etc/passwd',      PHP_URL_PATH)  =>  false
     *
     * 因此这里改为手工截取，并要求路径必须是以单个斜杠开头、且不含连续斜杠的
     * 绝对路径。连续斜杠本就不是 Typecho 会生成的规范链接，多半来自扫描器探测。
     *
     * @return string|null 合法路径；畸形请求返回 null
     */
    private static function resolveRequestPath(): ?string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = explode('?', $uri, 2)[0];

        if ($path === '' || $path[0] !== '/' || str_contains($path, '//')) {
            return null;
        }

        return $path;
    }

    /**
     * 判断当前请求本身是否可以参与缓存
     *
     * 这里只依据 HTTP 请求与 Typecho 核心的行为做判断，不涉及任何具体主题，
     * 目的是让插件在任意主题下都不会缓存出「与单次请求绑定」的内容。
     *
     * @return bool
     */
    private static function isCacheableRequest(): bool
    {
        // 1) 只缓存 HTTP 安全方法。POST 等写请求若被 beforeRender 直接吐缓存并
        //    exit()，表单提交会被静默吞掉。
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        // 2) 带查询串的请求一律不缓存。
        //    其一，Typecho 开启「反垃圾保护」时会在页面里输出
        //    md5(secret & 完整请求 URL) 派生的一次性 token，提交时用 Referer 比对；
        //    若缓存键忽略查询串，带 utm/ref 等参数的首访会把与自身 URL 绑定的 token
        //    写进缓存，后续访客提交时会被静默拒绝。
        //    其二，?replyTo= 之类参数会改变页面内容，忽略它会让嵌套回复失效。
        //    其三，跳过带参请求可避免随机查询串撑爆缓存（缓存投毒式 DoS）。
        $query = $_SERVER['QUERY_STRING'] ?? (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '');
        if ($query !== '') {
            return false;
        }

        // 3) 携带 Typecho 评论态 cookie 的请求不缓存。
        //    评论校验失败或待审核时，Typecho 会写入 __typecho_remember_*、
        //    __typecho_unapproved_comment 等 cookie，主题可能据此回填表单或
        //    显示「您的评论正在审核」。这类页面含有该访客的私有内容，
        //    一旦写入缓存就会广播给所有人。
        foreach (array_keys($_COOKIE) as $name) {
            if (str_starts_with($name, '__typecho_remember_') || $name === '__typecho_unapproved_comment') {
                return false;
            }
        }

        return true;
    }

    /**
     * 在渲染前检查缓存是否存在
     *
     * @param Archive $archive
     * @return void
     * @throws DbException
     * @throws PluginException
     */
    public static function beforeRender(Archive $archive): void
    {
        if (!self::isCacheableRequest()) {
            return;
        }

        $requestUri = self::resolveRequestPath();
        if ($requestUri === null) {
            return;
        }

        $user = User::alloc();
        if ($user->hasLogin()) {
            return;
        }

        // 扩展点：允许主题或其他插件否决本次缓存，用于处理插件无法感知的
        // 主题级动态内容。注册方式（例如在主题的 themeInit 中）：
        //
        //   \Typecho\Plugin::factory('TypechoPlugin\RedisCache\Plugin')->skipCache
        //       = 'yourCallback';
        //
        // 回调签名：function (bool $skip, Archive $archive, string $requestUri): bool
        if (\Typecho\Plugin::factory(self::class)->filter('skipCache', false, $archive, $requestUri)) {
            return;
        }

        $redis = self::initRedis();
        if (!$redis) {
            return;
        }

        $cacheKey      = self::makeCacheKey($requestUri, $archive);
        $cachedContent = $redis->get($cacheKey);

        if ($cachedContent !== false) {
            $config = Helper::options()->plugin(basename(__DIR__));

            if (isset($config->debug) && $config->debug == '1') {
                self::writeLog(
                    'cache-' . date('Y-m-d') . '.log',
                    date('[Y-m-d H:i:s]') . ' CACHE: (HIT)     KEY: (' . $cacheKey . ') URI: (' . $requestUri . ')'
                );
            }

            $cachedContent .= "\n<!-- Powered by Redis, TIME: " .
                date('Y-m-d H:i:s', time() - $redis->ttl($cacheKey)) .
                ', TTL: ' . $redis->ttl($cacheKey) . 's -->';

            // Typecho 的 Response 只把响应头存进数组，要等 respond() 才真正发送
            // （见 Typecho\Response::setHeader / sendHeaders）。这里直接 exit()
            // 会跳过全部响应头 —— 包括 Widget\Init 设置的 Content-Type（站点配置的
            // 内容类型与字符集）和 Archive::render() 设置的 X-Pingback。
            // 此前能正常显示，只是碰巧依赖了 PHP 的 default_charset 兜底。
            if (!headers_sent()) {
                \Typecho\Response::getInstance()->sendHeaders();
            }

            echo $cachedContent;
            exit();
        }

        // 缓存未命中，开始输出缓冲
        ob_start();
        self::$obStarted = true;
    }

    /**
     * 在渲染后保存缓存
     *
     * @param Archive $archive
     * @return void
     * @throws DbException
     * @throws PluginException
     */
    public static function afterRender(Archive $archive): void
    {
        // 如果 beforeRender 未开启缓冲（跳过了缓存逻辑），直接返回
        if (!self::$obStarted) {
            return;
        }
        self::$obStarted = false;

        $user = User::alloc();
        if ($user->hasLogin()) {
            ob_end_flush();
            return;
        }

        $redis = self::initRedis();
        if (!$redis) {
            ob_end_flush();
            return;
        }

        $requestUri = self::resolveRequestPath();
        if ($requestUri === null) {
            ob_end_flush();
            return;
        }

        $config = Helper::options()->plugin(basename(__DIR__));

        // URI 前缀筛选：读取配置中的路径前缀，只缓存匹配的页面
        $rawPrefixes = isset($config->uriPrefix) ? trim($config->uriPrefix) : '/';
        $uriPrefixes = array_filter(array_map('trim', explode(',', $rawPrefixes)));

        $matched = false;
        foreach ($uriPrefixes as $p) {
            if ($p === '/' || str_starts_with($requestUri, $p)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            if (isset($config->debug) && $config->debug == '1') {
                self::writeLog(
                    'cache-' . date('Y-m-d') . '.log',
                    date('[Y-m-d H:i:s]') . ' CACHE: (PASS)    REASON: (URI PREFIX NOT MATCHED)                           URI: (' . $requestUri . ')'
                );
            }
            ob_end_flush();
            return;
        }

        // URI 后缀筛选：根路径 / 始终通过；uriSuffix 为空则不限制
        $rawSuffixes = isset($config->uriSuffix) ? trim($config->uriSuffix) : '';
        if ($rawSuffixes !== '' && $requestUri !== '/') {
            $uriSuffixes   = array_filter(array_map('trim', explode(',', $rawSuffixes)));
            $suffixMatched = false;
            foreach ($uriSuffixes as $s) {
                if ($s !== '' && str_ends_with($requestUri, $s)) {
                    $suffixMatched = true;
                    break;
                }
            }
            if (!$suffixMatched) {
                if (isset($config->debug) && $config->debug == '1') {
                    self::writeLog(
                        'cache-' . date('Y-m-d') . '.log',
                        date('[Y-m-d H:i:s]') . ' CACHE: (PASS)    REASON: (URI SUFFIX NOT MATCHED)                           URI: (' . $requestUri . ')'
                    );
                }
                ob_end_flush();
                return;
            }
        }

        // 原先这里用 substr_count($requestUri, '/') > 2 跳过「较深嵌套」的路径。
        // 该规则误伤面过大：Typecho 的默认固定链接 /archives/1/ 是 3 个斜杠、
        // 日期型 /2026/08/23/title.html 是 4 个、分类页 /category/tech/ 是 3 个，
        // 全部被排除在缓存之外 —— 实际上只有 .html 后缀这一种链接形式能被缓存。
        //
        // 从提交历史看，当初的意图是拦截「畸形的连续斜杠」（日志原文写的是
        // multiple slashes detected），而不是限制路径深度。该校验现已前移到
        // resolveRequestPath()，用 str_contains($path, '//') 精确判断，
        // 因此这里不再需要按斜杠总数拦截。

        $content = ob_get_clean();
        if ($content === false) {
            return;
        }

        $cacheKey = self::makeCacheKey($requestUri, $archive);
        $ttl      = self::getExpireForArchive($archive);

        // 若站点开启了「评论自动关闭」，缓存不应活过该内容仍可评论的时间窗，
        // 否则评论关闭之后缓存页仍会显示评论表单，访客提交会被 403 拒绝。
        // 关闭时刻是确定的：created + commentsPostTimeout（见 Base/Contents::allow）
        $options = Helper::options();
        if ($archive->is('single') && $options->commentsAutoClose && $options->commentsPostTimeout > 0) {
            $closesIn = intval($archive->created) + intval($options->commentsPostTimeout) - time();
            if ($closesIn > 0 && $closesIn < $ttl) {
                $ttl = $closesIn;
            }
        }

        $redis->setex($cacheKey, $ttl, $content);
        echo $content;

        if (isset($config->debug) && $config->debug == '1') {
            self::writeLog(
                'cache-' . date('Y-m-d') . '.log',
                date('[Y-m-d H:i:s]') . ' CACHE: (MISS)    KEY: (' . $cacheKey . ') URI: (' . $requestUri . ')'
            );
        }
    }

    /**
     * 文章/独立页面发布时清除缓存（finishPublish 钩子传入 $contents, $widget）
     *
     * 内容发布或更新会同时改变该内容自身与所有列表页（标题、摘要、排序），
     * 因此在清理该 cid 的缓存之外，还需要清理全部列表页缓存。
     *
     * @param array $contents 内容数组
     * @param PostEdit|PageEdit $widget 编辑组件
     * @return void
     * @throws PluginException
     */
    public static function clearCacheOnPublish(array $contents, PostEdit|PageEdit $widget): void
    {
        $cid = intval($contents['cid'] ?? 0) ?: intval($widget->cid);

        self::purgeCache($cid, true, 'CONTENT UPDATED' . ($cid > 0 ? ' CID ' . $cid : ''));
    }

    /**
     * 评论提交时清除缓存（finishComment 钩子仅传入 $this）
     *
     * Feedback::$content 是私有属性，但评论行此时已经 push 进 widget，
     * 因此 $widget->cid 即为被评论内容的 ID。
     *
     * @param Feedback $widget 评论组件
     * @return void
     * @throws PluginException
     */
    public static function clearCacheOnComment(Feedback $widget): void
    {
        $cid = intval($widget->cid);

        $config     = Helper::options()->plugin(basename(__DIR__));
        $clearLists = !isset($config->clearListOnComment) || $config->clearListOnComment == '1';

        self::purgeCache($cid, $clearLists, 'NEW COMMENT' . ($cid > 0 ? ' ON CID ' . $cid : ' (CID UNKNOWN)'));
    }

    /**
     * 清空全部内容缓存（公开 API）
     *
     * 供主题或其他插件在自身配置变更后主动调用，例如主题轮换了第三方服务的
     * 站点密钥、切换了会影响所有页面的开关时：
     *
     *   if (class_exists('\TypechoPlugin\RedisCache\Plugin')) {
     *       \TypechoPlugin\RedisCache\Plugin::flushAll('RECAPTCHA KEY ROTATED');
     *   }
     *
     * 用 class_exists 保护即可，调用方不会因为未安装本插件而报错。
     *
     * @param string $reason 写入日志的原因说明
     * @return void
     * @throws PluginException
     */
    public static function flushAll(string $reason = 'MANUAL FLUSH'): void
    {
        self::purgeCache(0, true, $reason);
    }

    /**
     * 清理内容缓存
     *
     * @param int    $cid        内容 ID；大于 0 时只清理该内容的缓存，
     *                           小于等于 0 时作为兜底清理全部单篇缓存
     * @param bool   $clearLists 是否一并清理列表页缓存
     * @param string $reason     写入日志的原因说明
     * @return void
     * @throws PluginException
     */
    private static function purgeCache(int $cid, bool $clearLists, string $reason): void
    {
        $redis = self::initRedis();
        if (!$redis) {
            return;
        }

        $scope   = $cid > 0 ? $cid . ':*' : '*';
        $deleted = self::deleteByPattern($redis, self::$prefix . 'post:' . $scope)
            + self::deleteByPattern($redis, self::$prefix . 'page:' . $scope);

        if ($clearLists) {
            $deleted += self::deleteByPattern($redis, self::$prefix . 'list:*');
        }

        if ($deleted <= 0) {
            return;
        }

        $config = Helper::options()->plugin(basename(__DIR__));

        if (isset($config->debug) && $config->debug == '1') {
            self::writeLog(
                'cache-' . date('Y-m-d') . '.log',
                date('[Y-m-d H:i:s]') . ' CACHE: (VACATED) REASON: '
                    . str_pad('(' . $reason . ')', 50)
                    . 'SUM: (TOTAL ' . $deleted . ' KEYs)'
            );
        }
    }
}
