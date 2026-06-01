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
    private $cache;
    private $table = 'idempotency_keys';
    
    // تنظیمات
    private const TIMEOUT_SECONDS = 300; // 5 دقیقه
    private const RETRY_DELAY_SECONDS = 60; // پس از چه مدت خطای retryable قابل تلاش دوباره است
    private const CLEANUP_DAYS = 90; // نگهداری پیش‌فرض برای عملیات مالی
    private const MAX_RETRIES = 3;

    private const STATUS_PENDING = 'pending';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED_RETRYABLE = 'failed_retryable';
    private const STATUS_FAILED_FINAL = 'failed_final';
    private const STATUS_LEGACY_PROCESSING = 'processing';
    private const STATUS_LEGACY_FAILED = 'failed';

    public function __construct(Database $db, Cache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    private function redactSensitiveData(array $data): array
    {
        $sensitiveKeys = [
            'password', 'password_confirmation', 'pin', 'cvv2', 'card_number', 'card_num',
            'token', 'secret', 'authorization', 'api_key', 'key', 'pass', 'ssn', 'national_code',
            'cvv', 'card', 'pan', 'otp', 'code', 'email', 'mobile'
        ];

        $redacted = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $redacted[$k] = $this->redactSensitiveData($v);
            } elseif (is_string($k) && in_array(strtolower($k), $sensitiveKeys, true)) {
                $redacted[$k] = '[REDACTED]';
            } else {
                $redacted[$k] = $v;
            }
        }
        return $redacted;
    }

    public static function generate(?string $seed = null): string
    {
        if ($seed !== null) {
            $key = secure_key();
            // تولید deterministic key برای debugging
            return hash('sha256', $seed . $key);
        }
        
        // تولید random key با امنیت بالا
        return bin2hex(random_bytes(32)); // 64 کاراکتر hex
    }

    /**
     * ساخت یک کلید امن و قطعی بر اساس اطلاعات دقیق عملیات
     * جلوگیری قطعی از استفاده از time() و خطر Race Condition
     *
     * @param string $action نام بیزینسی عملیات (مثلاً 'referral_commission')
     * @param array $context دیتاهایی که این تراکنش را منحصربه‌فرد می‌کنند (userId, transactionId, ...)
     */
    public static function generateFromPayload(string $action, array $context): string
    {
        // CORE-049: Auto-incorporate request URI and Method in context to prevent collision bypass
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        
        $safeContext = array_filter($context, function($v) {
             return is_scalar($v) || is_null($v);
        });
        
        ksort($safeContext);
        $payloadStr = serialize($safeContext);
        $appKey = secure_key();
        
        // ترکیب امن: اکشن + مسیر + متد + داده‌ها + اپ‌کی
        $finalSeed = $action . '|' . $uri . '|' . $method . '|' . $payloadStr . '|' . $appKey;
        
        return hash('sha256', $finalSeed);
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
        $lockKey = "idempotency_lock:{$userId}:" . hash('sha256', $key);
        $cache = $this->cache;
        $isLocked = false;

        if ($retryCount === 0) {
            if (!$cache->lock($lockKey, 30, 5)) {
                throw new \RuntimeException("Concurrency lock failed. Another request is being processed.", 409);
            }
            $isLocked = true;
        }

        try {
            // CORE-048: Start a dedicated DB transaction so SELECT FOR UPDATE holds a real row lock
            $this->db->beginTransaction();

            // Redact sensitive parameters to prevent plain-text PII storage in the database
            $safeRequestData = is_array($requestData) ? $this->redactSensitiveData($requestData) : [];

            // CORE-049: Enrich payload tracking with structural request signatures to block cross-endpoint key reuse
            $payloadSignature = [
                'uri'    => $_SERVER['REQUEST_URI'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'data'   => $safeRequestData,
            ];
            $encodedSignature = json_encode($payloadSignature, JSON_UNESCAPED_UNICODE);

            // FIX C-1: ابتدا INSERT با ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) می‌کنیم
            // تا شناسه دقیق سطر را بگیریم و با FOR UPDATE قفل کنیم
            $insertSql = "INSERT INTO {$this->table}
                          (`key`, `user_id`, `action`, `status`, `request_data`, `created_at`, `expires_at`)
                          VALUES (:key, :user_id, :action, '" . self::STATUS_PENDING . "', :request_data, NOW(),
                                  DATE_ADD(NOW(), INTERVAL " . self::CLEANUP_DAYS . " DAY))
                          ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)";

            $stmt = $this->db->prepare($insertSql);
            $stmt->execute([
                'key'          => $key,
                'user_id'      => $userId,
                'action'       => $action,
                'request_data' => $encodedSignature,
            ]);

            $lastId = (int)$this->db->lastInsertId();
            $wasInserted = ($stmt->rowCount() === 1);

            $selectSql = "SELECT * FROM {$this->table}
                          WHERE `id` = :id
                          FOR UPDATE";

            $stmt = $this->db->prepare($selectSql);
            $stmt->execute(['id' => $lastId]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$existing) {
                $this->db->commit();
                throw new \RuntimeException("Idempotency key not found after insert: {$key}");
            }

            if (!$wasInserted) {
                $storedSignature = json_decode($existing['request_data'] ?? '', true);
                if (is_array($storedSignature)) {
                    $storedUri = $storedSignature['uri'] ?? '';
                    $storedMethod = $storedSignature['method'] ?? '';
                    $storedPayload = $storedSignature['data'] ?? [];

                    if ($storedUri !== $payloadSignature['uri'] || $storedMethod !== $payloadSignature['method']) {
                        $this->db->commit();
                        throw new \RuntimeException("Idempotency Collision: Reusing key '{$key}' for a different URI/Method footprint.", 409);
                    }

                    if (json_encode($storedPayload) !== json_encode($payloadSignature['data'])) {
                        $this->db->commit();
                        throw new \RuntimeException("Idempotency Collision: Reusing key '{$key}' with modified payload data.", 409);
                    }
                }
            }

            if ($wasInserted) {
                $this->logEvent('idempotency.key.created', [
                    'log_id' => $logId,
                    'key'    => $key,
                    'action' => $action,
                ]);
                $this->db->commit();
                return ['is_duplicate' => false, 'status' => self::STATUS_PENDING];
            }

            $status = $existing['status'] ?? '';
            $this->logEvent('idempotency.key.exists', [
                'log_id' => $logId,
                'key'    => $key,
                'status' => $status,
            ]);

            $result = null;
            if (!empty($existing['result'])) {
                $decoded = json_decode($existing['result'], true);
                $result = json_last_error() === JSON_ERROR_NONE ? $decoded : $existing['result'];
            }

            if ($status === self::STATUS_COMPLETED) {
                $this->db->commit();
                return [
                    'is_duplicate' => true,
                    'status'       => self::STATUS_COMPLETED,
                    'result'       => $result,
                    'cached_at'    => $existing['completed_at'] ?? $existing['created_at'],
                    'http_status'  => 200, // 200 OK: returning cached successful result
                ];
            }

            if ($status === self::STATUS_FAILED_FINAL || $status === self::STATUS_LEGACY_FAILED) {
                $this->db->commit();
                return [
                    'is_duplicate' => true,
                    'status'       => self::STATUS_FAILED_FINAL,
                    'result'       => $result,
                    'is_error'     => true,
                    'http_status'  => 400, // 400 Bad Request: unrecoverable failure, do not retry
                    'retry_allowed' => false,
                ];
            }

            if ($status === self::STATUS_FAILED_RETRYABLE) {
                $elapsed = time() - strtotime($existing['created_at']);
                $retryAfter = max(0, self::RETRY_DELAY_SECONDS - $elapsed);
                
                if ($elapsed > self::RETRY_DELAY_SECONDS) {
                    // Sufficient time has passed, allow retry
                    $this->updateStatusWithMetadata($key, $userId, self::STATUS_PENDING, [
                        'retry_allowed' => true,
                        'previous_attempt_at' => $existing['created_at'],
                        'elapsed_seconds' => $elapsed,
                    ]);
                    $this->db->commit();
                    return ['is_duplicate' => false, 'status' => self::STATUS_PENDING];
                }

                // Still within cooldown period
                $this->db->commit();
                return [
                    'is_duplicate' => true,
                    'status'       => self::STATUS_FAILED_RETRYABLE,
                    'result'       => $result,
                    'is_error'     => true,
                    'http_status'  => 202, // 202 Accepted: still processing, retry later
                    'retry_after'  => $retryAfter,
                    'retry_allowed' => true,
                ];
            }

            if ($status === self::STATUS_PENDING || $status === self::STATUS_LEGACY_PROCESSING) {
                $elapsed = time() - strtotime($existing['created_at']);
                if ($elapsed < self::TIMEOUT_SECONDS) {
                    // Operation still in progress
                    $this->db->commit();
                    return [
                        'is_duplicate'  => true,
                        'status'        => self::STATUS_PENDING,
                        'result'        => [
                            'success'         => false,
                            'message'         => 'درخواست شما در حال پردازش است. لطفاً صبر کنید.',
                            'elapsed_seconds' => $elapsed,
                            'retry_after'     => min(self::TIMEOUT_SECONDS - $elapsed, 30),
                        ],
                        'is_processing' => true,
                        'http_status'   => 409, // 409 Conflict: operation already in progress
                    ];
                }

                // Timeout occurred, reset to allow retry
                $this->updateStatusWithMetadata($key, $userId, self::STATUS_PENDING, ['timeout_occurred' => true]);
                $this->db->commit();
                return ['is_duplicate' => false, 'status' => self::STATUS_PENDING];
            }

            $this->db->commit();
            return ['is_duplicate' => false, 'status' => self::STATUS_PENDING];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            if (($e instanceof \PDOException) && ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry'))) {
                $this->logEvent('idempotency.key.race_retry', [
                    'log_id' => $logId,
                    'key'    => $key,
                    'retry'  => $retryCount,
                ], 'warning');
                usleep(50000 * ($retryCount + 1));
                return $this->check($key, $userId, $action, $requestData, $retryCount + 1);
            }

            $this->logEvent('idempotency.check.database_error', [
                'log_id' => $logId,
                'key'    => $key,
                'error'  => $e->getMessage(),
            ], 'error');
            
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            
            throw new \RuntimeException(
                "Idempotency check failed: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        } finally {
            if ($isLocked) {
                $this->cache->unlock($lockKey);
            }
        }
    }

    /**
     * به‌روزرسانی وضعیت کلید
     */
    private function updateStatus(string $key, int $userId, string $status, ?array $metadata = null): bool
    {
        $sql = "UPDATE {$this->table} 
                SET `status` = :status, `created_at` = NOW()";
        
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

    private function isRetryableException(\Throwable $exception): bool
    {
        // ✅ **Explicit Retryable Exceptions** — عملیات دوباره قابل تلاش است
        if ($exception instanceof \Core\Exceptions\TransientException) {
            return true;  // Temporary failures (network timeouts, brief unavailability)
        }

        if ($exception instanceof \Core\Exceptions\RateLimitedFailure) {
            return true;  // Rate limiting — retry after backoff
        }

        if ($exception instanceof \Core\Exceptions\ProviderUnavailable) {
            return true;  // Gateway/provider temporarily unavailable
        }

        if ($exception instanceof \PDOException) {
            return true;  // Database errors (connection timeout, deadlock, etc.)
        }

        // ✅ **Non-Retryable Exceptions** — خطاهایی که دوباره تلاش مفید نیست
        if ($exception instanceof \Core\Exceptions\BusinessException) {
            return false;  // Business logic violations (insufficient balance, invalid state)
        }

        if ($exception instanceof \Core\Exceptions\ValidationException) {
            return false;  // Validation errors (invalid input, schema mismatch)
        }

        // ❌ **Default: Non-Retryable**
        // اگر exception نوع شناخت‌شده‌ای نیست، آن را non-retryable فرض می‌کنیم
        // تا عملیات مالی به دلایل نامعلوم دوباره اجرا نشوند
        return false;
    }

    private function prepareResultForStorage($result)
    {
        if (is_array($result) || is_object($result)) {
            return json_encode($this->normalizeResultForStorage($result), JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($result) || is_numeric($result)) {
            return json_encode($result, JSON_UNESCAPED_UNICODE);
        }

        return (string)$result;
    }

    private function normalizeResultForStorage($result): array
    {
        $payload = is_object($result) ? get_object_vars($result) : $result;
        $allowed = [
            'transaction_id', 'escrow_id', 'status', 'success', 'ok', 'message', 'error',
            'amount', 'currency', 'net_amount', 'commission', 'refund_amount',
            'wallet_transaction', 'request_id', 'order_id', 'listing_id', 'execution_id',
            'to_user_id', 'from_user_id', 'seller_id', 'buyer_id'
        ];

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $normalized[$key] = $value;
                continue;
            }

            if (is_array($value) && $this->isFlatScalarArray($value)) {
                $normalized[$key] = $value;
                continue;
            }

            $normalized[$key] = [
                'type' => is_object($value) ? 'object' : 'array',
                'count' => is_countable($value) ? count($value) : null,
            ];
        }

        if (count($normalized) > 20) {
            $normalized = array_slice($normalized, 0, 20, true);
            $normalized['truncated'] = true;
        }

        return $normalized;
    }

    private function isFlatScalarArray(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * ذخیره نتیجه موفق
     * 
     * @param string $key
     * @param mixed $result
     * @param int|null $userId
     * @return bool
     */
    public function complete(string $key, $result, int $userId): bool
    {
        try {
            $sql = "UPDATE {$this->table} 
                    SET `status` = :status,
                        `result` = :result,
                        `completed_at` = NOW()
                    WHERE `key` = :key AND `user_id` = :user_id";

            $params = [
                'key' => $key,
                'user_id' => $userId,
                'status' => self::STATUS_COMPLETED,
                'result' => $this->prepareResultForStorage($result),
            ];

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
    public function fail(string $key, $error, int $userId, bool $retryable = false): bool
    {
        try {
            $errorData = is_array($error) ? $error : ['error' => $error];
            $status = $retryable ? self::STATUS_FAILED_RETRYABLE : self::STATUS_FAILED_FINAL;

            $sql = "UPDATE {$this->table} 
                    SET `status` = :status,
                        `result` = :result,
                        `completed_at` = NOW()
                    WHERE `key` = :key AND `user_id` = :user_id";

            $params = [
                'key' => $key,
                'user_id' => $userId,
                'status' => $status,
                'result' => $this->prepareResultForStorage($errorData),
            ];

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);

            if ($success) {
                $this->logEvent('idempotency.key.failed', [
                    'key' => $key,
                    'status' => $status,
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

    public function abort(string $key, int $userId): bool
    {
        try {
            $sql = "DELETE FROM {$this->table} WHERE `key` = :key AND `user_id` = :user_id";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(['key' => $key, 'user_id' => $userId]);
            
            if ($success) {
                $this->logEvent('idempotency.key.aborted', ['key' => $key], 'warning');
            }
            return $success;
        } catch (\PDOException $e) {
            $this->logEvent('idempotency.abort.failed', ['key' => $key, 'error' => $e->getMessage()], 'error');
            return false;
        }
    }


    /**
     * Scope-aware idempotency wrapper for general application use-cases.
     */
    public function run(string $scope, int $actorId, string $key, callable $callback, ?array $requestData = null): mixed
    {
        $scope = preg_replace('/[^A-Za-z0-9_.:-]/', '_', trim($scope)) ?: 'default';
        $scopedKey = hash('sha256', $scope . '|' . $actorId . '|' . $key);
        return $this->wrapInstance($scopedKey, $actorId, $scope, $callback, $requestData);
    }

    public function keyFromPayload(string $scope, array $payload): string
    {
        ksort($payload);
        return hash('sha256', $scope . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE));
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
        // H19 Fix: استفاده از Container برای Resolve شدن وابستگی‌ها در Wrapperهای استاتیک
        $service = Container::getInstance()->make(self::class);
        $logId = uniqid('WRAP_', true);
        
        $service->logEvent('idempotency.wrap.started', [
            'log_id' => $logId,
            'key' => $key,
            'user_id' => $userId,
            'action' => $action,
        ]);
        // بررسی کلید
        $check = $service->check($key, $userId, $action, $requestData);
        
        if ($check['is_duplicate']) {
            $service->logEvent('idempotency.wrap.duplicate_returned', [
                'log_id' => $logId,
                'key' => $key,
            ], 'warning');
            return $check['result'];
        }
        
        try {
            // اجرای عملیات
            $service->logEvent('idempotency.wrap.callback.executing', [
                'log_id' => $logId,
                'key' => $key,
            ]);
            $result = $callback();
            
            // ذخیره نتیجه موفق
            $service->complete($key, $result, $userId);
            
            $service->logEvent('idempotency.wrap.callback.success', [
                'log_id' => $logId,
                'key' => $key,
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $service->abort($key, $userId);

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
     * Instance version of wrap for dependency injection
     */
    public function wrapInstance(string $key, int $userId, string $action, callable $callback, ?array $requestData = null)
    {
        $logId = uniqid('WRAP_', true);
        
        $this->logEvent('idempotency.wrap.started', [
            'log_id' => $logId,
            'key' => $key,
            'user_id' => $userId,
            'action' => $action,
        ]);
        // بررسی کلید
        $check = $this->check($key, $userId, $action, $requestData);
        
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
            $this->complete($key, $result, $userId);
            
            $this->logEvent('idempotency.wrap.callback.success', [
                'log_id' => $logId,
                'key' => $key,
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $retryable = $this->isRetryableException($e);
            $this->fail($key, [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ], $userId, $retryable);

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
                'retryable' => $retryable,
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