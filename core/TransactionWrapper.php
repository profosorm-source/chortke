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

                // اگر آخرین تلاش بود، exception را throw کن
                if ($attempt === $maxRetries) {
                    throw $e;
                }

                // در غیر این صورت، retry کن
                usleep(100000 * $attempt); // exponential backoff
            }
        }

        throw $lastException;
    }
}