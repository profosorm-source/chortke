<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\CacheInterface;
use App\Models\BulkOperation;
use App\Services\Notification\NotificationService;
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
class BulkOperationsService extends \App\Services\BaseService
{
    private Database $db;
    private CacheInterface $cache;
    private BulkOperation $bulkOperationModel;
    private ?NotificationService $notificationService;

    private const MAX_BULK_ITEMS = 1000;
    private const BATCH_SIZE = 100;

    public function __construct(
        LoggerInterface $logger,
        Database $db,
        BulkOperation $bulkOperationModel,
        CacheInterface $cache,
        ?NotificationService $notificationService = null
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->bulkOperationModel = $bulkOperationModel;
        $this->cache = $cache;
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
            return $this->errorResponse('هیچ آیتمی انتخاب نشده است.');
        }

        if (count($ids) > self::MAX_BULK_ITEMS) {
            return $this->errorResponse(
                'حداکثر ' . self::MAX_BULK_ITEMS . ' آیتم قابل پردازش است.'
            );
        }

        if (empty($data)) {
            return $this->errorResponse('داده‌ای برای به‌روزرسانی وجود ندارد.');
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

            return $this->successResponse(
                "{$updated} رکورد به‌روز شد.",
                ['updated' => $updated]
            );

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('bulk_update', $e);
            
            return $this->errorResponse('خطا در به‌روزرسانی گروهی.');
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
            return $this->errorResponse('حذف سخت نیاز به تأیید دارد.');
        }

        if (empty($ids)) {
            return $this->errorResponse('هیچ آیتمی انتخاب نشده است.');
        }

        if (count($ids) > self::MAX_BULK_ITEMS) {
            return $this->errorResponse(
                'حداکثر ' . self::MAX_BULK_ITEMS . ' آیتم قابل پردازش است.'
            );
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

            return $this->successResponse(
                "{$deleted} رکورد حذف شد.",
                ['deleted' => $deleted]
            );

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('bulk_hard_delete', $e);
            
            return $this->errorResponse('خطا در حذف گروهی.');
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
        string $sql,
        array $params = [],
        array $headers = [],
        string $filename = 'export'
    ): array {
        try {
            $data = $this->bulkOperationModel->executeQuery($sql, $params);

            if (empty($data)) {
                return $this->errorResponse('هیچ داده‌ای برای صادرات وجود ندارد.');
            }

            // ایجاد نام فایل
            $filename = $this->sanitizeFilename($filename);
            $filename .= '_' . date('Y-m-d_His') . '.csv';
            
            $filepath = $this->getStoragePath('exports/' . $filename);

            // اطمینان از وجود دایرکتوری
            $dir = dirname($filepath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // ایجاد فایل CSV
            $file = fopen($filepath, 'w');
            
            // UTF-8 BOM برای Excel
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Headers
            if (empty($headers)) {
                $headers = array_keys($data[0]);
            }
            fputcsv($file, $headers);

            // Data
            foreach ($data as $row) {
                fputcsv($file, array_values($row));
            }

            fclose($file);

            $this->logOperation('export_csv', $filename, [
                'count' => count($data),
            ]);

            return $this->successResponse(
                count($data) . ' رکورد صادر شد.',
                [
                    'file_path' => $filepath,
                    'filename' => $filename,
                    'count' => count($data),
                ]
            );

        } catch (\Exception $e) {
            $this->logError('export_csv', $e);
            return $this->errorResponse('خطا در صادرات فایل.');
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
            $data = $this->bulkOperationModel->executeQuery($sql, $params);

            if (empty($data)) {
                return $this->errorResponse('هیچ داده‌ای برای صادرات وجود ندارد.');
            }

            $filename = $this->sanitizeFilename($filename);
            $filename .= '_' . date('Y-m-d_His') . '.json';
            
            $filepath = $this->getStoragePath('exports/' . $filename);

            $dir = dirname($filepath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents(
                $filepath,
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );

            $this->logOperation('export_json', $filename, [
                'count' => count($data),
            ]);

            return $this->successResponse(
                count($data) . ' رکورد صادر شد.',
                [
                    'file_path' => $filepath,
                    'filename' => $filename,
                    'count' => count($data),
                ]
            );

        } catch (\Exception $e) {
            $this->logError('export_json', $e);
            return $this->errorResponse('خطا در صادرات فایل.');
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
            return $this->errorResponse('فایل یافت نشد.');
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

            return $this->successResponse(
                "{$results['success']} از {$results['total']} رکورد وارد شد.",
                $results
            );

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('import_csv', $e);
            
            return $this->errorResponse('خطا در وارد‌سازی فایل.');
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
            return $this->errorResponse('سرویس نوتیفیکیشن در دسترس نیست.');
        }

        if (empty($userIds)) {
            return $this->errorResponse('هیچ کاربری انتخاب نشده است.');
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
                    $this->notificationService->send($userId, $type, $title, $message);
                    $results['success']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                }
            }
        }

        $this->logOperation('bulk_notify', 'notifications', $results);

        return $this->successResponse(
            "نوتیفیکیشن به {$results['success']} کاربر ارسال شد.",
            $results
        );
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
            $this->db->beginTransaction();
            $affected = $this->bulkOperationModel->executeBatch($sql, $batchParams);
            $this->db->commit();

            return $this->successResponse(
                "{$affected} رکورد تحت تأثیر قرار گرفت.",
                ['affected' => $affected]
            );

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('custom_bulk', $e);
            
            return $this->errorResponse('خطا در اجرای عملیات گروهی.');
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

    // successResponse/errorResponse دریافت شده‌اند از BaseService

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

