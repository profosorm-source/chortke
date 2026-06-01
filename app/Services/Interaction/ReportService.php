<?php

declare(strict_types=1);

namespace App\Services\Interaction;

use App\Enums\InteractionType;
use App\Enums\ModuleContext;
use App\Contracts\LoggerInterface;
use Core\Database;
use Core\EventDispatcher;

/**
 * سرویس مدیریت گزارش تخلفات (Reports)
 * مسئولیت: ثبت گزارش تخلف برای محتوا یا کاربران، و اخطار/مسدودسازی در صورت نیاز
 */
class ReportService
{
    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;

        
    }

    /**
     * ثبت ریپورت برای یک موجودیت
     */
    public function submit(int $reporterId, string $interactableType, int $interactableId, ModuleContext $context, string $reason, ?string $description = null): bool
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO interactions 
                (user_id, interactable_type, interactable_id, interaction_type, context, meta_json, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $meta = json_encode([
                'reason' => $reason,
                'description' => $description,
                'status' => 'pending' // pending, reviewed, resolved, rejected
            ]);

            $stmt->execute([
                $reporterId,
                $interactableType,
                $interactableId,
                InteractionType::REPORT->value,
                $context->value,
                $meta
            ]);

            $reportId = (int)$this->db->lastInsertId();

            $this->db->commit();

            // در صورتی که تعداد ریپورت‌های یک محتوا از حدی گذشت، ایونت شلیک شود
            if ($this->getReportCount($interactableType, $interactableId) >= 5) {
                $this->eventDispatcher->dispatchAsync('report.threshold_reached', (object)[
                    'entity_type' => $interactableType,
                    'entity_id' => $interactableId,
                    'context' => $context->value
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('report_service.submit_failed', [
                'reporter_id' => $reporterId,
                'entity' => $interactableType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * دریافت تعداد ریپورت‌های یک موجودیت
     */
    public function getReportCount(string $interactableType, int $interactableId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(id) FROM interactions 
            WHERE interactable_type = ? AND interactable_id = ? AND interaction_type = ?
        ");
        $stmt->execute([
            $interactableType,
            $interactableId,
            InteractionType::REPORT->value
        ]);
        
        return (int)$stmt->fetchColumn();
    }
}
