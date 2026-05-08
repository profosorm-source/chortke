<?php

declare(strict_types=1);
namespace Core;

/**
 * Idempotency Key System - Version 2.0
 * 
 * جلوگیری از اجرای مجدد درخواست‌های مالی با امکانات پیشرفته:
 * - Automatic retry برای timeout شده‌ها
 * - Distributed locking support
 * - Comprehensive logging
 * - Request data tracking
 * 
 * @package Core
 * @version 2.0
 * @author Security Team
 */
class IdempotencyKey
{
    private $db;
    private $table = 'idempotency_keys';
    
    // تنظیمات
    private const TIMEOUT_SECONDS = 300; // 5 دقیقه
    private const CLEANUP_DAYS = 7;
    private const MAX_RETRIES = 3;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * تولید کلید جدید با امنیت بالا
     * 
     * @param string|null $seed داده اختیاری برای تولید deterministic key
     * @return string
     */
    public static function generate(?string $seed = null): string
    {
        if ($seed !== null) {
            // تولید deterministic key برای debugging
            return hash('sha256', $seed . config('app_key', 'default_key'));
        }
        
        // تولید random key با امنیت بالا
        return bin2hex(random_bytes(32)); // 64 کاراکتر hex
    }

    /**
     * بررسی و ذخیره کلید با قابلیت‌های پیشرفته
     *
     * FIX C-1: Race Condition — از INSERT IGNORE + SELECT FOR UPDATE استفاده می‌کنیم
     *          تا بین CHECK و INSERT هیچ پنجره‌ای برای race condition نباشد.
     * FIX C-2: Infinite Recursion — پارامتر $retryCount اضافه شد و حداکثر
     *          MAX_RETRIES بار تلاش می‌شود.
     * FIX C-3: Fail-Open — در صورت خطای DB که duplicate entry نباشد،
     *          به جای ['is_duplicate'=>false]، exception پرتاب می‌شود
     *          تا عملیات مالی بدون چک idempotency اجرا نشود.
     */
    public function check(string $key, int $userId, string $action, ?array $requestData = null, int $retryCount = 0): array
    {
        // FIX C-2: محدودیت عمق recursion
        if ($retryCount >= self::MAX_RETRIES) {
            throw new \RuntimeException("Idempotency check failed after {$retryCount} retries for key: {$key}");
        }

        $logId = uniqid('IDEM_', true);

        try {
            // FIX C-1: ابتدا INSERT IGNORE می‌کنیم تا ردیف وجود داشته باشد
            // سپس با SELECT FOR UPDATE قفل می‌گیریم — این race condition را حذف می‌کند.
            $insertSql = "INSERT IGNORE INTO {$this->table}
                          (`key`, `user_id`, `action`, `status`, `request_data`, `created_at`, `expires_at`)
                          VALUES (:key, :user_id, :action, 'processing', :request_data, NOW(),
                                  DATE_ADD(NOW(), INTERVAL " . self::CLEANUP_DAYS . " DAY))";

            $stmt = $this->db->prepare($insertSql);
            $stmt->execute([
                'key'          => $key,
                'user_id'      => $userId,
                'action'       => $action,
                'request_data' => $requestData ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : null,
            ]);

            $wasInserted = $stmt->rowCount() > 0;

            // حالا با FOR UPDATE وضعیت واقعی را می‌خوانیم
            $selectSql = "SELECT * FROM {$this->table}
                          WHERE `key` = :key AND `user_id` = :user_id
                          FOR UPDATE";

            $stmt = $this->db->prepare($selectSql);
            $stmt->execute(['key' => $key, 'user_id' => $userId]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$existing) {
                // نباید اتفاق بیفتد — اگر INSERT IGNORE کار کرد ردیف وجود دارد
                throw new \RuntimeException("Idempotency key not found after insert: {$key}");
            }

            // ردیف جدید درج شد — درخواست اول
            if ($wasInserted) {
                $this->logEvent('idempotency.key.created', [
    'log_id' => $logId,
    'key' => $key,
    'action' => $action,
]);
                return ['is_duplicate' => false];
            }

            // ردیف قبلاً وجود داشت — بررسی وضعیت
            $this->logEvent('idempotency.key.exists', [
    'log_id' => $logId,
    'key' => $key,
    'status' => $existing['status'] ?? null,
]);
            if ($existing['status'] === 'completed') {
                $result = json_decode($existing['result'], true) ?? ['error' => 'Invalid result format'];
                return [
                    'is_duplicate' => true,
                    'result'       => $result,
                    'cached_at'    => $existing['completed_at'] ?? $existing['created_at'],
                ];
            }

            if ($existing['status'] === 'failed') {
                $elapsed = time() - strtotime($existing['created_at']);
                if ($elapsed > 60) {
                    $this->updateStatus($key, $userId, 'processing');
                    return ['is_duplicate' => false];
                }
                $result = json_decode($existing['result'], true) ?? ['error' => 'Unknown error'];
                return ['is_duplicate' => true, 'result' => $result, 'is_error' => true];
            }

            if ($existing['status'] === 'processing') {
                $elapsed = time() - strtotime($existing['created_at']);
                if ($elapsed < self::TIMEOUT_SECONDS) {
                    return [
                        'is_duplicate'  => true,
                        'result'        => [
                            'success'         => false,
                            'message'         => 'درخواست شما در حال پردازش است. لطفاً صبر کنید.',
                            'elapsed_seconds' => $elapsed,
                            'retry_after'     => 30,
                        ],
                        'is_processing' => true,
                    ];
                }
                // Timeout — اجازه retry
                $this->updateStatus($key, $userId, 'processing', ['timeout_occurred' => true]);
                return ['is_duplicate' => false];
            }

            return ['is_duplicate' => false];

        } catch (\PDOException $e) {
            // FIX C-3: Fail-Closed — فقط duplicate key خطا را retry می‌کنیم.
            // سایر خطاهای DB را به بالا پرتاب می‌کنیم تا عملیات مالی
            // بدون چک idempotency اجرا نشود (fail-open خطرناک است).
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                $this->logEvent('idempotency.key.race_retry', [
    'log_id' => $logId,
    'key' => $key,
    'retry' => $retryCount,
], 'warning');
                usleep(50000 * ($retryCount + 1)); // backoff تدریجی
                return $this->check($key, $userId, $action, $requestData, $retryCount + 1);
            }

            // FIX C-3: خطای واقعی DB — throw می‌کنیم، fail-open نیستیم
            $this->logEvent('idempotency.check.database_error', [
    'log_id' => $logId,
    'key' => $key,
    'error' => $e->getMessage(),
], 'error');
            throw new \RuntimeException(
                "Idempotency check failed due to database error: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    private function logEvent(string $event, array $context = [], string $level = 'info'): void
{
    if (function_exists('logger')) {
        $payload = array_merge(['channel' => 'idempotency'], $context);

        if ($level === 'error') {
            logger()->error($event, $payload);
            return;
        }

        if ($level === 'warning') {
    logger()->warning($event, $payload);
    return;
}
logger()->info($event, $payload);
        return;
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ' ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents(__DIR__ . '/../storage/logs/_idempotency_fallback.log', $line, FILE_APPEND | LOCK_EX);
}
    /**
     * به‌روزرسانی وضعیت کلید
     */
    private function updateStatus(string $key, int $userId, string $status, ?array $metadata = null): bool
    {
        $sql = "UPDATE {$this->table} 
                SET `status` = :status";
        
        $params = [
            'key' => $key,
            'user_id' => $userId,
            'status' => $status
        ];
        
        if ($metadata) {
            $sql .= ", `result` = :metadata";
            $params['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        }
        
        $sql .= " WHERE `key` = :key AND `user_id` = :user_id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * ذخیره نتیجه موفق
     * 
     * @param string $key
     * @param mixed $result
     * @param int|null $userId
     * @return bool
     */
    public function complete(string $key, $result, ?int $userId = null): bool
    {
        try {
            $sql = "UPDATE {$this->table} 
                    SET `status` = 'completed',
                        `result` = :result,
                        `completed_at` = NOW()
                    WHERE `key` = :key";
            
            $params = [
                'key' => $key,
                'result' => is_array($result) || is_object($result) 
                    ? json_encode($result, JSON_UNESCAPED_UNICODE) 
                    : $result
            ];
            
            if ($userId !== null) {
                $sql .= " AND `user_id` = :user_id";
                $params['user_id'] = $userId;
            }
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);
            
            if ($success) {
                $this->logEvent('idempotency.key.completed', [
    'key' => $key,
]);
            }
            
            return $success;
            
        } catch (\PDOException $e) {
            $this->logEvent('idempotency.complete.failed', [
    'key' => $key,
    'error' => $e->getMessage(),
], 'error');
            return false;
        }
    }

    /**
     * علامت‌گذاری به عنوان شکست خورده
     * 
     * @param string $key
     * @param string|array $error
     * @param int|null $userId
     * @return bool
     */
    public function fail(string $key, $error, ?int $userId = null): bool
    {
        try {
            $errorData = is_array($error) ? $error : ['error' => $error];
            
            $sql = "UPDATE {$this->table} 
                    SET `status` = 'failed',
                        `result` = :result,
                        `completed_at` = NOW()
                    WHERE `key` = :key";
            
            $params = [
                'key' => $key,
                'result' => json_encode($errorData, JSON_UNESCAPED_UNICODE)
            ];
            
            if ($userId !== null) {
                $sql .= " AND `user_id` = :user_id";
                $params['user_id'] = $userId;
            }
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);
            
            if ($success) {
                $this->logEvent('idempotency.key.failed', [
    'key' => $key,
], 'warning');
            }
            
            return $success;
            
        } catch (\PDOException $e) {
            $this->logEvent('idempotency.fail_mark.failed', [
    'key' => $key,
    'error' => $e->getMessage(),
], 'error');
            return false;
        }
    }

    /**
     * پاک کردن کلیدهای قدیمی و منقضی شده
     * 
     * @param bool $dryRun فقط شمارش بدون حذف
     * @return int تعداد کلیدهای حذف شده
     */
    public function cleanup(bool $dryRun = false): int
    {
        try {
            $expiryDate = date('Y-m-d H:i:s', strtotime('-' . self::CLEANUP_DAYS . ' days'));
            
            if ($dryRun) {
                // فقط شمارش
                $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                        WHERE `created_at` < :expiry_date OR `expires_at` < NOW()";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['expiry_date' => $expiryDate]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                return (int)($result['count'] ?? 0);
            }
            
            // حذف واقعی
            $sql = "DELETE FROM {$this->table} 
                    WHERE `created_at` < :expiry_date OR `expires_at` < NOW()";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['expiry_date' => $expiryDate]);
            
            $deleted = $stmt->rowCount();
            
            if ($deleted > 0) {
                $this->logEvent('idempotency.cleanup.completed', [
    'deleted' => $deleted,
    'expiry_date' => $expiryDate,
]);
                }
            
            return $deleted;
            
        } catch (\PDOException $e) {
            $this->logEvent('idempotency.cleanup.failed', [
    'error' => $e->getMessage(),
], 'error');
            return 0;
        }
    }

    /**
     * Wrapper برای اجرای عملیات با idempotency check
     * 
     * @param string $key کلید idempotency
     * @param int $userId شناسه کاربر
     * @param string $action نوع عملیات
     * @param callable $callback تابعی که باید اجرا شود
     * @param array|null $requestData داده‌های درخواست
     * @return mixed نتیجه callback یا نتیجه cached
     * @throws \Exception
     */
    public static function wrap(string $key, int $userId, string $action, callable $callback, ?array $requestData = null)
    {
        $service = new self();
        $logId = uniqid('WRAP_', true);
        
       $this->logEvent('idempotency.wrap.started', [
    'log_id' => $logId,
    'key' => $key,
    'user_id' => $userId,
    'action' => $action,
]);
        // بررسی کلید
        $check = $service->check($key, $userId, $action, $requestData);
        
        if ($check['is_duplicate']) {
            $this->logEvent('idempotency.wrap.duplicate_returned', [
    'log_id' => $logId,
    'key' => $key,
], 'warning');
            return $check['result'];
        }
        
        try {
            // اجرای عملیات
            $this->logEvent('idempotency.wrap.callback.executing', [
    'log_id' => $logId,
    'key' => $key,
]);
            $result = $callback();
            
            // ذخیره نتیجه موفق
            $service->complete($key, $result, $userId);
            
            $this->logEvent('idempotency.wrap.callback.success', [
    'log_id' => $logId,
    'key' => $key,
]);
            
            return $result;
            
        } catch (\Exception $e) {
    $service->fail($key, [
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], $userId);

    logger()->error('callback.failed', [
        'channel' => 'payment_callback',
        'log_id' => $logId,
        'user_id' => $userId ?? null,
        'key' => $key,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    throw $e;
}
    }
    
    /**
     * دریافت آمار استفاده از idempotency keys
     * 
     * @return array
     */
    public function getStats(): array
    {
        try {
            $sql = "SELECT 
                        `status`,
                        COUNT(*) as count,
                        COUNT(CASE WHEN `created_at` >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 END) as last_hour,
                        COUNT(CASE WHEN `created_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h
                    FROM {$this->table}
                    GROUP BY `status`";
            
            $stmt = $this->db->query($sql);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $stats = [
                'total' => 0,
                'by_status' => []
            ];
            
            foreach ($results as $row) {
                $stats['total'] += $row['count'];
                $stats['by_status'][$row['status']] = $row;
            }
            
            return $stats;
            
        } catch (\PDOException $e) {
            $this->logEvent('idempotency.stats.failed', [
    'error' => $e->getMessage(),
], 'error');
            return ['error' => 'internal_error'];
        }
    }
}