<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use App\Models\Rating;
use Core\Validator;

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
        $this->validateRatingInput($raterId, $ratedId, $refType, $refId, $rating, $review, $ratedType);

        $ok = $this->ratingModel->createOnce([
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
        } else {
            $this->logWarning('rating.duplicate_or_lock_failed', [
                'rater_id' => $raterId,
                'ref_type' => $refType,
                'ref_id' => $refId,
            ]);
        }

        return (bool)$ok;
    }

    private function validateRatingInput(
        int $raterId,
        int $ratedId,
        string $refType,
        int $refId,
        int $rating,
        ?string $review,
        string $ratedType
    ): void {
        if ($raterId <= 0 || $ratedId <= 0 || $refId <= 0) {
            throw new \InvalidArgumentException('Invalid rating identifiers.');
        }
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be between 1 and 5.');
        }
        if (!$this->isSafeReference($refType) || !$this->isSafeReference($ratedType)) {
            throw new \InvalidArgumentException('Invalid rating reference type.');
        }
        if ($review !== null && mb_strlen($review) > 2000) {
            throw new \InvalidArgumentException('Review text is too long.');
        }
    }

    private function validateReportInput(array $data): void
    {
        $validator = new Validator($data, [
            'reporter_id' => 'required|integer|min:1',
            'ref_type' => 'required|max:50',
            'ref_id' => 'required|integer|min:1',
            'reason' => 'required|max:100',
        ], $this->db);

        if ($validator->fails()) {
            throw new \InvalidArgumentException('Invalid report payload: ' . json_encode($validator->errors(), JSON_UNESCAPED_UNICODE));
        }

        if (!$this->isSafeReference((string)$data['ref_type'])) {
            throw new \InvalidArgumentException('Invalid report reference type.');
        }

        if (isset($data['description']) && mb_strlen((string)$data['description']) > 2000) {
            throw new \InvalidArgumentException('Report description is too long.');
        }
    }

    private function isSafeReference(string $value): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9_:-]{1,49}$/i', $value);
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
        $this->validateReportInput($data);

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

