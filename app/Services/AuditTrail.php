<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\AuditTrail as AuditTrailModel;


class AuditTrail
{
    use \App\Traits\ClientInfoTrait;

    private LoggerInterface $logger;
    private AuditTrailModel $auditTrailModel;

    public function __construct(LoggerInterface $logger, AuditTrailModel $auditTrailModel)
    {
        $this->logger = $logger;
        $this->auditTrailModel = $auditTrailModel;
    }

    public function record(
        string $event,
        ?int $userId = null,
        array $context = [],
        ?int $actorId = null
    ): bool {
        try {
            // Publish an audit-record event instead of writing directly.
            // A dedicated listener will persist the record to DB. This keeps audit as an event-driven source of truth.
            $eventObj = new \App\Events\AuditRecordedEvent($event, $userId, $context, $actorId);
            \Core\EventDispatcher::getInstance()->dispatch(\App\Events\AuditRecordedEvent::class, $eventObj);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.record.publish_failed', [
                'channel' => 'audit_trail',
                'event' => $event,
                'user_id' => $userId,
                'actor_id' => $actorId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * Persist an audit record directly to storage. Intended for use by the audit event listener.
     */
    public function persistRecord(
        string $event,
        ?int $userId = null,
        array $context = [],
        ?int $actorId = null
    ): bool {
        try {
            $safeEvent = $this->sanitizeEvent($event);
            $safeContext = $this->sanitizeContext($context);

            $result = $this->auditTrailModel->createEntry([
                'request_id' => $_SERVER['REQUEST_ID'] ?? null,
                'event' => $safeEvent,
                'user_id' => $userId,
                'actor_id' => $actorId ?? $this->currentUserId(),
                'context' => json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address' => $this->clientIp(),
                'user_agent' => $this->userAgent(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return (bool)$result;
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.persist.failed', [
                'channel' => 'audit_trail',
                'event' => $event,
                'user_id' => $userId,
                'actor_id' => $actorId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * ثبت لاگ تغییرات ادمین در جدول اختصاصی admin_audit_log
     */
    public function logAdminAction(
        int $adminId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues,
        ?array $newValues,
        string $ipAddress,
        string $userAgent,
        string $sessionId
    ): bool {
        try {
            return (bool)db()->query(
                "INSERT INTO admin_audit_log (admin_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, session_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $adminId,
                    $action,
                    $entityType,
                    $entityId,
                    $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                    $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                    $ipAddress,
                    $userAgent,
                    $sessionId
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.admin_action.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function diff(
        string $event,
        ?int $userId,
        array $before,
        array $after,
        array $ignore = ['updated_at', 'created_at', 'password', 'remember_token']
    ): bool {
        $changes = [];

        // Check for modified or new keys
        foreach ($after as $key => $newVal) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            $oldVal = $before[$key] ?? null;
            if ($oldVal !== $newVal) {
                $changes[$key] = ['from' => $oldVal, 'to' => $newVal];
            }
        }

        // Check for deleted keys (present in $before but missing in $after)
        foreach ($before as $key => $oldVal) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            if (!array_key_exists($key, $after)) {
                $changes[$key] = ['from' => $oldVal, 'to' => null];
            }
        }

        if (empty($changes)) {
            return true;
        }

        return $this->record($event, $userId, ['changes' => $changes]);
    }

    public function archiveOlderThan(int $days = 30, int $chunkSize = 2000): array
    {
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $archiveDir = function_exists('storage_path') 
                ? storage_path('audit-archives') 
                : dirname(__DIR__, 2) . '/storage/audit-archives';

            if (!is_dir($archiveDir)) {
                if (!mkdir($archiveDir, 0755, true) && !is_dir($archiveDir)) {
                    throw new \RuntimeException("Failed to create directory: {$archiveDir}");
                }
            }

            // MED-05: اعمال File Lock برای جلوگیری از تضادی cron‌های همزمان
            $lockFile = $archiveDir . '/.archive.lock';
            $lock = fopen($lockFile, 'w');
            if (!$lock) {
                throw new \RuntimeException('Cannot create lock file');
            }

            // بگیرید exclusive non-blocking lock
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                return [
                    'archived' => 0,
                    'deleted' => 0,
                    'file' => null,
                    'error' => 'Archive already running - lock acquired by another process'
                ];
            }

            try {
                $stamp = date('Ymd_His');
                $jsonlFile = $archiveDir . "/audit_{$stamp}.jsonl";
                $gzFile = $jsonlFile . '.gz';

                $fp = fopen($jsonlFile, 'ab');
                if (!$fp) {
                    throw new \RuntimeException('Cannot create archive file');
                }

                $total = 0;
                $lastId = 0;

                while (true) {
                    $rows = $this->auditTrailModel->fetchBatchOlderThan($cutoff, $lastId, $chunkSize);

                    if (empty($rows)) {
                        break;
                    }

                    foreach ($rows as $row) {
                        fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                        $total++;
                        $lastId = (int)($row['id'] ?? $lastId);
                    }
                }

                fclose($fp);

                if ($total === 0) {
                    if (file_exists($jsonlFile)) {
                        unlink($jsonlFile);
                    }
                    return [
                        'archived' => 0,
                        'deleted' => 0,
                        'file' => null,
                        'cutoff' => $cutoff,
                    ];
                }

                $in = fopen($jsonlFile, 'rb');
                if (!$in) {
                    throw new \RuntimeException('Cannot open archive temp file');
                }

                $out = gzopen($gzFile, 'wb9');
                if (!$out) {
                    fclose($in);
                    throw new \RuntimeException('Cannot create gzip archive');
                }

                while (!feof($in)) {
                    $chunk = fread($in, 8192);
                    if ($chunk === false) {
                        gzclose($out);
                        fclose($in);
                        throw new \RuntimeException('Cannot read archive temp chunk');
                    }
                    gzwrite($out, $chunk);
                }

                gzclose($out);
                fclose($in);

                if (file_exists($jsonlFile)) {
                    unlink($jsonlFile);
                }

                if (!file_exists($gzFile) || filesize($gzFile) === 0) {
                    throw new \RuntimeException('Archive gzip file is invalid');
                }

                $deleted = 0;
                do {
                    $batch = $this->auditTrailModel->deleteOlderThan($cutoff, 5000, true);
                    $deleted += $batch;
                } while ($batch === 5000);

                // L-SRV-02 Fix: پاکسازی خودکار آرشیوهای فشرده قدیمی‌تر از ۹۰ روز جهت جلوگیری از پر شدن تدریجی هارد دیسک
                $purgedArchivesCount = $this->purgeOldArchiveFiles($archiveDir, 90);

                $this->logger->info('audit_trail.archive.completed', [
                    'channel' => 'audit_trail',
                    'cutoff' => $cutoff,
                    'archived' => $total,
                    'deleted' => $deleted,
                    'file' => basename($gzFile),
                    'size' => filesize($gzFile),
                    'sha256' => hash_file('sha256', $gzFile),
                    'purged_archives' => $purgedArchivesCount,
                ]);

                return [
                    'archived' => $total,
                    'deleted' => $deleted,
                    'file' => $gzFile,
                    'cutoff' => $cutoff,
                    'size' => filesize($gzFile),
                    'purged_archives' => $purgedArchivesCount,
                ];
            } finally {
                // MED-05: همیشه lock را release کن
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.archive.failed', [
                'channel' => 'audit_trail',
                'days' => $days,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'archived' => 0,
                'deleted' => 0,
                'file' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
    public function getForUser(int $userId, int $limit = 50): array
    {
        try {
            return $this->auditTrailModel->getForUser($userId, $limit);
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.get_for_user.failed', [
                'channel' => 'audit_trail',
                'user_id' => $userId,
                'limit' => $limit,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function getAll(
        int $page = 1,
        int $perPage = 50,
        ?string $event = null,
        ?int $userId = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        try {
            return $this->auditTrailModel->getAll($page, $perPage, $event, $userId, $search, $dateFrom, $dateTo);
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.get_all.failed', [
                'channel' => 'audit_trail',
                'error' => $e->getMessage(),
            ]);
            return [
                'rows' => [],
                'total' => 0,
                'page' => $page,
                'totalPages' => 0,
            ];
        }
    }

    /**
     * لیست انواع eventهای موجود در audit_trail
     */
    public function getEventTypes(): array
    {
        try {
            return $this->auditTrailModel->getEventTypes();
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.get_event_types.failed', [
                'channel' => 'audit_trail',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return [];
        }
    }

    /**
     * آمار کلی audit در بازه زمانی
     */
    public function getStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        try {
            return $this->auditTrailModel->getStats($dateFrom, $dateTo);
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.get_stats.failed', [
                'channel' => 'audit_trail',
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return [
                'total' => 0,
                'unique_users' => 0,
                'unique_actors' => 0,
                'today' => 0,
            ];
        }
    }

    public function cleanup(int $days = 365, bool $bypassCompliance = false): int
    {
        try {
            return $this->auditTrailModel->cleanupOlderThan($days, $bypassCompliance);
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.cleanup.failed', [
                'channel' => 'audit_trail',
                'days' => $days,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function sanitizeEvent(string $event): string
    {
        // حذف کاراکترهای خاص
        $event = preg_replace('/[^a-zA-Z0-9_.\-:]/', '', $event);
        
        // محدود کردن طول
        return substr($event, 0, 100);
    }

    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'private_key', 'card_number', 'sheba', 'iban'];
        
        // Mask sensitive keys at any level recursively
        $masked = $context;
        array_walk_recursive($masked, function (&$value, $key) use ($sensitiveKeys) {
            $k = strtolower((string)$key);
            foreach ($sensitiveKeys as $field) {
                if (str_contains($k, $field)) {
                    $value = '[REDACTED]';
                    return;
                }
            }

            if (is_string($value) && mb_strlen($value) > 2000) {
                $value = mb_substr($value, 0, 2000) . '...';
            }
        });

        // Limit array depth to prevent size explosion
        return $this->limitArrayDepth($masked, 5);
    }

    private function limitArrayDepth(array $array, int $maxDepth, int $currentDepth = 0): array
    {
        if ($currentDepth >= $maxDepth) {
            return ['[MAX_DEPTH_REACHED]'];
        }
        
        $result = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->limitArrayDepth($value, $maxDepth, $currentDepth + 1);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }

    /**
     * L-SRV-02 Fix: حذف فیزیکی فایل‌های فشرده آرشیو خیلی قدیمی جهت آزادسازی خودکار فضای دیسک
     */
    private function purgeOldArchiveFiles(string $archiveDir, int $retentionDays = 90): int
    {
        $purged = 0;
        try {
            if (!is_dir($archiveDir)) {
                return 0;
            }
            $files = glob($archiveDir . '/audit_*.jsonl.gz');
            if (!$files) {
                return 0;
            }
            $now = time();
            foreach ($files as $file) {
                $fileTime = filemtime($file);
                if (($now - $fileTime) > ($retentionDays * 86400)) {
                    if (@unlink($file)) {
                        $purged++;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('audit_trail.purge_archives.failed', ['error' => $e->getMessage()]);
        }
        return $purged;
    }
}

