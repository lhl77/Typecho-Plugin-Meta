<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class TypechoMeta_Action extends \Typecho\Widget implements \Widget\ActionInterface
{
    private $responded = false;
    private $jsonPayload = null;

    public function action()
    {
        $this->registerShutdownGuard();
        $this->loadJsonPayload();

        $do = trim((string) $this->input('do', ''));
        if ($do === 'ping') {
            $this->jsonSuccess(array(
                'pong' => 1,
                'time' => time(),
                'php' => PHP_VERSION,
                'sapi' => PHP_SAPI
            ));
            return;
        }

        if ($do !== 'generate') {
            $this->jsonError('不支持的操作', 400);
            return;
        }

        if (!$this->requireLogin()) {
            return;
        }

        $title = (string) $this->input('title', '');
        $content = (string) $this->input('content', '');
        if (trim($content) === '') {
            $this->jsonError('正文不能为空', 422);
            return;
        }

        try {
            $result = $this->generateSeo($title, $content);
            $this->jsonSuccess($result);
        } catch (Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    private function loadJsonPayload()
    {
        $this->jsonPayload = array();

        $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
        if (strpos($contentType, 'application/json') === false) {
            return;
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $this->jsonPayload = $decoded;
        }
    }

    private function input($key, $default = '')
    {
        $value = $this->request->get($key, null);
        if ($value !== null && $value !== '') {
            return $value;
        }

        if (is_array($this->jsonPayload) && array_key_exists($key, $this->jsonPayload)) {
            return $this->jsonPayload[$key];
        }

        return $default;
    }

    private function requireLogin()
    {
        $user = \Typecho\Widget::widget('Widget_User');
        if (!$user->hasLogin()) {
            $this->jsonError('请先登录', 401);
            return false;
        }

        // 文章编辑相关操作，最低贡献者权限。
        if (!$user->pass('contributor', true)) {
            $this->jsonError('权限不足', 403);
            return false;
        }

        return true;
    }

    private function generateSeo($title, $content)
    {
        $settings = \Typecho\Widget::widget('Widget_Options')->plugin('TypechoMeta');

        $provider = isset($settings->aiProvider) ? trim((string) $settings->aiProvider) : 'openai_compatible';
        $apiUrl = isset($settings->aiApiUrl) ? trim((string) $settings->aiApiUrl) : '';
        $model = isset($settings->aiModel) ? trim((string) $settings->aiModel) : '';
        $token = isset($settings->aiToken) ? trim((string) $settings->aiToken) : '';

        if ($apiUrl === '' || $model === '' || $token === '') {
            throw new Exception('请先在插件设置中填写 AI 接口地址、模型名和 Token');
        }

        $limit = isset($settings->aiContentLimit) ? (int) $settings->aiContentLimit : 3000;
        if ($limit < 200) {
            $limit = 200;
        }

        $descMaxLen = isset($settings->aiDescriptionMaxLen) ? (int) $settings->aiDescriptionMaxLen : 160;
        if ($descMaxLen < 60) {
            $descMaxLen = 60;
        }

        $keywordsMax = isset($settings->aiKeywordsMaxCount) ? (int) $settings->aiKeywordsMaxCount : 8;
        if ($keywordsMax < 3) {
            $keywordsMax = 3;
        }

        $timeout = isset($settings->aiTimeout) ? (int) $settings->aiTimeout : 30;
        if ($timeout < 5) {
            $timeout = 5;
        }

        $temperature = isset($settings->aiTemperature) ? (float) $settings->aiTemperature : 0.2;

        $cleanTitle = trim(strip_tags((string) $title));
        $cleanContent = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $content)));
        if (function_exists('mb_substr')) {
            $cleanContent = mb_substr($cleanContent, 0, $limit);
        } else {
            $cleanContent = substr($cleanContent, 0, $limit);
        }

        $promptTemplate = isset($settings->aiPromptTemplate) ? (string) $settings->aiPromptTemplate : '';
        if (trim($promptTemplate) === '') {
            $promptTemplate = "你是一名资深 SEO 编辑。请根据给定文章标题和正文，生成 JSON，且仅输出 JSON。\n"
                . "输出格式：{\"description\":\"...\",\"keywords\":[\"k1\",\"k2\"]}\n"
                . "要求：\n"
                . "1) description 为中文，通顺，避免夸张，适合搜索摘要；\n"
                . "2) keywords 返回 5~10 个关键词，避免重复；\n"
                . "3) 不要输出 markdown，不要解释。\n\n"
                . "标题：{{title}}\n"
                . "正文：{{content}}";
        }

        $prompt = str_replace(array('{{title}}', '{{content}}'), array($cleanTitle, $cleanContent), $promptTemplate);
        $raw = $this->requestAi($provider, $apiUrl, $model, $token, $prompt, $temperature, $timeout);
        $parsed = $this->parseAiResult($raw, $descMaxLen, $keywordsMax);

        if ($parsed['description'] === '' || $parsed['keywords'] === '') {
            throw new Exception('AI 返回结果格式不符合要求，请检查提示词或模型输出。');
        }

        return $parsed;
    }

    private function requestAi($provider, $apiUrl, $model, $token, $prompt, $temperature, $timeout)
    {
        $apiUrl = $this->normalizeApiUrl($provider, $apiUrl, $model);

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

            $json = $this->httpJson('POST', $url, array('Content-Type: application/json'), $payload, $timeout);
            $text = '';
            if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                $text = $json['candidates'][0]['content']['parts'][0]['text'];
            }
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

            $json = $this->httpJson('POST', $apiUrl, $headers, $payload, $timeout);
            $text = '';
            if (isset($json['content'][0]['text'])) {
                $text = $json['content'][0]['text'];
            }
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

        $json = $this->httpJson('POST', $apiUrl, $headers, $payload, $timeout);
        $text = '';
        if (isset($json['choices'][0]['message']['content'])) {
            $text = $json['choices'][0]['message']['content'];
        }

        return trim((string) $text);
    }

    private function normalizeApiUrl($provider, $apiUrl, $model)
    {
        $url = trim((string) $apiUrl);
        if ($url === '') {
            return $url;
        }

        if ($provider === 'openai_compatible') {
            $parts = @parse_url($url);
            $path = isset($parts['path']) ? (string) $parts['path'] : '';

            if ($path === '' || $path === '/') {
                return rtrim($url, '/') . '/v1/chat/completions';
            }

            if (preg_match('#/v1/?$#', $url)) {
                return rtrim($url, '/') . '/chat/completions';
            }

            if (preg_match('#/chat/completions/?$#', $url)) {
                return rtrim($url, '/');
            }

            return $url;
        }

        if ($provider === 'anthropic') {
            if (preg_match('#/v1/?$#', $url)) {
                return rtrim($url, '/') . '/messages';
            }
            return $url;
        }

        if ($provider === 'gemini') {
            if (strpos($url, ':generateContent') !== false) {
                return $url;
            }

            if (strpos($url, 'generativelanguage.googleapis.com') !== false && preg_match('#/v1(beta)?/?$#', $url)) {
                $modelName = trim((string) $model);
                if ($modelName !== '') {
                    return rtrim($url, '/') . '/models/' . rawurlencode($modelName) . ':generateContent';
                }
            }

            return $url;
        }

        return $url;
    }

    private function httpJson($method, $url, $headers, $payload, $timeout)
    {
        if (!function_exists('curl_init')) {
            throw new Exception('当前 PHP 未启用 curl 扩展');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(3, (int) $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new Exception('AI 请求失败：' . $error . ' (#' . $errno . ')');
        }

        $json = json_decode((string) $response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            if (is_array($json)) {
                $msg = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $msg = trim((string) $response);
            }

            if ($msg === '') {
                $msg = '<empty body>';
            }

            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($msg) > 300) {
                    $msg = mb_substr($msg, 0, 300) . '...';
                }
            } elseif (strlen($msg) > 300) {
                $msg = substr($msg, 0, 300) . '...';
            }

            throw new Exception('AI 接口返回异常（HTTP ' . $httpCode . '，URL: ' . $url . '）：' . $msg);
        }

        if (!is_array($json)) {
            $preview = trim((string) $response);
            if ($preview === '') {
                $preview = '<empty body>';
            }
            throw new Exception('AI 接口返回非 JSON 数据（URL: ' . $url . '）：' . $preview);
        }

        return $json;
    }

    private function parseAiResult($raw, $descMaxLen, $keywordsMax)
    {
        $candidate = trim((string) $raw);
        if (preg_match('/\{[\s\S]*\}/', $candidate, $m)) {
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
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($description) > (int) $descMaxLen) {
                $description = mb_substr($description, 0, (int) $descMaxLen);
            }
        } elseif (strlen($description) > (int) $descMaxLen) {
            $description = substr($description, 0, (int) $descMaxLen);
        }

        $cleanKeywords = array();
        foreach ($keywords as $item) {
            $k = trim((string) $item);
            if ($k !== '' && !in_array($k, $cleanKeywords, true)) {
                $cleanKeywords[] = $k;
            }
            if (count($cleanKeywords) >= (int) $keywordsMax) {
                break;
            }
        }

        return array(
            'description' => $description,
            'keywords' => implode(', ', $cleanKeywords)
        );
    }

    private function jsonSuccess($data)
    {
        $this->sendJson(array(
            'success' => 1,
            'message' => 'ok',
            'data' => $data
        ), 200);
    }

    private function jsonError($message, $code = 500)
    {
        $this->sendJson(array(
            'success' => 0,
            'message' => (string) $message
        ), $code);
    }

    private function sendJson($payload, $code)
    {
        $this->responded = true;

        if (!headers_sent()) {
            http_response_code((int) $code);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('X-TypechoMeta-Action: 1');
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $json = json_encode($payload, $flags);
        if ($json === false) {
            if (!headers_sent()) {
                http_response_code(500);
            }

            $json = json_encode(array(
                'success' => 0,
                'message' => 'JSON 编码失败：' . json_last_error_msg()
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($json === false) {
            $json = '{"success":0,"message":"JSON encode failed"}';
        }

        echo $json;
        exit;
    }

    private function registerShutdownGuard()
    {
        register_shutdown_function(function () {
            if ($this->responded) {
                return;
            }

            $error = error_get_last();
            if (!is_array($error)) {
                return;
            }

            $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
            if (!in_array($error['type'], $fatalTypes, true)) {
                return;
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('Pragma: no-cache');
                header('X-TypechoMeta-Fatal: 1');
            }

            echo json_encode(array(
                'success' => 0,
                'message' => 'Fatal: ' . (string) ($error['message'] ?? 'unknown error'),
                'file' => (string) ($error['file'] ?? ''),
                'line' => (int) ($error['line'] ?? 0)
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
    }
}
