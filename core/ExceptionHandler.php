<?php

declare(strict_types=1);

namespace Core;

use Throwable;
use ErrorException;
use Core\Exceptions\NotFoundException;
use Core\Exceptions\UnauthorizedException;
use Core\Exceptions\ValidationException;

class ExceptionHandler
{
	
	private static bool $handlingException = false;
    /**
     * ثبت Handler برای خطاها و Exception ها
     */
    public static function register(): void
    {
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
    private static function logToEmergencySentry(string $message, ?string $trace = null, string $level = 'ERROR'): void
    {
        try {
            $emergencyData = [
                'message'   => '🔴 ' . $level . ': ' . $message,
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
    if (self::$handlingException) {
        self::fallbackLog('exception.recursive.detected', [
            'message' => $exception->getMessage(),
        ]);
        
        // M27 Fix: ثبت بلادرنگ خطای تودرتو و بازگشتی درون صف اضطراری جهت جلوگیری از هدر رفت دیباگ
        self::logToEmergencySentry(
            'Recursive Exception Detected: ' . $exception->getMessage(),
            $exception->getTraceAsString(),
            'CRITICAL_RECURSIVE'
        );
        
        http_response_code(500);
        die('critical system error');
    }

    self::$handlingException = true;

    try {
        // ✅ استفاده صحیح از Logger - بدون $this
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
            self::logToAdvancedSystem($exception);
        } catch (\Throwable $e) {
            self::fallbackLog('exception.log_to_advanced_system.failed', [
                'message' => $e->getMessage(),
            ]);
        }

        http_response_code(500);

        $isJsonRequest = self::isJsonRequest();
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
            'HANDLER_COLLAPSE'
        );
        
        http_response_code(500);
        die('system error');
    } finally {
        self::$handlingException = false;
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
    private static function logToAdvancedSystem(\Throwable $exception): void
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

            // استفاده از سرویس
            $errorService = app(\App\Services\ErrorLogService::class);

            // تعیین سطح
            $level = self::determineErrorLevel($exception);

            // دریافت user_id
            $userId = null;
            try {
                $session = Session::getInstance();
                $userId = $session->get('user_id');
            } catch (\Throwable $e) {
                // بی‌خیال
            }

            $errorService->logError(
                $level,
                $exception->getMessage(),
                $exception,
                $userId,
                [
                    'url' => $_SERVER['REQUEST_URI'] ?? '',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? ''
                ]
            );

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
            if (function_exists('logger')) {
                logger()->error('Fatal Error: ' . $error['message'], [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => $error['type'],
                ]);
            }
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
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $isLocal = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;

        $isJson = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && $_SERVER['HTTP_ACCEPT'] === 'application/json');

        if ($debug) {
            if ($isJson) {
                echo json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                echo '<pre>' . e(
                    print_r($error, true),
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
        self::logToEmergencySentry(
            'FATAL SHUTDOWN: ' . ($error['message'] ?? 'Unknown fatal shutdown event'),
            $traceInfo,
            'FATAL'
        );

        // تلاش ثانویه برای درج بلادرنگ در دیتابیس (در صورت برقراری ارتباط)
        try {
            $db = Database::getInstance();
            
            $tableExists = $db->query("SHOW TABLES LIKE 'error_logs'")->fetch();
            if (!$tableExists) return;

            $errorService = app(\App\Services\ErrorLogService::class);

            $errorService->logError(
                'FATAL',
                $error['message'],
                null,
                null,
                [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => $error['type']
                ]
            );
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

            $perfService = app(\App\Services\PerformanceMonitorService::class);

            $endpoint = $_SERVER['REQUEST_URI'] ?? '/';
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $statusCode = http_response_code() ?: 200;

            $userId = null;
            try {
                $session = Session::getInstance();
                $userId = $session->get('user_id');
            } catch (\Throwable $e) {
                // بی‌خیال
            }

            $perfService->logRequest($endpoint, $method, $statusCode, $userId);

        } catch (\Throwable $e) {
            // Silent
        }
    }
    
    /**
     * نمایش صفحه خطا در Debug Mode
     */
   private static function renderDebugPage(Throwable $exception): void
{
    http_response_code(500);

    $isDebug = (bool) config('app.debug', false);

    // محدودسازی trace برای جلوگیری از مصرف زیاد حافظه/HTML حجیم
    $trace = $isDebug ? mb_substr($exception->getTraceAsString(), 0, 12000) : '';
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>خطای سیستم</title>
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
                    // M26 Fix: تبدیل کاملاً امن تمام لینک‌های متنی موجود در متن خطا به لینک‌های فعال و قابل کلیک در صفحه دیباگ
                    $escapedMsg = e($exception->getMessage());
                    echo preg_replace(
                        '/https?:\/\/[^\s<"\']+/', 
                        '<a href="$0" target="_blank" style="color:#e53935; font-weight:bold; text-decoration:underline;">$0</a>', 
                        $escapedMsg
                    );
                ?>
            </div>
            <div class="meta">
                <strong>فایل:</strong> <?= e($exception->getFile()) ?><br>
                <strong>خط:</strong> <?= e((int) $exception->getLine()) ?>
            </div>

            <?php if ($isDebug): ?>
                <h3>Stack Trace:</h3>
                <div class="trace"><?= e($trace) ?></div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
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
     * رندر خطا به صورت JSON
     */
    private static function renderJsonError(\Throwable $exception): void
    {
        // H26 Fix: پیاده‌سازی تور نجات سراسری برای جلوگیری از کرش‌های بازگشتی (Circular Crash Loop)
        try {
            $contract = \App\Services\ErrorContract::internalError('خطای سیستمی');

            if ($exception instanceof \Core\Exceptions\ValidationException) {
                $contract = \App\Services\ErrorContract::validation(
                    'داده‌های ورودی نامعتبر',
                    $exception->getErrors()
                );
            } elseif ($exception instanceof \Core\Exceptions\UnauthorizedException) {
                $contract = \App\Services\ErrorContract::unauthorized(
                    $exception->getMessage() ?: 'احراز هویت لازم است'
                );
            } elseif ($exception instanceof \Core\Exceptions\NotFoundException) {
                $contract = \App\Services\ErrorContract::notFound(
                    $exception->getMessage() ?: 'منبع یافت نشد'
                );
            } elseif ($exception instanceof \Core\Exceptions\BusinessException) {
                $contract = \App\Services\ErrorContract::internalError(
                    $exception->getMessage() ?: 'خطای بیزینسی'
                );
            }

            http_response_code($contract->getStatusCode());
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($contract->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;

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
            } elseif ($exception instanceof \Core\Exceptions\NotFoundException) {
                $statusCode = 404;
                $message = $exception->getMessage() ?: 'آدرس یا منبع یافت نشد';
            } else {
                $message = $exception->getMessage() ?: 'خطای سیستمی در حین پردازش رخ داد';
            }

            http_response_code($statusCode);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }

            echo json_encode([
                'success' => false,
                'message' => $message,
                'errors'  => $errors,
                'code'    => $statusCode
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            
            exit;
        }
    }
}