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
    ) {}

    /**
     * ثبت نظر و امتیاز
     */
    public function rate(
        int $raterId,
        int $ratedId,
        string $refType,
        int $refId,
        int $rating,
        ?string $review = null
    ): bool {
        return $this->ratingModel->create([
            'rater_id' => $raterId,
            'rated_id' => $ratedId,
            'rated_type' => 'user', // Default to user, can be expanded
            'ref_type' => $refType,
            'ref_id' => $refId,
            'rating' => $rating,
            'review_text' => $review
        ]);
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
        try {
            $stmt = $this->db->prepare("
                INSERT INTO reports (reporter_id, ref_type, ref_id, reason, description, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            return $stmt->execute([
                $data['reporter_id'],
                $data['ref_type'],
                $data['ref_id'],
                $data['reason'],
                $data['description'] ?? null
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('report.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

