<?php

declare(strict_types=1);

namespace App\Middleware;

use Closure;
use App\Services\AntiFraud\FraudGuardService;
use App\Contracts\LoggerInterface;

/**
 * TaskFraudGuardMiddleware
 * 
 * میان‌افزاری برای اعمال گیت ضدتقلب روی درخواست‌های درون‌سرویسی (مخصوصاً Social Tasks).
 * با استفاده از Core\Pipeline امکان ماژولار کردن این بررسی‌ها فراهم شده است.
 */
class TaskFraudGuardMiddleware
{
    private FraudGuardService $fraudGuard;
    private LoggerInterface $logger;
    public function __construct(
        FraudGuardService $fraudGuard,
        LoggerInterface $logger
    ) {        $this->fraudGuard = $fraudGuard;
        $this->logger = $logger;
}

    /**
     * @param array $payload آرایه شامل user_id, action, context
     * @param Closure $next
     * @return array
     */
    public function handle(array $payload, Closure $next): array
    {
        $userId = $payload['user_id'] ?? 0;
        $action = $payload['action'] ?? 'task.social';
        $context = $payload['context'] ?? [];

        if ($userId <= 0) {
            return ['success' => false, 'message' => 'شناسه کاربر نامعتبر است'];
        }

        $risk = $this->fraudGuard->checkAction($userId, $action, $context);

        if (!$risk['allowed']) {
            $this->logger->warning('fraud_guard_middleware.blocked', [
                'user_id' => $userId,
                'action'  => $action,
                'reason'  => $risk['reason']
            ]);
            return ['success' => false, 'message' => 'امکان اجرای عملیات به دلیل محدودیت مسدود شد: ' . $risk['reason']];
        }

        return $next($payload);
    }
}
