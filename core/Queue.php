<?php

declare(strict_types=1);

namespace Core;

use DateTime;

/**
 * Queue System - سیستم صف دوگانه‌سوز (مبتنی بر Redis با Fallback به دیتابیس)
 *
 * جدول queues (برای حالت دیتابیس):
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

    // تنظیمات و کلاینت Redis
    private ?\Redis $redis = null;
    private bool $useRedis = false;
    private string $redisPrefix = 'chortke:queue';

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->initRedis();
    }

    /**
     * راه‌اندازی اتصال Redis در صورت تنظیم درایور به redis
     */
    private function initRedis(): void
    {
        try {
            $driver = config('queue.driver', 'database');
            if ($driver === 'redis') {
                $cache = \Core\Cache::getInstance();
                if ($cache->driver() === 'redis') {
                    $this->redis = $cache->redis();
                    $this->useRedis = $this->redis !== null;
                    $prefix = config('redis.prefix', 'chortke');
                    $this->redisPrefix = "{$prefix}:queue";
                }
            }
        } catch (\Throwable $e) {
            $this->useRedis = false;
            if (function_exists('logger')) {
                logger()->warning('queue.redis_init_failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * اضافه کردن job به صف
     */
    private function resolveQueueName(string $job, ?string $queue = null): string
    {
        if ($queue !== null && $queue !== '') {
            return $queue;
        }

        $jobClass = trim($job, '\\');

        $mapping = [
            // notifications
            'App\\Jobs\\SendEmailJob' => 'notifications',
            'App\\Jobs\\SendBulkNotificationJob' => 'notifications',
            'App\\Jobs\\PersistBulkInAppNotificationJob' => 'notifications',
            
            // analytics
            'App\\Jobs\\AggregateAnalyticsJob' => 'analytics',
            'App\\Jobs\\ScoreRecalculationJob' => 'analytics',
            'App\\Jobs\\LogPerformanceJob' => 'analytics',
            'App\\Jobs\\UpdateFraudScoreJob' => 'analytics',
            
            // maintenance
            'App\\Jobs\\EscrowTimeoutJob' => 'maintenance',
            'App\\Jobs\\CacheWarmupJob' => 'maintenance',
            'App\\Jobs\\NotificationCleanupJob' => 'maintenance',
            'App\\Jobs\\VitrineListingExpiryJob' => 'maintenance',
            'App\\Jobs\\InfluencerOrderTimeoutJob' => 'maintenance',
            'App\\Jobs\\SocialTaskApprovalReminderJob' => 'maintenance',
            'App\\Jobs\\PredictionGameSettlementJob' => 'maintenance',
            
            // high_priority
            'App\\Jobs\\ApplyWeeklyProfitLossJob' => 'high_priority',
            'App\\Jobs\\InvestmentProfitDistributionJob' => 'high_priority',
        ];

        return $mapping[$jobClass] ?? $this->defaultQueue;
    }

    /**
     * اضافه کردن job به صف
     */
    public function push(string $job, array $data = [], ?string $queue = null, int $delay = 0): bool
    {
        $queue = $this->resolveQueueName($job, $queue);
        $availableAt = $delay > 0 ? time() + $delay : time();

        // 🚀 Real-time delta-buffering to eliminate propagation delay/race conditions during async updates!
        if (trim($job, '\\') === 'App\\Jobs\\UpdateFraudScoreJob') {
            $userId = (int)($data['user_id'] ?? 0);
            $delta  = (float)($data['delta'] ?? 0);
            $domain = (string)($data['domain'] ?? 'fraud');
            
            if ($userId > 0 && $delta !== 0.0) {
                try {
                    $cache = \Core\Cache::getInstance();
                    $tempKey = "temp_{$domain}_score:{$userId}";
                    $cache->incrementFloat($tempKey, $delta);
                    $cache->forget("user_score:{$userId}:{$domain}");
                } catch (\Throwable $e) {
                    if (function_exists('logger')) {
                        logger()->warning('queue.delta_buffer.failed', [
                            'user_id' => $userId,
                            'domain' => $domain,
                            'delta' => $delta,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        // اگر Redis فعال باشد، تلاش برای درج در صف Redis
        if ($this->useRedis) {
            try {
                // دریافت شناسه عددی و اتمیک یکتا برای سازگاری کامل با شناسه دیتابیس
                $jobId = (int) $this->redis->incr("{$this->redisPrefix}:job_id_counter");

                $payload = [
                    'job' => $job,
                    'data' => $data,
                    'meta' => [
                        'correlation_id' => $_SERVER['REQUEST_ID'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? null),
                    ],
                ];

                $jobData = [
                    'id' => $jobId,
                    'queue' => $queue,
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'attempts' => 0,
                    'available_at' => $availableAt,
                    'created_at' => time(),
                    'reserved_at' => null,
                ];

                // ذخیره متادیتا با انقضای ۷ روز
                $this->redis->setEx("{$this->redisPrefix}:job:{$jobId}", 86400 * 7, json_encode($jobData));

                // اضافه کردن به صف مرتب‌شده (Pending Sorted Set) با اسکور زمان دسترسی
                $this->redis->zAdd("{$this->redisPrefix}:{$queue}:pending", $availableAt, (string)$jobId);

                return true;
            } catch (\Throwable $e) {
                if (function_exists('logger')) {
                    logger()->error('queue.redis.push_failed_falling_back', [
                        'job' => $job,
                        'queue' => $queue,
                        'error' => $e->getMessage(),
                    ]);
                }
                // در صورت بروز خطا در Redis، به صف دیتابیس سوئیچ می‌کند
            }
        }

        // سیستم صف مبتنی بر دیتابیس (Fallback)
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
        $queue = $this->resolveQueueName($job, $queue);
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

    /**
     * برداشتن جاب از صف با پشتیبانی از اولویت‌بندی
     */
    public function pop(?string $queue = null): ?array
    {
        if ($queue === null) {
            $priorities = ['high_priority', 'default', 'notifications', 'analytics', 'maintenance'];
            foreach ($priorities as $q) {
                $job = $this->popSingleQueue($q);
                if ($job) {
                    return $job;
                }
            }
            return null;
        }

        return $this->popSingleQueue($queue);
    }

    /**
     * برداشتن جاب از یک صف مشخص
     */
    private function popSingleQueue(string $queue): ?array
    {
        if ($this->useRedis) {
            try {
                $now = time();
                $visibilityTimeout = (int)config('queue.visibility_timeout', 90);
                $reservedUntil = $now + $visibilityTimeout;

                // ۱. مکانیزم Self-healing خودکار برای جاب‌های منقضی شده در وضعیت reserved
                // بازگرداندن جاب‌هایی که زمان رزروشان سر رسیده است به صف آماده جهت جلوگیری از استاک شدن
                $expiredScript = <<<LUA
                    local pendingKey = KEYS[1]
                    local reservedKey = KEYS[2]
                    local now = tonumber(ARGV[1])
                    
                    local expiredJobs = redis.call('zRangeByScore', reservedKey, 0, now)
                    for i, jobId in ipairs(expiredJobs) do
                        redis.call('zRem', reservedKey, jobId)
                        redis.call('zAdd', pendingKey, now, jobId)
                    end
                    return #expiredJobs
LUA;
                $this->redis->eval(
                    $expiredScript,
                    ["{$this->redisPrefix}:{$queue}:pending", "{$this->redisPrefix}:{$queue}:reserved", $now],
                    2
                );

                // ۲. برداشتن اتمیک جاب آماده به کمک اسکریپت لونا و انتقال آن به لیست رزرو شده
                $popScript = <<<LUA
                    local pendingKey = KEYS[1]
                    local reservedKey = KEYS[2]
                    local now = tonumber(ARGV[1])
                    local reservedUntil = tonumber(ARGV[2])

                    local jobs = redis.call('zRangeByScore', pendingKey, 0, now, 'LIMIT', 0, 1)
                    if #jobs > 0 then
                        local jobId = jobs[1]
                        redis.call('zRem', pendingKey, jobId)
                        redis.call('zAdd', reservedKey, reservedUntil, jobId)
                        return jobId
                    end
                    return nil
LUA;
                $jobId = $this->redis->eval(
                    $popScript,
                    ["{$this->redisPrefix}:{$queue}:pending", "{$this->redisPrefix}:{$queue}:reserved", $now, $reservedUntil],
                    2
                );

                if (!$jobId) {
                    return null;
                }

                $jobId = (int)$jobId;

                // ۳. خواندن اطلاعات کامل جاب
                $jobDataJson = $this->redis->get("{$this->redisPrefix}:job:{$jobId}");
                if (!$jobDataJson) {
                    // در صورت از بین رفتن متادیتا، جاب را از لیست رزرو شده حذف کرده و تلاش مجدد می‌کنیم
                    $this->redis->zRem("{$this->redisPrefix}:{$queue}:reserved", (string)$jobId);
                    return $this->popSingleQueue($queue);
                }

                $jobData = json_decode($jobDataJson, true);
                $newAttempts = (int)($jobData['attempts'] ?? 0) + 1;

                // به‌روزرسانی تعداد تلاش‌ها و زمان رزرو
                $jobData['attempts'] = $newAttempts;
                $jobData['reserved_at'] = $now;
                $this->redis->setEx("{$this->redisPrefix}:job:{$jobId}", 86400 * 7, json_encode($jobData));

                $payload = json_decode($jobData['payload'], true) ?? [];

                return [
                    'id' => $jobId,
                    'job' => $payload['job'] ?? '',
                    'data' => $payload['data'] ?? [],
                    'meta' => $payload['meta'] ?? [],
                    'attempts' => $newAttempts
                ];
            } catch (\Throwable $e) {
                if (function_exists('logger')) {
                    logger()->error('queue.redis.pop_failed_falling_back', [
                        'queue' => $queue,
                        'error' => $e->getMessage(),
                    ]);
                }
                // در صورت بروز هر خطایی، به عنوان Fallback سراغ دیتابیس می‌رود
            }
        }

        // حالت دیتابیس (Standard Mode / Fallback)
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
        if ($this->useRedis) {
            try {
                $jobDataJson = $this->redis->get("{$this->redisPrefix}:job:{$id}");
                if ($jobDataJson) {
                    $jobData = json_decode($jobDataJson, true);
                    $queue = $jobData['queue'] ?? 'default';

                    $this->redis->zRem("{$this->redisPrefix}:{$queue}:pending", (string)$id);
                    $this->redis->zRem("{$this->redisPrefix}:{$queue}:reserved", (string)$id);
                    $this->redis->del("{$this->redisPrefix}:job:{$id}");
                    return true;
                }
            } catch (\Throwable $e) {
                if (function_exists('logger')) {
                    logger()->error('queue.redis.delete_failed', [
                        'id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // دیتابیس
        $result = $this->db->table('queues')
            ->where('id', '=', $id)
            ->delete();

        return $result > 0;
    }

    /**
     * بازگرداندن job به صف (برای retry با تاخیر نمایی)
     */
    public function release(int $id, int $delay = 0): bool
    {
        if ($this->useRedis) {
            try {
                $jobDataJson = $this->redis->get("{$this->redisPrefix}:job:{$id}");
                if ($jobDataJson) {
                    $jobData = json_decode($jobDataJson, true);
                    $queue = $jobData['queue'] ?? 'default';
                    $attempts = (int)($jobData['attempts'] ?? 1);

                    if ($delay === 0) {
                        $baseDelay = 60; // 60 seconds
                        $exponential = $baseDelay * pow(2, $attempts - 1);
                        $jitter = rand(5, 45);
                        $delay = (int) min($exponential + $jitter, 14400); // Max 4 hours
                    }

                    $availableAt = time() + $delay;

                    $jobData['reserved_at'] = null;
                    $jobData['available_at'] = $availableAt;

                    // به‌روزرسانی متادیتا و بازگرداندن از reserved به pending با امتیاز زمان جدید
                    $this->redis->setEx("{$this->redisPrefix}:job:{$id}", 86400 * 7, json_encode($jobData));
                    $this->redis->zRem("{$this->redisPrefix}:{$queue}:reserved", (string)$id);
                    $this->redis->zAdd("{$this->redisPrefix}:{$queue}:pending", $availableAt, (string)$id);

                    return true;
                }
            } catch (\Throwable $e) {
                if (function_exists('logger')) {
                    logger()->error('queue.redis.release_failed', [
                        'id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // دیتابیس
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
     * پاک کردن jobهای قدیمی دیتابیس (فقط برای حالت دیتابیس لازم است؛ در Redis انقضا خودکار است)
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

        if ($this->useRedis) {
            try {
                $pending = $this->redis->zCard("{$this->redisPrefix}:{$queue}:pending");
                $reserved = $this->redis->zCard("{$this->redisPrefix}:{$queue}:reserved");
                return (int)($pending + $reserved);
            } catch (\Throwable $e) {
                if (function_exists('logger')) {
                    logger()->error('queue.redis.size_failed', [
                        'queue' => $queue,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

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

            $queueName = 'default';
            $payloadStr = '';

            // ۱. دریافت اطلاعات جاب
            if ($this->useRedis) {
                try {
                    $jobDataJson = $this->redis->get("{$this->redisPrefix}:job:{$id}");
                    if ($jobDataJson) {
                        $jobData = json_decode($jobDataJson, true);
                        $queueName = $jobData['queue'] ?? 'default';
                        $payloadStr = $jobData['payload'] ?? '';
                    }
                } catch (\Throwable $e) {
                    if (function_exists('logger')) {
                        logger()->error('queue.redis.fail_read_failed', ['id' => $id, 'error' => $e->getMessage()]);
                    }
                }
            }

            if (empty($payloadStr)) {
                // تلاش برای خواندن از جدول queues دیتابیس
                $job = $this->db->selectOne("SELECT * FROM queues WHERE id = :id", ['id' => $id]);
                if (!$job) {
                    $this->db->commit();
                    return false;
                }
                $queueName = $job->queue;
                $payloadStr = $job->payload;
            }

            // ۲. درج در جدول failed_jobs (DLQ متمرکز)
            $exceptionStr = get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString();

            // لاگ کردن رویداد به عنوان یک خطا در poison message
            if (function_exists('logger')) {
                logger()->critical('queue_job_failed_dlq_moved', [
                    'job_id' => $id,
                    'queue' => $queueName,
                    'payload' => $payloadStr,
                    'error' => $exception->getMessage()
                ]);
            }

            $this->db->table('failed_jobs')->insert([
                'queue' => $queueName,
                'payload' => $payloadStr,
                'exception' => $exceptionStr,
                'failed_at' => date('Y-m-d H:i:s')
            ]);

            // ۳. حذف از صف اصلی
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
    // Section 8.5 / 8.7 — DLQ housekeeping helpers (سازگار با هر دو حالت صف)
    // =========================================================================

    /**
     * حذف failed_jobs قدیمی‌تر از $days روز
     */
    public function purgeFailedJobsOlderThan(int $days, ?string $queue = null, int $batch = 500): int
    {
        $days  = max(1, min(3650, $days));
        $batch = max(50, min(5000, $batch));

        $totalDeleted = 0;
        $safety = 0;

        while ($safety++ < 1000) {
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
     * شمارش failed_jobs
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
     * بازگرداندن دسته‌ای از failed_jobs به صف اصلی (re-queue)
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

    /**
     * دریافت آمار لحظه‌ای از وضعیت تمام صف‌های دارای اولویت و DLQ
     */
    public function getQueueStatusReport(): array
    {
        $queues = ['high_priority', 'default', 'notifications', 'analytics', 'maintenance'];
        $report = [];

        foreach ($queues as $q) {
            $total = 0;
            $pending = 0;
            $delayed = 0;
            $running = 0;
            $failed = 0;

            if ($this->useRedis) {
                try {
                    $now = time();
                    $pendingCount = (int)$this->redis->zCount("{$this->redisPrefix}:{$q}:pending", "-inf", (string)$now);
                    $delayedCount = (int)$this->redis->zCount("{$this->redisPrefix}:{$q}:pending", "(" . $now, "+inf");
                    $runningCount = (int)$this->redis->zCard("{$this->redisPrefix}:{$q}:reserved");

                    $total = $pendingCount + $delayedCount + $runningCount;
                    $pending = $pendingCount;
                    $delayed = $delayedCount;
                    $running = $runningCount;

                    $failedRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM failed_jobs WHERE queue = :queue", ['queue' => $q]);
                    $failed = (int)($failedRow->c ?? 0);
                } catch (\Throwable $e) {
                    if (function_exists('logger')) {
                        logger()->error('queue.redis.status_report_failed', ['queue' => $q, 'error' => $e->getMessage()]);
                    }
                    $this->useRedis = false; // سوئیچ به دیتابیس به عنوان Fallback
                }
            }

            if (!$this->useRedis) {
                $totalRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM queues WHERE queue = :queue", ['queue' => $q]);
                $total = (int)($totalRow->c ?? 0);

                $pendingRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM queues WHERE queue = :queue AND available_at <= NOW() AND reserved_at IS NULL", ['queue' => $q]);
                $pending = (int)($pendingRow->c ?? 0);

                $delayedRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM queues WHERE queue = :queue AND available_at > NOW() AND reserved_at IS NULL", ['queue' => $q]);
                $delayed = (int)($delayedRow->c ?? 0);

                $runningRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM queues WHERE queue = :queue AND reserved_at IS NOT NULL", ['queue' => $q]);
                $running = (int)($runningRow->c ?? 0);

                $failedRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM failed_jobs WHERE queue = :queue", ['queue' => $q]);
                $failed = (int)($failedRow->c ?? 0);
            }

            $report[$q] = [
                'total_jobs' => $total,
                'pending_jobs' => $pending,
                'delayed_jobs' => $delayed,
                'running_jobs' => $running,
                'failed_jobs' => $failed,
                'status' => $running > 10 ? 'congested' : ($total > 0 ? 'active' : 'idle')
            ];
        }

        // آمار کلی شکست‌ها
        $totalFailedRow = $this->db->selectOne("SELECT COUNT(*) AS c FROM failed_jobs");
        $totalFailed = (int)($totalFailedRow->c ?? 0);

        $report['meta'] = [
            'total_failed_dlq' => $totalFailed,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        return $report;
    }
}
