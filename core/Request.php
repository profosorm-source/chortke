<?php

declare(strict_types=1);
namespace Core;

/**
 * Request Handler
 * 
 * مدیریت درخواست‌های HTTP
 */
class Request
{
    private $method;
    private $uri;
    private $params = [];
    private $query = [];
    private $body = [];
    private $files = [];
    private $headers = [];
    private array $data;
    // FIX C-6: کش محتوای php://input — stream فقط یک بار قابل خواندن است
    private string $rawInput = '';
    private ?array $parsedBody = null;
    private ?object $user = null;
    private array $attributes = [];
    private ?string $nonce = null; // HIGH-11 Fix: CSP Nonce for secure script execution

    /**
     * HIGH-11 Fix: Generate or retrieve a single-use nonce for CSP
     */
    public function nonce(): string
    {
        if ($this->nonce === null) {
            $this->nonce = bin2hex(random_bytes(16));
        }
        return $this->nonce;
    }

    public function setUser(object $user): void
    {
        $this->user = $user;
    }

    public function user(): ?object
    {
        return $this->user;
    }

    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }

    public function getUser(): ?object
    {
        // M12 Fix: حذف ابهام و ایجاد مستعار (Alias) تمیز برای سازگاری ۱۰۰ درصدی با سایر بخش‌های نرم‌افزار
        return $this->user();
    }

    public function __construct()
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // CORE-023: Support method override
        if ($method === 'POST') {
            $methodOverride = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_POST['_method'] ?? 'POST';
            $method = strtoupper($methodOverride);
        }
        
        $this->method  = $method;
        $this->uri     = $this->parseUri();
        $this->query   = $_GET;
        $this->files   = $_FILES;
        $this->headers = $this->parseHeaders();

        // CORE-021: Read php://input with limit
        $maxBody = (int) config('request.max_body_bytes', 1048576);
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBody) {
            throw new \Core\Exceptions\PayloadTooLargeException();
        }

        // FIX C-6: php://input یک stream است و فقط یک بار قابل خواندن است.
        // مقدار را یک‌بار اینجا می‌خوانیم و در $this->rawInput کش می‌کنیم.
        // parseBody() و json() از این مقدار کش‌شده استفاده می‌کنند.
        $this->rawInput = file_get_contents('php://input') ?: '';

        // M11 Fix: انتقال کدهای سنگین و تکراری پارس بادی به متد parseBody جهت بارگذاری کاملاً Lazy (تنبل)
        $this->body = $_POST;
    }

public function isJson(): bool
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    return str_contains(strtolower($contentType), 'application/json');
}

    /**
     * دریافت Method
     */
    public function method()
    {
        return $this->method;
    }

    /**
     * دریافت URI
     */
    public function uri()
    {
        return $this->uri;
    }
 /**
     * گرفتن IP کاربر
     * FIX B-01: حذف HTTP_CLIENT_IP و HTTP_X_FORWARDED_FOR — هر دو جعل‌پذیرند.
     * فقط REMOTE_ADDR قابل اعتماد است.
     */
    public function ip(): string
    {
        return get_client_ip();
    }
	 /**
     * دریافت User-Agent
     */
    public function userAgent(): string
    {
        return mb_substr(get_user_agent(), 0, 500);
    }
    /**
     * بررسی Method
     */
    public function isMethod($method)
    {
        return strtoupper($this->method) === strtoupper($method);
    }

    /**
     * بررسی GET
     */
    public function isGet()
    {
        return $this->isMethod('GET');
    }

    /**
     * بررسی POST
     */
    public function isPost()
    {
        return $this->isMethod('POST');
    }

public function get(?string $key = null, $default = null)
{
    return $this->query($key, $default);
}

public function post(?string $key = null, $default = null)
{
    return $this->body($key, $default);
}
    /**
     * دریافت پارامتر از URL
     */
    public function param($key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * تنظیم پارامترها (توسط Router)
     */
    public function setParams($params)
    {
        $this->params = $params;
    }

    /**
     * دریافت Query String
     */
    public function query($key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }
        
        return $this->query[$key] ?? $default;
    }
public function body(?string $key = null, $default = null)
{
    $data = $this->parseBody(); // JSON/Form
    if ($key === null) return $data;
    return $data[$key] ?? $default;
}

/**
 * پردازش بدنه درخواست (JSON یا فرم)
 */
private function parseBody(): array
{
    // M11 Fix: پارس کردن داده‌های بدنه (JSON/Form) دقیقاً در اولین زمان نیاز و کش کردن آن برای ریکوئست‌های بعدی
    if ($this->parsedBody !== null) {
        return $this->parsedBody;
    }

    // CORE-022: JSON parse failure validation
    if ($this->isJson()) {
        if (!empty($this->rawInput)) {
            $data = json_decode($this->rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Core\Exceptions\ValidationException(['body' => 'Invalid JSON body'], 'Invalid JSON body');
            }
            $this->parsedBody = array_merge($this->body, is_array($data) ? $data : []);
            return $this->parsedBody;
        }
    }

    // CORE-023: Parse application/x-www-form-urlencoded for PUT/PATCH/DELETE
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (str_contains(strtolower($contentType), 'application/x-www-form-urlencoded') 
        && in_array($this->method, ['PUT', 'PATCH', 'DELETE'])) {
        parse_str($this->rawInput, $parsedParams);
        $this->parsedBody = array_merge($this->body, $parsedParams);
        return $this->parsedBody;
    }

    $this->parsedBody = $this->body;
    return $this->parsedBody;
}

    /**
     * دریافت Body (POST)
     */
    public function input($key = null, $default = null)
    {
        // M11 Fix: ارجاع متد به لایه تنبل پارس بادی جهت دسترسی سریع و ایمن
        $data = $this->parseBody();
        if ($key === null) {
            return $data;
        }
        
        return $data[$key] ?? $default;
    }

    /**
     * دریافت همه ورودی‌ها
     */
    public function all()
    {
        // M11 Fix: ترکیب داده‌های Query و Body پارس‌شده به صورت کاملاً Lazy
        return array_merge($this->query, $this->parseBody());
    }

    /**
     * دریافت فقط فیلدهای مشخص
     */
    public function only($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $all = $this->all();
        $result = [];
        
        foreach ($keys as $key) {
            if (isset($all[$key])) {
                $result[$key] = $all[$key];
            }
        }
        
        return $result;
    }

    /**
     * دریافت فایل
     */
    public function file($key)
    {
        return $this->files[$key] ?? null;
    }

    /**
     * بررسی وجود فایل
     */
    public function hasFile($key)
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    /**
     * دریافت Header
     */
    public function header($key, $default = null)
    {
        $key = strtolower($key);
        return $this->headers[$key] ?? $default;
    }

    /**
     * دریافت داده JSON از php://input
     */
    public function json(): ?array
    {
        // FIX C-6: از rawInput کش‌شده استفاده می‌کنیم
        $data = json_decode($this->rawInput, true);
        return is_array($data) ? $data : null;
    }

    /**
     * بررسی درخواست Ajax
     */
    public function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    public function isSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https') {
            return true;
        }

        // فقط در صورتی که آی‌پی فرستنده جزو پروکسی‌های معتبر باشد، به هدر X-Forwarded-Proto اعتماد می‌کنیم
        $trustedProxies = (array)config('app.trusted_proxies', ['127.0.0.1']);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

        $isTrusted = false;
        foreach ($trustedProxies as $proxy) {
            if (ip_in_range($clientIp, $proxy)) {
                $isTrusted = true;
                break;
            }
        }

        if ($isTrusted) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse کردن URI
     */
    private function parseUri()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // حذف Query String
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // استفاده از APP_BASE_PATH اگر مشخص شده باشد، در غیر این صورت از مسیر اسکریپت
        $basePath = config('app.base_path', '');
        if ($basePath === null || $basePath === '') {
            $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            $scriptDir  = dirname($scriptName);
            $basePath   = ($scriptDir === '/' || $scriptDir === '\\') ? '' : rtrim($scriptDir, '/');
            $basePath   = preg_replace('/\/public$/', '', $basePath);
        }

        if ($basePath !== '' && $basePath !== '/' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }
        
        return '/' . trim($uri, '/');
    }

    /**
     * Parse کردن Headers
     */
    private function parseHeaders()
    {
        $headers = [];
        
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($headerName)] = $value;
            }
        }
        
        return $headers;
    }

    /**
     * Validate کردن ورودی
     */
    public function validate(array $rules): array
    {
        // Fix: سینک کردن صدا زدن متد با ساختار صحیح و اصلاح‌شده کلاس Validator
        $validator = new Validator($this->all(), $rules);
        
        return $validator->fails() ? $validator->errors() : [];
    }
}