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

    // ─────────────────────────────────────────────────────────────────────────
    // PSR-3 METHODS
    // ─────────────────────────────────────────────────────────────────────────

    public function emergency(string $message, array $context = []): void
    {
        $this->logService->system('emergency', $message, $context, $this->userId);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->logService->system('alert', $message, $context, $this->userId);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->logService->system('critical', $message, $context, $this->userId);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logService->system('error', $message, $context, $this->userId);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logService->system('warning', $message, $context, $this->userId);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->logService->system('notice', $message, $context, $this->userId);
    }

    public function info(string $message, array $context = []): void
    {
        $this->logService->system('info', $message, $context, $this->userId);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->logService->system('debug', $message, $context, $this->userId);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->logService->system($level, $message, $context, $this->userId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EXTENDED METHODS (غیر PSR-3 ولی مفید)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * لاگ سیستمی
     */
    public function system(string $level, string $message, array $context = []): void
    {
        $this->logService->system($level, $message, $context, $this->userId);
    }

    /**
     * لاگ فعالیت کاربر
     */
    public function activity(string $action, string $description, ?int $userId = null, array $metadata = []): void
    {
        $this->logService->activity($action, $description, $userId ?? $this->userId, $metadata);
    }

     /**
     * لاگ امنیتی
     */
    public function security(string $level, string $message, array $context = []): void
    {
        $this->logService->security($level, $message, $context, $this->userId);
    }

    /**
     * لاگ Performance
     */
    public function performance(string $metric, float $value, array $context = []): void
    {
        $this->logService->performance($metric, $value, $context);
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

    // trace فقط در debug و با سقف طول
    $isDebug = !empty($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] !== 'false';
    if ($isDebug) {
        $context['trace'] = mb_substr($e->getTraceAsString(), 0, 8000);
    }

    $this->error($message ?: $e->getMessage(), $context);
}
}

