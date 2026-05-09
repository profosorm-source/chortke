<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Services\Shared\RatingService as SharedRatingService;
use App\Models\SocialTaskExecutionModel;
use App\Models\SocialTaskAnalyticsModel;
use App\Services\Shared\ScoreService;

use App\Contracts\LoggerInterface;
/**
 * RatingService - Adapter for SocialTask Rating
 *
 * سیستم امتیازدهی دوطرفه با استفاده از Shared RatingService.
 */
class RatingService extends \App\Services\BaseService
{
    // بازه مجاز برای ثبت امتیاز پس از تأیید (ساعت)
    private const RATING_WINDOW_HOURS = 72;

    public function __construct(
        private SharedRatingService $sharedRating,
        private SocialTaskExecutionModel $executionModel,
        private SocialTaskAnalyticsModel $analyticsModel,
        private ScoreService $trust,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * Executor به Advertiser امتیاز می‌دهد
     */
    public function rateAdvertiser(int $executionId, int $executorId, int $stars, string $comment = ''): array
    {
        $stars = max(1, min(5, $stars));

        $exec = $this->executionModel->getExecutionWithAd($executionId, $executorId);

        if (!$exec) {
            return ['success' => false, 'message' => 'اجرا یافت نشد یا تأیید نشده'];
        }

        if ($this->analyticsModel->hasUserRated($executionId, $executorId, 'executor')) {
            return ['success' => false, 'message' => 'قبلاً امتیاز داده‌اید'];
        }

        if (!$this->isWithinRatingWindow($exec->completed_at ?? $exec->created_at)) {
            return ['success' => false, 'message' => 'مهلت امتیازدهی گذشته است'];
        }

        // Use Shared RatingService
        $this->sharedRating->rate(
            $executorId,
            (int)$exec->advertiser_id,
            'social_task',
            $executionId,
            $stars,
            $comment
        );

        // Task-specific logic
        $this->updateAdvertiserRating((int)$exec->advertiser_id);

        return ['success' => true, 'message' => 'امتیاز ثبت شد'];
    }

    /**
     * Advertiser به Executor امتیاز می‌دهد
     */
    public function rateExecutor(int $executionId, int $advertiserId, int $stars, string $comment = ''): array
    {
        $stars = max(1, min(5, $stars));

        $exec = $this->executionModel->getExecutionWithAdForAdvertiser($executionId, $advertiserId);

        if (!$exec) {
            return ['success' => false, 'message' => 'اجرا یافت نشد'];
        }

        if ($this->analyticsModel->hasUserRated($executionId, $advertiserId, 'advertiser')) {
            return ['success' => false, 'message' => 'قبلاً امتیاز داده‌اید'];
        }

        if (!$this->isWithinRatingWindow($exec->completed_at ?? $exec->created_at)) {
            return ['success' => false, 'message' => 'مهلت امتیازدهی گذشته است'];
        }

        // Use Shared RatingService
        $this->sharedRating->rate(
            $advertiserId,
            (int)$exec->executor_id,
            'social_task',
            $executionId,
            $stars,
            $comment
        );

        if ($stars >= 4) {
            // Reward trust for good rating? Logic can be expanded here.
        }

        $this->updateExecutorRating((int)$exec->executor_id);

        return ['success' => true, 'message' => 'امتیاز ثبت شد'];
    }

    public function getAdvertiserRating(int $advertiserId): array
    {
        $row = $this->analyticsModel->getAvgRating($advertiserId, 'executor');
        return [
            'avg_stars'     => $row ? round((float)($row->avg_stars ?? 0), 1) : 0,
            'total_ratings' => $row ? (int)($row->total_ratings ?? 0) : 0,
        ];
    }

    public function getExecutorRating(int $executorId): array
    {
        $row = $this->analyticsModel->getAvgRating($executorId, 'advertiser');
        return [
            'avg_stars'     => $row ? round((float)($row->avg_stars ?? 0), 1) : 0,
            'total_ratings' => $row ? (int)($row->total_ratings ?? 0) : 0,
        ];
    }

    public function getComments(int $userId, string $raterType = 'advertiser', int $limit = 10): array
    {
        return $this->analyticsModel->getUserRatingHistory($userId, $raterType, $limit);
    }

    public function getPendingReviews(int $limit = 20, int $offset = 0): array
    {
        return $this->analyticsModel->getPendingRatings($limit, $offset);
    }

    public function moderateReview(int $reviewId, string $status, int $adminId): array
    {
        $allowed = ['approved', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'message' => 'وضعیت نامعتبر است'];
        }

        $review = $this->analyticsModel->getRatingById($reviewId);
        if (!$review) {
            return ['success' => false, 'message' => 'بررسی یافت نشد'];
        }

        $this->analyticsModel->updateRatingStatus($reviewId, $status, $adminId);

        if ($status === 'approved') {
            if ($review->rater_type === 'executor') {
                $this->updateAdvertiserRating((int)$review->rated_id);
            } else {
                $this->updateExecutorRating((int)$review->rated_id);
            }
        }

        return ['success' => true, 'message' => 'بررسی با موفقیت به‌روزرسانی شد'];
    }

    public function getReviewStats(): array
    {
        $summary = $this->analyticsModel->getRatingStats();
        return [
            'pending_reviews'  => $summary ? (int)($summary->pending_reviews ?? 0) : 0,
            'approved_reviews' => $summary ? (int)($summary->approved_reviews ?? 0) : 0,
            'rejected_reviews' => $summary ? (int)($summary->rejected_reviews ?? 0) : 0,
        ];
    }

    public function getRatingHistory(int $userId, string $role = 'rated', int $limit = 20, int $offset = 0): array
    {
        $column = $role === 'rater' ? 'rater_id' : 'rated_id';
        return $this->analyticsModel->getRatingHistoryFull($userId, $column, $limit, $offset);
    }

    private function isWithinRatingWindow(?string $completedAt): bool
    {
        if (!$completedAt) return false;
        $completed = strtotime($completedAt);
        return (time() - $completed) <= (self::RATING_WINDOW_HOURS * 3600);
    }

    private function updateAdvertiserRating(int $advertiserId): void
    {
        $rating = $this->getAdvertiserRating($advertiserId);
        $this->analyticsModel->updateUserStats($advertiserId, (float)$rating['avg_stars'], (int)$rating['total_ratings'], 'advertiser');
    }

    private function updateExecutorRating(int $executorId): void
    {
        $rating = $this->getExecutorRating($executorId);
        $this->analyticsModel->updateUserStats($executorId, (float)$rating['avg_stars'], (int)$rating['total_ratings'], 'executor');
    }
}

