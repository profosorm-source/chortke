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
    private ?string $downloadPath = null; // H10 Fix: مدیریت استریم دانلود بدون بلاک کردن ترد اجرا
    
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
        'cross-origin-embedder-policy',
        'reporting-endpoints',
        'report-to',
        'nel',
        'vary',
        'access-control-allow-credentials',
        'access-control-allow-methods',
        'access-control-allow-headers',
        'access-control-max-age',
        'server',
    ];

    public function setStatusCode(int $code): self
    {
        if ($code < 100 || $code > 599) {
            throw new \InvalidArgumentException("کد وضعیت HTTP نامعتبر است: {$code}");
        }
        $this->statusCode = $code;
        return $this;
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
    public function setHeader(string $name, string $value): self
    {
        $this->validateHeader($name, $value);
        $this->headers[$name] = $value;
        return $this;
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
        $this->statusCode = $statusCode;
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');

        $options = JSON_UNESCAPED_UNICODE;
        if (config('app.debug')) {
            $options |= JSON_PRETTY_PRINT;
        }
        
        $this->content = json_encode($data, $options | JSON_THROW_ON_ERROR);
        $this->send();
    }

    /**
     * ارسال پاسخ بدون محتوا (204 No Content)
     */
    public function noContent(): void
    {
        $this->statusCode = 204;
        $this->content = '';
        $this->send();
    }

    /**
     * ارسال پاسخ HTML
     */
    public function html(string $content, int $statusCode = 200): void
    {
        $this->statusCode = $statusCode;
        $this->setHeader('Content-Type', 'text/html; charset=utf-8');
        
        $this->content = $content;
        $this->send();
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
        // Prevent CRLF injection
        if (preg_match("/[\r\n]/", $url)) {
            throw new \InvalidArgumentException('آدرس ریدایرکت شامل کاراکترهای غیرمجاز است');
        }

        // 1. Block protocol-relative or backslash bypasses (e.g. //google.com, ///google.com, /\\google.com)
        if (preg_match('#^[\\\\/]{2,}#', $url) || preg_match('#^/+\\\\#', $url)) {
            throw new \InvalidArgumentException('آدرس ریدایرکت نامعتبر است (آدرس‌های نسبی پروتکل مجاز نیستند)');
        }

        // 2. Allow safe relative paths starting with a single slash (e.g. /dashboard)
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0 && strpos($url, '/\\') !== 0) {
            return true;
        }

        // 3. For absolute URLs, parse and strictly validate host
        $baseUrl = parse_url(config('app.url', 'http://localhost'));
        $baseHost = $baseUrl['host'] ?? '';

        // If it starts with a scheme or looks like a full URL
        if (preg_match('#^(https?:)?//#i', $url) || filter_var($url, FILTER_VALIDATE_URL)) {
            $redirectUrl = parse_url($url);
            $redirectHost = $redirectUrl['host'] ?? null;
            if ($redirectHost !== $baseHost) {
                throw new \InvalidArgumentException("تغییر مسیر به دامنه‌های خارجی مجاز نیست");
            }
            return true;
        }

        // 4. Block path traversal or relative tricks (e.g. absolute-path bypasses in relative clothing)
        if (preg_match('#^(https?:)?//#i', ltrim($url, '/\\'))) {
            throw new \InvalidArgumentException('آدرس ریدایرکت نامعتبر است');
        }

        // Safe relative path without leading slash (e.g. "home")
        return true;
    }
    
    /**
     * Redirect
     */
    public function redirect(string $url, int $statusCode = 302): void
    {
        $this->validateRedirectUrl($url);
        session_write_close();
        
        $this->statusCode = $statusCode;
        $this->setHeader('Location', $url);
        
        $this->send();
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
            $this->statusCode = 404;
            $this->content = 'فایل پیدا نشد';
            $this->send();
            return;
        }

        // H10 Fix: به جای exit، مسیر دانلود را ست کرده و Response Exception شلیک می‌کنیم
        $this->setHeader('Content-Type', 'application/octet-stream');
        $this->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        $this->setHeader('Content-Length', (string)filesize($filePath));
        
        $this->downloadPath = $filePath;
        $this->send();
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
     * ارسال فیزیکی Status Code و هدرهای انباشته شده به کلاینت
     */
    private function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // [MED-03] Security Headers: Enforcing best-practice security policies
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Expect-CT: max-age=86400, enforce');
        
        // HIGH-11 Fix: Dynamic Content Security Policy (CSP) with Nonce
        try {
            $request = \Core\Container::getInstance()->make(\Core\Request::class);
            $nonce = $request->nonce();
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://www.google.com https://www.gstatic.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; frame-src 'self' https://www.google.com; connect-src 'self';");
        } catch (\Throwable $e) {
            // Fallback policy if request/nonce generation fails
            header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;");
        }
        
        // HSTS (Strict-Transport-Security) only over HTTPS
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        
        if ($isSecure) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // اعمال نهایی وضعیت HTTP
        http_response_code($this->statusCode);
        
        // انتشار تمامی هدرهای ساخته شده توسط لایه‌های سیستم
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
    }

    /**
     * ارسال نهایی فیزیکی به مرورگر (فقط توسط Router صدا زده می‌شود)
     */
    public function sendToBrowser(): void
    {
        $this->sendHeaders();
        
        if ($this->downloadPath && file_exists($this->downloadPath)) {
            // M13 Fix: پیاده‌سازی مکانیزم دانلود امن و کم‌مصرف حافظه (Chunked Memory-Safe Streaming)
            // غیرفعال کردن تمامی بافرهای خروجی فعال جهت آزادسازی رم سرور
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            $file = fopen($this->downloadPath, 'rb');
            if ($file !== false) {
                while (!feof($file) && connection_status() === 0) {
                    echo fread($file, 8192); // استریم فایل به صورت تکه‌تکه‌های ۸ کیلوبایتی
                    flush(); // هل دادن فیزیکی داده‌ها به سمت کلاینت و مرورگر
                }
                fclose($file);
            }
        } else {
            echo $this->content;
        }
    }

    /**
     * H10 Fix: آزادسازی پایپ‌لاین روت‌ها
     * به جای خروج فیزیکی و خاموش شدن پردازش، یک Response Exception پرتاب می‌شود 
     * که توسط لایه Router گرفته شده و به چرخه حیات طبیعی برمی‌گرداند.
     */
    public function send(): void
    {
        throw new \Core\Exceptions\HttpResponseException($this);
    }

    /**
     * نمایش View
     */
    public function view($viewName, $data = [])
    {
        ob_start();
        view($viewName, $data);
        $this->content = ob_get_clean();
        
        // H10 Fix: شلیک پاسخ استاندارد به جای echo و exit فیزیکی
        $this->send();
    }

	/**
     * تنظیم HTTP Status Code
     */
    public function status(int $code): self
    {
        $this->setStatusCode($code);
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