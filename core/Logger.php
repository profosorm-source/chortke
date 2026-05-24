<?php

declare(strict_types=1);

namespace Core;

use App\Contracts\LoggerInterface;
use App\Services\LogService;

/**
 * Logger — Facade/Wrapper (PSR-3 Compatible)
 *
 * این کلاس فقط یک wrapper ساده است که همه کال‌ها را به LogService می‌فرستد
 * هیچ منطقی اینجا نیست - فقط delegate کردن
 *
 * استفاده:
 *   - در سرویس‌ها: DI injection
 *   - در کنترلرها: DI injection
 *   - متدهای اضافی: system(), activity(), audit(), security(), performance()
 */
class Logger implements LoggerInterface
{
    private LogService $logService;
    private ?int $userId = null;
    private array $defaultContext = [];

    public function __construct(LogService $logService)
{
    $this->logService = $logService;
}

    /**
     * تنظیم User ID برای لاگ‌های بعدی
     */
    public function setUserId(?int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }


    public function withContext(array $context): self
    {
        $this->defaultContext = array_merge($this->defaultContext, $context);
        return $this;
    }

    private function enrich(array $context): array
    {
        $traceContext = function_exists('get_trace_context') ? get_trace_context() : [
            'trace_id' => $_SERVER['HTTP_X_TRACE_ID'] ?? ($_SERVER['REQUEST_ID'] ?? null),
            'span_id' => $_SERVER['HTTP_X_SPAN_ID'] ?? null,
            'parent_span_id' => $_SERVER['HTTP_X_PARENT_SPAN_ID'] ?? null,
        ];

        return array_merge(
            [
                'request_id' => $_SERVER['REQUEST_ID'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? null),
                'trace_id' => $traceContext['trace_id'] ?? null,
                'span_id' => $traceContext['span_id'] ?? null,
                'parent_span_id' => $traceContext['parent_span_id'] ?? null,
            ],
            $this->defaultContext,
            $context
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PSR-3 METHODS
    // ─────────────────────────────────────────────────────────────────────────

    public function emergency(string $message, array $context = []): void
    {
        $this->logService->logSystem('emergency', $message, $this->enrich($context));
    }

    public function alert(string $message, array $context = []): void
    {
        $this->logService->logSystem('alert', $message, $this->enrich($context));
    }

    public function critical(string $message, array $context = []): void
    {
        $this->logService->logSystem('critical', $message, $this->enrich($context));
    }

    public function error(string $message, array $context = []): void
    {
        $this->logService->logSystem('error', $message, $this->enrich($context));
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logService->logSystem('warning', $message, $this->enrich($context));
    }

    public function notice(string $message, array $context = []): void
    {
        $this->logService->logSystem('notice', $message, $this->enrich($context));
    }

    public function info(string $message, array $context = []): void
    {
        $this->logService->logSystem('info', $message, $this->enrich($context));
    }

    public function debug(string $message, array $context = []): void
    {
        $this->logService->logSystem('debug', $message, $this->enrich($context));
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->logService->logSystem($level, $message, $this->enrich($context));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EXTENDED METHODS (غیر PSR-3 ولی مفید)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * لاگ سیستمی
     */
    public function system(string $level, string $message, array $context = []): void
    {
        $this->logService->logSystem($level, $message, $this->enrich($context));
    }

    /**
     * لاگ فعالیت کاربر
     */
    public function activity(string $action, string $description, ?int $userId = null, array $metadata = []): void
    {
        $this->logService->logActivity($action, $description, $userId ?? $this->userId, $this->enrich($metadata));
    }

     /**
     * لاگ امنیتی
     */
    public function security(string $level, string $message, array $context = []): void
    {
        $this->logService->logSecurity($context['type'] ?? 'security', $message, $level, $this->enrich($context));
    }

    /**
     * لاگ Performance
     */
    public function performance(string $metric, float $value, array $context = []): void
    {
        $this->logService->logPerformance($metric, $value, $this->enrich($context));
    }

    /**
 * لاگ Exception
 */
public function exception(\Throwable $e, string $message = '', array $context = []): void
{
    $context['exception'] = get_class($e);
    $context['exception_message'] = $e->getMessage();
    $context['file'] = $e->getFile();
    $context['line'] = $e->getLine();

    // M25 Fix: ثبت ردپا (Trace) خطاها حتی در پروداکشن جهت دیباگ اصولی حوادث در اولین فرصت
    // اما در حالت عملیاتی طول کمتری (مثلاً ۲۵۰۰ کاراکتر) را ذخیره می‌کنیم تا دیتابیس سنگین نشود
    $isDebug = (bool) config('app.debug', false);
    $traceLimit = $isDebug ? 8000 : 2500;
    $context['trace'] = mb_substr($e->getTraceAsString(), 0, $traceLimit);

    $this->error($message ?: $e->getMessage(), $context);
}
}

