<?php

namespace TypechoPlugin\Accelerate;

use Redis;
use Throwable;
use Typecho\Cookie;
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
use Widget\Contents\Attachment\Edit as AttachmentEdit;
use Widget\Comments\Edit as CommentsEdit;
use Widget\Feedback;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 页面缓存插件 via Redis for Typecho
 *
 * @package Accelerate
 * @author Vex
 * @version 0.1.1
 * @link https://github.com/vndroid/Accelerate
 */
class Plugin implements PluginInterface
{
    /**
     * 缓存键结构版本
     *
     * 键名结构发生变化、或存量缓存的内容已不可信时，必须递增此版本号。
     * 插件会在下一次连接 Redis 时作废当前前缀下的全部内容缓存，
     * 避免旧键既无法命中、又无法被按 cid 精确清理而长期滞留。
     *
     * v1: {prefix}post:{md5} / {prefix}page:{md5}
     * v2: {prefix}{post|page|list}:{id}:{md5}
     * v3: 前缀规范化为 plugin:accelerate:[{siteTag}:]，键形态与 v2 相同
     * v4: 键形态与 v3 相同。用于作废 v3 期间写入的两类脏缓存：404 页面
     *     （键名是合法的 list:0:{md5}）与密码保护文章的明文正文，
     *     两者都无法靠键名形态识别，只能整体作废。
     * v5: hash 的输入从「路径」改为「规范 origin + 路径」，键形态不变。
     *     同时作废 v4 期间可能被 http/https 或别名域名互相污染的缓存。
     */
    private const SCHEMA_VERSION = '5';

    /**
     * 缓存键命名空间（硬编码，不可配置）
     *
     * 与 Typecho 存放插件配置的 options 行名 plugin:Accelerate 沿用同一套命名习惯，
     * 便于在 Redis GUI 里按 : 建树浏览，也便于一眼区分本插件与其他应用的键。
     * 可选的站点标识由配置项 siteTag 追加在其后。
     */
    private const NAMESPACE_PREFIX = 'plugin:accelerate:';

    /**
     * Redis 连接与读写超时（秒）
     *
     * 缓存是「可选加速层」，宁可放弃加速也不该让访客为一个不可达的 Redis 干等。
     * phpredis 的默认读超时是 0（无限期阻塞），必须显式设置。
     */
    private const CONNECT_TIMEOUT = 1.0;
    private const READ_TIMEOUT    = 1.0;

    /**
     * 初始化实例
     */
    private static ?Redis $redis = null;

    /**
     * 本次请求内 Redis 是否已经确认不可用
     *
     * 单个请求里 beforeRender / afterRender / purgeCache 都会调 initRedis()，
     * 不记忆失败的话，Redis 挂掉时每个调用点都要重新吃一遍 connect 超时。
     */
    private static bool $initFailed = false;

    /**
     * 当前命名空间的键结构是否已确认为 SCHEMA_VERSION
     *
     * 迁移由抢到锁的那一个请求执行，其余请求会跳过迁移。跳过之后如果照常读写，
     * 就可能在迁移完成前命中上一版语义的键（例如 v3 遗留的 404 页面缓存、
     * 密码文章明文）。因此把「schema 已就绪」作为前台读写的前置条件；
     * 清理与后台管理路径不受它约束 —— 那些操作本来就是要去动旧数据的。
     */
    private static bool $schemaReady = false;

    /**
     * 本次请求使用的 schema 哨兵值：SCHEMA_VERSION + 配置代次
     *
     * 光有 SCHEMA_VERSION 挡不住这个场景：改 siteTag / host / port 时，
     * flushOnCriticalChange() 会用旧配置去清空旧命名空间，但**那一刻 Redis
     * 恰好不可达**的话清理就失败了，而配置照样保存。旧命名空间里 schema 仍是
     * 当前版本、内容键也都在，日后一旦切回去就会原样命中陈旧内容。
     *
     * 加一段代次即可：代次存在插件配置（数据库）里，不依赖 Redis 当时是否活着。
     * 每次关键配置变更都递增，于是切回旧命名空间时哨兵必然对不上，强制全量作废。
     */
    private static string $schemaStamp = '';

    /**
     * 进入 beforeRender() 时的 $_COOKIE 快照
     *
     * 用于在 afterRender() 里判断渲染期间是否动过 cookie。
     * Typecho 的 Response::setCookie() 只把 cookie 存进数组、等 respond() 才发送，
     * headers_list() 看不到；但 Cookie::set() 会同步写 $_COOKIE（见
     * var/Typecho/Cookie.php:143），所以比对 $_COOKIE 就能发现。
     *
     * 存整个数组而不是 array_keys()：键不变、**值**被刷新（访问计数、A/B 分桶、
     * 语言偏好之类）同样意味着响应绑定到了当前访客。PHP 数组写时复制，
     * $_COOKIE 又很小，存一份副本的成本可以忽略。
     */
    private static array $cookiesAtStart = [];

    /**
     * 已注册延迟清理的 cid，避免批量操作时重复注册 shutdown 回调
     */
    private static array $deferredPurges = [];

    /**
     * 当前生效的完整缓存键前缀（命名空间 + 可选站点标识），由 makePrefix() 计算
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
        if (!str_ends_with(trim(__DIR__, '/\\'), 'Accelerate')) {
            throw new PluginException(_t('插件目录名必须为 Accelerate（区分大小写），请检查插件目录名是否正确'));
        }
        // 在内容渲染前尝试从缓存获取
        Archive::pluginHandle()->beforeRender = [self::class, 'beforeRender'];

        // 在内容渲染后缓存内容
        Archive::pluginHandle()->afterRender = [self::class, 'afterRender'];

        // 当文章 / 页面发布或更新时清除缓存
        PostEdit::pluginHandle()->finishPublish = [self::class, 'clearCacheOnPublish'];
        PageEdit::pluginHandle()->finishPublish = [self::class, 'clearCacheOnPublish'];

        // 当内容被删除时清除缓存。
        // 不挂的话，已删除的文章最长还能从 Redis 公开访问 postExpire（默认一天），
        // 独立页面最长 pageExpire（默认一月）—— 内容都没了，缓存还在对外服务。
        PostEdit::pluginHandle()->finishDelete = [self::class, 'clearCacheOnContentDelete'];
        PageEdit::pluginHandle()->finishDelete = [self::class, 'clearCacheOnContentDelete'];

        // 附件也要挂：archiveType 'attachment' 在 isCacheableArchive() 的白名单里，
        // 附件页是会被缓存的。钩子在 Attachment\Edit::deleteByIds() 里，
        // 签名与 Post / Page 的 finishDelete 完全一致。
        // 附件「编辑」（updateAttachment()）核心没有提供任何钩子，覆盖不到。
        AttachmentEdit::pluginHandle()->finishDelete = [self::class, 'clearCacheOnContentDelete'];

        // 当内容被标记为隐藏 / 私密 / 待审核时清除缓存。
        // Typecho 的状态变更走的是 markPost()/markPage()，不经过 finishPublish。
        PostEdit::pluginHandle()->finishMark = [self::class, 'clearCacheOnContentMark'];
        PageEdit::pluginHandle()->finishMark = [self::class, 'clearCacheOnContentMark'];

        // 前台评论提交时清除缓存
        Feedback::pluginHandle()->finishComment = [self::class, 'clearCacheOnComment'];

        // 后台评论操作时清除缓存。
        // 注意 Widget\Comments\Edit 与 Widget\Feedback 是两个不同的类：后台「回复评论」
        // 触发的是前者的 finishComment，前台访客评论触发的是后者，必须分别挂。
        // 另外评论只有 mark 没有 finishMark ——「通过 / 待审核 / 垃圾」三个操作都走 mark，
        // 且它在 UPDATE 之前触发（见 var/Widget/Comments/Edit.php::mark）。
        // 引用与 Pingback。两者都只能挂 filter 而不是 finish* 钩子：
        // Feedback::finishTrackback 与 XmlRpc::finishPingback 都只传 $widget，
        // 而 trackback() 在触发钩子前没有像 comment() 那样 push 评论行
        // （见 var/Widget/Feedback.php:265-271 与 :344-350），$widget->cid 拿不到。
        // filter 的第一个参数里带着 cid，且必须原样返回。
        // Pingback 用字符串句柄而不是 XmlRpc::pluginHandle()：后者会在启用插件时
        // 就把 Widget\XmlRpc 及其 IXR 依赖全部 autoload 进来，而这个类平时只在
        // XML-RPC 请求里用到。Plugin::factory() 按原始字符串建索引，
        // 与类内 pluginHandle() 里的 static::class 完全一致。
        Feedback::pluginHandle()->trackback = [self::class, 'clearCacheOnTrackback'];
        \Typecho\Plugin::factory('Widget\\XmlRpc')->pingback = [self::class, 'clearCacheOnPingback'];

        CommentsEdit::pluginHandle()->mark          = [self::class, 'clearCacheOnCommentMark'];
        CommentsEdit::pluginHandle()->finishDelete  = [self::class, 'clearCacheOnCommentDelete'];
        CommentsEdit::pluginHandle()->finishEdit    = [self::class, 'clearCacheOnCommentTouch'];
        CommentsEdit::pluginHandle()->finishComment = [self::class, 'clearCacheOnCommentTouch'];

        \Typecho\Plugin::factory('admin/footer.php')->begin = [self::class, 'injectFooterJs'];
        \Typecho\Plugin::factory('admin/menu.php')->navBar = [self::class, 'addAdminPageBar'];

        Helper::addPanel(3, 'Accelerate/Panel.php', _t('缓存管理'), _t('文章缓存清单'), 'administrator');

        $configLink = '<a href="' . Helper::options()->adminUrl('options-plugin.php?config=Accelerate', true) . '">' . _t('前往设置') . '</a>';
        return _t('插件已启用，但缓存功能未启用，请检查服务 URI ，') . $configLink;
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @throws PluginException
     */
    public static function deactivate(): string
    {
        Helper::removePanel(3, 'Accelerate/Panel.php');

        $config = Helper::options()->plugin(basename(__DIR__));
        $shouldCleanCache = !isset($config->cleanCacheOnDeactivate) || $config->cleanCacheOnDeactivate == '1';

        $cleanCount = 0;

        if ($shouldCleanCache) {
            // 传 true 忽略 enableCache 开关：用户先在设置里关掉缓存、再停用插件时，
            // 旧缓存同样需要按「禁用时清理」的选择被清掉。
            $redis = self::initRedis(true);
            if ($redis) {
                $cleanCount = self::deleteByPattern($redis, self::$prefix . '*') ?? 0;
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
            _t('是否启用缓存功能')
        );
        $form->addInput($enableCache);

        $host = new Text(
            'host',
            null,
            '127.0.0.1',
            _t('Redis 服务地址'),
            _t('输入 Redis 服务主机地址，默认为 127.0.0.1')
        );
        $form->addInput($host);

        $port = new Text(
            'port',
            null,
            '6379',
            _t('Redis 服务端口'),
            _t('输入 Redis 服务端口，默认为 6379')
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

        $siteTag = new Text(
            'siteTag',
            null,
            '',
            _t('站点标识'),
            _t('可选。同一个 Redis 库同时承载多个站点时，在此填写站点标识加以区分，键名将变为 <code>plugin:accelerate:{标识}:post:{cid}:{hash}</code>。只允许字母、数字、下划线与连字符，默认不加标识')
        );
        $form->addInput($siteTag);

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
     * 保存配置前的校验与提示（Typecho 在 configHandle() 之前调用）
     *
     * 为什么校验分成 configCheck + configHandle 两半：
     * configCheck() 的返回值会被 Typecho 设成 Notice 并置 configNoticed，从而抑制
     * 默认那句「插件设置已经保存」——但它**不会阻止保存**（见
     * var/Widget/Plugins/Edit.php:118-138）。真正决定要不要落库的是 configHandle()。
     * 所以提示走这里，拦截走那里，两边共用同一个 validateConfig()。
     *
     * @param array $settings 表单提交的配置
     * @return string|null 提示信息
     */
    public static function configCheck(array $settings): ?string
    {
        $errors = self::validateConfig($settings);

        if (!empty($errors)) {
            return _t('设置未保存：') . implode('；', $errors);
        }

        // 校验通过，configHandle() 随后会落库。顺手做一次连接自检 ——
        // 这是唯一适合做可写性测试的时机：用户主动触发、有人看着结果，
        // 而不是让每个前台请求都白跑一遍。
        if (($settings['enableCache'] ?? '0') === '1') {
            $result = self::selfTest($settings);

            // 自检失败不阻止保存（Redis 临时不可达时，用户仍然需要能改 TTL、
            // 改 URI 规则，甚至需要靠「保存正确的主机名」把自己救回来）。
            // 但提示必须把后果说明白 —— 从 configCheck() 返回的消息，Typecho 一律
            // 用 notice 类型渲染（Widget\Plugins\Edit:122-131），给不了红色，
            // 只能靠文案区分。
            return $result['ok']
                ? _t('设置已保存，Redis 自检通过：') . $result['message']
                : _t(
                    '设置已保存，但 Redis 自检失败：%s —— 缓存暂时不会生效，请修正配置后重新保存。',
                    $result['message']
                );
        }

        return _t('设置已保存（缓存功能未启用）');
    }

    /**
     * 接管配置保存
     *
     * 注意：一旦插件定义了 configHandle()，Typecho 就**不再代为保存**配置
     * （var/Widget/Plugins/Edit.php:132-136 里 configHandle 返回 true 后就跳过了
     * self::configPlugin），必须自己调 Helper::configPlugin()。
     *
     * @param array $settings 表单提交的配置
     * @param bool  $isInit   是否为插件启用时写入的表单默认值
     * @return void
     */
    public static function configHandle(array $settings, bool $isInit): void
    {
        // 启用插件时写入的是表单默认值，本来就合法，直接落库
        if (!$isInit) {
            if (!empty(self::validateConfig($settings))) {
                // 校验不通过就不写库，原有配置保持不变。
                // 提示信息由 configCheck() 负责，这里静默返回即可。
                return;
            }

            $settings = self::flushOnCriticalChange($settings);
        }

        Helper::configPlugin(basename(__DIR__), $settings);
    }

    /**
     * 关键配置发生变化时，先按**旧配置**清空一次命名空间，再落库
     *
     * 针对两类会让存量缓存变得不可信的改动：
     *
     * 1) enableCache 从启用切到禁用（或反过来）。禁用期间 purgeCache() 虽然已经
     *    改成忽略开关，但如果那段时间 Redis 本身不可达，失效就丢了；重新启用后
     *    未过 TTL 的旧内容会原样复活。
     * 2) uriPrefix / uriSuffix 收紧。已存在但不再符合规则的键不会自己消失。
     * 3) siteTag / host / port / password 变化。前者换命名空间，后三者换实例 ——
     *    都必须在切换**之前**、用旧参数连上去把旧数据清掉，否则就再也够不着了。
     *
     * 无论清理成功与否都会递增配置代次 cacheGeneration，并把它写进返回的
     * $settings 一起落库。这一步才是真正的保险：清理依赖 Redis 当场可达，
     * 代次不依赖 —— 就算这次一个键都没删掉，旧命名空间里的哨兵也已经过期了，
     * 日后切回去必然触发一次全量作废。
     *
     * @param array $settings 即将保存的新配置
     * @return array 可能带上了新代次的配置
     */
    private static function flushOnCriticalChange(array $settings): array
    {
        try {
            $old = Helper::options()->plugin(basename(__DIR__));
        } catch (Throwable $e) {
            // 还没有旧配置（首次保存），无需清理
            return $settings;
        }

        $watched = ['enableCache', 'uriPrefix', 'uriSuffix', 'siteTag', 'host', 'port', 'password'];
        $changed = false;

        foreach ($watched as $key) {
            if ((string) ($old->$key ?? '') !== (string) ($settings[$key] ?? '')) {
                $changed = true;
                break;
            }
        }

        if (!$changed) {
            return $settings;
        }

        // 递增代次。cacheGeneration 不在 config(Form) 里声明，所以 getAllRequest()
        // 不会带它；而 Helper::configPlugin() 内部是 array_merge($已存, $settings)，
        // 因此不主动写的时候旧值会自然保留。
        $settings['cacheGeneration'] = intval($old->cacheGeneration ?? 0) + 1;

        // initRedis() 此刻读到的仍是旧配置，self::$prefix 也是旧前缀 —— 正是所需
        $redis = self::initRedis(true);
        if (!$redis) {
            return $settings;
        }

        $deleted = self::deleteByPattern($redis, self::$prefix . '*');

        self::writeLog(
            'cache-' . date('Y-m-d') . '.log',
            date('[Y-m-d H:i:s]') . ' CACHE: (FLUSHED) REASON: '
                . str_pad('(CONFIG CHANGED GEN ' . $settings['cacheGeneration'] . ')', 50)
                . ($deleted === null ? 'SUM: (FAILED)' : 'SUM: (TOTAL ' . $deleted . ' KEYs)')
        );

        return $settings;
    }

    /**
     * 校验一份配置，返回全部错误信息
     *
     * 原则是「直接拒绝非法值，不静默修正」：
     * - TTL 原先走 `intval($v) ?: 默认值`。负数为真会原样进 setex()，Redis 直接报
     *   invalid expire time；填 0 则被悄悄改回默认值，界面上完全看不出来。
     * - siteTag 原先在 makePrefix() 里被正则剥掉非法字符，于是 `site!` 和 `site?`
     *   都变成 `site` —— 多站点共用一个 Redis 库时会共用同一命名空间，
     *   表现为缓存串站或互相误清。makePrefix() 的剥离保留为兜底（老配置里可能
     *   已经存着非法值），但新值一律在这里拒掉。
     * - enableAuth 原先只控制后台字段显隐，完全没参与连接逻辑，是否认证实际由
     *   密码是否为空决定。这里不去静默清空密码（那会直接搞坏一个正在工作的连接），
     *   而是要求用户把开关和密码调成一致。
     *
     * @param array $settings
     * @return string[]
     */
    private static function validateConfig(array $settings): array
    {
        $errors = [];

        if (trim((string) ($settings['host'] ?? '')) === '') {
            $errors[] = _t('Redis 服务地址不能为空');
        }

        $port = (string) ($settings['port'] ?? '');
        if (!ctype_digit($port) || intval($port) < 1 || intval($port) > 65535) {
            $errors[] = _t('Redis 服务端口必须是 1 到 65535 之间的整数');
        }

        $expires = [
            'postExpire' => _t('文章缓存时间'),
            'pageExpire' => _t('页面缓存时间'),
        ];

        foreach ($expires as $key => $label) {
            $value = (string) ($settings[$key] ?? '');
            if (!ctype_digit($value) || intval($value) < 1) {
                $errors[] = _t('%s 必须是大于 0 的整数', $label);
            }
        }

        $tag = trim((string) ($settings['siteTag'] ?? ''));
        if ($tag !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $tag)) {
            $errors[] = _t('站点标识只允许字母、数字、下划线与连字符');
        }

        // Radio 取值枚举校验。Typecho 的 Form\Element\Radio 没有内置取值约束，
        // Form::validate() 也只跑显式 addRule() 加的规则，所以伪造的 POST 能存进库。
        // 存进去之后各处判断会互相矛盾：initRedis() 用 !== '1' 之外的比较会当作启用，
        // addAdminPageBar() 的 === '1' 又显示未启用。
        $radios = [
            'enableCache'            => _t('启用缓存'),
            'enableAuth'             => _t('启用认证'),
            'clearListOnComment'     => _t('评论时清理列表页缓存'),
            'debug'                  => _t('调试模式'),
            'cleanCacheOnDeactivate' => _t('禁用时清理缓存'),
        ];

        foreach ($radios as $key => $label) {
            if (!in_array((string) ($settings[$key] ?? ''), ['0', '1'], true)) {
                $errors[] = _t('%s 的取值不合法', $label);
            }
        }

        $enableAuth = (string) ($settings['enableAuth'] ?? '0');
        $password   = (string) ($settings['password'] ?? '');

        if ($enableAuth === '1' && $password === '') {
            $errors[] = _t('已启用 Redis 认证，但密码为空');
        }

        if ($enableAuth === '0' && $password !== '') {
            $errors[] = _t('未启用 Redis 认证，但填写了密码；请启用认证，或清空密码');
        }

        return $errors;
    }

    /**
     * 用给定的一组配置连一次 Redis，做一轮读写自检
     *
     * 只在后台保存配置时调用，不参与任何前台请求。直接吃 $settings 而不是读
     * Helper::options()，因为此刻新配置还没落库。
     *
     * 键名带随机后缀并设了 TTL：原先固定用 {prefix}test，两个并发请求会互相
     * 覆盖和误删，导致假阴性。
     *
     * @param array $settings
     * @return array{ok: bool, message: string}
     */
    public static function selfTest(array $settings): array
    {
        try {
            $prefix = self::makePrefix($settings['siteTag'] ?? '');
            $key    = $prefix . 'test:' . bin2hex(random_bytes(8));
            $value  = 'accelerate-selftest-' . bin2hex(random_bytes(8));

            $redis     = new Redis();
            $connected = $redis->connect(
                (string) ($settings['host'] ?? '127.0.0.1'),
                intval($settings['port'] ?? 6379),
                self::CONNECT_TIMEOUT,
                null,
                0,
                self::READ_TIMEOUT
            );

            if (!$connected) {
                return ['ok' => false, 'message' => _t('无法连接到 Redis 服务')];
            }

            $redis->setOption(Redis::OPT_READ_TIMEOUT, self::READ_TIMEOUT);

            $password = (string) ($settings['password'] ?? '');
            if ($password !== '' && !$redis->auth($password)) {
                return ['ok' => false, 'message' => _t('认证失败')];
            }

            $redis->setex($key, 10, $value);
            $readBack = $redis->get($key);
            $redis->del($key);
            $redis->close();

            if ($readBack !== $value) {
                return [
                    'ok'      => false,
                    'message' => _t('连接正常，但写入校验未通过（实例可能是只读副本，或内存已满）'),
                ];
            }

            return ['ok' => true, 'message' => _t('连接与读写均正常')];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
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
        // 仅在本插件配置页注入：先确认是插件配置页，再确认是 Accelerate
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
     * @param bool $ignoreSwitch 忽略 enableCache 开关。供「管理与清理」场景使用：
     *                           停用插件时清缓存、后台缓存管理页。这两处即使用户
     *                           已经关掉缓存，也必须能连上去处理存量数据。
     * @return Redis|null
     * @throws PluginException
     */
    public static function initRedis(bool $ignoreSwitch = false): ?Redis
    {
        if (self::$redis !== null) {
            return self::$redis;
        }

        // 本次请求内已经确认不可用就不再重试，避免每个调用点各吃一遍连接超时
        if (self::$initFailed) {
            return null;
        }

        $config = Helper::options()->plugin(basename(__DIR__));

        // 如果未启用缓存，直接返回。这里不置 $initFailed —— 缓存被禁用不是连接失败，
        // 同一请求里随后带 $ignoreSwitch 的调用仍然应该能连上。
        //
        // 判断用 !== '1'（白名单）而不是 == '0'：库里若存着历史脏值或伪造值，
        // 「不等于 0 就算启用」会把无法识别的取值当成启用，白名单则安全默认。
        if (!$ignoreSwitch && (string) ($config->enableCache ?? '0') !== '1') {
            return null;
        }

        // 设置缓存参数，配置为空时使用默认值
        self::$prefix      = self::makePrefix($config->siteTag ?? '');
        self::$schemaStamp = self::SCHEMA_VERSION . ':' . intval($config->cacheGeneration ?? 0);
        self::$postExpire  = intval($config->postExpire) ?: 86400;
        self::$pageExpire  = intval($config->pageExpire) ?: 2592000;

        // 创建日志目录（writeLog 会自行处理，此处无需手动创建）
        $logFilename = 'redis-' . date('Y-m-d') . '.log';

        try {
            // 尝试连接 Redis。第 6 个参数是读超时 —— phpredis 默认 0（无限期阻塞），
            // 一个卡住的 Redis 会把请求一起挂死，必须显式设置。
            $redis     = new Redis();
            $connected = $redis->connect(
                $config->host,
                intval($config->port),
                self::CONNECT_TIMEOUT,
                null,
                0,
                self::READ_TIMEOUT
            );

            if (!$connected) {
                throw new \Exception('无法连接到 Redis 服务');
            }

            $redis->setOption(Redis::OPT_READ_TIMEOUT, self::READ_TIMEOUT);

            // 如果设置了密码，进行验证。
            // 「是否认证」由密码是否为空决定，而不是由 enableAuth 决定 —— 这两者
            // 的一致性改由 validateConfig() 在保存配置时强制（enableAuth=0 却填了
            // 密码，或 enableAuth=1 却留空，都会被拒绝保存）。
            if (!empty($config->password)) {
                $authResult = $redis->auth($config->password);
                if (!$authResult) {
                    throw new \Exception('Redis 服务认证失败');
                }
            }

            // 这里原先有一次 PING。现在 migrateSchema() 的 GET 就是本请求的第一条
            // 真实命令，连接不可用时它会抛异常并被下面的 catch 接住，PING 已成冗余。
            // 这里原先还做了三件事，全部已经移出热路径：
            //
            // 1) 固定键名 {prefix}test 的写入 / 读回 / 删除（3 次额外往返）。
            //    除了慢，还有并发竞争：请求 A 的 del() 可能早于请求 B 的 get()，
            //    B 会读到 false，误判成「写入测试失败」并放弃整个连接，
            //    该请求于是完全不走缓存。可写性测试现在只在后台保存配置时跑
            //    （见 selfTest()），键名带随机后缀。
            // 2) RedisJSON 探测（MODULE LIST + COMMAND INFO，最多 2 次往返）。
            //    探测结果没有任何调用方，纯粹写进日志，已整体删除。
            // 3) 无条件写一行连接成功日志 —— 相当于每个前台请求多做一次磁盘 append。
            //    现在只在调试模式下写。
            if (isset($config->debug) && $config->debug == '1') {
                self::writeLog(
                    $logFilename,
                    date('[Y-m-d H:i:s]') . ' redis connect successful: ' . $config->host . ':' . $config->port
                );
            }

            // 键结构迁移。异常**不再吞掉** —— 迁移没走完就说明命名空间里还混着
            // 上一版语义的键，此时继续用这个连接读缓存是不安全的，交给下面的
            // catch 统一降级成「本请求不使用 Redis」。
            // 返回 false 表示「别的请求正在迁移」，连接照常可用（清理与后台管理
            // 仍需要它），只是前台读写要靠 $schemaReady 挡住。
            self::$schemaReady = self::migrateSchema($redis, $logFilename);

            self::$redis = $redis;
            return $redis;
        } catch (Throwable $e) {
            self::$initFailed = true;
            self::writeLog($logFilename, date('[Y-m-d H:i:s]') . ' redis connect failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 执行一次 Redis 调用，失败时降级而不是把异常抛给 Typecho
     *
     * 原先只有连接初始化过程有容错，后续的 get / ttl / setex / scan / del 全都裸奔。
     * phpredis 在连接中途断开、内存写满、连到只读副本等情况下会抛 RedisException，
     * 未捕获就会被 Typecho 渲染成异常页 —— 一个「可选加速层」不该有能力让正常
     * 页面渲染失败。约定：读取失败等价于未命中，写入与清理失败只记日志。
     *
     * 失败后顺手把连接标记为不可用。坏掉的连接在同一个请求里不会自愈，
     * 继续拿它去调只会在后续每个调用点再抛一次。
     *
     * @param callable $operation 实际的 Redis 调用
     * @param mixed    $fallback  失败时的返回值
     * @param string   $what      写进日志的操作名
     * @return mixed
     */
    private static function attempt(callable $operation, $fallback, string $what)
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            self::$redis      = null;
            self::$initFailed = true;

            self::writeLog(
                'redis-' . date('Y-m-d') . '.log',
                date('[Y-m-d H:i:s]') . ' ' . $what . ' failed: ' . $e->getMessage()
            );

            return $fallback;
        }
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
     * 计算当前生效的缓存键前缀
     *
     * 命名空间恒为 NAMESPACE_PREFIX；站点标识为可选段，只在同一 Redis 库
     * 跑多个 Typecho 站点时才需要填写。标识经白名单清洗，避免用户输入的
     * 冒号、空格、通配符污染键结构或干扰 SCAN 的 MATCH 模式。
     *
     * @param string|null $siteTag 用户填写的站点标识（未经清洗）
     * @return string 以冒号结尾的完整前缀
     */
    private static function makePrefix(?string $siteTag): string
    {
        $tag = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) $siteTag));

        return $tag === '' ? self::NAMESPACE_PREFIX : self::NAMESPACE_PREFIX . $tag . ':';
    }

    /**
     * 返回当前生效的缓存键前缀（公开 API，供 Panel.php 及第三方调用）
     *
     * initRedis() 成功后 self::$prefix 已就绪；若缓存被禁用导致 initRedis()
     * 提前返回，则此处直接读配置重新算一次，保证 Panel 列出的键与实际写入一致。
     *
     * @return string
     */
    public static function getPrefix(): string
    {
        if (self::$prefix === '') {
            try {
                $config = Helper::options()->plugin(basename(__DIR__));
                self::$prefix = self::makePrefix($config->siteTag ?? '');
            } catch (Throwable $e) {
                return self::NAMESPACE_PREFIX;
            }
        }

        return self::$prefix;
    }

    /**
     * 判断某个键名是否为本插件在当前前缀下写入的内容缓存键（公开 API，供 Panel.php 使用）
     *
     * 键名校验集中在这里，避免 Panel 里再抄一份正则而与 makeCacheKey()
     * 漂移。控制键（test / schema / schema:lock）不符合内容键形态，
     * 因此会被一并判否 —— 它们既不该展示在缓存清单里，更不该被手工删除。
     *
     * @param string $key 完整键名（含前缀）
     * @return bool
     */
    public static function isContentCacheKey(string $key): bool
    {
        $prefix = self::getPrefix();

        if (!str_starts_with($key, $prefix)) {
            return false;
        }

        return (bool) preg_match(
            '/^(post|page|list):\d+:[0-9a-f]{32}$/',
            substr($key, strlen($prefix))
        );
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
     * hash 的输入是「规范 origin + 路径」而不是只有路径。同一条路径在
     * http 与 https 下产出的页面并不相同：Widget\Options::___siteUrl() 会在
     * $request->isSecure() 时把站点地址整体改写成 https（var/Widget/Options.php），
     * 评论反垃圾令牌又是 md5(secret & 完整请求 URL)（var/Widget/Archive.php:1194，
     * 其中 getUrlPrefix() 含 scheme 与 Host）。只用路径当键会让两种协议、
     * 不同端口、别名域名共用同一份缓存，轻则令牌失配导致评论提交失败，
     * 重则互相污染。origin 由 resolveCanonicalOrigin() 校验后给出。
     *
     * @param string  $origin     经白名单校验的 scheme://host[:port]
     * @param string  $requestUri
     * @param Archive $archive
     * @return string
     */
    private static function makeCacheKey(string $origin, string $requestUri, Archive $archive): string
    {
        $hash = md5($origin . $requestUri);

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
     * 返回 null 而不是 0 表示**失败**。两者必须区分开：调用方（尤其是管理页）
     * 需要能说清楚到底是「确实一条都没有」还是「Redis 出问题了」，
     * 否则会出现「已清空全部内容缓存，共 0 条」这种明明失败却报成功的提示。
     *
     * @param Redis  $redis
     * @param string $pattern 完整的键名匹配模式（需自行带上前缀）
     * @return int|null 实际删除的键数量；失败返回 null
     */
    private static function deleteByPattern(Redis $redis, string $pattern): ?int
    {
        // purgeCache() 一次会连调本函数 2~3 次。首次失败后连接已被标记为不可用，
        // 后面几次没必要再各抛一次异常、各写一行日志。
        if (self::$initFailed) {
            return null;
        }

        // 整段包在 attempt() 里：清理失败只记日志，绝不能让发布文章、提交评论
        // 这些核心操作因为 Redis 出问题而报错。
        $deleted = self::attempt(function () use ($redis, $pattern) {
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
        }, null, 'cache purge (' . $pattern . ')');

        return $deleted === null ? null : intval($deleted);
    }

    /**
     * 键结构迁移
     *
     * 用一个哨兵键记录当前使用的结构版本，版本不匹配时作废全部内容缓存。
     *
     * 之所以放在这里而不是 activate()：Typecho 在禁用插件时会一并删除插件配置
     * （var/Widget/Plugins/Edit.php），activate() 执行时拿不到 Redis 连接参数；
     * 而覆盖文件升级时插件保持启用状态，activate() 根本不会被调用。
     * 挂在连接建立之后，才能覆盖到全部升级路径。
     *
     * @param Redis  $redis
     * @param string $logFilename
     * @return bool 命名空间是否已确认为当前结构版本
     */
    private static function migrateSchema(Redis $redis, string $logFilename): bool
    {
        $schemaKey = self::$prefix . 'schema';

        // 比对的是「版本 + 配置代次」，不是光比版本，原因见 $schemaStamp 的注释
        if ($redis->get($schemaKey) === self::$schemaStamp) {
            return true;
        }

        // 抢占迁移锁，避免并发请求同时扫描整个 keyspace。
        //
        // TTL 取 300 秒而不是 60：keyspace 很大时一轮 SCAN 可能超过一分钟，
        // 锁提前过期会让第二个请求开始第二轮迁移，与第一轮交叠。
        $lockKey = self::$prefix . 'schema:lock';
        if (!$redis->set($lockKey, '1', ['nx', 'ex' => 300])) {
            // 已经有请求在迁移。**本次请求不能使用缓存** —— 此刻命名空间里
            // 还混着上一版语义的键，读到就可能是不该对外的内容（例如 v3 遗留的
            // 密码文章明文），写进去的新键也可能被随后的清理扫掉。
            return false;
        }

        $purged = self::purgeLegacyKeys($redis);

        $redis->set($schemaKey, self::$schemaStamp);
        $redis->del($lockKey);

        self::writeLog(
            $logFilename,
            date('[Y-m-d H:i:s]') . ' schema migrated to ' . self::$schemaStamp
                . ': purged ' . $purged . ' legacy key(s)'
        );

        return true;
    }

    /**
     * 作废当前前缀下的全部内容缓存
     *
     * 只保留少量控制键，其余一律删除。缓存本身是可丢弃的，「整体作废」
     * 比「按形态识别历史格式」既更简单也更安全：它同时覆盖两种情况 ——
     * 键结构变更（旧键无法命中也无法按 cid 清理），以及键名合法但内容
     * 已不可信（例如 v3 期间写入的 404 页面缓存、密码保护文章明文）。
     * 将来无论出于哪种原因需要作废缓存，只需递增 SCHEMA_VERSION；
     * 关键配置变更导致的作废则由 $schemaStamp 里的配置代次自动完成。
     *
     * @param Redis $redis
     * @return int 实际删除的键数量
     */
    private static function purgeLegacyKeys(Redis $redis): int
    {
        // 控制键：连接测试、结构版本标记、迁移锁，不属于内容缓存，需保留
        $reserved = ['test', 'schema', 'schema:lock'];

        $purged   = 0;
        $stale    = [];
        $iterator = null;

        $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

        while (($keys = $redis->scan($iterator, self::$prefix . '*', 500)) !== false) {
            foreach ($keys as $key) {
                $name = substr($key, strlen(self::$prefix));

                if (in_array($name, $reserved, true)) {
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
     * 解析并校验当前请求的 origin
     *
     * **不能直接信任 Host 请求头**：它由客户端控制。如果按 Host 分别建键，
     * 攻击者随便伪造几个 Host 就能生成任意多份缓存把内存撑满；如果不校验就
     * 混用同一份缓存，又会出现别名域名污染主域名的问题。
     * 这里的做法是二选一之外的第三条：Host 与站点配置的规范主机名不一致时
     * **一律不缓存**，让这类请求老老实实回源。真要支持多域名，应当在 Web
     * 服务器层做 301 规范化。
     *
     * 端口保留在 origin 里（同一主机的 :80 与 :8443 内容可能不同），
     * 但比对主机名时忽略端口 —— 站点配置里的 siteUrl 未必写了端口。
     *
     * @return string|null scheme://host[:port]；无法确认规范性时返回 null
     */
    private static function resolveCanonicalOrigin(): ?string
    {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));

        if ($host === '') {
            return null;
        }

        $options   = Helper::options();
        $canonical = strtolower((string) parse_url($options->siteUrl, PHP_URL_HOST));

        if ($canonical === '' || preg_replace('/:\d+$/', '', $host) !== $canonical) {
            return null;
        }

        return ($options->request->isSecure() ? 'https' : 'http') . '://' . $host;
    }

    /**
     * 判断路径是否满足配置里的 URI 前缀 / 后缀规则
     *
     * 抽成独立函数是为了让读、写两侧用同一份判断。原先这段只写在 afterRender()
     * 里，于是缩小 uriPrefix / uriSuffix 之后，已经存在但不再符合规则的缓存
     * 仍会一直命中到 TTL 到期；顺带地，beforeRender() 还会为这些注定写不进去的
     * 请求白做一次 Redis GET 和一次 ob_start()。
     *
     * @param string $requestUri
     * @param mixed  $config 插件配置
     * @return bool
     */
    private static function matchesUriRules(string $requestUri, $config): bool
    {
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
            self::logPass('URI PREFIX NOT MATCHED', $requestUri, $config);
            return false;
        }

        // URI 后缀筛选：根路径 / 始终通过；uriSuffix 为空则不限制
        $rawSuffixes = isset($config->uriSuffix) ? trim($config->uriSuffix) : '';
        if ($rawSuffixes !== '' && $requestUri !== '/') {
            $uriSuffixes   = array_filter(array_map('trim', explode(',', $rawSuffixes)));
            $suffixMatched = false;

            foreach ($uriSuffixes as $suffix) {
                if ($suffix !== '' && str_ends_with($requestUri, $suffix)) {
                    $suffixMatched = true;
                    break;
                }
            }

            if (!$suffixMatched) {
                self::logPass('URI SUFFIX NOT MATCHED', $requestUri, $config);
                return false;
            }
        }

        return true;
    }

    /**
     * 调试模式下记录一次「跳过缓存」
     *
     * @param string $reason
     * @param string $requestUri
     * @param mixed  $config
     * @return void
     */
    private static function logPass(string $reason, string $requestUri, $config): void
    {
        if (!isset($config->debug) || $config->debug != '1') {
            return;
        }

        self::writeLog(
            'cache-' . date('Y-m-d') . '.log',
            date('[Y-m-d H:i:s]') . ' CACHE: (PASS)    REASON: '
                . str_pad('(' . $reason . ')', 50) . 'URI: (' . $requestUri . ')'
        );
    }

    /**
     * 读取 Typecho\Response 对象的内部状态
     *
     * Response 把状态码、内容类型和待发送的响应头全部存成 private 字段，
     * 且**没有提供任何 getter**（var/Typecho/Response.php:85/100/105）。
     * 更麻烦的是它在前台正常渲染时压根不会把这些发出去：全项目里 sendHeaders()
     * 只出现在异常处理（var/Widget/Init.php:42）和 redirect() / throwJson() /
     * throwFinish() 这几条**都会 exit** 的路径上，而 Common::init() 里那个带
     * sendHeaders 回调的 ob_start 只有 install.php 用。所以 afterRender 时
     * headers_list() 里既没有 Content-Type 也没有状态码 —— 想知道应用层的意图，
     * 只能反射。
     *
     * **这是对 Typecho 私有字段的脆弱耦合**，字段一旦改名就读不到了，因此整段
     * 包在 try/catch 里：读不到就按「正常响应」处理，宁可多缓存，也不能因为
     * 反射失败让缓存功能整体失效。
     * 依赖的字段名：status、contentType、headers、cookies。
     *
     * @return array{status: int, contentType: string, headers: array, cookies: array}
     */
    private static function typechoResponseState(): array
    {
        static $props = null;

        $fallback = [
            'status'      => 200,
            'contentType' => 'text/html',
            'headers'     => [],
            'cookies'     => [],
        ];

        // false 表示上一次反射就失败了（字段被改名 / 被移除），本请求内不再重试
        if ($props === false) {
            return $fallback;
        }

        try {
            if ($props === null) {
                $props = [];
                foreach (array_keys($fallback) as $name) {
                    $property = new \ReflectionProperty(\Typecho\Response::class, $name);
                    $property->setAccessible(true);
                    $props[$name] = $property;
                }
            }

            $response = \Typecho\Response::getInstance();

            return [
                'status'      => intval($props['status']->getValue($response)),
                'contentType' => (string) $props['contentType']->getValue($response),
                'headers'     => (array) $props['headers']->getValue($response),
                'cookies'     => (array) $props['cookies']->getValue($response),
            ];
        } catch (Throwable $e) {
            $props = false;   // 别在同一请求里反复尝试
            return $fallback;
        }
    }

    /**
     * 渲染结束后，判断这个响应本身是否适合被缓存
     *
     * isCacheableArchive() 拦的是 Typecho 自己产出的 404 / 403，但主题或其他插件
     * 在渲染期间仍可能把响应变成别的东西。缓存命中时插件是无条件把内容当成一个
     * 正常页面吐出去的，所以凡是应用层意图并非「普通 HTML 页面」的响应，
     * 都不能进缓存 —— 否则一次 503 或一次 JSON 输出会被存下来，之后无差别地
     * 喂给所有访客。
     *
     * 这里**没有**检查 301/302，因为 Typecho 自己的 redirect() 走
     * Response::respond()，那个方法末尾就是 exit（var/Typecho/Response.php:235），
     * afterRender 根本不会执行。下面的 Location 检查针对的是主题裸调 header()
     * 不 exit、以及通过 Typecho API setHeader('Location', …) 两种情况。
     *
     * @return bool
     */
    private static function isCacheableResponse(): bool
    {
        // 1) PHP 层状态码。抓得到主题 / 插件裸调的 http_response_code() 与 header()。
        if (http_response_code() !== 200) {
            return false;
        }

        // 2) 渲染期间用裸 header() 发出的跳转或个性化响应头
        foreach (headers_list() as $header) {
            if (stripos($header, 'Location:') === 0 || stripos($header, 'Set-Cookie:') === 0) {
                return false;
            }
        }

        // 3) Typecho Response 对象里记下的意图（反射读取，见上面的说明）。
        //    这一段拦的是 setStatus(503)、setContentType('application/json')、
        //    setHeader('Location', …) 这类只存进对象、没有真正发出去的调用。
        $state = self::typechoResponseState();

        if ($state['status'] !== 200) {
            return false;
        }

        // 与站点配置的内容类型逐字比对，而不是硬编码 text/html。
        // Widget\Init 启动时就把 $options->contentType 灌进了 Response
        // （var/Widget/Init.php:109），正常渲染时两者必然相等；一旦不等，
        // 就说明渲染途中有人把它改成了别的东西（application/json 之类）。
        // 硬编码的话，把站点 contentType 设成 application/xhtml+xml 的用户
        // 会发现整站突然不缓存了。
        $expectedType = strtolower(trim((string) (Helper::options()->contentType ?: 'text/html')));

        if (strtolower(trim($state['contentType'])) !== $expectedType) {
            return false;
        }

        // contentType 字段只是 Content-Type 响应头的一份副本：setContentType() 会
        // 同时写这两处，但**直接调 setHeader('Content-Type', …) 只写 headers**，
        // 字段纹丝不动。所以真正的判据是 headers 里的值，上面那一比只是廉价前置。
        // 头里的值形如 "text/html; charset=UTF-8"，比对前要剥掉参数部分。
        foreach ($state['headers'] as $name => $value) {
            if (strcasecmp((string) $name, 'Location') === 0) {
                return false;
            }

            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                $headerType = strtolower(trim(explode(';', (string) $value, 2)[0]));
                if ($headerType !== $expectedType) {
                    return false;
                }
            }
        }

        // 待发送的 cookie 队列。Cookie::set() 会顺手写 $_COOKIE（下面第 4 段就是
        // 靠它发现的），但更底层的 Response::setCookie() **只往这个 private 数组里
        // append，$_COOKIE 完全不动** —— 主题或插件直接调它时，第 4 段看不见。
        // 一个可缓存的 GET 上这个队列本来就该是空的。
        if (!empty($state['cookies'])) {
            return false;
        }

        // 4) Typecho 层设置的 cookie。Response::setCookie() 只把 cookie 存进数组、
        //    等 respond() 才发送，headers_list() 看不到；但 Cookie::set() 会同步写
        //    $_COOKIE（var/Typecho/Cookie.php:143），所以比对 $_COOKIE 就能发现。
        //    用 !== 全量比较：新增、删除、以及**值被改写**都算，任何一种都说明
        //    这个响应是绑定到当前访客的。
        if ($_COOKIE !== self::$cookiesAtStart) {
            return false;
        }

        return true;
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

        // 3) 携带「与该访客绑定」的 Typecho cookie 的请求不缓存。
        //
        //    __typecho_remember_* / __typecho_unapproved_comment：
        //    评论校验失败或待审核时由 Typecho 写入，主题可能据此回填表单或
        //    显示「您的评论正在审核」。这类页面含有该访客的私有内容，
        //    一旦写入缓存就会广播给所有人。
        //
        //    protectPassword_{cid}：
        //    Widget\Base\Contents::___hidden() 用它判断是否输出受保护正文
        //    （见 var/Widget/Base/Contents.php）。持有该 cookie 的访客看到的是
        //    全文，不持有的看到的是密码表单 —— 两种都不能进公共缓存。
        //    放在请求层判断而不是只判断单篇归档，是因为列表页同样会为持有
        //    cookie 的访客渲染出受保护文章的正文摘要。
        //    本函数在 beforeRender() 里于「查缓存」之前调用，因此读、写两侧
        //    同时被阻断：持有密码的访客既不会污染缓存，也不会命中别人的缓存。
        //
        //    **必须先去掉 Typecho 的 cookie 前缀再比对**：Cookie::setPrefix() 会把
        //    md5($options->rootUrl) 拼在每个键名前面（var/Typecho/Cookie.php:69/128/142，
        //    由 var/Widget/Init.php:96 调用），上面这些 cookie 全部走 Cookie::set() 写入，
        //    所以 $_COOKIE 里的真实键名形如
        //    「32位十六进制 + __typecho_remember_mail」。直接对裸名做前缀匹配
        //    一条都匹配不上，等于整段判断失效。
        //    Init 早于 beforeRender 执行，此处 getPrefix() 必已就绪；前缀为空
        //    （或有人手工种了裸 cookie）时下面的写法同样成立。
        $cookiePrefix = Cookie::getPrefix();

        foreach (array_keys($_COOKIE) as $rawName) {
            $name = ($cookiePrefix !== '' && str_starts_with($rawName, $cookiePrefix))
                ? substr($rawName, strlen($cookiePrefix))
                : $rawName;

            if (
                str_starts_with($name, '__typecho_remember_')
                || $name === '__typecho_unapproved_comment'
                || str_starts_with($name, 'protectPassword_')
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * 判断当前归档本身是否可以参与缓存
     *
     * 与 isCacheableRequest() 的分工：那边只看 HTTP 请求（方法、查询串、
     * cookie），这边看 Typecho 解析出来的归档对象。两者必须在 beforeRender()
     * 与 afterRender() 两侧同时生效，否则会出现「开了输出缓冲、却永远写不进
     * 缓存」的空转。
     *
     * @param Archive $archive
     * @return bool
     */
    private static function isCacheableArchive(Archive $archive): bool
    {
        $type = $archive->getArchiveType();

        // 1) 404 页面。error404Handle() 是 Widget\Archive 里唯一把 archiveType
        //    设为 'archive' 的分支，且它照常走 render()，因此 beforeRender /
        //    afterRender 都会触发 —— 不拦的话，任意随机路径（/random-1、
        //    /random-2 …）都会各写出一个 list:0:{md5} 键并按 postExpire 存活，
        //    可以被用来撑爆 Redis 内存。
        //
        //    这里不能用 $archive->is('archive')：is() 内部有
        //    (archiveSingle ? 'single' : 'archive') == $archiveType 的兜底分支，
        //    分类页、标签页、日期归档同样会返回 true。必须读 getArchiveType()。
        if ($type === 'archive') {
            return false;
        }

        // 2) 归档类型白名单。上面已经点名拦掉 404，这里再要求类型必须是
        //    Widget\Archive 自身会产出的取值；主题或插件通过 setArchiveType()
        //    设置的自定义类型一律跳过 —— 宁可少缓存，也不缓存语义未知的页面。
        static $allowed = [
            'index', 'front', 'single', 'post', 'page', 'attachment',
            'category', 'tag', 'author', 'date', 'search',
        ];

        if (!in_array($type, $allowed, true)) {
            return false;
        }

        // 3) 密码保护内容的兜底。带 protectPassword_ cookie 的请求已经在
        //    isCacheableRequest() 里被挡掉（那条同时覆盖列表页），这里再对单篇
        //    补一层：未持有密码时页面输出的是密码表单，且 Widget\Archive 会
        //    setStatus(403)，而本插件命中缓存时是以 200 输出的 —— 缓存它既没有
        //    收益，又会把 403 变成 200。
        if ($archive->is('single')) {
            if (strlen((string) $archive->password) > 0 || $archive->hidden) {
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

        $config = Helper::options()->plugin(basename(__DIR__));

        // Host 与站点规范主机名不一致时不参与缓存。若站点确实要支持多个域名，
        // 请在 Web 服务器层做 301 规范化 —— 这里放行只会让缓存互相污染。
        $origin = self::resolveCanonicalOrigin();
        if ($origin === null) {
            self::logPass('NON CANONICAL ORIGIN', $requestUri, $config);
            return;
        }

        if (!self::isCacheableArchive($archive)) {
            return;
        }

        $user = User::alloc();
        if ($user->hasLogin()) {
            return;
        }

        // URI 规则在「查缓存之前」判断。放在写入侧才判断的话，用户缩小
        // uriPrefix / uriSuffix 之后，已存在但不再符合规则的缓存会一直命中到
        // TTL 到期；而且这些注定写不进去的请求还会白做一次 GET 和一次 ob_start()。
        if (!self::matchesUriRules($requestUri, $config)) {
            return;
        }

        // 扩展点：允许主题或其他插件否决本次缓存，用于处理插件无法感知的
        // 主题级动态内容。注册方式（例如在主题的 themeInit 中）：
        //
        //   \Typecho\Plugin::factory('TypechoPlugin\Accelerate\Plugin')->skipCache
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

        // 迁移未确认完成时不读缓存：命名空间里可能还混着上一版语义的键
        if (!self::$schemaReady) {
            self::logPass('SCHEMA NOT READY', $requestUri, $config);
            return;
        }

        $cacheKey      = self::makeCacheKey($origin, $requestUri, $archive);
        $cachedContent = self::attempt(
            fn () => $redis->get($cacheKey),
            false,
            'cache read (' . $cacheKey . ')'
        );

        // 用 is_string 而不是 !== false：读取失败时 attempt() 也返回 false，
        // 两种情况都应当按「未命中」处理。
        if (is_string($cachedContent)) {
            if (isset($config->debug) && $config->debug == '1') {
                self::writeLog(
                    'cache-' . date('Y-m-d') . '.log',
                    date('[Y-m-d H:i:s]') . ' CACHE: (HIT)     KEY: (' . $cacheKey . ') URI: (' . $requestUri . ')'
                );
            }

            // 原先这里输出的 TIME 是 date(..., time() - ttl)，标称「缓存写入时间」
            // 但算法是错的：刚写入时 ttl 仍等于完整 TTL，算出来会是「一天前」。
            // 要还原写入时刻得额外存一份原始 TTL，不值得，改为只报剩余存活时间。
            // 同时这里原本调了两次 ttl()，白白多一次往返。
            $remaining = intval(self::attempt(
                fn () => $redis->ttl($cacheKey),
                0,
                'cache ttl (' . $cacheKey . ')'
            ));

            $cachedContent .= "\n<!-- Powered by Redis, SERVED: " . date('Y-m-d H:i:s') .
                ', TTL: ' . $remaining . 's -->';

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

        // 缓存未命中，开始输出缓冲。
        // 同时给 $_COOKIE 拍一张快照，afterRender() 用它判断渲染期间是否动过
        // cookie（那意味着响应绑定到了当前访客，不能进公共缓存）。
        self::$cookiesAtStart = $_COOKIE;

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
        if (!$redis || !self::$schemaReady) {
            ob_end_flush();
            return;
        }

        $requestUri = self::resolveRequestPath();
        if ($requestUri === null) {
            ob_end_flush();
            return;
        }

        $origin = self::resolveCanonicalOrigin();
        if ($origin === null) {
            ob_end_flush();
            return;
        }

        // 与 beforeRender() 保持一致：归档类型在渲染过程中还可能被主题改写，
        // 因此这里必须重新判断一次，不能只依赖进入 beforeRender 时的结论。
        if (!self::isCacheableArchive($archive)) {
            ob_end_flush();
            return;
        }

        $config = Helper::options()->plugin(basename(__DIR__));

        // 与 beforeRender() 共用同一份 URI 规则判断
        if (!self::matchesUriRules($requestUri, $config)) {
            ob_end_flush();
            return;
        }

        // 响应级校验：渲染过程中主题或其他插件可能把响应变成非 200、跳转，
        // 或设置了与访客绑定的 cookie。这些都不能以 200 text/html 缓存下来。
        if (!self::isCacheableResponse()) {
            self::logPass('RESPONSE NOT CACHEABLE', $requestUri, $config);
            ob_end_flush();
            return;
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

        $cacheKey = self::makeCacheKey($origin, $requestUri, $archive);
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

        // 必须先输出、再写缓存。此时页面内容已经被 ob_get_clean() 从缓冲区
        // 取走，若先执行 setex() 且 Redis 在此刻抛出 RedisException（连接中途
        // 断开、内存满、只读副本等），访客拿到的就是一个白页或异常页 ——
        // 一个「可选加速层」不该有能力让正常页面渲染失败。
        echo $content;

        $stored = self::attempt(
            fn () => $redis->setex($cacheKey, $ttl, $content),
            false,
            'cache write (' . $cacheKey . ')'
        );

        if (!$stored) {
            return;
        }

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
     * 内容被删除时清除缓存（finishDelete 钩子传入 $cid, $widget）
     *
     * Typecho 删除文章/页面走 deletePost()/deletePage()，不经过 finishPublish。
     * 传入的第一个参数就是 cid 本身（来自 request 的 int 过滤），不是内容数组。
     *
     * @param int $cid 被删除内容的 ID
     * @param PostEdit|PageEdit|AttachmentEdit $widget 编辑组件
     * @return void
     * @throws PluginException
     */
    public static function clearCacheOnContentDelete(int $cid, PostEdit|PageEdit|AttachmentEdit $widget): void
    {
        self::purgeCache($cid, true, 'CONTENT DELETED' . ($cid > 0 ? ' CID ' . $cid : ''));

        // 附件被删掉之后，引用它的那篇文章 / 页面里的图片就成了死链，
        // 必须一并失效 —— 只清附件自己的页面是不够的。
        //
        // parent 读得到：Attachment\Edit::deleteByIds() 在触发本钩子之前已经用
        // [$this, 'push'] 把整行压进了 widget（同一段代码里的
        // Upload::deleteHandle($this->toColumn([... 'parent'])) 就在用它）。
        //
        // 第二次调用传 false：列表页缓存在上面那次已经清过了。
        if ($widget instanceof AttachmentEdit) {
            $parent = intval($widget->parent);

            if ($parent > 0 && $parent !== $cid) {
                self::purgeCache($parent, false, 'ATTACHMENT DELETED FROM CID ' . $parent);
            }
        }
    }

    /**
     * 内容状态变更时清除缓存（finishMark 钩子传入 $status, $cid, $widget）
     *
     * 覆盖后台的「公开 / 私密 / 隐藏 / 待审核」标记操作。转为非公开状态后若不清缓存，
     * 旧的公开版本会继续从 Redis 对外服务直到 TTL 到期。
     *
     * @param string $status 新状态
     * @param int $cid 内容 ID
     * @param PostEdit|PageEdit $widget 编辑组件
     * @return void
     * @throws PluginException
     */
    public static function clearCacheOnContentMark(string $status, int $cid, PostEdit|PageEdit $widget): void
    {
        self::purgeCache($cid, true, 'CONTENT MARKED ' . strtoupper($status) . ($cid > 0 ? ' CID ' . $cid : ''));
    }

    /**
     * 前台评论提交时清除缓存（finishComment 钩子仅传入 $this）
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
        self::purgeCommentCache(intval($widget->cid), 'NEW COMMENT');
    }

    /**
     * 后台标记评论状态时清除缓存（mark 钩子传入 $comment, $widget, $status）
     *
     * 「通过 / 待审核 / 垃圾」三个操作都走这里。评论没有 finishMark，
     * 而 mark 在 UPDATE 之前触发（var/Widget/Comments/Edit.php::mark），
     * 因此存在一个极窄的窗口：清完缓存、状态尚未落库时若恰好有前台请求，
     * 会用旧状态重新填充缓存。核心没有提供更靠后的钩子，接受这个窗口。
     *
     * @param array $comment 评论行
     * @param CommentsEdit $widget 评论编辑组件
     * @param string $status 目标状态
     * @return void
     * @throws PluginException
     */
    public static function clearCacheOnCommentMark(array $comment, CommentsEdit $widget, string $status): void
    {
        self::purgeCommentCacheDeferred(
            intval($comment['cid'] ?? 0),
            'COMMENT MARKED ' . strtoupper($status)
        );
    }

    /**
     * 前台引用（Trackback）时清除缓存（trackback 是 filter，必须原样返回）
     *
     * @param array $trackback 待插入的引用行，含 cid
     * @param mixed $content   被引用的内容
     * @return array
     * @throws PluginException
     */
    public static function clearCacheOnTrackback(array $trackback, $content): array
    {
        self::purgeCommentCacheDeferred(intval($trackback['cid'] ?? 0), 'NEW TRACKBACK');

        return $trackback;
    }

    /**
     * XML-RPC Pingback 时清除缓存（pingback 是 filter，必须原样返回）
     *
     * @param array $pingback 待插入的 pingback 行，含 cid
     * @param mixed $post     被 ping 的内容
     * @return array
     * @throws PluginException
     */
    public static function clearCacheOnPingback(array $pingback, $post): array
    {
        self::purgeCommentCacheDeferred(intval($pingback['cid'] ?? 0), 'NEW PINGBACK');

        return $pingback;
    }

    /**
     * 后台删除评论时清除缓存（finishDelete 钩子传入 $comment, $widget）
     *
     * @param array $comment 被删除的评论行
     * @param CommentsEdit $widget 评论编辑组件
     * @return void
     * @throws PluginException
     */
    public static function clearCacheOnCommentDelete(array $comment, CommentsEdit $widget): void
    {
        self::purgeCommentCache(intval($comment['cid'] ?? 0), 'COMMENT DELETED');
    }

    /**
     * 后台编辑评论、回复评论时清除缓存（finishEdit / finishComment 均仅传入 $this）
     *
     * 两个钩子触发前评论行都已经 push 进 widget，因此 $widget->cid 可用。
     * 注意这里的 finishComment 属于 Widget\Comments\Edit（后台回复），
     * 与 Widget\Feedback::finishComment（前台评论）是两个不同的类。
     *
     * @param CommentsEdit $widget 评论编辑组件
     * @return void
     * @throws PluginException
     */
    public static function clearCacheOnCommentTouch(CommentsEdit $widget): void
    {
        self::purgeCommentCache(intval($widget->cid), 'COMMENT EDITED OR REPLIED');
    }

    /**
     * 评论类操作的统一清理入口
     *
     * 是否连带清理列表页由配置项 clearListOnComment 决定：主题若不在列表页显示
     * 评论数，保留列表页缓存可以大幅缩小失效范围。
     *
     * @param int $cid 被评论内容的 ID
     * @param string $reason 写入日志的原因说明
     * @return void
     * @throws PluginException
     */
    private static function purgeCommentCache(int $cid, string $reason): void
    {
        $config     = Helper::options()->plugin(basename(__DIR__));
        $clearLists = !isset($config->clearListOnComment) || $config->clearListOnComment == '1';

        self::purgeCache(
            $cid,
            $clearLists,
            $reason . ($cid > 0 ? ' ON CID ' . $cid : ' (CID UNKNOWN)')
        );
    }

    /**
     * 「立即清理 + 请求收尾时再清一次」
     *
     * 用于那些在数据库写入**之前**触发的钩子：
     * - Widget\Comments\Edit::mark（通过 / 待审核 / 垃圾，见该文件 mark() 方法）
     * - Widget\Feedback 的 trackback filter
     * - Widget\XmlRpc 的 pingback filter
     *
     * 只清一次的话，「清完缓存」和「状态落库」之间存在一个窗口：期间若有前台
     * 请求进来，它读到的还是旧状态，于是把旧内容重新写回缓存。核心没有提供
     * 更靠后的钩子，但 register_shutdown_function 可以：请求收尾时数据库写入
     * 早已完成，此时再清一次即可关闭窗口（throwJson() 的 exit 不影响 shutdown 回调）。
     *
     * 批量操作会对每条评论各触发一次钩子，因此按 cid 去重，只注册一个回调。
     *
     * @param int    $cid
     * @param string $reason
     * @return void
     * @throws PluginException
     */
    private static function purgeCommentCacheDeferred(int $cid, string $reason): void
    {
        self::purgeCommentCache($cid, $reason);

        if (isset(self::$deferredPurges[$cid])) {
            return;
        }

        self::$deferredPurges[$cid] = true;

        register_shutdown_function(static function () use ($cid, $reason) {
            try {
                self::purgeCommentCache($cid, $reason . ' (DEFERRED)');
            } catch (Throwable $e) {
                // 请求已经结束，这里再抛异常没有任何意义
            }
        });
    }

    /**
     * 清空全部内容缓存（公开 API）
     *
     * 供主题或其他插件在自身配置变更后主动调用，例如主题轮换了第三方服务的
     * 站点密钥、切换了会影响所有页面的开关时：
     *
     *   if (class_exists('\TypechoPlugin\Accelerate\Plugin')) {
     *       \TypechoPlugin\Accelerate\Plugin::flushAll('RECAPTCHA KEY ROTATED');
     *   }
     *
     * 用 class_exists 保护即可，调用方不会因为未安装本插件而报错。
     *
     * @param string $reason 写入日志的原因说明
     * @return int|null 实际删除的键数量；Redis 不可用或清理失败时返回 null
     * @throws PluginException
     */
    public static function flushAll(string $reason = 'MANUAL FLUSH'): ?int
    {
        return self::purgeCache(0, true, $reason);
    }

    /**
     * 清理内容缓存
     *
     * @param int    $cid        内容 ID；大于 0 时只清理该内容的缓存，
     *                           小于等于 0 时作为兜底清理全部单篇缓存
     * @param bool   $clearLists 是否一并清理列表页缓存
     * @param string $reason     写入日志的原因说明
     * @return int|null 实际删除的键数量；Redis 不可用或任一段清理失败时返回 null
     * @throws PluginException
     */
    private static function purgeCache(int $cid, bool $clearLists, string $reason): ?int
    {
        // 传 true 忽略 enableCache 开关。用户关掉缓存之后，删除 / 隐藏 / 评论等
        // 失效操作原先完全拿不到连接，等他重新打开缓存，未过 TTL 的旧页面就会
        // 原样复活。清理动作和「是否启用缓存」本来就是两回事。
        $redis = self::initRedis(true);
        if (!$redis) {
            return null;
        }

        $scope    = $cid > 0 ? $cid . ':*' : '*';
        $patterns = [
            self::$prefix . 'post:' . $scope,
            self::$prefix . 'page:' . $scope,
        ];

        if ($clearLists) {
            $patterns[] = self::$prefix . 'list:*';
        }

        // 任何一段失败都整体报失败：这时候「删了几条」已经不可信了
        $deleted = 0;
        foreach ($patterns as $pattern) {
            $count = self::deleteByPattern($redis, $pattern);
            if ($count === null) {
                return null;
            }
            $deleted += $count;
        }

        if ($deleted <= 0) {
            return 0;
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

        return $deleted;
    }
}
