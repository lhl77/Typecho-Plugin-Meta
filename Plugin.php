<?php
/**
 * 自定义文章 SEO 元信息与 AI 生成（Title / Description / Keywords）
 *
 * @package TypechoMeta
 * @author LHL
 * @version 1.0.1
 * @link https://github.com/lhl77/Typecho-Plugin-Meta
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Action.php';

use Typecho\Plugin;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Layout;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Widget\Archive;
use Widget\Options;

class TypechoMeta_HtmlElement extends \Typecho\Widget\Helper\Form\Element
{
    private string $rawHtml;

    public function __construct(string $html)
    {
        $this->name = '__typechometa_html_' . (++self::$uniqueId);
        $this->rawHtml = $html;
    }

    public function input(?string $name = null, ?array $options = null): ?Layout
    {
        return null;
    }

    protected function inputValue($value): void
    {
    }

    public function render(): void
    {
        echo $this->rawHtml;
    }
}

class TypechoMeta_Plugin implements PluginInterface
{
    private const DEFAULT_PROMPT = "你是一名资深 SEO 编辑。请根据给定文章标题和正文，生成 JSON，且仅输出 JSON。\n"
        . "输出格式：{\"description\":\"...\",\"keywords\":[\"k1\",\"k2\"]}\n"
        . "要求：\n"
        . "1) description 为中文，通顺，避免夸张，适合搜索摘要；\n"
        . "2) keywords 返回 5~10 个关键词，避免重复；\n"
        . "3) 不要输出 markdown，不要解释。\n\n"
        . "标题：{{title}}\n"
        . "正文：{{content}}";

    public static function activate()
    {
        self::ensureActionRegistered();

        // 对齐 AB-Store：在常用入口周期性补写 action，避免历史激活失败后路由丢失。
        Plugin::factory('admin/footer.php')->begin = array(
            'TypechoMeta_Plugin',
            'ensureActionRegistered'
        );
        Plugin::factory('index.php')->begin = array(
            'TypechoMeta_Plugin',
            'ensureActionRegistered'
        );

        Plugin::factory('Widget\\Contents\\Post\\Edit')->getDefaultFieldItems = array(
            'TypechoMeta_Plugin',
            'registerPostMetaFields'
        );
        Plugin::factory('Widget\\Contents\\Page\\Edit')->getDefaultFieldItems = array(
            'TypechoMeta_Plugin',
            'registerPostMetaFields'
        );
        Plugin::factory('Widget\\Archive')->headerOptions = array(
            'TypechoMeta_Plugin',
            'applyArchiveMeta'
        );
        Plugin::factory('Widget\\Archive')->header = array(
            'TypechoMeta_Plugin',
            'renderSeoEnhancements'
        );
        Plugin::factory('admin/write-post.php')->bottom = array(
            'TypechoMeta_Plugin',
            'renderEditorAiButton'
        );
        Plugin::factory('admin/write-page.php')->bottom = array(
            'TypechoMeta_Plugin',
            'renderEditorAiButton'
        );

    }

    public static function deactivate()
    {
        \Utils\Helper::removeAction('typecho-meta');
        \Utils\Helper::removeAction('typechometa');
    }

    public static function config(Form $form)
    {
        self::ensureActionRegistered();
        self::sanitizeLegacyConfigKeys();

        echo '<div style="margin:0 0 14px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc;line-height:1.7;">'
            . '<strong>TypechoMeta</strong>：文章 SEO 元信息与 AI 生成增强插件。<br>'
            . 'GitHub：<a href="https://github.com/lhl77/Typecho-Plugin-Meta" target="_blank" rel="noopener">https://github.com/lhl77/Typecho-Plugin-Meta</a><br>'
            . '作者博客：<a href="https://blog.lhl.one" target="_blank" rel="noopener">https://blog.lhl.one</a>'
            . '</div>';

        $provider = new Select(
            'aiProvider',
            array(
                'openai_compatible' => _t('OpenAI 兼容接口（推荐，适配 OpenAI/DeepSeek/Qwen/豆包/Grok 等）'),
                'gemini' => _t('Google Gemini 原生接口'),
                'anthropic' => _t('Anthropic Claude 原生接口')
            ),
            'openai_compatible',
            _t('AI 提供方'),
            _t('若服务商提供 OpenAI 兼容接口，优先选择第一项。')
        );
        $form->addInput($provider);

        $apiUrl = new Text(
            'aiApiUrl',
            null,
            'https://api.openai.com/v1/chat/completions',
            _t('AI 接口地址'),
            _t('完整接口地址。OpenAI 兼容通常是 /v1/chat/completions。Gemini/Claude 请填各自 messages 接口。')
        );
        $form->addInput($apiUrl->addRule('required', _t('AI 接口地址不能为空')));

        $model = new Text('aiModel', null, 'gpt-4o-mini', _t('AI 模型名'), _t('例如 gpt-4o-mini、deepseek-chat、qwen-plus 等。'));
        $form->addInput($model->addRule('required', _t('AI 模型名不能为空')));

        $token = new Text('aiToken', null, '', _t('AI Token / API Key'), _t('仅用于服务端调用，不会输出到前台。'));
        $form->addInput($token);

        $temperature = new Text('aiTemperature', null, '0.2', _t('AI 温度'), _t('0~2，建议 0.1~0.5 提高稳定性。'));
        $form->addInput($temperature);

        $timeout = new Text('aiTimeout', null, '30', _t('请求超时（秒）'), _t('调用 AI 接口的最大等待秒数。'));
        $form->addInput($timeout->addRule('isInteger', _t('超时必须是整数')));

        $contentLimit = new Text('aiContentLimit', null, '3000', _t('正文截取字数'), _t('调用 AI 前最多截取多少字符，避免超长导致费用和超时增加。'));
        $form->addInput($contentLimit->addRule('isInteger', _t('正文截取字数必须是整数')));

        $descMaxLen = new Text('aiDescriptionMaxLen', null, '160', _t('Description 最大长度'), _t('AI 结果会截断到该长度以内。'));
        $form->addInput($descMaxLen->addRule('isInteger', _t('Description 最大长度必须是整数')));

        $keywordsMax = new Text('aiKeywordsMaxCount', null, '8', _t('Keywords 最大数量'), _t('AI 返回关键词会截断到该数量。'));
        $form->addInput($keywordsMax->addRule('isInteger', _t('Keywords 最大数量必须是整数')));

        $prompt = new Textarea('aiPromptTemplate', null, self::DEFAULT_PROMPT, _t('AI 提示词模板'), _t('支持占位符 {{title}} 与 {{content}}。'));
        $prompt->input->setAttribute('rows', '9');
        $form->addInput($prompt->addRule('required', _t('提示词不能为空')));

        $seoAdvanced = new Checkbox(
            'enableAdvancedSeo',
            array('1' => _t('启用文章页高级 SEO 标签输出（canonical / robots / og / twitter / json-ld）')),
            array('1'),
            _t('高级 SEO'),
            _t('仅增强文章页输出，不会自动调用 AI。')
        );
        $form->addInput($seoAdvanced);

        $seoCanonical = new Checkbox('seoCanonical', array('1' => _t('输出 canonical')), array('1'), _t('Canonical'), _t('建议开启')); 
        $form->addInput($seoCanonical);

        $seoRobots = new Checkbox('seoRobots', array('1' => _t('输出 robots')), array('1'), _t('Robots'), _t('建议开启')); 
        $form->addInput($seoRobots);

        $seoOg = new Checkbox('seoOg', array('1' => _t('输出 Open Graph')), array('1'), _t('Open Graph'), _t('社交分享优化')); 
        $form->addInput($seoOg);

        $seoTwitter = new Checkbox('seoTwitter', array('1' => _t('输出 Twitter Card')), array('1'), _t('Twitter Card'), _t('社交分享优化')); 
        $form->addInput($seoTwitter);

        $seoJsonLd = new Checkbox('seoJsonLd', array('1' => _t('输出 Article JSON-LD')), array('1'), _t('Schema'), _t('结构化数据')); 
        $form->addInput($seoJsonLd);

        $strictDedup = new Checkbox(
            'seoStrictDedupMode',
            array('1' => _t('启用去重优先模式（推荐，避免与主题重复输出 canonical / og / twitter）')),
            array('1'),
            _t('去重优先模式'),
            _t('开启后插件将不再输出 canonical / Open Graph / Twitter 标签，仅保留必要的 robots 与 JSON-LD。')
        );
        $form->addInput($strictDedup);

        $robotsText = new Text(
            'seoRobotsValue',
            null,
            'index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1',
            _t('Robots 内容'),
            _t('例如：index,follow')
        );
        $form->addInput($robotsText);

        $homeTitle = new Text(
            'homeMetaTitle',
            null,
            '',
            _t('首页 Meta Title'),
            _t('博客首页 SEO 标题，留空则使用站点默认标题')
        );
        $form->addInput($homeTitle);

        $homeDescription = new Textarea(
            'homeMetaDescription',
            null,
            '',
            _t('首页 Meta Description'),
            _t('博客首页 SEO 描述，留空则使用系统默认描述')
        );
        $homeDescription->input->setAttribute('rows', '3');
        $form->addInput($homeDescription);

        $homeKeywords = new Textarea(
            'homeMetaKeywords',
            null,
            '',
            _t('首页 Meta Keywords'),
            _t('博客首页关键词，建议使用英文逗号分隔')
        );
        $homeKeywords->input->setAttribute('rows', '2');
        $form->addInput($homeKeywords);

        // 兼容历史配置键：为旧键补 hidden input，避免 Typecho 配置回填时触发 value() on null。
        self::ensureLegacyInputs($form);

        self::appendAiTester($form);
    }

    private static function sanitizeLegacyConfigKeys(): void
    {
        try {
            $settings = self::getPluginSettings();
            if (!is_object($settings) || !method_exists($settings, 'toArray')) {
                return;
            }

            $current = $settings->toArray();
            if (!is_array($current) || empty($current)) {
                return;
            }

            $allowed = self::allowedConfigKeys();

            $allowedMap = array_fill_keys($allowed, true);
            $filtered = array();
            $hasLegacyKey = false;

            foreach ($current as $key => $value) {
                if (isset($allowedMap[$key])) {
                    $filtered[$key] = $value;
                } else {
                    $hasLegacyKey = true;
                }
            }

            if ($hasLegacyKey) {
                \Utils\Helper::configPlugin('TypechoMeta', $filtered);
            }
        } catch (\Throwable $e) {
            // 忽略清理异常，避免影响配置页打开。
        }
    }

    private static function ensureLegacyInputs(Form $form): void
    {
        try {
            $settings = self::getPluginSettings();
            if (!is_object($settings) || !method_exists($settings, 'toArray')) {
                return;
            }

            $current = $settings->toArray();
            if (!is_array($current) || empty($current)) {
                return;
            }

            $allowedMap = array_fill_keys(self::allowedConfigKeys(), true);

            foreach ($current as $key => $value) {
                $name = (string) $key;
                if ($name === '' || isset($allowedMap[$name])) {
                    continue;
                }

                // 防止意外键名污染表单结构，仅保留安全字符。
                if (!preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
                    continue;
                }

                if ($form->getInput($name) !== null) {
                    continue;
                }

                if (is_array($value)) {
                    $value = implode(',', array_map('strval', $value));
                } elseif (is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $hidden = new Hidden($name, null, (string) $value);
                $form->addInput($hidden);
            }
        } catch (\Throwable $e) {
            // 忽略兜底异常，不影响主配置渲染。
        }
    }

    private static function allowedConfigKeys(): array
    {
        return array(
            'aiProvider',
            'aiApiUrl',
            'aiModel',
            'aiToken',
            'aiTemperature',
            'aiTimeout',
            'aiContentLimit',
            'aiDescriptionMaxLen',
            'aiKeywordsMaxCount',
            'aiPromptTemplate',
            'enableAdvancedSeo',
            'seoCanonical',
            'seoRobots',
            'seoOg',
            'seoTwitter',
            'seoJsonLd',
            'seoStrictDedupMode',
            'seoRobotsValue',
            'homeMetaTitle',
            'homeMetaDescription',
            'homeMetaKeywords'
        );
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function registerPostMetaFields(Layout $layout)
    {
        $metaTitle = new Text(
            'metaTitle',
            null,
            '',
            _t('Meta Title'),
            _t('自定义该文章的标题（留空则使用默认文章标题）')
        );
        $layout->addItem($metaTitle);

        $metaDescription = new Textarea(
            'metaDescription',
            null,
            '',
            _t('Meta Description'),
            _t('自定义该文章的描述（留空则使用默认描述）')
        );
        $metaDescription->input->setAttribute('rows', '3');
        $layout->addItem($metaDescription);

        $metaKeywords = new Textarea(
            'metaKeywords',
            null,
            '',
            _t('Meta Keywords'),
            _t('自定义该文章的关键词，建议用英文逗号分隔（留空则使用默认关键词）')
        );
        $metaKeywords->input->setAttribute('rows', '2');
        $layout->addItem($metaKeywords);
    }

    public static function applyArchiveMeta($allows, $archive)
    {
        $settings = self::getPluginSettings();
        $strictDedupMode = self::isChecked($settings->seoStrictDedupMode ?? array('1'));

        // 去重优先模式：关闭 Typecho 内核默认 social 输出（og/twitter），避免与主题重复。
        if ($strictDedupMode) {
            $allows['social'] = 0;
        }

        if ($archive->is('index')) {
            $homeTitle = trim((string) ($settings->homeMetaTitle ?? ''));
            $homeDescription = trim((string) ($settings->homeMetaDescription ?? ''));
            $homeKeywords = trim((string) ($settings->homeMetaKeywords ?? ''));

            if ($homeTitle !== '' && method_exists($archive, 'setArchiveTitle')) {
                $archive->setArchiveTitle($homeTitle);
            }

            if ($homeDescription !== '') {
                $allows['description'] = htmlspecialchars($homeDescription, ENT_QUOTES, 'UTF-8');
                if (method_exists($archive, 'setArchiveDescription')) {
                    $archive->setArchiveDescription($homeDescription);
                }
            }

            if ($homeKeywords !== '') {
                $allows['keywords'] = htmlspecialchars($homeKeywords, ENT_QUOTES, 'UTF-8');
                if (method_exists($archive, 'setArchiveKeywords')) {
                    $archive->setArchiveKeywords($homeKeywords);
                }
            }

            return $allows;
        }

        if (!$archive->is('single')) {
            return $allows;
        }

        $metaTitle = self::getFieldValue($archive, 'metaTitle');
        $metaDescription = self::getFieldValue($archive, 'metaDescription');
        $metaKeywords = self::getFieldValue($archive, 'metaKeywords');

        if ('' !== $metaTitle && method_exists($archive, 'setArchiveTitle')) {
            $archive->setArchiveTitle($metaTitle);
        }

        if ('' !== $metaDescription) {
            $allows['description'] = htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8');
            if (method_exists($archive, 'setArchiveDescription')) {
                $archive->setArchiveDescription($metaDescription);
            }
        }

        if ('' !== $metaKeywords) {
            $allows['keywords'] = htmlspecialchars($metaKeywords, ENT_QUOTES, 'UTF-8');
            if (method_exists($archive, 'setArchiveKeywords')) {
                $archive->setArchiveKeywords($metaKeywords);
            }
        }

        return $allows;
    }

    public static function renderSeoEnhancements($header, $archive): void
    {
        $settings = self::getPluginSettings();
        if (!self::isChecked($settings->enableAdvancedSeo ?? array('1'))) {
            return;
        }
        $strictDedupMode = self::isChecked($settings->seoStrictDedupMode ?? array('1'));

        $head = is_string($header) ? $header : '';

        if ($archive->is('index')) {
            $siteUrl = rtrim((string) ($archive->options->siteUrl ?? ''), '/');
            $url = $siteUrl !== '' ? $siteUrl . '/' : htmlspecialchars((string) $archive->options->index, ENT_QUOTES, 'UTF-8');

            $siteName = trim((string) ($archive->options->title ?? ''));
            if ($siteName === '') {
                $siteName = (string) parse_url((string) $url, PHP_URL_HOST);
            }

            $homeTitle = trim((string) ($settings->homeMetaTitle ?? ''));
            $homeDescription = trim((string) ($settings->homeMetaDescription ?? ''));
            $homeKeywords = trim((string) ($settings->homeMetaKeywords ?? ''));
            $title = $homeTitle !== '' ? $homeTitle : $siteName;
            $description = $homeDescription !== ''
                ? $homeDescription
                : trim((string) ($archive->options->description ?? ''));

            $chunks = array();

            if (self::isChecked($settings->seoCanonical ?? array('1')) && !self::headHasCanonical($head)) {
                $tag = '<link rel="canonical" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" />';
                $chunks[] = $tag;
                $head .= $tag;
            }

            if (self::isChecked($settings->seoRobots ?? array('1')) && !self::headHasMetaName($head, 'robots')) {
                $robots = trim((string) ($settings->seoRobotsValue ?? 'index,follow'));
                if ($robots !== '') {
                    $tag = '<meta name="robots" content="' . htmlspecialchars($robots, ENT_QUOTES, 'UTF-8') . '" />';
                    $chunks[] = $tag;
                    $head .= $tag;
                }
            }

            if (!$strictDedupMode && $homeKeywords !== '' && !self::headHasMetaName($head, 'keywords')) {
                $tag = '<meta name="keywords" content="' . htmlspecialchars($homeKeywords, ENT_QUOTES, 'UTF-8') . '" />';
                $chunks[] = $tag;
                $head .= $tag;
            }

            if (!$strictDedupMode && $description !== '' && !self::headHasMetaName($head, 'description')) {
                $tag = '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '" />';
                $chunks[] = $tag;
                $head .= $tag;
            }

            if (!$strictDedupMode && self::isChecked($settings->seoOg ?? array('1'))) {
                if (!self::headHasMetaProperty($head, 'og:type')) {
                    $tag = '<meta property="og:type" content="website" />';
                    $chunks[] = $tag;
                    $head .= $tag;
                }
                if (!self::headHasMetaProperty($head, 'og:url')) {
                    $tag = '<meta property="og:url" content="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" />';
                    $chunks[] = $tag;
                    $head .= $tag;
                }
                if ($siteName !== '' && !self::headHasMetaProperty($head, 'og:site_name')) {
                    $tag = '<meta property="og:site_name" content="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '" />';
                    $chunks[] = $tag;
                    $head .= $tag;
                }
                if ($title !== '' && !self::headHasMetaProperty($head, 'og:title')) {
                    $tag = '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" />';
                    $chunks[] = $tag;
                    $head .= $tag;
                }
                if ($description !== '' && !self::headHasMetaProperty($head, 'og:description')) {
                    $tag = '<meta property="og:description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '" />';
                    $chunks[] = $tag;
                    $head .= $tag;
                }
            }

            if (!$strictDedupMode && self::isChecked($settings->seoTwitter ?? array('1'))) {
                if (!self::headHasMetaName($head, 'twitter:card')) {
                    $tag = '<meta name="twitter:card" content="summary" />';
                    $chunks[] = $tag;
                    $head .= $tag;
                }
                if ($title !== '' && !self::headHasMetaName($head, 'twitter:title')) {
                    $tag = '<meta name="twitter:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" />';
                    $chunks[] = $tag;
                    $head .= $tag;
                }
                if ($description !== '' && !self::headHasMetaName($head, 'twitter:description')) {
                    $tag = '<meta name="twitter:description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '" />';
                    $chunks[] = $tag;
                    $head .= $tag;
                }
            }

            if (self::isChecked($settings->seoJsonLd ?? array('1')) && !self::headHasJsonLdType($head, 'WebSite')) {
                $jsonLd = array(
                    '@context' => 'https://schema.org',
                    '@type' => 'WebSite',
                    'name' => $siteName,
                    'url' => $url,
                    'description' => $description
                );
                $chunks[] = '<script type="application/ld+json">'
                    . json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . '</script>';
            }

            if (!empty($chunks)) {
                echo implode("\n", $chunks) . "\n";
            }

            return;
        }

        if (!$archive->is('single')) {
            return;
        }

        $title = self::resolveTitle($archive);
        $description = self::resolveDescription($archive);
        $keywords = self::resolveKeywords($archive);
        $url = htmlspecialchars((string) $archive->permalink, ENT_QUOTES, 'UTF-8');
        $siteNameRaw = trim((string) ($archive->options->title ?? ''));
        if ($siteNameRaw === '') {
            $siteNameRaw = (string) parse_url((string) ($archive->options->siteUrl ?? ''), PHP_URL_HOST);
        }
        if ($siteNameRaw === '') {
            $siteNameRaw = (string) ($_SERVER['HTTP_HOST'] ?? 'blog.lhl.one');
        }
        $siteName = htmlspecialchars($siteNameRaw, ENT_QUOTES, 'UTF-8');

        $chunks = array();

        if (!$strictDedupMode && self::isChecked($settings->seoCanonical ?? array('1')) && !self::headHasCanonical($head)) {
            $tag = '<link rel="canonical" href="' . $url . '" />';
            $chunks[] = $tag;
            $head .= $tag;
        }

        if (self::isChecked($settings->seoRobots ?? array('1')) && !self::headHasMetaName($head, 'robots')) {
            $robots = trim((string) ($settings->seoRobotsValue ?? 'index,follow'));
            if ($robots !== '') {
                $tag = '<meta name="robots" content="' . htmlspecialchars($robots, ENT_QUOTES, 'UTF-8') . '" />';
                $chunks[] = $tag;
                $head .= $tag;
            }
        }

        if (!$strictDedupMode && self::isChecked($settings->seoOg ?? array('1'))) {
            if (!self::headHasMetaProperty($head, 'og:type')) {
                $tag = '<meta property="og:type" content="article" />';
                $chunks[] = $tag;
                $head .= $tag;
            }
            if (!self::headHasMetaProperty($head, 'og:url')) {
                $tag = '<meta property="og:url" content="' . $url . '" />';
                $chunks[] = $tag;
                $head .= $tag;
            }
            if ($siteName !== '' && !self::headHasMetaProperty($head, 'og:site_name')) {
                $tag = '<meta property="og:site_name" content="' . $siteName . '" />';
                $chunks[] = $tag;
                $head .= $tag;
            }
            if ($title !== '' && !self::headHasMetaProperty($head, 'og:title')) {
                $tag = '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" />';
                $chunks[] = $tag;
                $head .= $tag;
            }
            if ($description !== '' && !self::headHasMetaProperty($head, 'og:description')) {
                $tag = '<meta property="og:description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '" />';
                $chunks[] = $tag;
                $head .= $tag;
            }
        }

        if (!$strictDedupMode && self::isChecked($settings->seoTwitter ?? array('1'))) {
            if (!self::headHasMetaName($head, 'twitter:card')) {
                $tag = '<meta name="twitter:card" content="summary" />';
                $chunks[] = $tag;
                $head .= $tag;
            }
            if ($title !== '' && !self::headHasMetaName($head, 'twitter:title')) {
                $tag = '<meta name="twitter:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" />';
                $chunks[] = $tag;
                $head .= $tag;
            }
            if ($description !== '' && !self::headHasMetaName($head, 'twitter:description')) {
                $tag = '<meta name="twitter:description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '" />';
                $chunks[] = $tag;
                $head .= $tag;
            }
        }

        if (self::isChecked($settings->seoJsonLd ?? array('1')) && !self::headHasJsonLdType($head, 'Article')) {
            $jsonLd = array(
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $title,
                'description' => $description,
                'keywords' => $keywords,
                'mainEntityOfPage' => $archive->permalink,
                'datePublished' => date('c', (int) $archive->created),
                'dateModified' => date('c', (int) $archive->modified),
                'author' => array(
                    '@type' => 'Person',
                    'name' => (string) $archive->author->screenName
                ),
                'publisher' => array(
                    '@type' => 'Organization',
                    'name' => $siteNameRaw
                )
            );
            $chunks[] = '<script type="application/ld+json">'
                . json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . '</script>';
        }

        if (!empty($chunks)) {
            echo implode("\n", $chunks) . "\n";
        }
    }

    private static function headHasCanonical(string $head): bool
    {
        return preg_match('/<link\b[^>]*\brel\s*=\s*["\']canonical["\'][^>]*>/i', $head) === 1;
    }

    private static function headHasMetaName(string $head, string $name): bool
    {
        $name = preg_quote($name, '/');
        return preg_match('/<meta\b[^>]*\bname\s*=\s*["\']' . $name . '["\'][^>]*>/i', $head) === 1;
    }

    private static function headHasMetaProperty(string $head, string $property): bool
    {
        $property = preg_quote($property, '/');
        return preg_match('/<meta\b[^>]*\bproperty\s*=\s*["\']' . $property . '["\'][^>]*>/i', $head) === 1;
    }

    private static function headHasJsonLdType(string $head, string $schemaType): bool
    {
        if (preg_match('/<script\b[^>]*type\s*=\s*["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $head, $m) !== 1) {
            return false;
        }

        $schemaType = preg_quote($schemaType, '/');
        return preg_match('/"@type"\s*:\s*"' . $schemaType . '"/i', $m[1]) === 1;
    }

    public static function renderEditorAiButton($post): void
    {
        $actionUrl = htmlspecialchars(self::getActionUrl(), ENT_QUOTES, 'UTF-8');
                $actionToken = htmlspecialchars(self::getActionToken($actionUrl), ENT_QUOTES, 'UTF-8');
        echo '<script>
(function(){
  function ensureBtn(){
    var desc = document.querySelector("textarea[name=\"fields[metaDescription]\"]");
    var keys = document.querySelector("textarea[name=\"fields[metaKeywords]\"]");
    if(!desc || !keys){ return; }
    if(document.getElementById("typechometa-ai-btn")){ return; }

    var wrap = document.createElement("div");
    wrap.id = "typechometa-ai-wrap";
    wrap.style.marginTop = "8px";

    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "btn btn-xs";
    btn.id = "typechometa-ai-btn";
    btn.textContent = "AI生成 Description / Keywords";

    var status = document.createElement("span");
    status.id = "typechometa-ai-status";
    status.style.marginLeft = "8px";
    status.style.color = "#666";

    btn.addEventListener("click", function(){
      var titleEl = document.getElementById("title");
      var textEl = document.getElementById("text");
            var token = "' . $actionToken . '";
      var title = titleEl ? titleEl.value : "";
      var content = textEl ? textEl.value : "";
      if(!content || content.trim() === ""){
        status.textContent = "正文为空，无法生成";
        return;
      }
      btn.disabled = true;
      status.textContent = "AI 正在生成...";

            var payload = {
                do: "generate",
                source: "editor",
                title: title,
                content: content
            };
            if(token){ payload._ = token; }

      fetch("' . $actionUrl . '", {
        method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: JSON.stringify(payload),
        credentials: "same-origin"
            }).then(function(res){
                return res.text().then(function(text){
                    var data = null;
                    try { data = JSON.parse(text); } catch(e) {
                        var preview = text ? text.slice(0, 120).replace(/\s+/g, " ") : "";
                        throw new Error("接口返回非 JSON（HTTP " + res.status + "）：" + preview);
                    }
                    return data;
                });
            }).then(function(res){
        if(!res || !res.success){
          throw new Error((res && res.message) ? res.message : "生成失败");
        }
        if(res.data && typeof res.data.description === "string"){
          desc.value = res.data.description;
        }
        if(res.data && typeof res.data.keywords === "string"){
          keys.value = res.data.keywords;
        }
        status.textContent = "生成完成，请手动保存文章";
      }).catch(function(err){
        status.textContent = "失败：" + err.message;
      }).finally(function(){
        btn.disabled = false;
      });
    });

    wrap.appendChild(btn);
    wrap.appendChild(status);
    keys.parentNode.appendChild(wrap);
  }
  if(document.readyState === "loading"){
    document.addEventListener("DOMContentLoaded", ensureBtn);
  }else{
    ensureBtn();
  }
})();
</script>';
    }

    public static function generateSeoByAi(string $title, string $content): array
    {
        $settings = self::getPluginSettings();
        $provider = trim((string) ($settings->aiProvider ?? 'openai_compatible'));
        $apiUrl = trim((string) ($settings->aiApiUrl ?? ''));
        $model = trim((string) ($settings->aiModel ?? ''));
        $token = trim((string) ($settings->aiToken ?? ''));

        if ($apiUrl === '' || $model === '' || $token === '') {
            throw new \RuntimeException('请先在插件设置中填写 AI 接口地址、模型名和 Token');
        }

        $limit = max(200, (int) ($settings->aiContentLimit ?? 3000));
        $descMaxLen = max(60, (int) ($settings->aiDescriptionMaxLen ?? 160));
        $keywordsMax = max(3, (int) ($settings->aiKeywordsMaxCount ?? 8));
        $timeout = max(5, (int) ($settings->aiTimeout ?? 30));
        $temperature = (float) ($settings->aiTemperature ?? 0.2);

        $cleanTitle = trim(strip_tags($title));
        $cleanContent = trim(preg_replace('/\s+/u', ' ', strip_tags($content)));
        $cleanContent = mb_substr($cleanContent, 0, $limit);

        $prompt = (string) ($settings->aiPromptTemplate ?? self::DEFAULT_PROMPT);
        if (trim($prompt) === '') {
            $prompt = self::DEFAULT_PROMPT;
        }
        $prompt = str_replace(array('{{title}}', '{{content}}'), array($cleanTitle, $cleanContent), $prompt);

        $raw = self::requestAi($provider, $apiUrl, $model, $token, $prompt, $temperature, $timeout);
        $parsed = self::parseAiResult($raw, $descMaxLen, $keywordsMax);

        if ($parsed['description'] === '' || $parsed['keywords'] === '') {
            throw new \RuntimeException('AI 返回结果格式不符合要求，请检查提示词或模型输出。');
        }

        return array(
            'description' => $parsed['description'],
            'keywords' => $parsed['keywords'],
            'raw' => $raw
        );
    }

    private static function parseAiResult(string $raw, int $descMaxLen, int $keywordsMax): array
    {
        $candidate = trim($raw);
        if (preg_match('/\{[\s\S]*\}/u', $candidate, $m)) {
            $candidate = $m[0];
        }

        $data = json_decode($candidate, true);
        if (!is_array($data)) {
            return array('description' => '', 'keywords' => '');
        }

        $description = '';
        if (isset($data['description'])) {
            $description = (string) $data['description'];
        } elseif (isset($data['meta_description'])) {
            $description = (string) $data['meta_description'];
        }

        $keywords = array();
        if (isset($data['keywords']) && is_array($data['keywords'])) {
            $keywords = $data['keywords'];
        } elseif (isset($data['keywords'])) {
            $keywords = preg_split('/[,，\n]+/u', (string) $data['keywords']);
        } elseif (isset($data['keyword'])) {
            $keywords = preg_split('/[,，\n]+/u', (string) $data['keyword']);
        }

        $description = trim(preg_replace('/\s+/u', ' ', $description));
        if (mb_strlen($description) > $descMaxLen) {
            $description = mb_substr($description, 0, $descMaxLen);
        }

        $cleanKeywords = array();
        foreach ($keywords as $item) {
            $k = trim((string) $item);
            if ($k !== '' && !in_array($k, $cleanKeywords, true)) {
                $cleanKeywords[] = $k;
            }
            if (count($cleanKeywords) >= $keywordsMax) {
                break;
            }
        }

        return array(
            'description' => $description,
            'keywords' => implode(', ', $cleanKeywords)
        );
    }

    private static function requestAi(
        string $provider,
        string $apiUrl,
        string $model,
        string $token,
        string $prompt,
        float $temperature,
        int $timeout
    ): string {
        if ($provider === 'gemini') {
            $url = $apiUrl;
            if (strpos($url, 'key=') === false) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'key=' . rawurlencode($token);
            }
            $payload = array(
                'contents' => array(
                    array(
                        'role' => 'user',
                        'parts' => array(array('text' => $prompt))
                    )
                ),
                'generationConfig' => array(
                    'temperature' => $temperature
                )
            );

            $result = self::httpJson('POST', $url, array('Content-Type: application/json'), $payload, $timeout);
            $text = $result['json']['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return trim((string) $text);
        }

        if ($provider === 'anthropic') {
            $payload = array(
                'model' => $model,
                'max_tokens' => 1024,
                'temperature' => $temperature,
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt)
                )
            );
            $headers = array(
                'Content-Type: application/json',
                'x-api-key: ' . $token,
                'anthropic-version: 2023-06-01'
            );
            $result = self::httpJson('POST', $apiUrl, $headers, $payload, $timeout);
            $text = $result['json']['content'][0]['text'] ?? '';
            return trim((string) $text);
        }

        $payload = array(
            'model' => $model,
            'messages' => array(
                array('role' => 'system', 'content' => '你是 SEO 助手，请严格按要求输出。'),
                array('role' => 'user', 'content' => $prompt)
            ),
            'temperature' => $temperature
        );
        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        );
        $result = self::httpJson('POST', $apiUrl, $headers, $payload, $timeout);
        $text = $result['json']['choices'][0]['message']['content'] ?? '';
        return trim((string) $text);
    }

    private static function httpJson(string $method, string $url, array $headers, array $payload, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(3, $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException('AI 请求失败：' . $error . ' (#' . $errno . ')');
        }

        $json = json_decode((string) $response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = is_array($json) ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $response;
            throw new \RuntimeException('AI 接口返回异常（HTTP ' . $httpCode . '）：' . $msg);
        }

        if (!is_array($json)) {
            throw new \RuntimeException('AI 接口返回非 JSON 数据');
        }

        return array('json' => $json);
    }

    private static function appendAiTester(Form $form): void
    {
        $actionUrl = htmlspecialchars(self::getActionUrl(), ENT_QUOTES, 'UTF-8');
        $actionToken = htmlspecialchars(self::getActionToken($actionUrl), ENT_QUOTES, 'UTF-8');
        $html = '<section style="margin-top:16px;padding:12px;border:1px solid #ddd;border-radius:6px;">'
            . '<h4 style="margin:0 0 10px;">AI 生成测试（手动触发）</h4>'
            . '<p style="margin:0 0 8px;color:#666;">保存本页配置后，输入标题与正文片段，点击按钮生成 description 与 keywords。</p>'
            . '<p><input type="text" id="typechometa-test-title" class="text" style="width:100%;" placeholder="测试标题"></p>'
            . '<p><textarea id="typechometa-test-content" class="text" style="width:100%;min-height:120px;" placeholder="测试正文"></textarea></p>'
            . '<p><button type="button" class="btn" id="typechometa-test-generate">调用 AI 生成</button> <span id="typechometa-test-status" style="margin-left:8px;color:#666;"></span></p>'
            . '<p><label>description</label><textarea id="typechometa-test-desc" class="text" style="width:100%;min-height:80px;"></textarea></p>'
            . '<p><label>keywords</label><textarea id="typechometa-test-keys" class="text" style="width:100%;min-height:60px;"></textarea></p>'
            . '</section>'
            . '<script>(function(){'
            . 'function bind(){'
            . 'var btn=document.getElementById("typechometa-test-generate"); if(!btn||btn.dataset.binded==="1"){return;} btn.dataset.binded="1";'
            . 'var status=document.getElementById("typechometa-test-status");'
            . 'btn.addEventListener("click",function(){'
            . 'var token="' . $actionToken . '";'
            . 'var title=(document.getElementById("typechometa-test-title")||{}).value||"";'
            . 'var content=(document.getElementById("typechometa-test-content")||{}).value||"";'
            . 'if(!content.trim()){status.textContent="请先填写正文";return;}'
            . 'btn.disabled=true;status.textContent="AI 生成中...";'
            . 'var payload={do:"generate",source:"config",title:title,content:content};if(token){payload._=token;}'
            . 'fetch("' . $actionUrl . '",{method:"POST",headers:{"Content-Type":"application/json","X-Requested-With":"XMLHttpRequest"},credentials:"same-origin",body:JSON.stringify(payload)})'
            . '.then(function(res){return res.text().then(function(text){var data=null;try{data=JSON.parse(text);}catch(e){var preview=text?text.slice(0,120).replace(/\s+/g," "):"";throw new Error("接口返回非 JSON（HTTP "+res.status+"）："+preview);}return data;});})'
            . '.then(function(res){if(!res||!res.success){throw new Error((res&&res.message)?res.message:"生成失败");}'
            . 'document.getElementById("typechometa-test-desc").value=(res.data&&res.data.description)?res.data.description:"";'
            . 'document.getElementById("typechometa-test-keys").value=(res.data&&res.data.keywords)?res.data.keywords:"";'
            . 'status.textContent="完成";' 
            . '})'
            . '.catch(function(err){status.textContent="失败："+err.message;})'
            . '.finally(function(){btn.disabled=false;});'
            . '});'
            . '}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",bind);}else{bind();}'
            . '})();</script>';

        $form->addInput(new TypechoMeta_HtmlElement($html));
    }

    private static function getPluginSettings()
    {
        return Options::alloc()->plugin('TypechoMeta');
    }

    /**
     * 获取 action URL
     * Typecho action 路由格式：/index.php/action/[actionName]
     */
    public static function getActionUrl(): string
    {
        try {
            self::ensureActionRegistered();

            $options = Options::alloc();
            // 使用 Common::url 构造正确的 URL（index() 方法会 echo 而不是 return）
            $url = \Typecho\Common::url('/action/typechometa', $options->index);
            if (!empty($url)) {
                return $url;
            }
            
            // 降级：手动构造
            $siteUrl = rtrim($options->siteUrl ?? '', '/');
            if (!empty($siteUrl)) {
                return $siteUrl . '/index.php/action/typechometa';
            }
        } catch (\Throwable $e) {
            // 忽略异常
        }
        
        // 最终降级：从当前请求推断
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/index.php/action/typechometa';
    }

    private static function getActionToken(string $actionUrl): string
    {
        try {
            $security = \Typecho\Widget::widget('Widget\\Security');
            $token = (string) $security->getToken($actionUrl);
            if ($token !== '') {
                return $token;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return '';
    }

    public static function ensureActionRegistered(): void
    {
        try {
            // 双 action 名并存：新名避免连字符兼容问题，旧名保持历史调用可用。
            \Utils\Helper::addAction('typechometa', 'TypechoMeta_Action');
            \Utils\Helper::addAction('typecho-meta', 'TypechoMeta_Action');
        } catch (\Throwable $e) {
            // 忽略注册异常，避免配置页或编辑页被中断。
        }
    }

    private static function isChecked($value): bool
    {
        if (is_array($value)) {
            return in_array('1', $value, true) || in_array(1, $value, true);
        }
        return (string) $value === '1';
    }

    private static function resolveTitle(Archive $archive): string
    {
        $custom = self::getFieldValue($archive, 'metaTitle');
        if ($custom !== '') {
            return $custom;
        }
        if (method_exists($archive, 'getArchiveTitle')) {
            return trim((string) $archive->getArchiveTitle());
        }
        return trim((string) $archive->title);
    }

    private static function resolveDescription(Archive $archive): string
    {
        $custom = self::getFieldValue($archive, 'metaDescription');
        if ($custom !== '') {
            return $custom;
        }
        return trim((string) ($archive->excerpt ?? ''));
    }

    private static function resolveKeywords(Archive $archive): string
    {
        return self::getFieldValue($archive, 'metaKeywords');
    }

    private static function getFieldValue($archive, $name)
    {
        if (!isset($archive->fields) || !isset($archive->fields->{$name})) {
            return '';
        }

        return trim((string) $archive->fields->{$name});
    }
}
