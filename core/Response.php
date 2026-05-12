<?php
namespace Core;

/**
 * Response Handler
 * 
 * مدیریت پاسخ‌های HTTP
 */
class Response
{
    private $statusCode = 200;
    private $headers = [];
    private $content = '';
    
    // ✅ Whitelist of allowed header names (case-insensitive)
    private const ALLOWED_HEADERS = [
        'cache-control',
        'content-type',
        'content-length',
        'content-encoding',
        'content-disposition',
        'expires',
        'etag',
        'last-modified',
        'pragma',
        'location',
        'set-cookie',
        'x-custom-header',
        'access-control-allow-origin',
        'x-frame-options',
        'x-content-type-options',
        'x-xss-protection',
        'strict-transport-security',
        'content-security-policy',
        'referrer-policy',
        'permissions-policy',
        'cross-origin-opener-policy',
        'cross-origin-resource-policy',
        'vary',
        'access-control-allow-credentials',
        'access-control-allow-methods',
        'access-control-allow-headers',
        'access-control-max-age',
        'server',
    ];

    public function setStatusCode(int $code): void
    {
        $this->statusCode = $code;
        http_response_code($code);
    }
    
    /**
     * دریافت Status Code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    /**
     * ✅ Validate header name and value to prevent header injection
     */
    private function validateHeader(string $name, string $value): bool
    {
        // ✅ Header name should be alphanumeric and hyphenated only
        if (!preg_match('/^[a-zA-Z0-9\-]+$/', $name)) {
            throw new \InvalidArgumentException("نام Header معتبر نیست: {$name}");
        }
        
        // ✅ Prevent CRLF injection in value
        if (preg_match("/[\r\n]/", $value)) {
            throw new \InvalidArgumentException("مقدار Header نمی‌تواند شامل خط جدید باشد");
        }
        
        // ✅ Check against whitelist
        if (!in_array(strtolower($name), self::ALLOWED_HEADERS, true)) {
            throw new \InvalidArgumentException("Header نام‌گذاری شده نیست: {$name}");
        }
        
        return true;
    }
    
    /**
     * تنظیم Header
     */
    public function setHeader(string $name, string $value): void
    {
        $this->validateHeader($name, $value);
        if (!headers_sent()) {
            header("{$name}: {$value}");
        }
    }

    /**
     * تنظیم Content
     */
    public function setContent($content)
    {
        $this->content = $content;
        return $this;
    }

    /**
     * دریافت Content
     */
    public function getContent(): string
    {
        return (string)$this->content;
    }

    public function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $options = JSON_UNESCAPED_UNICODE;
        if (config('app.debug')) {
            $options |= JSON_PRETTY_PRINT;
        }
        echo json_encode($data, $options);
        if (defined('TESTING') && TESTING === true) {
            return;
        }
        exit;
    }

    /**
     * ارسال پاسخ HTML
     */
    public function html(string $content, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $content;
        if (defined('TESTING') && TESTING === true) {
            return;
        }
        exit;
    }
    
    /**
     * پاسخ موفق
     */
    public function success($message, $data = [], $statusCode = 200)
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    /**
     * پاسخ خطا
     */
    public function error($message, $errors = [], $statusCode = 400)
    {
        return $this->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ], $statusCode);
    }

    /**
     * ✅ Validate URL to prevent open redirect attacks
     */
    private function validateRedirectUrl(string $url): bool
    {
        // ✅ Allow relative URLs (start with / but NOT //)
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return true;
        }
        
        // ✅ Allow URLs from same domain only
        $baseUrl = parse_url(config('app.url', 'http://localhost'));
        $redirectUrl = parse_url($url);
        
        // اگر protocol یا host متفاوت باشد، reject کن
        if (isset($redirectUrl['host']) && $redirectUrl['host'] !== ($baseUrl['host'] ?? '')) {
            throw new \InvalidArgumentException("Open redirect نیست مجاز");
        }
        
        return true;
    }
    
    /**
     * Redirect
     */
    public function redirect(string $url, int $statusCode = 302): void
    {
        $this->validateRedirectUrl($url);
        session_write_close();
        http_response_code($statusCode);
        if (!headers_sent()) {
            header("Location: {$url}");
        }
        if (defined('TESTING') && TESTING === true) {
            $this->statusCode = $statusCode;
            $this->headers['Location'] = $url;
            return;
        }
        exit;
    }
    
    private function validateFilePath(string $filePath): bool
    {
        // ✅ Prevent directory traversal
        if (strpos($filePath, '..') !== false) {
            throw new \InvalidArgumentException("Path traversal نیست مجاز");
        }
        
        // ✅ Get real path
        $realPath = realpath($filePath);
        if ($realPath === false) {
            throw new \InvalidArgumentException("فایل پیدا نشد");
        }
        
        // ✅ Ensure file is within allowed base directories (public/uploads or secure storage)
        $allowedBases = [
            realpath(__DIR__ . '/../public/uploads'),
            realpath(__DIR__ . '/../storage'),
        ];
        
        $isAllowed = false;
        foreach ($allowedBases as $base) {
            if ($base !== false && str_starts_with($realPath, $base)) {
                $isAllowed = true;
                break;
            }
        }
        
        if (!$isAllowed) {
            throw new \InvalidArgumentException("فایل خارج از دایرکتوری مجاز است");
        }
        
        return true;
    }
    
    /**
     * ✅ Validate filename to prevent header injection
     */
    private function validateFileName(string $fileName): string
    {
        // ✅ Remove any path separators
        $fileName = basename($fileName);
        
        // ✅ Prevent CRLF injection
        if (preg_match("/[\r\n]/", $fileName)) {
            throw new \InvalidArgumentException("نام فایل معتبر نیست");
        }
        
        // ✅ Sanitize filename - remove quotes and dangerous characters
        $fileName = str_replace(['"', "'", "\x00"], '', $fileName);
        
        return $fileName;
    }
    
    /**
     * دانلود فایل
     */
    public function download(string $filePath, string $fileName): void
    {
        // ✅ Validate both path and filename
        $this->validateFilePath($filePath);
        $fileName = $this->validateFileName($fileName);
        
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo 'فایل پیدا نشد';
            exit;
        }

        // ✅ Set proper headers (validated)
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        
        readfile($filePath);
        exit;
    }
    
    /**
     * برگشت به صفحه قبل
     */
    public function back()
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? url();
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (parse_url($referer, PHP_URL_HOST) !== $host) {
            $referer = url();
        }
        return $this->redirect($referer);
    }

    /**
     * ارسال پاسخ
     */
    public function send()
    {
        // تنظیم Status Code
        http_response_code($this->statusCode);
        
        // ارسال Headers
        foreach ($this->headers as $name => $value) {
            try {
                $this->validateHeader($name, $value);
                header("{$name}: {$value}");
            } catch (\InvalidArgumentException $e) {
                // Log and skip invalid header
                error_log($e->getMessage());
            }
        }
        
        // ارسال Content
        echo $this->content;
        
        exit;
    }

    /**
     * نمایش View
     */
    public function view($viewName, $data = [])
    {
        ob_start();
        view($viewName, $data);
        $this->content = ob_get_clean();
        
        echo $this->content;
        exit;
    }

	/**
     * تنظیم HTTP Status Code
     */
    public function status(int $code): self
    {
        http_response_code($code);
        return $this;
    }

    /**
     * ارسال Header
     */
    public function header(string $name, string $value): self
    {
        $this->setHeader($name, $value);
        return $this;
    }
}