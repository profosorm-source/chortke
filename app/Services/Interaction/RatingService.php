<?php

declare(strict_types=1);

namespace App\Services\Interaction;

use App\Enums\InteractionType;
use App\Enums\ModuleContext;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * سرویس مدیریت امتیازدهی (ستاره دادن) به محتواها
 * مسئولیت: ثبت ریتینگ ۱ تا ۵ برای هر مدل پلیمورفیک در هر ماژول
 */
class RatingService extends \App\Services\BaseService
{
    public function __construct(
        private Database $db,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * ثبت یا آپدیت امتیاز یک کاربر برای یک موجودیت
     *
     * @param int $score امتیاز بین ۱ تا ۵
     */
    public function rate(int $userId, string $interactableType, int $interactableId, ModuleContext $context, int $score): bool
    {
        if ($score < 1 || $score > 5) {
            throw new \InvalidArgumentException("Rating score must be between 1 and 5.");
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO interactions 
                (user_id, interactable_type, interactable_id, interaction_type, context, value, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()
            ");

            return $stmt->execute([
                $userId,
                $interactableType,
                $interactableId,
                InteractionType::RATING->value,
                $context->value,
                $score
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('rating_service.rate_failed', [
                'user_id' => $userId,
                'entity' => $interactableType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * دریافت میانگین امتیازات یک موجودیت
     */
    public function getAverageRating(string $interactableType, int $interactableId): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(AVG(value), 0.0) FROM interactions
            WHERE interactable_type = ? AND interactable_id = ? AND interaction_type = ?
        ");
        
        $stmt->execute([
            $interactableType,
            $interactableId,
            InteractionType::RATING->value
        ]);

        return (float)$stmt->fetchColumn();
    }
}
