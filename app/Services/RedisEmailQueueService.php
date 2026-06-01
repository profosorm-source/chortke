<?php

namespace App\Services;

use Core\Cache;

use App\Contracts\LoggerInterface;
use App\Contracts\MetricsCollectorInterface;

/**
 * Redis Email Queue Service
 * 
 * مدیریت صف ایمیل با Redis + Fallback به Database
 * - در Redis: سریع، atomic، بدون فشار به DB
 * - در Database: فقط برای آرشیو و گزارش‌گیری
 */
class RedisEmailQueueService
{
    private ?\Redis $redisClient = null;
    private bool $useRedis = false;
    private string $queueKey = 'email:queue';
    private string $processingKey = 'email:processing';
    private string $metaPrefix = 'email:meta:';
private MetricsCollectorInterface $metrics;

    private \Core\Cache $cache;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        MetricsCollectorInterface $metrics
    )
    {        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;

                $this->metrics = $metrics;
        $this->redisClient = $this->cache->redis();
        $this->useRedis = $this->cache->driver() === 'redis';

        $prefix = config('redis.prefix', 'chortke');
        $this->queueKey = "{$prefix}:email:queue";
        $this->processingKey = "{$prefix}:email:processing";
        $this->metaPrefix = "{$prefix}:email:meta:";
    }

    /**
     * اضافه کردن ایمیل به صف
     * 
     * @param array $emailData ['to', 'subject', 'body', 'priority', 'user_id', etc.]
     * @return bool|string ایمیل ID در صورت موفقیت
     */
    public function push(array $emailData): bool|string
    {
        $emailId = $this->generateEmailId();
        $priority = $this->getPriorityScore($emailData['priority'] ?? 'normal');
        $scheduledAt = $emailData['scheduled_at'] ?? time();

        $payload = [
            'id' => $emailId,
            'to' => $emailData['to'],
            'subject' => $emailData['subject'],
            'body' => $emailData['body'],
            'priority' => $emailData['priority'] ?? 'normal',
            'user_id' => $emailData['user_id'] ?? null,
            'template' => $emailData['template'] ?? null,
            'variables' => $emailData['variables'] ?? [],
            'attempts' => 0,
            'status' => 'pending',
            'created_at' => time(),
            'scheduled_at' => $scheduledAt,
        ];

        if ($this->useRedis) {
            try {
                // ذخیره metadata
                $this->redisClient->setEx(
                    $this->metaPrefix . $emailId,
                    86400 * 7, // 7 days TTL
                    json_encode($payload)
                );

                // اضافه به صف با اولویت و زمان‌بندی (زمان‌بندی اولویت بالاتر دارد تا کارهای آینده زودتر برداشته نشوند)
                $score = ($scheduledAt * 10) + $priority;
                $this->redisClient->zAdd($this->queueKey, $score, $emailId);

                $this->logger->info('email.redis.queued', [
                    'email_id' => $emailId,
                    'priority' => $priority,
                    'scheduled_at' => $scheduledAt,
                ]);

                $this->trackQueueDepth();
                return $emailId;
            } catch (\Throwable $e) {
                $this->logger->error('email.redis.queue.failed', [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $dbResult = $this->fallbackToDatabase($payload);
                if ($dbResult) {
                    $this->trackQueueDepth();
                    return $dbResult;
                }
                $fileResult = $this->fallbackToFile($payload);
                $this->trackQueueDepth();
                return $fileResult;
            }
        }

        $dbResult = $this->fallbackToDatabase($payload);
        if ($dbResult) {
            $this->trackQueueDepth();
            return $dbResult;
        }
        $fileResult = $this->fallbackToFile($payload);
        $this->trackQueueDepth();
        return $fileResult;
    }

    /**
     * دریافت ایمیل‌های آماده ارسال (Atomic Pop using Lua)
     */
    public function pop(int $limit = 10): array
    {
        // 🚀 Self-healing recovery: recover any file-based fallbacks first
        $this->recoverFileFallbacks();

        if ($this->useRedis) {
            try {
                $now = time();
                $maxScore = ($now * 10) + 9;

                // 🚀 BUG-02 Fix: Atomic Pop using Lua Script
                $script = <<<LUA
                    local items = redis.call('ZRANGEBYSCORE', KEYS[1], 0, ARGV[1], 'LIMIT', 0, ARGV[2])
                    for i, id in ipairs(items) do
                        redis.call('ZREM', KEYS[1], id)
                        redis.call('SADD', KEYS[2], id)
                    end
                    return items
LUA;

                $emailIds = $this->redisClient->eval($script, [$this->queueKey, $this->processingKey, $maxScore, $limit], 2);

                if (empty($emailIds)) {
                    return [];
                }

                $emails = [];
                foreach ($emailIds as $emailId) {
                    $data = $this->redisClient->get($this->metaPrefix . $emailId);
                    if ($data) {
                        $email = json_decode($data, true);
                        
                        // بررسی تعداد تلاش (حداکثر ۵ بار تلاش مجدد مجاز است)
                        if ($email['attempts'] < 5) {
                            $emails[] = $email;
                        } else {
                            $this->redisClient->sRem($this->processingKey, $emailId);
                            $this->markAsFailed($emailId, 'Max attempts reached during pop');
                        }
                    } else {
                        // Metadata گم شده - پاکسازی از processing
                        $this->redisClient->sRem($this->processingKey, $emailId);
                    }
                }

                $this->trackQueueDepth();
                return $emails;
            } catch (\Throwable $e) {
                $this->logger->error('email.redis.pop.failed', ['error' => $e->getMessage()]);
                $dbEmails = $this->fallbackGetFromDatabase($limit);
                $this->trackQueueDepth();
                return $dbEmails;
            }
        }

        $dbEmails = $this->fallbackGetFromDatabase($limit);
        $this->trackQueueDepth();
        return $dbEmails;
    }

    /**
     * تلاش برای "تصاحب" یک ایمیل خاص (Claim)
     * مخصوص SendEmailJob جهت جلوگیری از تداخل با processQueue
     * 🚀 BUG-01 Fix
     */
    public function claim(string $emailId): bool
    {
        if (str_starts_with($emailId, 'file_')) {
            $realId = str_replace('file_', '', $emailId);
            $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(dirname(__DIR__));
            $file = $basePath . '/storage/logs/email_fallback_queue/' . $realId . '.json';
            if (file_exists($file)) {
                return true;
            }

            $this->logger->warning('email.file.claim_missing', ['email_id' => $emailId, 'file' => $file]);
            return false;
        }

        if (!$this->useRedis) {
            try {
                $this->db->beginTransaction();
                $id = str_replace('db_', '', $emailId);
                
                // SELECT FOR UPDATE to lock the row atomically
                $row = $this->db->selectOne(
                    "SELECT status FROM email_queue WHERE id = ? FOR UPDATE",
                    [$id]
                );
                
                if ($row && $row->status === 'pending') {
                    $this->db->execute(
                        "UPDATE email_queue SET status = 'sending', updated_at = NOW() WHERE id = ?",
                        [$id]
                    );
                    $this->db->commit();
                    return true;
                }
                
                $this->db->commit();
                return false;
            } catch (\Throwable $e) {
                $this->db->rollback();
                $this->logger->error('email.database.claim.failed', ['email_id' => $emailId, 'error' => $e->getMessage()]);
                return false;
            }
        }

        try {
            // به صورت اتمیک سعی میکنیم از صف حذف و به پردازش اضافه کنیم
            $script = <<<LUA
                if redis.call('ZREM', KEYS[1], ARGV[1]) == 1 then
                    redis.call('SADD', KEYS[2], ARGV[1])
                    return 1
                end
                return 0
LUA;
            return (bool) $this->redisClient->eval($script, [$this->queueKey, $this->processingKey, $emailId], 2);
        } catch (\Throwable $e) {
            $this->logger->error('email.redis.claim.failed', ['email_id' => $emailId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * علامت‌گذاری به عنوان ارسال شده
     */
    public function markAsSent(string $emailId): bool
    {
        if (str_starts_with($emailId, 'file_')) {
            $realId = str_replace('file_', '', $emailId);
            $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(dirname(__DIR__));
            $file = $basePath . '/storage/logs/email_fallback_queue/' . $realId . '.json';
            if (file_exists($file)) {
                @unlink($file);
                $this->metrics->increment('email.send.success');
                $this->trackQueueDepth();
                return true;
            }

            $this->logger->warning('email.file.mark_sent_missing', ['email_id' => $emailId, 'file' => $file]);
            $this->trackQueueDepth();
            return false;
        }

        if ($this->useRedis) {
            try {
                // حذف از processing
                $this->redisClient->sRem($this->processingKey, $emailId);
                
                // به‌روزرسانی metadata
                $data = $this->redisClient->get($this->metaPrefix . $emailId);
                if ($data) {
                    $email = json_decode($data, true);
                    $email['status'] = 'sent';
                    $email['sent_at'] = time();
                    
                    // ذخیره در DB برای آرشیو
                    $this->archiveToDatabase($email);
                    
                    // حذف از Redis (دیگر نیازی نیست)
                    $this->redisClient->del($this->metaPrefix . $emailId);
                }

                $this->metrics->increment('email.send.success');
                $this->trackQueueDepth();
                $this->logger->info('email.redis.sent_archived', [
                    'email_id' => $emailId,
                ]);
                return true;
            } catch (\Throwable $e) {
                $this->logger->error('email.redis.mark_sent.failed', [
                    'email_id' => $emailId ?? null,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                return false;
            }
        }

        $success = $this->fallbackMarkAsSentInDatabase($emailId);
        if ($success) {
            $this->metrics->increment('email.send.success');
        }
        $this->trackQueueDepth();
        return $success;
    }

    /**
     * علامت‌گذاری به عنوان ناموفق (با Exponential Backoff)
     * 🚀 BUG-05 Fix
     */
    public function markAsFailed(string $emailId, string $error): bool
    {
        $this->metrics->increment('email.send.failure');
        if (str_starts_with($emailId, 'file_')) {
            $realId = str_replace('file_', '', $emailId);
            $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(dirname(__DIR__));
            $file = $basePath . '/storage/logs/email_fallback_queue/' . $realId . '.json';
            if (file_exists($file)) {
                $content = @file_get_contents($file);
                if ($content) {
                    $email = json_decode($content, true);
                    $email['attempts']++;
                    $email['error_message'] = $error;
                    
                    if ($email['attempts'] >= 5) {
                        @unlink($file);
                        try {
                            $this->db->execute(
                                "INSERT INTO email_dlq (email_id, payload, reason, created_at) VALUES (?, ?, ?, NOW())",
                                [$emailId, json_encode($email), $error]
                            );
                        } catch (\Throwable $dlqError) {
                            $this->logger->error('email.dlq.db_failed', ['error' => $dlqError->getMessage()]);
                        }
                    } else {
                        $delay = 60 * pow(2, $email['attempts'] - 1) + rand(0, 30);
                        $delay = min($delay, 3600);
                        $email['scheduled_at'] = time() + $delay;
                        @file_put_contents($file, json_encode($email, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    }
                    $this->trackQueueDepth();
                    return true;
                }
                $this->logger->warning('email.file.mark_failed_invalid', ['email_id' => $emailId, 'file' => $file]);
            } else {
                $this->logger->warning('email.file.mark_failed_missing', ['email_id' => $emailId, 'file' => $file]);
            }
            $this->trackQueueDepth();
            return false;
        }

        if ($this->useRedis) {
            try {
                // حذف از processing
                $this->redisClient->sRem($this->processingKey, $emailId);
                
                $data = $this->redisClient->get($this->metaPrefix . $emailId);
                if ($data) {
                    $email = json_decode($data, true);
                    $email['attempts']++;
                    $email['error_message'] = $error;

                    if ($email['attempts'] >= 5) {
                        // 🚀 Dead Letter Queue (DLQ)
                        $email['status'] = 'failed';
                        $email['failed_at'] = time();
                        
                        // 1. Archive to main queue table
                        $this->archiveToDatabase($email);
                        
                        // 2. Send to dedicated DLQ table for auditing
                        try {
                            $this->db->execute(
                                "INSERT INTO email_dlq (email_id, payload, reason, created_at) VALUES (?, ?, ?, NOW())",
                                [$emailId, json_encode($email), $error]
                            );
                        } catch (\Throwable $dlqError) {
                            $this->logger->error('email.dlq.db_failed', ['error' => $dlqError->getMessage()]);
                        }

                        // 3. Push to Redis LIST for fast monitoring
                        try {
                            $this->redisClient->rPush('email:dlq', json_encode($email));
                            $this->redisClient->lTrim('email:dlq', -10000, -1);
                        } catch (\Throwable $redisError) {
                            $this->logger->error('email.dlq.redis_failed', ['error' => $redisError->getMessage()]);
                        }

                        $this->redisClient->del($this->metaPrefix . $emailId);
                        
                        $this->logger->warning("Email moved to DLQ after max attempts: {$emailId}", ['error' => $error]);
                    } else {
                        // 🚀 Exponential Backoff with jitter
                        $delay = 60 * pow(2, $email['attempts'] - 1) + rand(0, 30);
                        $delay = min($delay, 3600);
                        
                        $email['status'] = 'pending';
                        $this->redisClient->setEx(
                            $this->metaPrefix . $emailId,
                            86400 * 7,
                            json_encode($email)
                        );
                        
                        $priority = $this->getPriorityScore($email['priority']);
                        $nextRun = time() + $delay;
                        $score = ($nextRun * 10) + $priority; 
                        $this->redisClient->zAdd($this->queueKey, $score, $emailId);
                        
                        $this->logger->info('email.redis.retry_scheduled', [
                            'email_id' => $emailId,
                            'attempt' => $email['attempts'],
                            'delay_seconds' => $delay
                        ]);
                    }
                }

                $this->trackQueueDepth();
                return true;
            } catch (\Throwable $e) {
                $this->logger->error('email.redis.mark_failed.error', ['error' => $e->getMessage()]);
                $this->trackQueueDepth();
                return false;
            }
        }

        $res = $this->fallbackMarkAsFailedInDatabase($emailId, $error);
        $this->trackQueueDepth();
        return $res;
    }

    /**
     * آمار صف
     */
    public function getStats(): array
    {
        if ($this->useRedis) {
            try {
                return [
                    'pending' => (int) $this->redisClient->zCard($this->queueKey),
                    'processing' => (int) $this->redisClient->sCard($this->processingKey),
                    'driver' => 'redis'
                ];
            } catch (\Throwable $e) {
                $this->logger->error('email.redis.stats.error', ['error' => $e->getMessage()]);
            }
        }

        return $this->fallbackGetStatsFromDatabase();
    }

    /**
     * بازگرداندن پیام‌های یتیم (Orphaned) به صف
     * 🚀 BUG-03 Fix: Visibility Timeout
     * پیام‌هایی که بیش از 10 دقیقه در processing مانده‌اند بازگردانده می‌شوند.
     */
    public function requeueOrphans(): int
    {
        if (!$this->useRedis) return 0;

        try {
            $emailIds = $this->redisClient->sMembers($this->processingKey) ?? [];
            $requeued = 0;
            $now = time();

            foreach ($emailIds as $emailId) {
                $data = $this->redisClient->get($this->metaPrefix . $emailId);
                if ($data) {
                    $email = json_decode($data, true);
                    // اگه بیش از 600 ثانیه (10 دقیقه) در حال پردازش بوده
                    // فرض بر این است که worker کرش کرده
                    if (isset($email['updated_at']) && ($now - $email['updated_at'] > 600)) {
                        $this->redisClient->sRem($this->processingKey, $emailId);
                        
                        $priority = $this->getPriorityScore($email['priority'] ?? 'normal');
                        $score = ($now * 10) + $priority;
                        $this->redisClient->zAdd($this->queueKey, $score, $emailId);
                        $requeued++;
                    }
                } else {
                    // پیام بدون متا - حذف از پردازش
                    $this->redisClient->sRem($this->processingKey, $emailId);
                }
            }

            if ($requeued > 0) {
                $this->logger->info('email.redis.orphans_requeued', ['count' => $requeued]);
            }

            return $requeued;
        } catch (\Throwable $e) {
            $this->logger->error('email.redis.requeue_orphans.failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * پاکسازی ایمیل‌های قدیمی از Redis
     */
    public function cleanup(): int
    {
        $this->requeueOrphans();

        if ($this->useRedis) {
            try {
                $cleaned = 0;

                // پاکسازی ایمیل‌های خیلی قدیمی (بیش از 7 روز)
                $pattern = $this->metaPrefix . '*';
                $cursor = null;

                do {
                    $keys = $this->redisClient->scan($cursor, $pattern, 100);
                    if ($keys) {
                        foreach ($keys as $key) {
                            $ttl = $this->redisClient->ttl($key);
                            if ($ttl < 0) { // منقضی شده
                                $this->redisClient->del($key);
                                $cleaned++;
                            }
                        }
                    }
                } while ($cursor > 0);

                $this->logger->info('email.redis.cleanup.completed', [
                    'channel' => 'email',
                    'cleaned' => $cleaned,
                ]);

                return $cleaned;
            } catch (\Throwable $e) {
                $this->logger->error('email.redis.cleanup.failed', [
                    'channel' => 'email',
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return 0;
            }
        }

        return 0;
    }

    // ─────────────────────────────────────────────────
    //  Private Helpers
    // ─────────────────────────────────────────────────

    private function generateEmailId(): string
    {
        return uniqid('email_', true) . '_' . bin2hex(random_bytes(4));
    }

    private function getPriorityScore(string $priority): int
    {
        return match($priority) {
            'urgent' => 1,
            'high' => 2,
            'normal' => 3,
            'low' => 4,
            default => 3,
        };
    }

    // ─────────────────────────────────────────────────
    //  Database Fallback Methods
    // ─────────────────────────────────────────────────

    private function fallbackToDatabase(array $payload): bool|string
    {
        try {
            $db = $this->db;
            
            $result = $db->execute(
                "INSERT INTO email_queue 
                (user_id, to_email, subject, body, template, variables, priority, status, scheduled_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())",
                [
                    $payload['user_id'],
                    $payload['to'],
                    $payload['subject'],
                    $payload['body'],
                    $payload['template'],
                    json_encode($payload['variables']),
                    $payload['priority'],
                    date('Y-m-d H:i:s', $payload['scheduled_at'])
                ]
            );

            if ($result) {
                return 'db_' . $db->lastInsertId();
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('email.database.queue.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function fallbackGetFromDatabase(int $limit): array
    {
        try {
            $this->db->beginTransaction();
            $now = date('Y-m-d H:i:s');
            $maxAttempts = 5;

            // Select and lock the rows
            $emails = $this->db->fetchAll(
                "SELECT * FROM email_queue
                 WHERE status = 'pending'
                   AND attempts < :max_attempts
                   AND (scheduled_at IS NULL OR scheduled_at <= :now)
                 ORDER BY
                   CASE priority
                     WHEN 'urgent' THEN 1
                     WHEN 'high'   THEN 2
                     WHEN 'normal' THEN 3
                     ELSE 4
                   END ASC,
                   created_at ASC
                 LIMIT :limit FOR UPDATE",
                ['now' => $now, 'max_attempts' => $maxAttempts, 'limit' => $limit]
            );

            if (empty($emails)) {
                $this->db->commit();
                return [];
            }

            // Atomic reserve: set status to 'sending'
            $ids = [];
            foreach ($emails as $email) {
                $ids[] = $email->id;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $this->db->execute(
                "UPDATE email_queue SET status = 'sending', updated_at = NOW() WHERE id IN ($placeholders)",
                $ids
            );

            $this->db->commit();

            // Convert DB objects to standard email arrays to match Redis pop output structure
            $result = [];
            foreach ($emails as $email) {
                $result[] = [
                    'id' => 'db_' . $email->id,
                    'to' => $email->to_email,
                    'subject' => $email->subject,
                    'body' => $email->body,
                    'priority' => $email->priority,
                    'user_id' => $email->user_id,
                    'template' => $email->template,
                    'variables' => json_decode($email->variables, true) ?? [],
                    'attempts' => (int)$email->attempts,
                    'status' => 'sending',
                    'created_at' => strtotime($email->created_at),
                    'scheduled_at' => strtotime($email->scheduled_at),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logger->error('email.database.get.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function fallbackMarkAsSentInDatabase(string $emailId): bool
    {
        try {
            $db = $this->db;
            $id = str_replace('db_', '', $emailId);
            
            return $db->execute(
                "UPDATE email_queue SET status = 'sent', sent_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$id]
            ) !== false;
        } catch (\Throwable $e) {
            $this->logger->error('email.database.mark_sent.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function fallbackMarkAsFailedInDatabase(string $emailId, string $error): bool
    {
        try {
            $db = $this->db;
            $id = str_replace('db_', '', $emailId);
            
            $row = $db->selectOne("SELECT attempts, user_id, to_email, subject, body, template, variables, priority FROM email_queue WHERE id = ?", [$id]);
            if (!$row) {
                return false;
            }
            
            $attempts = (int)$row->attempts + 1;
            $maxAttempts = 5;
            
            if ($attempts >= $maxAttempts) {
                // Write to DLQ table
                try {
                    $payload = [
                        'user_id' => $row->user_id,
                        'to' => $row->to_email,
                        'subject' => $row->subject,
                        'body' => $row->body,
                        'template' => $row->template,
                        'variables' => json_decode($row->variables, true) ?? [],
                        'priority' => $row->priority,
                        'attempts' => $attempts,
                        'status' => 'failed'
                    ];
                    $db->execute(
                        "INSERT INTO email_dlq (email_id, payload, reason, created_at) VALUES (?, ?, ?, NOW())",
                        [$emailId, json_encode($payload), $error]
                    );
                } catch (\Throwable $dlqError) {
                    $this->logger->error('email.dlq.db_failed', ['error' => $dlqError->getMessage()]);
                }
                
                $db->execute(
                    "UPDATE email_queue
                     SET attempts = ?,
                         status = 'failed',
                         error_message = ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$attempts, $error, $id]
                );
            } else {
                $delay = 60 * pow(2, $attempts - 1) + rand(0, 30);
                $delay = min($delay, 3600);
                $scheduledAt = date('Y-m-d H:i:s', time() + $delay);
                
                $db->execute(
                    "UPDATE email_queue
                     SET attempts = ?,
                         status = 'pending',
                         scheduled_at = ?,
                         error_message = ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$attempts, $scheduledAt, $error, $id]
                );
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('email.database.mark_failed.error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function fallbackToFile(array $payload): string|bool
    {
        try {
            $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(dirname(__DIR__));
            $dir = $basePath . '/storage/logs/email_fallback_queue';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                $this->logger->error('email.file.mkdir_failed', ['dir' => $dir]);
                return false;
            }
            $filePath = $dir . '/' . $payload['id'] . '.json';
            $success = file_put_contents($filePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            if ($success !== false) {
                $this->logger->warning('email.file.fallback_queued', [
                    'email_id' => $payload['id'],
                    'filePath' => $filePath
                ]);
                return 'file_' . $payload['id'];
            }
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('email.file.fallback_failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function recoverFileFallbacks(): int
    {
        try {
            $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(dirname(__DIR__));
            $dir = $basePath . '/storage/logs/email_fallback_queue';
            if (!is_dir($dir)) {
                return 0;
            }

            $files = glob($dir . '/*.json');
            if (empty($files)) {
                return 0;
            }

            $recovered = 0;
            foreach ($files as $file) {
                $content = @file_get_contents($file);
                if (!$content) {
                    continue;
                }

                $payload = json_decode($content, true);
                if (!$payload || !isset($payload['to'])) {
                    @unlink($file); // Invalid file
                    continue;
                }

                $emailData = [
                    'to' => $payload['to'],
                    'subject' => $payload['subject'],
                    'body' => $payload['body'],
                    'priority' => $payload['priority'] ?? 'normal',
                    'user_id' => $payload['user_id'] ?? null,
                    'template' => $payload['template'] ?? null,
                    'variables' => $payload['variables'] ?? [],
                    'scheduled_at' => $payload['scheduled_at'] ?? time(),
                ];

                $result = $this->push($emailData);
                if ($result && !str_starts_with((string)$result, 'file_')) {
                    @unlink($file);
                    $recovered++;
                }
            }

            if ($recovered > 0) {
                $this->logger->info('email.file.recovered', ['count' => $recovered]);
            }

            return $recovered;
        } catch (\Throwable $e) {
            $this->logger->error('email.file.recovery_failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function fallbackGetStatsFromDatabase(): array
    {
        try {
            $db = $this->db;
            $rows = $db->fetchAll("SELECT status, COUNT(*) as cnt FROM email_queue GROUP BY status");
            
            $stats = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'driver' => 'database'];
            foreach ($rows as $r) {
                $r = (array)$r;
                $stats[$r['status']] = (int)$r['cnt'];
            }
            return $stats;
        } catch (\Throwable $e) {
            $this->logger->error('email.database.stats.error', ['error' => $e->getMessage()]);
            return ['driver' => 'database'];
        }
    }

    private function archiveToDatabase(array $email): void
    {
        try {
            $db = $this->db;
            
            $db->execute(
                "INSERT INTO email_queue 
                (user_id, to_email, subject, body, template, variables, priority, status, attempts, sent_at, error_message, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE status = VALUES(status), sent_at = VALUES(sent_at), updated_at = NOW()",
                [
                    $email['user_id'],
                    $email['to'],
                    $email['subject'],
                    $email['body'],
                    $email['template'] ?? null,
                    json_encode($email['variables'] ?? []),
                    $email['priority'],
                    $email['status'],
                    $email['attempts'],
                    isset($email['sent_at']) ? date('Y-m-d H:i:s', $email['sent_at']) : null,
                    $email['error_message'] ?? null,
                    date('Y-m-d H:i:s', $email['created_at'])
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('email.database.archive.failed', ['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────
    //  Admin Panel Methods
    // ─────────────────────────────────────────────────

    /**
     * دریافت ایمیل‌های صف برای Admin Panel
     * 
     * @param int $page
     * @param int $perPage
     * @param string|null $status - فیلتر: pending, sent, failed, processing
     * @param string|null $search - جستجو در: to_email, subject
     * @return array
     */
    public function getEmailsForAdmin(
        int $page = 1,
        int $perPage = 30,
        ?string $status = null,
        ?string $search = null
    ): array {
        try {
            $db = $this->db;
            $offset = ($page - 1) * $perPage;

            // ساخت WHERE clause
            $where = [];
            $params = [];

            if ($status) {
                $where[] = "status = ?";
                $params[] = $status;
            }

            if ($search) {
                $where[] = "(to_email LIKE ? OR subject LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

            // دریافت کل تعداد
            $totalResult = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM email_queue $whereClause",
                $params
            );
            $total = $totalResult['cnt'] ?? 0;

            // دریافت ایمیل‌های صفحه جاری
            $emails = $db->fetchAll(
                "SELECT * FROM email_queue $whereClause
                 ORDER BY created_at DESC
                 LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset])
            );

            // دریافت آمار
            $statsResult = $db->fetchAll(
                "SELECT status, COUNT(*) as cnt FROM email_queue GROUP BY status"
            );

            $stats = [
                'pending' => 0,
                'processing' => 0,
                'sent' => 0,
                'failed' => 0,
            ];

            foreach ($statsResult as $row) {
                $row = (array)$row;
                if (isset($stats[$row['status']])) {
                    $stats[$row['status']] = (int)$row['cnt'];
                }
            }

            return [
                'emails' => $emails,
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'stats' => $stats,
                'totalPages' => (int)ceil($total / $perPage),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('email.admin.get_emails.failed', [
                'channel' => 'email',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return [
                'emails' => [],
                'total' => 0,
                'page' => $page,
                'perPage' => $perPage,
                'stats' => ['pending' => 0, 'sent' => 0, 'failed' => 0, 'processing' => 0],
                'totalPages' => 0,
            ];
        }
    }

    /**
     * تلاش مجدد برای تمام ایمیل‌های ناموفق
     * 
     * @return int تعداد ایمیل‌های بازیافت شده
     */
    public function retryAllFailed(): int
    {
        try {
            $db = $this->db;

            // بروزرسانی ایمیل‌های ناموفق به pending
            $result = $db->execute(
                "UPDATE email_queue 
                 SET status = 'pending', attempts = 0, updated_at = NOW() 
                 WHERE status = 'failed'",
                []
            );

            $affected = $db->affectedRows();

            $this->logger->info('email.admin.retry_all_failed', [
                'channel' => 'email',
                'count' => $affected,
            ]);

            return $affected ?? 0;
        } catch (\Throwable $e) {
            $this->logger->error('email.admin.retry_all_failed.error', [
                'channel' => 'email',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return 0;
        }
    }

    /**
     * تلاش مجدد برای یک ایمیل خاص
     * 
     * @param int $id شناسه ایمیل (از DB)
     * @return bool آیا موفق بود
     */
    public function retryEmail(int $id): bool
    {
        try {
            $db = $this->db;

            // بروزرسانی ایمیل واحد
            $result = $db->execute(
                "UPDATE email_queue 
                 SET status = 'pending', attempts = 0, updated_at = NOW() 
                 WHERE id = ?",
                [$id]
            );

            if ($db->affectedRows() > 0) {
                $this->logger->info('email.admin.retry_single', [
                    'channel' => 'email',
                    'email_id' => $id,
                ]);
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('email.admin.retry_single.error', [
                'channel' => 'email',
                'email_id' => $id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return false;
        }
    }

    /**
     * Track and record email queue depth as a metric gauge
     */
    private function trackQueueDepth(): void
    {
        try {
            if ($this->useRedis) {
                $depth = $this->redisClient->zCard($this->queueKey) ?: 0;
            } else {
                $depth = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'") ?: 0;
            }
            $this->metrics->gauge('email.queue.depth', (float)$depth);
        } catch (\Throwable $e) {
        }
    }
}


