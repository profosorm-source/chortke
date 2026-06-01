<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\CacheInterface;
use App\Models\BulkOperation;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\LoggerInterface;

/**
 * سرویس عملیات گروهی
 * 
 * قابل استفاده برای هر Module برای انجام عملیات دسته‌جمعی
 * - تغییرات گروهی
 * - حذف گروهی
 * - صادرات
 * - وارد‌سازی
 * 
 * @package App\Services
 */
class BulkOperationsService
{
    private BulkOperation $bulkOperationModel;
    private ?NotificationServiceInterface $notificationService;

    private const MAX_BULK_ITEMS = 1000;
    private const BATCH_SIZE = 100;

    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        BulkOperation $bulkOperationModel,
        CacheInterface $cache,
        ?NotificationServiceInterface $notificationService = null
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;

        
        $this->bulkOperationModel = $bulkOperationModel;
        $this->notificationService = $notificationService;
    }

    /**
     * به‌روزرسانی گروهی
     * 
     * @param string $table نام جدول
     * @param array $ids آرایه ID ها
     * @param array $data داده‌های جدید ['column' => 'value']
     * @param string $idColumn نام ستون ID (پیش‌فرض: 'id')
     * @return array نتیجه عملیات
     */
    public function bulkUpdate(
        string $table,
        array $ids,
        array $data,
        string $idColumn = 'id'
    ): array {
        if (empty($ids)) {
            throw new \Core\Exceptions\BusinessException('هیچ آیتمی انتخاب نشده است.');
        }

        if (count($ids) > self::MAX_BULK_ITEMS) {
            throw new \Core\Exceptions\BusinessException('حداکثر ' . self::MAX_BULK_ITEMS . ' آیتم قابل پردازش است.');
        }

        if (empty($data)) {
            throw new \Core\Exceptions\BusinessException('داده‌ای برای به‌روزرسانی وجود ندارد.');
        }

        try {
            $this->db->beginTransaction();

            $updated = 0;
            $batches = array_chunk($ids, self::BATCH_SIZE);

            foreach ($batches as $batch) {
                $updated += $this->bulkOperationModel->applyBatchUpdate($table, $batch, $data, $idColumn);
            }

            $this->db->commit();

            $this->logOperation('bulk_update', $table, [
                'count' => $updated,
                'data' => $data,
            ]);

            return [
                'success' => true,
                'message' => "{$updated} رکورد به‌روز شد.",
                'data' => ['updated' => $updated]
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('bulk_update', $e);
            
            throw new \Core\Exceptions\BusinessException('خطا در به‌روزرسانی گروهی.');
        }
    }

    /**
     * حذف نرم گروهی
     * 
     * @param string $table
     * @param array $ids
     * @param string $idColumn
     * @param string $deletedColumn نام ستون soft delete
     * @return array
     */
    public function bulkSoftDelete(
        string $table,
        array $ids,
        string $idColumn = 'id',
        string $deletedColumn = 'is_deleted'
    ): array {
        return $this->bulkUpdate($table, $ids, [
            $deletedColumn => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $idColumn);
    }

    /**
     * حذف سخت گروهی (استفاده با احتیاط!)
     * 
     * @param string $table
     * @param array $ids
     * @param string $idColumn
     * @param bool $confirm تأیید حذف
     * @return array
     */
    public function bulkHardDelete(
        string $table,
        array $ids,
        string $idColumn = 'id',
        bool $confirm = false
    ): array {
        if (!$confirm) {
            throw new \Core\Exceptions\BusinessException('حذف سخت نیاز به تأیید دارد.');
        }

        if (empty($ids)) {
            throw new \Core\Exceptions\BusinessException('هیچ آیتمی انتخاب نشده است.');
        }

        if (count($ids) > self::MAX_BULK_ITEMS) {
            throw new \Core\Exceptions\BusinessException('حداکثر ' . self::MAX_BULK_ITEMS . ' آیتم قابل پردازش است.');
        }

        try {
            $this->db->beginTransaction();

            $deleted = 0;
            $batches = array_chunk($ids, self::BATCH_SIZE);

            foreach ($batches as $batch) {
                $deleted += $this->bulkOperationModel->applyBatchDelete($table, $batch, $idColumn);
            }

            $this->db->commit();

            $this->logOperation('bulk_hard_delete', $table, [
                'count' => $deleted,
            ], 'warning');

            return [
                'success' => true,
                'message' => "{$deleted} رکورد حذف شد.",
                'data' => ['deleted' => $deleted]
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('bulk_hard_delete', $e);
            
            throw new \Core\Exceptions\BusinessException('خطا در حذف گروهی.');
        }
    }

    /**
     * صادرات به CSV
     * 
     * @param string $sql کوئری SELECT
     * @param array $params پارامترهای کوئری
     * @param array $headers هدرهای CSV (فارسی)
     * @param string $filename نام فایل
     * @return array
     */
    public function exportToCSV(
        string|array $sqlOrRows,
        array $params = [],
        array $headers = [],
        string $filename = 'export'
    ): array {
        try {
            // ایجاد نام فایل
            $filename = $this->sanitizeFilename($filename);
            $filename .= '_' . date('Y-m-d_His') . '.csv';
            
            $filepath = $this->getStoragePath('exports/' . $filename);

            // اطمینان از وجود دایرکتوری
            $dir = dirname($filepath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $rows = null;
            if (is_array($sqlOrRows)) {
                $rows = $sqlOrRows;
            } else {
                $stmt = $this->db->query($sqlOrRows, $params);
            }

            // ایجاد فایل CSV
            $file = fopen($filepath, 'w');
            
            // UTF-8 BOM برای Excel
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            $count = 0;
            $firstRow = true;

            if ($rows !== null) {
                foreach ($rows as $row) {
                    $row = is_object($row) ? (array)$row : (array)$row;
                    if ($firstRow) {
                        if (empty($headers)) {
                            $headers = array_keys($row);
                        }
                        fputcsv($file, $headers);
                        $firstRow = false;
                    }
                    fputcsv($file, array_values($row));
                    $count++;
                }
            } else {
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if ($firstRow) {
                        if (empty($headers)) {
                            $headers = array_keys($row);
                        }
                        fputcsv($file, $headers);
                        $firstRow = false;
                    }
                    fputcsv($file, array_values($row));
                    $count++;
                }
            }

            fclose($file);

            if ($count === 0) {
                @unlink($filepath);
                throw new \Core\Exceptions\BusinessException('هیچ داده‌ای برای صادرات وجود ندارد.');
            }

            $this->logOperation('export_csv', $filename, [
                'count' => $count,
            ]);

            return [
                'success' => true,
                'message' => $count . ' رکورد صادر شد.',
                'data' => [
                    'file_path' => $filepath,
                    'filename' => $filename,
                    'count' => $count,
                ]
            ];

        } catch (\Exception $e) {
            $this->logger->error('export_csv', $e);
            throw new \Core\Exceptions\BusinessException('خطا در صادرات فایل.');
        }
    }

    /**
     * صادرات به JSON
     * 
     * @param string $sql
     * @param array $params
     * @param string $filename
     * @return array
     */
    public function exportToJSON(
        string $sql,
        array $params = [],
        string $filename = 'export'
    ): array {
        try {
            $filename = $this->sanitizeFilename($filename);
            $filename .= '_' . date('Y-m-d_His') . '.json';
            
            $filepath = $this->getStoragePath('exports/' . $filename);

            $dir = dirname($filepath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $stmt = $this->db->query($sql, $params);
            
            $file = fopen($filepath, 'w');
            fwrite($file, "[\n");
            
            $count = 0;
            $first = true;
            
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                if (!$first) {
                    fwrite($file, ",\n");
                }
                fwrite($file, json_encode($row, JSON_UNESCAPED_UNICODE));
                $first = false;
                $count++;
            }
            
            fwrite($file, "\n]");
            fclose($file);

            if ($count === 0) {
                @unlink($filepath);
                throw new \Core\Exceptions\BusinessException('هیچ داده‌ای برای صادرات وجود ندارد.');
            }

            $this->logOperation('export_json', $filename, [
                'count' => $count,
            ]);

            return [
                'success' => true,
                'message' => $count . ' رکورد صادر شد.',
                'data' => [
                    'file_path' => $filepath,
                    'filename' => $filename,
                    'count' => $count,
                ]
            ];

        } catch (\Exception $e) {
            $this->logger->error('export_json', $e);
            throw new \Core\Exceptions\BusinessException('خطا در صادرات فایل.');
        }
    }

    /**
     * وارد‌سازی از CSV
     * 
     * @param string $filePath مسیر فایل
     * @param callable $processor تابع پردازش هر سطر: fn($row) => bool
     * @param bool $hasHeader آیا سطر اول header است؟
     * @return array
     */
    public function importFromCSV(
        string $filePath,
        callable $processor,
        bool $hasHeader = true
    ): array {
        if (!file_exists($filePath)) {
            throw new \Core\Exceptions\BusinessException('فایل یافت نشد.');
        }

        try {
            $file = fopen($filePath, 'r');
            
            if ($hasHeader) {
                fgetcsv($file); // Skip header
            }

            $results = [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'errors' => [],
            ];

            $this->db->beginTransaction();

            while (($row = fgetcsv($file)) !== FALSE) {
                $results['total']++;

                try {
                    if ($processor($row)) {
                        $results['success']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = "Row {$results['total']}: پردازش ناموفق";
                    }
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Row {$results['total']}: " . $e->getMessage();
                }
            }

            fclose($file);
            $this->db->commit();

            $this->logOperation('import_csv', basename($filePath), $results);

            return [
                'success' => true,
                'message' => "{$results['success']} از {$results['total']} رکورد وارد شد.",
                'data' => $results
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('import_csv', $e);
            
            throw new \Core\Exceptions\BusinessException('خطا در وارد‌سازی فایل.');
        }
    }

    /**
     * ارسال نوتیفیکیشن گروهی
     * 
     * @param array $userIds
     * @param string $type
     * @param string $title
     * @param string $message
     * @return array
     */
    public function bulkNotify(
        array $userIds,
        string $type,
        string $title,
        string $message
    ): array {
        if (!$this->notificationService) {
            throw new \Core\Exceptions\BusinessException('سرویس نوتیفیکیشن در دسترس نیست.');
        }

        if (empty($userIds)) {
            throw new \Core\Exceptions\BusinessException('هیچ کاربری انتخاب نشده است.');
        }

        $results = [
            'total' => count($userIds),
            'success' => 0,
            'failed' => 0,
        ];

        $batches = array_chunk($userIds, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            foreach ($batch as $userId) {
                try {
                    $this->eventDispatcher->dispatchAsync('notification.requested', [
                        'user_id' => $userId,
                        'type' => $type,
                        'title' => $title,
                        'message' => $message,
                        'data' => []
                    ]);
                    $results['success']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $this->logger->error('bulk_notify.send_failed', [
                        'user_id' => $userId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->logOperation('bulk_notify', 'notifications', $results);

        return [
            'success' => true,
            'message' => "نوتیفیکیشن به {$results['success']} کاربر ارسال شد.",
            'data' => $results
        ];
    }

    /**
     * اجرای کوئری سفارشی گروهی
     * 
     * @param string $sql
     * @param array $batchParams آرایه‌ای از پارامترها
     * @return array
     */
    public function executeCustomBulk(string $sql, array $batchParams): array
    {
        try {
            $cleanedSql = \strtolower(\trim($sql));
            if (\stripos($cleanedSql, 'update') !== 0) {
                throw new \Core\Exceptions\BusinessException('Only UPDATE statements are allowed.');
            }

            $allowedTables = [
                'users', 'transactions', 'submissions', 'content_submissions',
                'custom_tasks', 'bug_reports', 'bulk_operations', 'content_revenues', 'banners'
            ];
            $isAllowed = false;
            foreach ($allowedTables as $table) {
                if (\strpos($cleanedSql, $table) !== false) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed) {
                throw new \Core\Exceptions\BusinessException('Batch execution is only allowed on whitelisted tables.');
            }

            $this->db->beginTransaction();
            $stmt = $this->db->prepare($sql);
            $affected = 0;

            foreach ($batchParams as $params) {
                $stmt->execute($params);
                $affected += $stmt->rowCount();
            }
            $this->db->commit();

            return [
                'success' => true,
                'message' => "{$affected} رکورد تحت تأثیر قرار گرفت.",
                'data' => ['affected' => $affected]
            ];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('custom_bulk', $e);
            
            throw new \Core\Exceptions\BusinessException('خطا در اجرای عملیات گروهی.');
        }
    }

    // ==================== Private Helper Methods ====================

    /**
     * به‌روزرسانی یک batch
     */
    private function updateBatch(
        string $table,
        array $ids,
        array $data,
        string $idColumn
    ): int {
        return $this->bulkOperationModel->applyBatchUpdate($table, $ids, $data, $idColumn);
    }

    /**
     * حذف یک batch
     */
    private function deleteBatch(string $table, array $ids, string $idColumn): int
    {
        return $this->bulkOperationModel->applyBatchDelete($table, $ids, $idColumn);
    }

    /**
     * پاک‌سازی نام فایل
     */
    private function sanitizeFilename(string $filename): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $filename);
    }

    /**
     * دریافت مسیر ذخیره‌سازی
     */
    private function getStoragePath(string $path): string
    {
        $basePath = __DIR__ . '/../../storage/';
        return $basePath . ltrim($path, '/');
    }

    /**
     * لاگ عملیات
     */
    private function logOperation(
    string $operation,
    string $target,
    array $details = [],
    string $level = 'info'
): void {
    $method = in_array($level, ['debug','info','notice','warning','error','critical','alert','emergency'], true)
        ? $level
        : 'info';

    $this->logger->{$method}(sprintf(
        'Operation: %s | Target: %s | Details: %s',
        $operation,
        $target,
        json_encode($details, JSON_UNESCAPED_UNICODE)
    ), [
        'channel' => 'bulk_operations',
        'operation' => $operation,
        'target' => $target,
        'details' => $details,
    ]);
}

    /**
     * پاک‌سازی Cache
     */
    public function clearCache(string $pattern = '*'): void
    {
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            if ($redis) {
                // ✅ Using scanKeys() instead of keys() for performance
                $keys = $redis->scanKeys($pattern);
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            }
        }
    }
}

