<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * BaseAdminController — پایه تمام کنترلرهای پنل مدیریت
 *
 * ─── جریان صحیح ────────────────────────────────────────────────
 *
 *   Container::make(AdminUserController)
 *       └─→ AdminUserController::__construct()     ← بدون پارامتر
 *               └─→ BaseAdminController::__construct()
 *                       └─→ BaseController::__construct()
 *                               └─→ Container: Request, Response, Session
 *                       └─→ requireAuth() + requireAdmin()
 *
 * ─── تذکر ──────────────────────────────────────────────────────
 *   Auth در دو سطح بررسی می‌شود:
 *     ۱. AdminMiddleware  (در Route) — قبل از رسیدن به Controller
 *     ۲. requireAuth/requireAdmin  (اینجا) — لایه دوم اطمینان
 */
abstract class BaseAdminController extends BaseController
{
    /**
     * وابستگی‌ها را پذیرفته و به سازنده والد ارسال می‌کند.
     */
    public function __construct(
        ?\Core\Session $session = null,
        ?\Core\Request $request = null,
        ?\Core\Response $response = null,
        ?\App\Services\Shared\PolicyService $policyService = null,
        ?\App\Contracts\LoggerInterface $logger = null,
        ?\Core\CSRF $csrf = null
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger, $csrf);
        $this->requireAuth();
        $this->requireAdmin();
    }

    /**
     * بررسی معتبر بودن تغییر وضعیت بر اساس ماشین وضعیت (CRIT-08)
     */
    protected function validateStatusTransition(string $from, string $to): bool
    {
        $allowedTransitions = [
            'active' => ['suspended', 'banned'],
            'suspended' => ['active', 'banned'],
            'banned' => ['active'],
            'deleted' => [],
        ];
        
        return in_array($to, $allowedTransitions[$from] ?? [], true);
    }

    /**
     * 🛡️ NEW-17 & NEW-12: ثبت ردپای حسابرسی تغییرات و عملیات حساس ادمین‌ها در دیتابیس
     */
    protected function auditLog(
        string $action, 
        string $entityType, 
        int $entityId, 
        ?array $oldValues, 
        ?array $newValues
    ): void {
        try {
            $oldValues = $this->redactPII($oldValues);
            $newValues = $this->redactPII($newValues);

            $auditTrailService = app(\App\Services\AuditTrail::class);
            $auditTrailService->logAdminAction(
                (int)user_id(),
                $action,
                $entityType,
                $entityId,
                $oldValues,
                $newValues,
                $this->request->ip(),
                $this->request->userAgent() ?: 'unknown',
                session_id() ?: ''
            );
        } catch (\Exception $e) {
            if (isset($this->logger)) {
                $this->logger->error('admin.audit_log.failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * 🛡️ Redact sensitive PII/credential fields from log payload
     */
    private function redactPII(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $piiKeys = [
            'password', 'passwd', 'password_confirmation',
            'token', 'access_token', 'refresh_token', 'csrf_token', 'csrf',
            'card', 'card_number', 'card_num', 'cvv', 'cvv2',
            'pin', 'otp', 'secret', 'key', 'private_key', 'encryption_key',
            'national_code', 'ssn', 'phone', 'mobile'
        ];

        $redacted = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = $this->redactPII($value);
            } elseif (in_array(strtolower((string)$key), $piiKeys, true)) {
                $redacted[$key] = '[REDACTED_PII]';
            } else {
                $redacted[$key] = $value;
            }
        }
        return $redacted;
    }
}

