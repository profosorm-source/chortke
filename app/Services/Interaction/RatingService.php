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
class RatingService
{
    private \Core\TransactionWrapper $transactionWrapper;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \Core\RateLimiter $rateLimiter;
    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \Core\RateLimiter $rateLimiter
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->db = $db;
        $this->logger = $logger;
        $this->rateLimiter = $rateLimiter;

        
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

        // جلوگیری از Rating Bombing (محدودیت هر کاربر در یک دقیقه)
        $rateKey = "rating_bomb:{$userId}:{$interactableType}:{$interactableId}";
        if (!$this->rateLimiter->attempt($rateKey, 1, 1, false)) {
            $this->logger->warning('rating_service.rate_limited', ['user_id' => $userId, 'entity' => $interactableType]);
            return false;
        }

        return $this->getTransactionWrapper()->runWithRetry(function() use ($userId, $interactableType, $interactableId, $context, $score) {
            // جلوگیری از Duplicate از طریق Pessimistic Lock
            $stmt = $this->db->prepare("
                SELECT id FROM interactions 
                WHERE user_id = ? AND interactable_type = ? AND interactable_id = ? AND interaction_type = ?
                FOR UPDATE
            ");
            $stmt->execute([$userId, $interactableType, $interactableId, InteractionType::RATING->value]);
            $existing = $stmt->fetchColumn();

            if ($existing) {
                $update = $this->db->prepare("UPDATE interactions SET value = ?, updated_at = NOW() WHERE id = ?");
                return $update->execute([$score, $existing]);
            } else {
                $insert = $this->db->prepare("
                    INSERT INTO interactions 
                    (user_id, interactable_type, interactable_id, interaction_type, context, value, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                return $insert->execute([$userId, $interactableType, $interactableId, InteractionType::RATING->value, $context->value, $score]);
            }
        });
    }

    /**
     * دریافت میانگین امتیازات یک موجودیت
     */
    public function getAverageRating(string $interactableType, int $interactableId): float
    {
        // Bayesian Average محاسبه به روش
        // C = Average rating of all entities (e.g. 3.0)
        // m = Minimum ratings required to be included in top-rated (e.g. 5)
        // R = Average rating for the entity
        // v = Number of ratings for the entity
        // Score = (v / (v+m)) * R + (m / (v+m)) * C
        
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(id) as total_votes,
                COALESCE(AVG(value), 0.0) as average_rating
            FROM interactions
            WHERE interactable_type = ? AND interactable_id = ? AND interaction_type = ?
        ");
        
        $stmt->execute([$interactableType, $interactableId, InteractionType::RATING->value]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $v = (int)($result['total_votes'] ?? 0);
        $R = (float)($result['average_rating'] ?? 0.0);
        
        if ($v === 0) {
            return 0.0;
        }

        $C = 3.0; // فرض سیستم
        $m = 5;   // حداقل آرای لازم
        
        $score = ($v / ($v + $m)) * $R + ($m / ($v + $m)) * $C;
        
        return round($score, 2);
    }
}
