<?php

declare(strict_types=1);

namespace Core;

use Throwable;
use ErrorException;
use Core\Exceptions\NotFoundException;
use Core\Exceptions\UnauthorizedException;
use Core\Exceptions\ValidationException;
use Core\Exceptions\SecurityException;

class ExceptionHandler
{
	
    private static int $exceptionDepth = 0;
    private const MAX_RECURSION = 3;
    public static function register(): void
    {
        // Do not register exception handler under PHPUnit/testing environment
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
            return;
        }

        // تبدیل خطاهای PHP به Exception
        set_error_handler([self::class, 'handleError']);
        
        // گرفتن Exception های catch نشده
        set_exception_handler([self::class, 'handle']);
        
        // گرفتن Fatal Errors
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    private static function fallbackLog(string $event, array $context = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents(__DIR__ . '/../storage/logs/_exception_fallback.log', $line, FILE_APPEND | LOCK_EX);
}

    /**
     * M27 Fix: ذخیره‌سازی نهایی اطلاعات بحرانی درون صف اضطراری آفلاین سنتری شخصی (jsonl)
     * این فایل بعداً در اولین ورود مدیر توسط داشبورد همگام‌سازی و از دیسک حذف می‌شود
     */
    private static function logToEmergencySentry(string $message, ?string $trace = null, string $level = 'ERROR', ?string $errorCode = null): void
    {
        try {
            $traceId = $_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['REQUEST_ID'] ?? 'unknown';
            $emergencyData = [
                'message'   => '🔴 ' . $level . ': ' . $message,
                'error_code'=> $errorCode,
                'trace_id'  => $traceId,
                'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'offline_cli',
                'timestamp' => time(),
                'trace'     => $trace ?? 'N/A'
            ];
            
            // استفاده هوشمند و امن از مسیردهی‌ها
            $logPath = function_exists('config') 
                ? config('paths.storage', __DIR__ . '/../storage') . '/logs/sentry_emergency.jsonl'
                : __DIR__ . '/../storage/logs/sentry_emergency.jsonl';

            $line = json_encode($emergencyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // در بدترین شرایط قفل سیستم، جلوی توقف فرآیند PHP را می‌گیرد
        }
    }


private static function latestDbFailureContext(): ?array
{
    $logFile = __DIR__ . '/../storage/logs/_db_fallback.log';
    if (!is_file($logFile)) {
        return null;
    }

    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return null;
    }

    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
    $uri = $_SERVER['REQUEST_URI'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $row = json_decode($lines[$i], true);
        if (!is_array($row)) {
            continue;
        }

        $event = (string)($row['event'] ?? '');
        if (strpos($event, 'database.') !== 0) {
            continue;
        }

        $ctx = $row['context'] ?? null;
        if (!is_array($ctx)) {
            continue;
        }

        if ($requestId) {
            if (($ctx['request_id'] ?? null) !== $requestId) {
                continue;
            }
        } else {
            if (($ctx['uri'] ?? null) !== $uri) {
                continue;
            }
            if (($ctx['ip'] ?? null) !== $ip) {
                continue;
            }
        }

        return $ctx;
    }

    return null;
}
    /**
     * مدیریت Exception ها
     */
    public static function handle(\Throwable $exception): void
{
    self::$exceptionDepth++;
    if (self::$exceptionDepth > self::MAX_RECURSION) {
        self::fallbackLog('exception.recursive.detected', [
            'message' => $exception->getMessage(),
        ]);
        
        // M27 Fix: ثبت بلادرنگ خطای تودرتو و بازگشتی درون صف اضطراری جهت جلوگیری از هدر رفت دیباگ
        self::logToEmergencySentry(
            'Recursive Exception Limit Exceeded: ' . $exception->getMessage(),
            $exception->getTraceAsString(),
            'CRITICAL_RECURSIVE_LIMIT',
            'RECURSION_ERROR'
        );
        
        http_response_code(500);
        die('critical system error: recursion loop');
    }

    try {
        if (!isset($_SERVER['REQUEST_ID']) && !isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            $_SERVER['REQUEST_ID'] = uniqid('req-');
        }

        $payload = self::getJsonPayloadForException($exception);
        $errorCode = $payload['error']['code'] ?? 'UNKNOWN_ERROR';
        $traceId = $payload['meta']['trace_id'] ?? $_SERVER['REQUEST_ID'];

        // استفاده صحیح از Logger - بدون $this
        try {
            if (function_exists('logger')) {
    $context = [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ];

    if (str_contains($exception->getMessage(), 'SQLSTATE')) {
        $context = self::enrichSqlContext($exception, $context);
    }

    logger()->error('exception.unhandled', $context);
}
        } catch (\Throwable $e) {
            self::fallbackLog('exception.logger.failed', [
                'message' => $e->getMessage(),
            ]);
        }

        // لاگ پیشرفته موجود خود پروژه
        try {
            self::logToAdvancedSystem($exception, $errorCode, $traceId);
        } catch (\Throwable $e) {
            self::fallbackLog('exception.log_to_advanced_system.failed', [
                'message' => $e->getMessage(),
            ]);
        }

        http_response_code(500);

        $isJsonRequest = self::isJsonRequest();

        // Handle Business/Validation exceptions for Web Requests (Flash and Redirect)
        if (!$isJsonRequest && ($exception instanceof \Core\Exceptions\BusinessException || $exception instanceof \Core\Exceptions\ValidationException || $exception instanceof \Core\Exceptions\InsufficientBalanceException)) {
            try {
                $session = \Core\Session::getInstance();
                $session->setFlash('error', $exception->getMessage());
                if ($exception instanceof \Core\Exceptions\ValidationException) {
                    $session->setFlash('errors', $exception->getErrors());
                }
                $ref = $_SERVER['HTTP_REFERER'] ?? '/';
                header("Location: $ref");
                exit;
            } catch (\Throwable $e) {
                // Fallback to normal error page if session/redirect fails
            }
        }

        if ($isJsonRequest) {
            self::renderJsonError($exception);
            return;
        }

        $debug = (bool) config('app.debug', false);

        if ($debug) {
            self::renderDebugPage($exception);
        } else {
            self::renderProductionPage($exception);
        }
    } catch (\Throwable $e) {
        self::fallbackLog('exception.handler.failed', [
            'message' => $e->getMessage(),
        ]);
        
        // M27 Fix: نجات و ثبت اطلاعات آخرین نفس درایو در صف اضطراری آفلاین در زمان فروپاشی هندلر
        self::logToEmergencySentry(
            'Handler Collapse Catch: ' . $e->getMessage(),
            $e->getTraceAsString(),
            'HANDLER_COLLAPSE',
            'HANDLER_CRASH'
        );
        
        http_response_code(500);
        die('system error');
    } finally {
        self::$exceptionDepth--;
    }
}

private static function enrichSqlContext(\Throwable $exception, array $context): array
{
    $dbCtx = null;

    if (class_exists('\\Core\\Database') && method_exists('\\Core\\Database', 'getLastSqlErrorContext')) {
        try {
            $dbCtx = \Core\Database::getLastSqlErrorContext();
        } catch (\Throwable $ignore) {
            $dbCtx = null;
        }
    }

    // مسیر 1: اگر context دیتابیس از Database.php موجود بود
    if (is_array($dbCtx) && !empty($dbCtx)) {
        $context['db_error'] = $dbCtx['error'] ?? $exception->getMessage();
        $context['db_sql'] = $dbCtx['sql'] ?? null;
        $context['db_sql_interpolated'] = $dbCtx['sql_interpolated'] ?? null;
        $context['db_file'] = $dbCtx['file'] ?? null;
        $context['db_line'] = $dbCtx['line'] ?? null;
        $context['db_tables'] = $dbCtx['tables'] ?? [];
        $context['db_unknown_column'] = $dbCtx['unknown_column'] ?? null;
        $context['db_stack'] = $dbCtx['stack'] ?? [];
        $context['db_params_count'] = $dbCtx['params_count'] ?? null;

        // این بخش همانی است که گفتی جاگذاری‌اش نامشخص بود
        $context['db_request'] = [
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        return $context;
    }

    // مسیر 2: fallback از trace خود exception
    [$originFile, $originLine, $stack] = self::extractAppOriginFromTrace($exception);

    $unknownColumn = null;
    if (preg_match("/Unknown column '([^']+)'/i", $exception->getMessage(), $m)) {
        $unknownColumn = $m[1];
    }

    $context['db_error'] = $exception->getMessage();
    $context['db_sql'] = null;
    $context['db_sql_interpolated'] = null;
    $context['db_file'] = $originFile;
    $context['db_line'] = $originLine;
    $context['db_tables'] = [];
    $context['db_unknown_column'] = $unknownColumn;
    $context['db_stack'] = $stack;
    $context['db_params_count'] = null;

    // این بخش هم برای fallback هم اضافه شد
    $context['db_request'] = [
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    return $context;
}



private static function extractAppOriginFromTrace(\Throwable $exception): array
{
    $originFile = null;
    $originLine = null;
    $stack = [];

    foreach ($exception->getTrace() as $t) {
        $file = $t['file'] ?? null;
        $line = $t['line'] ?? null;
        $class = $t['class'] ?? '';
        $fn = $t['function'] ?? '';

        $frame = ($class ? $class . '->' : '') . $fn . '()' . ($line ? ':' . $line : '');

        if ($file) {
            $normalized = str_replace('\\', '/', $file);

            $isAppFrame =
                str_contains($normalized, '/app/') ||
                str_ends_with($normalized, '/cron.php') ||
                str_contains($normalized, '/core/IdempotencyKey.php');

            // فقط فریم‌های کاربردی برای دیباگ محصول
            if ($isAppFrame) {
                $stack[] = $frame;
            }

            // اولین مبدا واقعی خارج از Database
            if (
                $originFile === null &&
                $isAppFrame &&
                !str_contains($normalized, '/core/Database.php')
            ) {
                $originFile = $file;
                $originLine = $line;
            }
        }
    }

    // اگر هیچ app frame پیدا نشد، fallback عمومی
    if (empty($stack)) {
        foreach ($exception->getTrace() as $t) {
            $class = $t['class'] ?? '';
            $fn = $t['function'] ?? '';
            $line = $t['line'] ?? null;
            $stack[] = ($class ? $class . '->' : '') . $fn . '()' . ($line ? ':' . $line : '');
            if (count($stack) >= 12) {
                break;
            }
        }
    }

    return [$originFile, $originLine, array_slice($stack, 0, 12)];
}

    /**
     * ثبت در سیستم لاگ پیشرفته
     */
    private static function logToAdvancedSystem(\Throwable $exception, string $errorCode = 'UNKNOWN', string $traceId = 'UNKNOWN'): void
    {
        try {
            // فقط اگر جداول وجود داشتن
            $db = Database::getInstance();
            
            // بررسی وجود جدول error_logs
            $tableExists = $db->query(
                "SHOW TABLES LIKE 'error_logs'"
            )->fetch();

            if (!$tableExists) {
                return; // جدول نیست، بی‌خیال
            }

            // ثبت مستقیم؛ وابستگی به ErrorLogService حذف شده تا سرویس حذف‌شده برنگردد.
            $level = self::determineErrorLevel($exception);
            $userId = null;
            try {
                $session = Session::getInstance();
                $userId = $session->get('user_id');
            } catch (\Throwable $e) {}

            $db->table('error_logs')->insert([
                'level' => $level,
                'message' => mb_substr($exception->getMessage(), 0, 2000),
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'user_id' => $userId,
                'context' => json_encode([
                    'url' => $_SERVER['REQUEST_URI'] ?? '',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    'error_code' => $errorCode,
                    'trace_id' => $traceId
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Throwable $e) {
            // اگر سیستم لاگ پیشرفته خراب بود، بی‌خیال
            self::fallbackLog('exception.advanced_logging.failed', [
    'message' => $e->getMessage(),
]);
        }
    }

    /**
     * تعیین سطح خطا
     */
    private static function determineErrorLevel(Throwable $exception): string
    {
        $message = $exception->getMessage();

        // خطاهای بحرانی
        if (
            $exception instanceof \Error ||
            $exception instanceof \ParseError ||
            str_contains($message, 'SQLSTATE') ||
            str_contains($message, 'Table') && str_contains($message, "doesn't exist") ||
            str_contains($message, 'Column not found')
        ) {
            return 'CRITICAL';
        }

        // خطاهای مهم
        if (
            str_contains($message, 'Undefined method') ||
            str_contains($message, 'Undefined variable') ||
            str_contains($message, 'Undefined array key')
        ) {
            return 'ERROR';
        }

        return 'WARNING';
    }
    
    /**
     * تبدیل Error به Exception
     */
    public static function handleError(
        int $level,
        string $message,
        string $file = '',
        int $line = 0
    ): bool {
        if (error_reporting() & $level) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }
        
        return false;
    }
    
    /**
     * گرفتن Fatal Errors
     */
    public static function handleShutdown(): void
{
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {

        self::logFatalError($error);

        // ✅ استفاده صحیح از Logger
        try {
            try { \Core\Container::getInstance()->make(\App\Contracts\LoggerInterface::class)->error('Fatal Error: ' . $error['message'], [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => $error['type'],
                ]); } catch(\Throwable $e) {}
        } catch (\Throwable $e) {
            self::fallbackLog('exception.fatal', [
                'message' => $error['message'] ?? null,
                'file' => $error['file'] ?? null,
                'line' => $error['line'] ?? null,
                'type' => $error['type'] ?? null,
            ]);
        }

        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code(500);

        $debug = (bool) config('app.debug', false);
        $isJson = self::isJsonRequest();

        if ($debug) {
            $sanitizedError = self::sanitizeErrorData($error);
            if ($isJson) {
                echo json_encode($sanitizedError, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                echo '<pre>' . e(
                    print_r($sanitizedError, true),
                    ENT_QUOTES,
                    'UTF-8'
                ) . '</pre>';
            }
        } else {
            if ($isJson) {
                echo json_encode(['message' => 'خطای سیستمی'], JSON_UNESCAPED_UNICODE);
            } else {
                echo '<h1>خطای سیستمی</h1><p>لطفاً بعداً تلاش کنید.</p>';
            }
        }

        self::logPerformance();
        exit;
    }

    self::logPerformance();
}
    
    /**
     * ثبت Fatal Error
     */
    private static function logFatalError(array $error): void
    {
        // M27 Fix: استفاده مستقیم از هلپر متمرکز و امن جهت ثبت خطا درون سیستم شخصی سنتری
        $traceInfo = '⚠️ File: ' . ($error['file'] ?? 'Unknown') . ' | Line: ' . ($error['line'] ?? '0') . ' | Type: ' . ($error['type'] ?? 'Fatal');
        $traceId = $_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['REQUEST_ID'] ?? 'unknown';
        self::logToEmergencySentry(
            'FATAL SHUTDOWN: ' . ($error['message'] ?? 'Unknown fatal shutdown event'),
            $traceInfo,
            'FATAL',
            'PHP_FATAL_ERROR'
        );

        // تلاش ثانویه برای درج بلادرنگ در دیتابیس (در صورت برقراری ارتباط)
        try {
            $db = Database::getInstance();
            
            $tableExists = $db->query("SHOW TABLES LIKE 'error_logs'")->fetch();
            if (!$tableExists) return;

            $db->table('error_logs')->insert([
                'level' => 'FATAL',
                'message' => mb_substr((string)$error['message'], 0, 2000),
                'exception_class' => null,
                'file' => $error['file'] ?? null,
                'line' => $error['line'] ?? null,
                'user_id' => null,
                'context' => json_encode([
                    'file' => $error['file'] ?? null,
                    'line' => $error['line'] ?? null,
                    'type' => $error['type'] ?? null,
                    'error_code' => 'PHP_FATAL_ERROR',
                    'trace_id' => $traceId
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // خطا در دیتابیس اهمیتی ندارد چون نسخه فیزیکی در jsonl بالاتر ذخیره شد و در داشبورد بازیابی می‌شود
        }
    }

    /**
     * ثبت Performance
     */
    private static function logPerformance(): void
    {
        try {
            $db = Database::getInstance();
            
            $tableExists = $db->query("SHOW TABLES LIKE 'performance_logs'")->fetch();
            if (!$tableExists) return;

            $endpoint = $_SERVER['REQUEST_URI'] ?? '/';
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $statusCode = http_response_code() ?: 200;

            $userId = null;
            try {
                $session = Session::getInstance();
                $userId = $session->get('user_id');
            } catch (\Throwable $e) {}

            $durationMs = isset($_SERVER['REQUEST_TIME_FLOAT'])
                ? (int)round((microtime(true) - (float)$_SERVER['REQUEST_TIME_FLOAT']) * 1000)
                : null;

            $supportsDetailedSchema = (bool) $db->query("SHOW COLUMNS FROM performance_logs LIKE 'endpoint'")->fetch();

            if ($supportsDetailedSchema) {
                $db->table('performance_logs')->insert([
                    'endpoint' => mb_substr($endpoint, 0, 500),
                    'method' => $method,
                    'status_code' => $statusCode,
                    'user_id' => $userId,
                    'duration_ms' => $durationMs,
                    'memory_peak' => memory_get_peak_usage(true),
                    'request_id' => $_SERVER['REQUEST_ID'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $db->table('performance_logs')->insert([
                    'metric' => 'request_failure',
                    'value' => $durationMs ?? 0,
                    'context' => json_encode([
                        'endpoint' => $endpoint,
                        'method' => $method,
                        'status_code' => $statusCode,
                        'user_id' => $userId,
                        'memory_peak' => memory_get_peak_usage(true),
                        'request_id' => $_SERVER['REQUEST_ID'] ?? null,
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

        } catch (\Throwable $e) {
            // Silent
        }
    }
    
    private static function maskPII(string $text): string
    {
        // 1. Mask Emails (e.g. john.doe@example.com -> [EMAIL_MASKED])
        $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL_MASKED]', $text);

        // 2. Mask Phone Numbers (e.g. Iranian mobile numbers starting with +98 or 09)
        $text = preg_replace('/(?:\+98|0)?9\d{9}/', '[PHONE_MASKED]', $text);

        // 3. Mask IPv4 Addresses
        $text = preg_replace('/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/', '[IP_MASKED]', $text);

        // 4. Mask sensitive parameter values (e.g. password=foo, pin:1234)
        $text = preg_replace('/(password|pass|secret|token|card_number|cvv2|cvv|pin)\s*[:=]\s*[^\s,\'\"]+/i', '$1=[MASKED]', $text);

        return $text;
    }

    /**
     * نمایش صفحه خطا در Debug Mode
     */
    private static function renderDebugPage(Throwable $exception): void
{
    http_response_code(500);

    $isDebug = (bool) config('app.debug', false);

    // [MED-01] Fix: Sanitize exception data to prevent info leakage
    $message = self::maskPII($exception->getMessage());
    $file = $exception->getFile();
    $trace = self::maskPII($exception->getTraceAsString());

    if (!$isDebug) {
        // Fallback safety if someone calls this directly
        self::renderProductionPage($exception);
        return;
    }

    // Mask absolute paths for security
    $baseDir = realpath(__DIR__ . '/../');
    $maskPath = function($path) use ($baseDir) {
        if (empty($path)) {
            return $path;
        }
        $path = str_replace($baseDir, '{ROOT}', $path);
        return preg_replace([
            '/\/home\/[^\/\s]+/',
            '/\/var\/www\/[^\/\s]+/',
            '/C:\\\\Users\\\\[^\/\s\\\\]+/i',
            '/C:\\\\xampp\\\\[^\/\s\\\\]+/i',
            '/\/usr\/share\/[^\/\s]+/',
        ], [
            '/home/{USER}',
            '/var/www/{APP}',
            'C:\\\\Users\\\\{USER}',
            'C:\\\\{APP}',
            '/usr/share/{PKG}',
        ], $path);
    };

    $displayFile = $maskPath($file);
    $displayTrace = $maskPath(mb_substr($trace, 0, 12000));
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>خطای سیستم (Debug Mode)</title>
        <style>
            body { font-family: Tahoma; background: #f5f5f5; padding: 20px; }
            .error-box { background: #fff; border: 3px solid #f44336; border-radius: 8px; padding: 20px; max-width: 900px; margin: 0 auto; }
            h1 { color: #f44336; margin: 0 0 15px; }
            .message { background: #ffebee; padding: 15px; border-radius: 5px; margin: 15px 0; }
            .trace { background: #263238; color: #aed581; padding: 15px; border-radius: 5px; overflow-x: auto; font-family: monospace; font-size: 12px; white-space: pre-wrap; }
            .meta { color: #666; font-size: 13px; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>خطای سیستم</h1>
            <div class="message">
                <strong><?= e(get_class($exception)) ?>:</strong><br>
                <?php
                    $escapedMsg = e($message);
                    echo preg_replace(
                        '/https?:\/\/[^\s<"\']+/', 
                        '<a href="$0" target="_blank" style="color:#e53935; font-weight:bold; text-decoration:underline;">$0</a>', 
                        $escapedMsg
                    );
                ?>
            </div>
            <div class="meta">
                <strong>فایل:</strong> <?= e($displayFile) ?><br>
                <strong>خط:</strong> <?= e((int) $exception->getLine()) ?>
            </div>

            <h3>Stack Trace:</h3>
            <div class="trace"><?= e($displayTrace) ?></div>
        </div>
    </body>
    </html>
    <?php
}

    /**
     * [MED-01] پاکسازی داده‌های حساس از خروجی خطا
     */
    private static function sanitizeErrorData(array $error): array
    {
        $sensitiveKeys = [
            'DB_PASS', 'DB_PASSWORD', 'APP_KEY', 'MAIL_PASSWORD', 
            'REDIS_PASSWORD', 'PASSWORD', 'SECRET'
        ];

        $baseDir = realpath(__DIR__ . '/../');

        $walk = function (&$item, $key) use ($sensitiveKeys, $baseDir) {
            // Mask paths
            if (is_string($item) && str_contains($item, $baseDir)) {
                $item = str_replace($baseDir, '{ROOT}', $item);
            }

            // Mask sensitive values
            foreach ($sensitiveKeys as $sKey) {
                if (is_string($key) && str_contains(strtoupper($key), $sKey)) {
                    $item = '********';
                }
            }

            // Mask PII in error message items
            if (is_string($item)) {
                $item = self::maskPII($item);
            }
        };

        array_walk_recursive($error, $walk);
        return $error;
    }
    
    /**
     * نمایش صفحه خطا در Production
     */
    private static function renderProductionPage(Throwable $exception): void
    {
        http_response_code(500);
        ?>
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>خطای سیستمی</title>
            <style>
                body { font-family: Tahoma; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .error-container { text-align: center; background: #fff; padding: 40px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                h1 { color: #f44336; font-size: 72px; margin: 0; }
                p { color: #666; font-size: 18px; }
                a { display: inline-block; margin-top: 20px; padding: 10px 30px; background: #4fc3f7; color: #fff; text-decoration: none; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class="error-container">
                <h1>500</h1>
                <p>متأسفانه خطای سیستمی رخ داده است</p>
                <p>لطفاً چند لحظه دیگر مجدداً تلاش کنید</p>
                <a href="<?= url('/') ?>">بازگشت به صفحه اصلی</a>
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * بررسی آیا درخواست JSON است
     */
    private static function isJsonRequest(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        return $isAjax || str_contains($accept, 'application/json') || str_contains($contentType, 'application/json');
    }

    /**
     * رندر خطا به صورت JSON (برای استفاده‌های Legacy)
     */
    private static function renderJsonError(\Throwable $exception): void
    {
        $payload = self::getJsonPayloadForException($exception);
        http_response_code($payload['code'] ?? 500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * دریافت ساختار استاندارد خطا بدون بستن پروسه (برای Middleware)
     */
    public static function getJsonPayloadForException(\Throwable $exception): array
    {
        // H26 Fix: پیاده‌سازی تور نجات سراسری برای جلوگیری از کرش‌های بازگشتی (Circular Crash Loop)
        try {
            $contract = \App\Contracts\ErrorContract::internalError('خطای سیستمی');

            if ($exception instanceof \Core\Exceptions\ValidationException) {
                $contract = \App\Contracts\ErrorContract::validation(
                    'داده‌های ورودی نامعتبر',
                    $exception->getErrors()
                );
            } elseif ($exception instanceof \Core\Exceptions\UnauthorizedException) {
                $contract = \App\Contracts\ErrorContract::unauthorized(
                    $exception->getMessage() ?: 'احراز هویت لازم است'
                );
            } elseif ($exception instanceof \Core\Exceptions\SecurityException) {
                if ($exception instanceof \Core\Exceptions\FraudDetectedException) {
                    $contract = \App\Contracts\ErrorContract::fraudDetected(
                        $exception->getMessage() ?: 'فعالیت مشکوک شناسایی شد'
                    );
                } else {
                    $contract = \App\Contracts\ErrorContract::forbidden(
                        $exception->getMessage() ?: 'بررسی امنیتی ناموفق'
                    );
                }
            } elseif ($exception instanceof \Core\Exceptions\NotFoundException) {
                $contract = \App\Contracts\ErrorContract::notFound(
                    $exception->getMessage() ?: 'منبع یافت نشد'
                );
            } elseif ($exception instanceof \Core\Exceptions\InsufficientBalanceException) {
                $contract = \App\Contracts\ErrorContract::insufficientFunds(
                    $exception->getMessage() ?: 'موجودی حساب کافی نیست'
                );
            } elseif ($exception instanceof \Core\Exceptions\RateLimitExceededException) {
                $contract = \App\Contracts\ErrorContract::rateLimited(
                    $exception->getMessage() ?: 'تعداد درخواست‌ها بیش از حد مجاز است'
                );
            } elseif ($exception instanceof \Core\Exceptions\InvalidStateException) {
                $contract = \App\Contracts\ErrorContract::conflict(
                    $exception->getMessage() ?: 'وضعیت درخواست نامعتبر است'
                );
            } elseif ($exception instanceof \App\Exceptions\SessionException) {
                $contract = new \App\Contracts\ErrorContract(
                    419,
                    'SESSION_ERROR',
                    $exception->getMessage() ?: 'نشست کاربری نامعتبر یا منقضی شده است'
                );
            } elseif ($exception instanceof \App\Exceptions\PaymentGatewayException) {
                $code = $exception->getCode() ?: 500;
                $contract = new \App\Contracts\ErrorContract(
                    $code,
                    'PAYMENT_GATEWAY_ERROR',
                    $exception->getMessage() ?: 'خطا در ارتباط با درگاه پرداخت'
                );
                if ($exception instanceof \App\Exceptions\PaymentVerificationException && $exception->getDetails()) {
                    $contract = $contract->withDetails($exception->getDetails());
                }
            } elseif ($exception instanceof \DomainException) {
                $contract = \App\Contracts\ErrorContract::internalError(
                    $exception->getMessage() ?: 'خطای عملیاتی نامعتبر'
                );
                // In ErrorContract, internalError is 500. We need 400 for DomainException
                // Since we might not have a helper for 400 badRequest, we can use a new instance
                $contract = new \App\Contracts\ErrorContract(
                    400,
                    'DOMAIN_LOGIC_ERROR',
                    $exception->getMessage() ?: 'خطای پردازش درخواست'
                );
            } elseif ($exception instanceof \Core\Exceptions\BusinessException) {
                $contract = \App\Contracts\ErrorContract::internalError(
                    $exception->getMessage() ?: 'خطای بیزینسی'
                );
            }

            return $contract->toArray();

        } catch (\Throwable $fallbackException) {
            // تور نجات نهایی در صورتی که ErrorContract یا ماژول‌های خارجی دچار شکست شوند:
            // ساخت خروجی JSON خام بدون هیچ‌گونه وابستگی کلاسی برای تضمین پایداری
            $statusCode = 500;
            $message = 'خطای ناشناخته سیستمی';
            $errors = [];

            if ($exception instanceof \Core\Exceptions\ValidationException) {
                $statusCode = 422;
                $message = 'اعتبارسنجی داده‌ها شکست خورد';
                $errors = $exception->getErrors();
            } elseif ($exception instanceof \Core\Exceptions\UnauthorizedException) {
                $statusCode = 401;
                $message = $exception->getMessage() ?: 'احراز هویت لازم است';
            } elseif ($exception instanceof \Core\Exceptions\SecurityException) {
                $statusCode = 403;
                $message = $exception->getMessage() ?: ($exception instanceof \Core\Exceptions\FraudDetectedException ? 'فعالیت مشکوک شناسایی شد' : 'بررسی امنیتی ناموفق');
            } elseif ($exception instanceof \Core\Exceptions\NotFoundException) {
                $statusCode = 404;
                $message = $exception->getMessage() ?: 'آدرس یا منبع یافت نشد';
            } elseif ($exception instanceof \Core\Exceptions\InsufficientBalanceException) {
                $statusCode = 400;
                $message = $exception->getMessage() ?: 'موجودی حساب کافی نیست';
            } elseif ($exception instanceof \Core\Exceptions\RateLimitExceededException) {
                $statusCode = 429;
                $message = $exception->getMessage() ?: 'تعداد درخواست‌ها بیش از حد مجاز است';
            } elseif ($exception instanceof \Core\Exceptions\InvalidStateException) {
                $statusCode = 409;
                $message = $exception->getMessage() ?: 'وضعیت درخواست نامعتبر است';
            } elseif ($exception instanceof \App\Exceptions\SessionException) {
                $statusCode = 419;
                $message = $exception->getMessage() ?: 'نشست کاربری نامعتبر یا منقضی شده است';
            } elseif ($exception instanceof \App\Exceptions\PaymentGatewayException) {
                $statusCode = $exception->getCode() ?: 500;
                $message = $exception->getMessage() ?: 'خطا در ارتباط با درگاه پرداخت';
            } elseif ($exception instanceof \DomainException) {
                $statusCode = 400;
                $message = $exception->getMessage() ?: 'خطای پردازش درخواست';
            } else {
                $debug = (bool) config('app.debug', false);
                $message = $debug 
                    ? ($exception->getMessage() ?: 'خطای سیستمی در حین پردازش رخ داد') 
                    : 'خطای سیستمی در حین پردازش رخ داد';
            }

            return [
                'success' => false,
                'message' => $message,
                'errors'  => $errors,
                'code'    => $statusCode
            ];
        }
    }
}