<?php

declare(strict_types=1);

namespace App\Services\Sentry;

use App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor;
use App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor;
use Core\Logger;
use Core\Session;

/**
 * 🛡️ SentryExceptionHandler - Global Handler برای خطاها
 */
class SentryExceptionHandler
{
    private bool $registered = false;

    public function __construct(
        private SentryErrorMonitor $errorMonitor,
        private SentryPerformanceMonitor $performanceMonitor,
        private Logger $logger,
        private Session $session
    ) {}

    /**
     * 📝 Register - ثبت handlerها
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);

        $this->registered = true;
    }

    /**
     * 🚨 Handle Error
     */
    public function handleError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        $exception = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        
        $level = match($errno) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'error',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
            default => 'info'
        };

        if (in_array($errno, [E_ERROR, E_WARNING, E_USER_ERROR, E_USER_WARNING])) {
            $userId = $this->getCurrentUserId();
            $this->errorMonitor->captureException($exception, $userId, [], $level);
        }

        return false;
    }

    /**
     * 💥 Handle Exception
     */
    public function handleException(\Throwable $exception): void
    {
        try {
            $userId = $this->getCurrentUserId();
            $this->errorMonitor->captureException($exception, $userId, ['http_code' => http_response_code()], 'error');
            $this->displayErrorPage($exception);
        } catch (\Throwable $e) {
            $this->logger->critical('sentry.exception_handler.failed', ['channel' => 'sentry', 'error' => $e->getMessage()]);
            $this->fallbackDisplay($exception);
        }
    }

    /**
     * ⚠️ Handle Shutdown (برای Fatal Errors)
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $exception = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            $userId = $this->getCurrentUserId();
            $this->errorMonitor->captureException($exception, $userId, [], 'fatal');
        }
        $this->finishPerformanceTracking();
    }

    private function finishPerformanceTracking(): void
    {
        try {
            $this->performanceMonitor->finishTransaction([
                'status_code' => http_response_code(),
                'user_id' => $this->getCurrentUserId(),
            ]);
        } catch (\Throwable) {}
    }

    private function displayErrorPage(\Throwable $exception): void
    {
        http_response_code(500);
        $appEnv = config('app.env', 'production');
        $isDebug = (bool)config('app.debug', false);
        
        if ($appEnv === 'production' && !$isDebug) {
            $errorView = dirname(__DIR__, 3) . '/views/errors/500.php';
            if (file_exists($errorView)) {
                include $errorView;
            } else {
                echo '<h1>خطایی رخ داده است</h1><p>لطفاً بعداً تلاش کنید.</p>';
            }
        } else {
            $this->detailedDisplay($exception);
        }
    }

    private function fallbackDisplay(\Throwable $exception): void
    {
        $appEnv = config('app.env', 'production');
        if ($appEnv !== 'production') {
            echo '<h1>Error</h1><p>' . e($exception->getMessage()) . '</p>';
        } else {
            echo '<h1>خطایی رخ داده است</h1><p>لطفاً بعداً تلاش کنید.</p>';
        }
    }

    private function detailedDisplay(\Throwable $exception): void
    {
        $trace = mb_substr($exception->getTraceAsString(), 0, 12000);
        echo '<html><head><title>Error</title><style>body{font-family:sans-serif;padding:20px;background:#f5f5f5;}.error{background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}h1{color:#d32f2f;margin:0 0 10px;}pre{background:#f5f5f5;padding:15px;overflow:auto;}</style></head><body><div class="error">';
        echo '<h1>' . e(get_class($exception)) . '</h1><p>' . e($exception->getMessage()) . '</p>';
        echo '<p><strong>File:</strong> ' . e($exception->getFile()) . ':' . $exception->getLine() . '</p><h3>Stack Trace:</h3><pre>' . e($trace) . '</pre></div></body></html>';
    }

    private function getCurrentUserId(): ?int
    {
        try {
            return $this->session->get('user_id') ? (int)$this->session->get('user_id') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getErrorMonitor(): SentryErrorMonitor
    {
        return $this->errorMonitor;
    }

    public function getPerformanceMonitor(): SentryPerformanceMonitor
    {
        return $this->performanceMonitor;
    }
}

/**
 * 🎯 Helper Functions
 */

function sentry_capture_exception(\Throwable $exception, ?int $userId = null, array $context = []): ?string
{
    $handler = app(SentryExceptionHandler::class);
    return $handler->getErrorMonitor()->captureException($exception, $userId, $context);
}

function sentry_capture_message(string $message, string $level = 'info', ?int $userId = null, array $context = []): ?string
{
    $handler = app(SentryExceptionHandler::class);
    return $handler->getErrorMonitor()->captureMessage($message, $level, $userId, $context);
}

function sentry_add_breadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
{
    $handler = app(SentryExceptionHandler::class);
    $handler->getErrorMonitor()->addBreadcrumb($message, $category, $level, $data);
}

function sentry_start_transaction(string $name, string $op = 'http.request', array $data = []): ?string
{
    $handler = app(SentryExceptionHandler::class);
    return $handler->getPerformanceMonitor()->startTransaction($name, $op, $data);
}

function sentry_start_span(string $op, string $description, array $data = []): string
{
    $handler = app(SentryExceptionHandler::class);
    return $handler->getPerformanceMonitor()->startSpan($op, $description, $data);
}

function sentry_finish_span(string $spanId, array $data = []): void
{
    $handler = app(SentryExceptionHandler::class);
    $handler->getPerformanceMonitor()->finishSpan($spanId, $data);
}

function sentry_track_query(string $query, float $duration, ?array $params = null): void
{
    $handler = app(SentryExceptionHandler::class);
    $handler->getPerformanceMonitor()->trackQuery($query, $duration, $params);
}
