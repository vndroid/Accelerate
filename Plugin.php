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
                $keys = $redis->keys(self::$prefix . '*');
                if (!empty($keys)) {
                    $cleanCount = count($keys);
                    $redis->del($keys);
                }
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
     * - page → prefix + page:md5(uri)
     * - post → prefix + post:md5(uri)
     *
     * @param string  $requestUri
     * @param Archive $archive
     * @return string
     */
    private static function makeCacheKey(string $requestUri, Archive $archive): string
    {
        if ($archive->is('page')) {
            return self::$prefix . 'page:' . md5($requestUri);
        }
        return self::$prefix . 'post:' . md5($requestUri);
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
     * 在渲染前检查缓存是否存在
     *
     * @param Archive $archive
     * @return void
     * @throws DbException
     * @throws PluginException
     */
    public static function beforeRender(Archive $archive): void
    {
        $user = User::alloc();
        if ($user->hasLogin()) {
            return;
        }

        $redis = self::initRedis();
        if (!$redis) {
            return;
        }

        $requestUri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
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

        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $config     = Helper::options()->plugin(basename(__DIR__));

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

        // 检查路径中是否存在较深嵌套（多于两个斜杠），如果存在则跳过缓存
        if (substr_count($requestUri, '/') > 2) {
            if (isset($config->debug) && $config->debug == '1') {
                self::writeLog(
                    'cache-' . date('Y-m-d') . '.log',
                    date('[Y-m-d H:i:s]') . ' CACHE: (PASS)    REASON: (URI WITH MULTIPLE SLASHES)                        URI: (' . $requestUri . ')'
                );
            }
            ob_end_flush();
            return;
        }

        $content = ob_get_clean();
        if ($content === false) {
            return;
        }

        $cacheKey = self::makeCacheKey($requestUri, $archive);
        $ttl      = self::getExpireForArchive($archive);

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
     * @param array $contents 内容数组
     * @param PostEdit|PageEdit $widget 编辑组件
     * @return void
     * @throws PluginException
     */
    public static function clearCacheOnPublish(array $contents, PostEdit|PageEdit $widget): void
    {
        self::flushPageCache();
    }

    /**
     * 评论提交时清除缓存（finishComment 钩子仅传入 $this）
     *
     * @param Feedback $widget 评论组件
     * @return void
     * @throws PluginException
     */
    public static function clearCacheOnComment(Feedback $widget): void
    {
        self::flushPageCache();
    }

    /**
     * 清除所有页面缓存
     *
     * @return void
     * @throws PluginException
     */
    private static function flushPageCache(): void
    {
        $redis = self::initRedis();
        if (!$redis) {
            return;
        }

        $keysArrays = array_merge(
            $redis->keys(self::$prefix . 'post:*') ?: [],
            $redis->keys(self::$prefix . 'page:*') ?: []
        );

        if (!empty($keysArrays)) {
            $redis->del($keysArrays);

            $config = Helper::options()->plugin(basename(__DIR__));

            if (isset($config->debug) && $config->debug == '1') {
                self::writeLog(
                    'cache-' . date('Y-m-d') . '.log',
                    date('[Y-m-d H:i:s]') . ' CACHE: (VACATED) REASON: (ARTICLE CONTENT UPDATED)                          SUM: (TOTAL '. count($keysArrays) . ' KEYs)'
                );
            }
        }
    }
}
