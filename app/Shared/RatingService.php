<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use App\Models\Rating;

use App\Contracts\LoggerInterface;
/**
 * RatingService - سرویس اشتراکی مدیریت نظرات، امتیازدهی، علاقه‌مندی‌ها و گزارشات
 * 
 * این سرویس تمامی تعاملات کاربر با محتوا (تسک، پروفایل و ...) را مدیریت می‌کند.
 */
class RatingService extends \App\Services\BaseService
{
    public function __construct(
        private Database $db,
        protected LoggerInterface $logger,
        private Rating $ratingModel
    ) {
        parent::__construct($logger);
    }

    /**
     * ثبت نظر و امتیاز
     */
    public function rate(
        int $raterId,
        int $ratedId,
        string $refType,
        int $refId,
        int $rating,
        ?string $review = null,
        string $ratedType = 'user'
    ): bool {
        // Guard: Block duplicate multi-rating attempts
        if ($this->ratingModel->hasRated($raterId, $refType, $refId)) {
            $this->logWarning('rating.duplicate_attempted', [
                'rater_id' => $raterId,
                'ref_type' => $refType,
                'ref_id' => $refId
            ]);
            return false;
        }

        $ok = $this->ratingModel->create([
            'rater_id' => $raterId,
            'rated_id' => $ratedId,
            'rated_type' => $ratedType, // Support dynamic injection
            'ref_type' => $refType,
            'ref_id' => $refId,
            'rating' => $rating,
            'review_text' => $review
        ]);

        if ($ok) {
            $this->logInfo('rating.submitted', [
                'rater_id' => $raterId,
                'ref_id' => $refId,
                'rating' => $rating
            ]);
        }

        return (bool)$ok;
    }

    /**
     * افزودن به علاقه‌مندی‌ها
     */
    public function favorite(int $userId, string $refType, int $refId): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO favorites (user_id, ref_type, ref_id, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            return $stmt->execute([$userId, $refType, $refId]);
        } catch (\Throwable $e) {
            $this->logger->error('favorite.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * حذف از علاقه‌مندی‌ها
     */
    public function unfavorite(int $userId, string $refType, int $refId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM favorites WHERE user_id = ? AND ref_type = ? AND ref_id = ?
        ");
        return $stmt->execute([$userId, $refType, $refId]);
    }

    /**
     * گزارش تخلف
     */
    public function report(array $data): bool
    {
        // Schema Guard Validation: Protect data structure and domain safety.
        $this->guardValidation($data, [
            'reporter_id' => 'required|numeric',
            'ref_type' => 'required|string',
            'ref_id' => 'required|numeric',
            'reason' => 'required|string'
        ]);

        try {
            $stmt = $this->db->prepare("
                INSERT INTO reports (reporter_id, ref_type, ref_id, reason, description, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            return $stmt->execute([
                (int)$data['reporter_id'],
                (string)$data['ref_type'],
                (int)$data['ref_id'],
                (string)$data['reason'],
                $data['description'] ?? null
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('report.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

