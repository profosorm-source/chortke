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

    public function setUser(object $user): void
    {
        $this->user = $user;
    }

    public function user(): ?object
    {
        return $this->user;
    }

    public function getUser(): ?object
    {
        return $this->user;
    }

    public function __construct()
    {
        $this->method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri     = $this->parseUri();
        $this->query   = $_GET;
        $this->files   = $_FILES;
        $this->headers = $this->parseHeaders();

        // FIX C-6: php://input یک stream است و فقط یک بار قابل خواندن است.
        // مقدار را یک‌بار اینجا می‌خوانیم و در $this->rawInput کش می‌کنیم.
        // parseBody() و json() از این مقدار کش‌شده استفاده می‌کنند.
        $this->rawInput = file_get_contents('php://input') ?: '';

        $this->body = $_POST;

        if ($this->isJson()) {
            $data = json_decode($this->rawInput, true);
            if (is_array($data)) {
                $this->body = $data;
            }
        }
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
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
	 /**
     * دریافت User-Agent
     */
    public function userAgent(): string
    {
        return get_user_agent();
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
    // FIX C-6: از rawInput کش‌شده در constructor استفاده می‌کنیم
    // نه از file_get_contents('php://input') که بار دوم خالی برمی‌گردد.
    if ($this->parsedBody !== null) {
        return $this->parsedBody;
    }

    if ($this->isJson()) {
        $data = json_decode($this->rawInput, true);
        if (is_array($data)) {
            $this->parsedBody = array_merge($this->body, $data);
            return $this->parsedBody;
        }
    }

    $this->parsedBody = $this->body;
    return $this->parsedBody;
}

    /**
     * دریافت Body (POST)
     */
    public function input($key = null, $default = null)
    {
        if ($key === null) {
            return $this->body;
        }
        
        return $this->body[$key] ?? $default;
    }

    /**
     * دریافت همه ورودی‌ها
     */
    public function all()
    {
        return array_merge($this->query, $this->body);
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

    /**
     * آیا اتصال امن است (HTTPS)
     */
    public function isSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }

        if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https') {
            return true;
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
        
        // حذف Base Path (اگر در subdirectory باشد)
        $scriptName = dirname($_SERVER['SCRIPT_NAME']);
        if ($scriptName !== '/' && strpos($uri, $scriptName) === 0) {
            $uri = substr($uri, strlen($scriptName));
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
        $validator = new Validator();
        $isValid = $validator->validate($this->all(), $rules);
        
        return $isValid ? [] : $validator->getErrors();
    }
}