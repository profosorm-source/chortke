<?php

declare(strict_types=1);

namespace Core;

use Throwable;

/**
 * Transaction Wrapper - مدیریت ایمن تراکنش‌های دیتابیس
 *
 * استفاده:
 * TransactionWrapper::run(function($db) {
 *     // عملیات دیتابیس
 *     $db->query(...);
 *     return $result;
 * });
 */
class TransactionWrapper
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * اجرای عملیات در تراکنش ایمن
     *
     * @param callable $operation عملیات مورد نظر
     * @return mixed نتیجه عملیات
     * @throws Throwable اگر عملیات شکست خورد
     */
    public function run(callable $operation)
    {
        $this->db->beginTransaction();

        try {
            $result = $operation($this->db);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * اجرای عملیات در تراکنش ایمن با retry
     *
     * @param callable $operation عملیات مورد نظر
     * @param int $maxRetries حداکثر تعداد تلاش مجدد
     * @return mixed نتیجه عملیات
     * @throws Throwable اگر عملیات شکست خورد
     */
    public function runWithRetry(callable $operation, int $maxRetries = 3): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->run($operation);
            } catch (Throwable $e) {
                $lastException = $e;

                // CORE-054: Enforce fail-safe transient error checking. Fast abort on non-transient errors.
                if (!$this->isTransientDatabaseError($e) || $attempt === $maxRetries) {
                    throw $e;
                }

                // Apply a short exponential backoff wait (between 100ms and 300ms base) before trying again
                usleep(100000 * $attempt);
            }
        }

        throw $lastException;
    }

    /**
     * CORE-054: Identify transient database lock/deadlock faults safe to retry
     */
    private function isTransientDatabaseError(Throwable $e): bool
    {
        $message = $e->getMessage();
        
        // Check if message explicitly notes deadlock or lock timeout
        if (stripos($message, 'Deadlock found') !== false || stripos($message, 'Lock wait timeout') !== false) {
            return true;
        }

        if ($e instanceof \PDOException) {
            // SQLSTATE 40001 indicates serialization failures/deadlocks
            if ($e->getCode() === '40001') {
                return true;
            }

            // Evaluate MySQL-specific numeric error codes (1205, 1213)
            $errorInfo = $e->errorInfo ?? [];
            $driverCode = (int)($errorInfo[1] ?? 0);
            if (in_array($driverCode, [1205, 1213], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * CORE-NEW: ترکیب قدرتمند Idempotency و Transaction با قابلیت Retry
     *
     * @param \Core\IdempotencyKey $idempotency اینستنس آیدمپوتنسی
     * @param string $key کلید منحصر به فرد تراکنش
     * @param int $userId شناسه کاربر
     * @param string $action نام اکشن بیزینسی
     * @param callable $operation عملیات اصلی برای اجرا
     * @param int $maxRetries تعداد تلاش مجدد برای خطاهای دیتابیس
     * @return mixed خروجی عملیات
     */
    public function runIdempotentWithRetry(
        IdempotencyKey $idempotency,
        string $key,
        int $userId,
        string $action,
        callable $operation,
        int $maxRetries = 3
    ) {
        // اجرای Idempotency Wrap. خود این متد یکبار عملیات را تضمین می‌کند
        return $idempotency->wrapInstance($key, $userId, $action, function () use ($operation, $maxRetries) {
            // عملیات واقعی داخل Transaction با قابلیت Retry اجرا می‌شود
            return $this->runWithRetry($operation, $maxRetries);
        });
    }
}