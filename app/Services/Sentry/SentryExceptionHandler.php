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
    private static ?self $instance = null;
    private bool $registered = false;

    private SentryErrorMonitor $errorMonitor;
    private SentryPerformanceMonitor $performanceMonitor;
    private Logger $logger;
    private Session $session;
    public function __construct(
        SentryErrorMonitor $errorMonitor,
        SentryPerformanceMonitor $performanceMonitor,
        Logger $logger,
        Session $session
    ) {        $this->errorMonitor = $errorMonitor;
        $this->performanceMonitor = $performanceMonitor;
        $this->logger = $logger;
        $this->session = $session;
}

    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('SentryExceptionHandler instance has not been initialized.');
        }
        return self::$instance;
    }

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
            if (!$this->isCircuitOpen()) {
                try {
                    $userId = $this->getCurrentUserId();
                    $this->errorMonitor->captureException($exception, $userId, [], $level);
                } catch (\Throwable $e) {
                    $this->recordFailure();
                }
            }
        }

        return true;
    }

    /**
     * 💥 Handle Exception
     */
    public function handleException(\Throwable $exception): void
    {
        try {
            $userId = $this->getCurrentUserId();
            if (!$this->isCircuitOpen()) {
                $this->errorMonitor->captureException($exception, $userId, ['http_code' => http_response_code()], 'error');
            }
            $this->displayErrorPage($exception);
        } catch (\Throwable $e) {
            $this->recordFailure();
            $this->logger->critical('sentry.exception_handler.failed', ['channel' => 'sentry', 'error' => $e->getMessage()]);
            $this->fallbackDisplay($exception);
        }
    }

    /**
     * ⚠️ Handle Shutdown (برای Fatal Errors)
     */
    public function handleShutdown(): void
    {
        @ignore_user_abort(true);
        @set_time_limit(10);
        
        try {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $exception = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
                $userId = $this->getCurrentUserId();
                if (!$this->isCircuitOpen()) {
                    $this->errorMonitor->captureException($exception, $userId, [], 'fatal');
                }
            }
        } catch (\Throwable $e) {
            $this->recordFailure();
            // Defensive logging to standard error fallback during shutdown
            @error_log('Sentry Shutdown capture failed: ' . $e->getMessage());
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
        $basePath = realpath(dirname(__DIR__, 3));
        if ($basePath) {
            $trace = str_replace($basePath, '[ROOT]', $trace);
        }
        
        $file = $exception->getFile();
        if ($basePath) {
            $file = str_replace($basePath, '[ROOT]', $file);
        }

        echo '<html><head><title>Error</title><style>body{font-family:sans-serif;padding:20px;background:#f5f5f5;}.error{background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}h1{color:#d32f2f;margin:0 0 10px;}pre{background:#f5f5f5;padding:15px;overflow:auto;}</style></head><body><div class="error">';
        echo '<h1>' . e(get_class($exception)) . '</h1><p>' . e($exception->getMessage()) . '</p>';
        echo '<p><strong>File:</strong> ' . e($file) . ':' . $exception->getLine() . '</p><h3>Stack Trace:</h3><pre>' . e($trace) . '</pre></div></body></html>';
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

    private function isCircuitOpen(): bool
    {
        $file = sys_get_temp_dir() . '/sentry_circuit_breaker.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['failures'] >= 5 && (time() - $data['last_failure']) < 60) {
                return true;
            }
            if ($data && (time() - $data['last_failure']) >= 60) {
                @unlink($file); // Reset circuit after 60s
            }
        }
        return false;
    }

    private function recordFailure(): void
    {
        $file = sys_get_temp_dir() . '/sentry_circuit_breaker.json';
        $failures = 1;
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $failures = $data['failures'] + 1;
            }
        }
        file_put_contents($file, json_encode(['failures' => $failures, 'last_failure' => time()]));
    }

    /**
     * 🎯 Encapsulated Static Helper Interfaces for System Logging
     */

    public static function captureException(\Throwable $exception, ?int $userId = null, array $context = []): ?string
    {
        $handler = self::getInstance();
        if ($handler->isCircuitOpen()) return null;
        try {
            return $handler->getErrorMonitor()->captureException($exception, $userId, $context);
        } catch (\Throwable $e) {
            $handler->recordFailure();
            return null;
        }
    }

    public static function captureMessage(string $message, string $level = 'info', ?int $userId = null, array $context = []): ?string
    {
        $handler = self::getInstance();
        if ($handler->isCircuitOpen()) return null;
        try {
            return $handler->getErrorMonitor()->captureMessage($message, $level, $userId, $context);
        } catch (\Throwable $e) {
            $handler->recordFailure();
            return null;
        }
    }

    public static function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
        $handler = self::getInstance();
        if ($handler->isCircuitOpen()) return;
        try {
            $handler->getErrorMonitor()->addBreadcrumb($message, $category, $level, $data);
        } catch (\Throwable $e) {
            $handler->recordFailure();
        }
    }

    public static function startTransaction(string $name, string $op = 'http.request', array $data = []): ?string
    {
        $handler = self::getInstance();
        if ($handler->isCircuitOpen()) return null;
        try {
            return $handler->getPerformanceMonitor()->startTransaction($name, $op, $data);
        } catch (\Throwable $e) {
            $handler->recordFailure();
            return null;
        }
    }

    public static function startSpan(string $op, string $description, array $data = []): string
    {
        $handler = self::getInstance();
        if ($handler->isCircuitOpen()) return '';
        try {
            return $handler->getPerformanceMonitor()->startSpan($op, $description, $data);
        } catch (\Throwable $e) {
            $handler->recordFailure();
            return '';
        }
    }

    public static function finishSpan(string $spanId, array $data = []): void
    {
        $handler = self::getInstance();
        if ($handler->isCircuitOpen()) return;
        try {
            $handler->getPerformanceMonitor()->finishSpan($spanId, $data);
        } catch (\Throwable $e) {
            $handler->recordFailure();
        }
    }

    public static function trackQuery(string $query, float $duration, ?array $params = null): void
    {
        $handler = self::getInstance();
        if ($handler->isCircuitOpen()) return;
        try {
            $handler->getPerformanceMonitor()->trackQuery($query, $duration, $params);
        } catch (\Throwable $e) {
            $handler->recordFailure();
        }
    }
}


