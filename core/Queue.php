<?php

declare(strict_types=1);

namespace Core;

use DateTime;

/**
 * Queue System - سیستم صف مبتنی بر دیتابیس
 *
 * جدول queues:
 * - id (primary key)
 * - queue (نام صف)
 * - payload (داده JSON)
 * - attempts (تعداد تلاش)
 * - reserved_at (زمان رزرو)
 * - available_at (زمان قابل دسترس)
 * - created_at
 */
class Queue
{
    private Database $db;
    private string $defaultQueue = 'default';
    private int $maxAttempts = 5;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * اضافه کردن job به صف
     */
    public function push(string $job, array $data = [], ?string $queue = null, int $delay = 0): bool
    {
        $queue = $queue ?: $this->defaultQueue;
        $availableAt = $delay > 0 ? time() + $delay : time();

        $result = $this->db->table('queues')->insert([
            'queue' => $queue,
            'payload' => json_encode([
                'job' => $job,
                'data' => $data,
                'meta' => [
                    'correlation_id' => $_SERVER['REQUEST_ID'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? null),
                ],
            ], JSON_UNESCAPED_UNICODE),
            'attempts' => 0,
            'available_at' => date('Y-m-d H:i:s', $availableAt),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return (bool)$result;
    }

    /**
     * Push a job only once for a bounded time-window.
     *
     * این پیاده‌سازی بدون نیاز به migration جدید و با استفاده از Cache atomic counter کار می‌کند.
     * در production اگر Redis موجود نباشد، Core\Cache طبق سیاست fail-closed خطا می‌دهد تا duplicateهای خطرناک ایجاد نشود.
     */
    public function pushUnique(
        string $job,
        array $data = [],
        string $dedupKey = '',
        ?string $queue = null,
        int $delay = 0,
        int $uniqueForSeconds = 86400
    ): bool {
        $queue = $queue ?: $this->defaultQueue;
        $dedupKey = trim($dedupKey);

        if ($dedupKey === '') {
            return $this->push($job, $data, $queue, $delay);
        }

        $cacheKey = 'queue_dedup:' . $queue . ':' . hash('sha256', $dedupKey);
        $count = \Core\Cache::getInstance()->increment($cacheKey, 1, max(60, $uniqueForSeconds));

        if ($count !== 1) {
            if (function_exists('logger')) {
                logger()->info('queue.unique_duplicate_skipped', [
                    'queue' => $queue,
                    'job' => $job,
                    'dedup_key' => $dedupKey,
                ]);
            }
            return false;
        }

        try {
            return $this->push($job, $data, $queue, $delay);
        } catch (\Throwable $e) {
            \Core\Cache::getInstance()->forget($cacheKey);
            throw $e;
        }
    }

    public function pop(?string $queue = null): ?array
    {
        $queue = $queue ?: $this->defaultQueue;

        try {
            $this->db->beginTransaction();

            $nowStr = date('Y-m-d H:i:s');
            // CORE-046: Use configurable visibility timeout from configs, fallback to 90s.
            $visibilityTimeout = (int)config('queue.visibility_timeout', 90);
            $timeoutThreshold = date('Y-m-d H:i:s', time() - $visibilityTimeout);

            // SELECT ... FOR UPDATE قفل امن برای جلوگیری از همپوشانی در سیستم‌های توزیع شده
            // الحاق شرط بازیابی جاب‌های استاک‌شده در وضعیت reserved_at
            $job = $this->db->selectOne(
                "SELECT * FROM queues
                 WHERE queue = :queue
                   AND attempts < :max_attempts
                   AND available_at <= :now
                   AND (reserved_at IS NULL OR reserved_at <= :timeout)
                 ORDER BY created_at ASC
                 LIMIT 1 FOR UPDATE",
                [
                    'queue' => $queue,
                    'max_attempts' => $this->maxAttempts,
                    'now' => $nowStr,
                    'timeout' => $timeoutThreshold
                ]
            );

            if (!$job) {
                $this->db->commit();
                return null;
            }

            $reservedAt = date('Y-m-d H:i:s');
            $newAttempts = (int)$job->attempts + 1;

            $this->db->execute(
                "UPDATE queues
                 SET reserved_at = :reserved_at, attempts = :attempts
                 WHERE id = :id",
                [
                    'reserved_at' => $reservedAt,
                    'attempts' => $newAttempts,
                    'id' => $job->id
                ]
            );

            $this->db->commit();

            $payload = json_decode($job->payload, true) ?? [];

            return [
                'id' => (int)$job->id,
                'job' => $payload['job'] ?? '',
                'data' => $payload['data'] ?? [],
                'meta' => $payload['meta'] ?? [],
                'attempts' => $newAttempts
            ];
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * حذف job از صف (پس از اجرای موفق)
     */
    public function delete(int $id): bool
    {
        $result = $this->db->table('queues')
            ->where('id', '=', $id)
            ->delete();

        return $result > 0;
    }

    /**
     * بازگرداندن job به صف (برای retry)
     */
    public function release(int $id, int $delay = 0): bool
    {
        if ($delay === 0) {
            // Calculate delay based on attempts
            $job = $this->db->selectOne("SELECT attempts FROM queues WHERE id = :id", ['id' => $id]);
            $attempts = $job ? (int)$job->attempts : 1;

            $baseDelay = 60; // 60 seconds
            $exponential = $baseDelay * pow(2, $attempts - 1);
            $jitter = rand(5, 45);
            $delay = (int) min($exponential + $jitter, 14400); // Max 4 hours
        }

        $availableAt = time() + $delay;

        $result = $this->db->table('queues')
            ->where('id', '=', $id)
            ->update([
                'reserved_at' => null,
                'available_at' => date('Y-m-d H:i:s', $availableAt)
            ]);

        return $result > 0;
    }

    /**
     * پاک کردن jobهای قدیمی
     */
    public function clean(int $days = 7): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $this->db->table('queues')
            ->where('created_at', '<', $cutoff)
            ->delete();
    }

    /**
     * شمارش jobهای موجود در صف
     */
    public function size(?string $queue = null): int
    {
        $queue = $queue ?: $this->defaultQueue;

        return (int) $this->db->table('queues')
            ->where('queue', '=', $queue)
            ->count();
    }

    /**
     * انتقال جاب شکست خورده نهایی به Dead Letter Queue (DLQ) و حذف از صف اصلی
     */
    public function fail(int $id, \Throwable $exception): bool
    {
        try {
            $this->db->beginTransaction();

            // ۱. دریافت اطلاعات جاب برای کپی به DLQ
            $job = $this->db->selectOne("SELECT * FROM queues WHERE id = :id", ['id' => $id]);
            if (!$job) {
                $this->db->commit();
                return false;
            }

            // ۲. درج در جدول failed_jobs
            $exceptionStr = get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString();

            // Critical logger call for poison message / DLQ movement
            if (function_exists('logger')) {
                logger()->critical('queue_job_failed_dlq_moved', [
                    'job_id' => $id,
                    'queue' => $job->queue,
                    'payload' => $job->payload,
                    'error' => $exception->getMessage()
                ]);
            }

            $this->db->table('failed_jobs')->insert([
                'queue' => $job->queue,
                'payload' => $job->payload,
                'exception' => $exceptionStr,
                'failed_at' => date('Y-m-d H:i:s')
            ]);

            // ۳. حذف از جدول اصلی صف‌ها
            $this->delete($id);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * دریافت حداکثر تعداد مجاز تلاش‌ها
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    // =========================================================================
    // Section 8.5 / 8.7 — DLQ housekeeping helpers
    // =========================================================================

    /**
     * حذف failed_jobs قدیمی‌تر از $days روز (به‌صورت اختیاری فقط برای یک queue).
     * برای جلوگیری از قفل طولانی، در batchهای کوچک حذف می‌کند.
     *
     * @return int تعداد رکوردهای حذف‌شده
     */
    public function purgeFailedJobsOlderThan(int $days, ?string $queue = null, int $batch = 500): int
    {
        $days  = max(1, min(3650, $days));
        $batch = max(50, min(5000, $batch));

        $totalDeleted = 0;
        $safety = 0;

        while ($safety++ < 1000) { // hard cap: at most 1000 * batch rows in one call
            $sql = "DELETE FROM failed_jobs
                    WHERE failed_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params = [$days];
            if ($queue !== null && $queue !== '') {
                $sql .= " AND queue = ?";
                $params[] = $queue;
            }
            $sql .= " LIMIT " . (int)$batch;

            $deleted = (int) $this->db->execute($sql, $params);
            if ($deleted <= 0) {
                break;
            }
            $totalDeleted += $deleted;
            if ($deleted < $batch) {
                break;
            }
        }
        return $totalDeleted;
    }

    /**
     * شمارش failed_jobs (به‌صورت اختیاری filter بر اساس queue).
     */
    public function countFailedJobs(?string $queue = null): int
    {
        $sql = "SELECT COUNT(*) AS c FROM failed_jobs";
        $params = [];
        if ($queue !== null && $queue !== '') {
            $sql .= " WHERE queue = ?";
            $params[] = $queue;
        }
        $row = $this->db->fetch($sql, $params);
        return (int) ($row->c ?? 0);
    }

    /**
     * بازگرداندن دسته‌ای از failed_jobs به صف اصلی (re-queue).
     * Idempotent از این جهت که هر row فقط یک بار delete می‌شود و در همان transaction push می‌گردد.
     *
     * @param string|null $queue  محدودسازی به یک queue (مثل failed_notifications)
     * @param int $limit حداکثر تعداد retry در یک اجرا
     * @return array{requeued:int, skipped:int, errors:int}
     */
    public function retryFailedJobsBatch(?string $queue = null, int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $stats = ['requeued' => 0, 'skipped' => 0, 'errors' => 0];

        $sql = "SELECT id, queue, payload FROM failed_jobs";
        $params = [];
        if ($queue !== null && $queue !== '') {
            $sql .= " WHERE queue = ?";
            $params[] = $queue;
        }
        $sql .= " ORDER BY failed_at ASC LIMIT " . (int)$limit;

        $rows = $this->db->fetchAll($sql, $params);
        if (empty($rows)) {
            return $stats;
        }

        foreach ($rows as $row) {
            $payload = json_decode((string)$row->payload, true);
            if (!is_array($payload) || empty($payload['job'])) {
                $stats['skipped']++;
                continue;
            }

            try {
                $this->db->beginTransaction();
                $ok = $this->push(
                    (string)$payload['job'],
                    (array)($payload['data'] ?? []),
                    (string)($row->queue ?? $this->defaultQueue)
                );
                if (!$ok) {
                    $this->db->rollBack();
                    $stats['errors']++;
                    continue;
                }
                $this->db->execute("DELETE FROM failed_jobs WHERE id = ?", [(int)$row->id]);
                $this->db->commit();
                $stats['requeued']++;
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $stats['errors']++;
                if (function_exists('logger')) {
                    logger()->warning('queue.failed_retry.failed', [
                        'failed_job_id' => (int)$row->id,
                        'queue'         => $row->queue ?? null,
                        'error'         => $e->getMessage(),
                    ]);
                }
            }
        }
        return $stats;
    }
}
