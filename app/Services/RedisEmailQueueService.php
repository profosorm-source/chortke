<?php

namespace App\Services;

use Core\Cache;

use App\Contracts\LoggerInterface;
/**
 * Redis Email Queue Service
 * 
 * مدیریت صف ایمیل با Redis + Fallback به Database
 * - در Redis: سریع، atomic، بدون فشار به DB
 * - در Database: فقط برای آرشیو و گزارش‌گیری
 */
class RedisEmailQueueService extends \App\Services\BaseService
{
    private Cache $cache;
    private ?\Redis $redis = null;
    private bool $useRedis = false;
    private string $queueKey = 'email:queue';
    private string $processingKey = 'email:processing';
    private string $metaPrefix = 'email:meta:';

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
        $this->redis = $this->cache->redis();
        $this->useRedis = $this->cache->driver() === 'redis';

        $prefix = env('REDIS_PREFIX', 'chortke');
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
                $this->redis->setEx(
                    $this->metaPrefix . $emailId,
                    86400 * 7, // 7 days TTL
                    json_encode($payload)
                );

                // اضافه به صف با اولویت (sorted set)
                $score = ($priority * 1000000) + $scheduledAt;
                $this->redis->zAdd($this->queueKey, $score, $emailId);

                $this->logger->info('email.redis.queued', [
                    'channel' => 'email',
                    'email_id' => $emailId,
                    'priority' => $priority,
                    'scheduled_at' => $scheduledAt,
                ]);

                return $emailId;
            } catch (\Throwable $e) {
                $this->logger->error('email.redis.queue.failed', [
                    'channel' => 'email',
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return $this->fallbackToDatabase($payload);
            }
        }

        return $this->fallbackToDatabase($payload);
    }

    /**
     * دریافت ایمیل‌های آماده ارسال
     */
    public function pop(int $limit = 10): array
    {
        if ($this->useRedis) {
            try {
                $now = time();
                
                // دریافت ایمیل‌های آماده (با اولویت و زمان‌بندی)
                $emailIds = $this->redis->zRangeByScore(
                    $this->queueKey,
                    0,
                    $now * 1000000 + 999999, // همه اولویت‌ها تا الان
                    ['limit' => [0, $limit]]
                );

                if (empty($emailIds)) {
                    return [];
                }

                $emails = [];
                foreach ($emailIds as $emailId) {
                    $data = $this->redis->get($this->metaPrefix . $emailId);
                    if ($data) {
                        $email = json_decode($data, true);
                        
                        // بررسی تعداد تلاش
                        if ($email['attempts'] < 3) {
                            // انتقال به processing
                            $this->redis->zRem($this->queueKey, $emailId);
                            $this->redis->sAdd($this->processingKey, $emailId);
                            
                            $emails[] = $email;
                        } else {
                            // حذف از صف (failed)
                            $this->redis->zRem($this->queueKey, $emailId);
                            $this->markAsFailed($emailId, 'Max attempts reached');
                        }
                    }
                }

                return $emails;
            } catch (\Throwable $e) {
                $this->logger->info('error', 'Redis email pop failed: ' . $e->getMessage());
                return $this->fallbackGetFromDatabase($limit);
            }
        }

        return $this->fallbackGetFromDatabase($limit);
    }

    /**
     * علامت‌گذاری به عنوان ارسال شده
     */
    public function markAsSent(string $emailId): bool
    {
        if ($this->useRedis) {
            try {
                // حذف از processing
                $this->redis->sRem($this->processingKey, $emailId);
                
                // به‌روزرسانی metadata
                $data = $this->redis->get($this->metaPrefix . $emailId);
                if ($data) {
                    $email = json_decode($data, true);
                    $email['status'] = 'sent';
                    $email['sent_at'] = time();
                    
                    // ذخیره در DB برای آرشیو
                    $this->archiveToDatabase($email);
                    
                    // حذف از Redis (دیگر نیازی نیست)
                    $this->redis->del($this->metaPrefix . $emailId);
                }

                $this->logger->info('email.redis.sent_archived', [
    'channel' => 'email',
    'email_id' => $emailId,
]);
return true;
} catch (\Throwable $e) {
    $this->logger->error('email.redis.mark_sent.failed', [
        'channel' => 'email',
        'email_id' => $emailId ?? null,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return false;
}
        }

        return $this->fallbackMarkAsSentInDatabase($emailId);
    }

    /**
     * علامت‌گذاری به عنوان ناموفق (با retry)
     */
    public function markAsFailed(string $emailId, string $error): bool
    {
        if ($this->useRedis) {
            try {
                // حذف از processing
                $this->redis->sRem($this->processingKey, $emailId);
                
                $data = $this->redis->get($this->metaPrefix . $emailId);
                if ($data) {
                    $email = json_decode($data, true);
                    $email['attempts']++;
                    $email['error_message'] = $error;

                    if ($email['attempts'] >= 3) {
                        // ناموفق نهایی - آرشیو در DB
                        $email['status'] = 'failed';
                        $this->archiveToDatabase($email);
                        $this->redis->del($this->metaPrefix . $emailId);
                        
                        $this->logger->warning("Email failed after 3 attempts: {$emailId}", []);
                    } else {
                        // بازگشت به صف برای retry
                        $email['status'] = 'pending';
                        $this->redis->setEx(
                            $this->metaPrefix . $emailId,
                            86400 * 7,
                            json_encode($email)
                        );
                        
                        $priority = $this->getPriorityScore($email['priority']);
                        $score = ($priority * 1000000) + time() + (300 * $email['attempts']); // تأخیر 5 دقیقه
                        $this->redis->zAdd($this->queueKey, $score, $emailId);
                        
                        $this->logger->info('email.redis.retry_scheduled', [
    'channel' => 'email',
    'email_id' => $emailId,
    'attempt' => $email['attempts'] ?? null,
]);
                    }
                }

                return true;
            } catch (\Throwable $e) {
                $this->logger->info('error', 'Redis mark as failed error: ' . $e->getMessage());
                return false;
            }
        }

        return $this->fallbackMarkAsFailedInDatabase($emailId, $error);
    }

    /**
     * آمار صف
     */
    public function getStats(): array
    {
        if ($this->useRedis) {
            try {
                return [
                    'pending' => (int) $this->redis->zCard($this->queueKey),
                    'processing' => (int) $this->redis->sCard($this->processingKey),
                    'driver' => 'redis'
                ];
            } catch (\Throwable $e) {
                $this->logger->info('error', 'Redis stats error: ' . $e->getMessage());
            }
        }

        return $this->fallbackGetStatsFromDatabase();
    }

    /**
     * پاکسازی ایمیل‌های قدیمی از Redis
     */
    public function cleanup(): int
    {
        if ($this->useRedis) {
            try {
                $cleaned = 0;

                // پاکسازی ایمیل‌های خیلی قدیمی (بیش از 7 روز)
                $pattern = $this->metaPrefix . '*';
                $cursor = null;

                do {
                    $keys = $this->redis->scan($cursor, $pattern, 100);
                    if ($keys) {
                        foreach ($keys as $key) {
                            $ttl = $this->redis->ttl($key);
                            if ($ttl < 0) { // منقضی شده
                                $this->redis->del($key);
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
            $db = \Core\Database::getInstance();
            
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
            $this->logger->info('error', 'Database email queue failed: ' . $e->getMessage());
            return false;
        }
    }

    private function fallbackGetFromDatabase(int $limit): array
    {
        try {
            $db = \Core\Database::getInstance();
            $now = date('Y-m-d H:i:s');

            return $db->fetchAll(
                "SELECT * FROM email_queue
                 WHERE status IN ('pending', 'sending')
                   AND attempts < 3
                   AND (scheduled_at IS NULL OR scheduled_at <= :now)
                 ORDER BY
                   CASE priority
                     WHEN 'urgent' THEN 1
                     WHEN 'high'   THEN 2
                     WHEN 'normal' THEN 3
                     ELSE 4
                   END ASC,
                   created_at ASC
                 LIMIT :limit",
                ['now' => $now, 'limit' => $limit]
            );
        } catch (\Throwable $e) {
            $this->logger->info('error', 'Database email get failed: ' . $e->getMessage());
            return [];
        }
    }

    private function fallbackMarkAsSentInDatabase(string $emailId): bool
    {
        try {
            $db = \Core\Database::getInstance();
            $id = str_replace('db_', '', $emailId);
            
            return $db->execute(
                "UPDATE email_queue SET status = 'sent', sent_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$id]
            ) !== false;
        } catch (\Throwable $e) {
            $this->logger->info('error', 'Database mark as sent failed: ' . $e->getMessage());
            return false;
        }
    }

    private function fallbackMarkAsFailedInDatabase(string $emailId, string $error): bool
    {
        try {
            $db = \Core\Database::getInstance();
            $id = str_replace('db_', '', $emailId);
            
            return $db->execute(
                "UPDATE email_queue
                 SET attempts = attempts + 1,
                     status = IF(attempts + 1 >= 3, 'failed', 'pending'),
                     error_message = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$error, $id]
            ) !== false;
        } catch (\Throwable $e) {
            $this->logger->info('error', 'Database mark as failed error: ' . $e->getMessage());
            return false;
        }
    }

    private function fallbackGetStatsFromDatabase(): array
    {
        try {
            $db = \Core\Database::getInstance();
            $rows = $db->fetchAll("SELECT status, COUNT(*) as cnt FROM email_queue GROUP BY status");
            
            $stats = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'driver' => 'database'];
            foreach ($rows as $r) {
                $r = (array)$r;
                $stats[$r['status']] = (int)$r['cnt'];
            }
            return $stats;
        } catch (\Throwable $e) {
            $this->logger->info('error', 'Database stats error: ' . $e->getMessage());
            return ['driver' => 'database'];
        }
    }

    private function archiveToDatabase(array $email): void
    {
        try {
            $db = \Core\Database::getInstance();
            
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
            $this->logger->info('error', 'Email archive to DB failed: ' . $e->getMessage());
        }
    }
}

