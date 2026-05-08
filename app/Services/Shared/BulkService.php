<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use App\Contracts\LoggerInterface;
/**
 * BulkService - سرویس اشتراکی عملیات گروهی
 * 
 * مدیریت تراکنش‌های سنگین گروهی و لاگ کردن نتایج آن‌ها.
 */
class BulkService extends \App\Services\BaseService
{
    public function __construct(
        private Database $db,
        protected LoggerInterface $logger
    ) {}

    /**
     * اجرای یک عملیات روی لیستی از شناسه‌ها
     * 
     * @param string $domain دامنه عملیات (task, user, order, etc)
     * @param array $ids لیست شناسه‌ها
     * @param callable $action تابعی که روی هر شناسه اجرا می‌شود
     */
    public function process(string $domain, array $ids, callable $action): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($ids as $id) {
            try {
                $this->db->beginTransaction();
                $res = $action($id);
                if ($res) {
                    $this->db->commit();
                    $results['success']++;
                } else {
                    $this->db->rollBack();
                    $results['failed']++;
                }
            } catch (\Throwable $e) {
                $this->db->rollBack();
                $results['failed']++;
                $results['errors'][$id] = $e->getMessage();
                $this->logger->error("bulk.process_failed", ['id' => $id, 'domain' => $domain, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }
}

