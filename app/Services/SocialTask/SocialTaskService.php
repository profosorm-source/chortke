<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Services\WalletService;
use App\Services\Notification\NotificationService;
use App\Services\ApiRateLimiter;
use App\Services\FinancialEscrowService;
use App\Services\StateMachineService;
use App\Services\RealTimeService;
use App\Models\SocialTaskModel;
use App\Contracts\LoggerInterface;
/**
 * SocialTaskService
 *
 * هماهنگ‌کننده اصلی ماژول SocialTask.
 */
class SocialTaskService extends \App\Services\BaseService
{
    // تسک‌های یوتیوب جدا هستند
    private const EXCLUDED_PLATFORMS_FROM_SOCIAL = ['youtube'];

    // زمان انتظار (ثانیه) برای rate limit per task_type
    private const TASK_EXPECTED_TIME = [
        'follow'       => 45,
        'like'         => 20,
        'comment'      => 90,
        'share'        => 30,
        'retweet'      => 25,
        'join_channel' => 30,
        'join_group'   => 30,
    ];

    public function __construct(
        private SocialTaskModel $model,
        private SocialTaskScoringService $scoring,
        private TrustScoreService $trust,
        private SilentAntiFraudService $antiFraud,
        private WalletService $wallet,
        private NotificationService $notification,
        private ApiRateLimiter $rateLimiter,
        protected LoggerInterface $logger,
        private FinancialEscrowService $escrow,
        private StateMachineService $stateMachine,
        private RealTimeService $realTime,
        private \App\Services\Shared\RatingService $ratingService
    ) {}

    /**
     * لیست تسک‌های فعال برای کاربر با اعمال فیلتر نامحسوس
     */
    public function getTasksForExecutor(int $userId, array $filters = [], int $limit = 20): array
    {
        $restriction = $this->antiFraud->getRestrictionLevel($userId);
        $effectiveLimit = $this->antiFraud->filterTaskCount($userId, $limit);

        $where = [
            "sa.status = 'active'",
            "sa.remaining_slots > 0",
            "sa.platform NOT IN ('" . implode("','", self::EXCLUDED_PLATFORMS_FROM_SOCIAL) . "')",
            "NOT EXISTS (
                SELECT 1 FROM social_task_executions ste
                WHERE ste.ad_id = sa.id
                  AND ste.executor_id = ?
                  AND ste.status NOT IN ('expired','cancelled')
            )"
        ];
        $params = [$userId];

        if (!empty($filters['platform'])) {
            $where[] = 'sa.platform = ?';
            $params[] = $filters['platform'];
        }

        if (!empty($filters['task_type'])) {
            $where[] = 'sa.task_type = ?';
            $params[] = $filters['task_type'];
        }

        if (!empty($filters['min_reward'])) {
            $where[] = 'sa.reward >= ?';
            $params[] = (float)$filters['min_reward'];
        }
        if (!empty($filters['max_reward'])) {
            $where[] = 'sa.reward <= ?';
            $params[] = (float)$filters['max_reward'];
        }

        $medianReward = $this->model->getMedianReward();
        if (empty($filters['is_mobile'])) {
            $where[] = 'sa.reward <= ?';
            $params[] = $medianReward;
        }

        if (!empty($filters['search'])) {
            $like = '%' . $this->sanitizeSearch((string)$filters['search']) . '%';
            $where[] = '(sa.title LIKE ? OR sa.description LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $orderBy = match ($filters['sort'] ?? 'random') {
            'price_desc' => 'sa.reward DESC',
            'price_asc'  => 'sa.reward ASC',
            'newest'     => 'sa.created_at DESC',
            default      => 'RAND()',
        };

        $tasks = $this->model->getActiveAds($where, $params, $orderBy, $effectiveLimit);

        foreach ($tasks as &$task) {
            $task->display_reward = $this->antiFraud->adjustedReward($userId, (float)$task->reward);
            $task->trust_display = $this->trust->get($userId);
        }

        return [
            'tasks' => $tasks,
            'restriction_level' => $restriction['level'],
            'trust_score' => $this->trust->get($userId),
        ];
    }

    public function adminRejectAd(int $adminId, int $adId, string $reason): array
    {
        try {
            $ad = $this->model->getAdById($adId);
            if (!$ad) return ['success' => false, 'message' => 'تبلیغ یافت نشد'];

            if (in_array($ad->status, ['completed', 'cancelled', 'rejected'], true)) {
                return ['success' => false, 'message' => 'این تبلیغ قابل رد شدن نیست'];
            }

            $this->model->updateAdStatus($adId, 'rejected', [
                'reject_reason' => $reason,
                'reviewed_by' => $adminId,
                'reviewed_at' => date('Y-m-d H:i:s')
            ]);

            return ['success' => true, 'message' => 'تبلیغ با موفقیت رد شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطا در رد تبلیغ: ' . $e->getMessage()];
        }
    }

    public function adminCancelAd(int $adminId, int $adId): array
    {
        try {
            $this->model->beginTransaction();
            $ad = $this->model->getAdById($adId, true);

            if (!$ad) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'تبلیغ یافت نشد'];
            }

            if (in_array($ad->status, ['completed', 'cancelled'], true)) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'این تبلیغ قابل لغو نیست'];
            }

            $refund = (float)($ad->remaining_budget ?? 0);
            if ($refund > 0) {
                $walletResult = $this->wallet->deposit((int)$ad->user_id, $refund, 'irt', [
                    'type' => 'social_ad_refund',
                    'description' => "Refund for cancelled social ad #{$adId}",
                    'gateway' => 'social_ad_refund',
                    'gateway_transaction_id' => 'refund_' . $adId . '_' . time(),
                    'ref_id' => $adId,
                    'ref_type' => 'social_ad',
                ]);

                if (empty($walletResult['success'])) {
                    $this->model->rollBack();
                    return ['success' => false, 'message' => $walletResult['message'] ?? 'خطا در بازگشت وجه'];
                }
            }

            $this->model->updateAdStatus($adId, 'cancelled');
            $this->model->commit();

            return ['success' => true, 'message' => 'تبلیغ لغو شد', 'refund' => $refund];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            return ['success' => false, 'message' => 'خطا در لغو تبلیغ: ' . $e->getMessage()];
        }
    }

    public function adminFlagExecution(int $adminId, int $executionId, string $note = ''): array
    {
        try {
            $exec = $this->model->getExecutionById($executionId);
            if (!$exec) return ['success' => false, 'message' => 'اجرا یافت نشد'];

            if (in_array($exec->status, ['expired', 'cancelled'], true)) {
                return ['success' => false, 'message' => 'این اجرا قابل علامت‌گذاری نیست'];
            }

            $this->model->flagExecution($executionId, $note);
            return ['success' => true, 'message' => 'اجرا برای بررسی علامت‌گذاری شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطا در علامت‌گذاری اجرا'];
        }
    }

    public function adminOverrideExecution(int $adminId, int $executionId, string $decision, string $reason): array
    {
        try {
            if (!in_array($decision, ['approved', 'soft_approved', 'rejected'], true)) {
                return ['success' => false, 'message' => 'تصمیم معتبر نیست'];
            }

            $reason = trim($reason);
            if ($reason === '') return ['success' => false, 'message' => 'دلیل override الزامی است'];

            $this->model->beginTransaction();
            $exec = $this->model->getExecutionById($executionId, true);

            if (!$exec) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'اجرا یافت نشد'];
            }

            $this->model->updateExecutionStatus($executionId, $decision, [
                'decision' => $decision,
                'override_reason' => $reason,
                'reviewed_by' => $adminId,
                'reviewed_at' => date('Y-m-d H:i:s')
            ]);

            $this->model->commit();
            return ['success' => true, 'message' => 'تصمیم با موفقیت override شد', 'old_decision' => $exec->decision ?? null, 'new_decision' => $decision];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            return ['success' => false, 'message' => 'خطا در override تصمیم'];
        }
    }

    public function adminAdjustTrust(int $adminId, int $userId, float $delta, string $reason): array
    {
        try {
            $reason = trim($reason);
            if ($reason === '') return ['success' => false, 'message' => 'دلیل الزامی است'];
            if ($delta == 0.0) return ['success' => false, 'message' => 'مقدار تغییر نمی‌تواند صفر باشد'];

            $this->model->beginTransaction();
            $row = $this->model->getUserTrust($userId, true);

            $oldTrust = $row ? (float)$row->trust_score : 50.0;
            $newTrust = max(0.0, min(100.0, $oldTrust + $delta));

            $this->model->upsertUserTrust($userId, $newTrust);
            $this->model->recordTrustAdjustment([
                'user_id' => $userId,
                'admin_id' => $adminId,
                'delta' => $delta,
                'old_trust' => $oldTrust,
                'new_trust' => $newTrust,
                'reason' => $reason
            ]);

            $this->model->commit();
            return ['success' => true, 'message' => 'امتیاز اعتماد با موفقیت تغییر کرد', 'old_trust' => $oldTrust, 'new_trust' => $newTrust];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            return ['success' => false, 'message' => 'خطا در تغییر امتیاز اعتماد'];
        }
    }

    public function startExecution(int $userId, int $adId, array $context = []): array
    {
        try {
            $this->model->beginTransaction();
            $ad = $this->model->getAdById($adId, true);

            if (!$ad || $ad->status !== 'active' || $ad->remaining_slots <= 0) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'تسک موجود نیست یا ظرفیت تکمیل شده'];
            }

            // Check existing
            $existing = $this->model->getExecutionWithAd($adId, $userId);
            if ($existing && !in_array($existing->status, ['expired', 'cancelled'], true)) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'قبلاً این تسک را شروع کرده‌اید'];
            }

            if (!$this->rateLimiter->check('task_submit', $userId, 50, 60)) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'تعداد تسک در این ساعت به حد مجاز رسیده است'];
            }

            $expectedTime = self::TASK_EXPECTED_TIME[$ad->task_type] ?? 60;

            if ($this->model->decrementAdSlots($adId) < 1) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'ظرفیت تکمیل شده'];
            }

            $execId = $this->model->createExecution([
                'ad_id' => $adId,
                'executor_id' => $userId,
                'ip_address' => $context['ip'] ?? '',
                'user_agent' => $context['user_agent'] ?? '',
                'expected_time' => $expectedTime
            ]);

            $this->model->commit();
            return ['success' => true, 'execution_id' => $execId, 'expected_time' => $expectedTime, 'target_url' => $ad->target_url, 'task_type' => $ad->task_type];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            return ['success' => false, 'message' => 'خطا در شروع تسک'];
        }
    }

    public function recordBehaviorSignals(int $executionId, int $userId, array $signals): bool
    {
        $exec = $this->model->getExecutionById($executionId);
        if (!$exec || (int)$exec->executor_id !== $userId) return false;

        $behaviorData = $this->model->getBehaviorData($executionId);
        $prevData = $behaviorData ? json_decode($behaviorData, true) ?: [] : [];

        $merged = $this->mergeBehaviorSignals($prevData, $signals);
        $this->model->updateExecutionBehavior($executionId, json_encode($merged, JSON_UNESCAPED_UNICODE));

        return true;
    }

    public function submitExecution(int $userId, int $executionId, array $payload = []): array
    {
        try {
            $this->model->beginTransaction();
            $exec = $this->model->getExecutionWithAd($executionId, $userId, true);

            if (!$exec) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'رکورد اجرا یافت نشد'];
            }

            if ($exec->status !== 'pending') {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'وضعیت اجرا برای ارسال معتبر نیست'];
            }

            $proofUrl = trim((string)($payload['proof_url'] ?? ''));
            $proofText = trim((string)($payload['proof_text'] ?? ''));

            if ($proofUrl === '' && $proofText === '') {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'مدرک انجام تسک الزامی است'];
            }

            $score = $this->antiFraud->scoreExecution($exec, $payload);
            $decision = $this->antiFraud->decisionFromScore($score);

            $finalStatus = ($decision['decision'] ?? '') === 'reject' ? 'rejected' : 'approved';
            $rewardPaid = 0;
            $rewardAmount = 0.0;

            if (!empty($decision['pay_reward'])) {
                $rewardAmount = (float)$this->antiFraud->adjustedReward($userId, (float)$exec->reward);

                if ($rewardAmount > 0) {
                    $pay = $this->wallet->deposit($userId, $rewardAmount, 'irt', [
                        'source' => 'social_task_reward',
                        'execution_id' => $executionId,
                        'ad_id' => (int)$exec->ad_id,
                        'task_type' => $exec->task_type ?? null,
                        'decision' => $decision['decision'] ?? null,
                        'risk_score' => $score['score'] ?? null,
                    ]);

                    if (empty($pay['success'])) {
                        $this->model->rollBack();
                        return ['success' => false, 'message' => $pay['message'] ?? 'خطا در پرداخت پاداش'];
                    }
                    $rewardPaid = 1;
                }
            }

            $this->model->updateExecutionStatus($executionId, $finalStatus, [
                'proof_url' => $proofUrl !== '' ? $proofUrl : null,
                'proof_text' => $proofText !== '' ? $proofText : null,
                'anti_fraud_score' => (float)($score['score'] ?? 0),
                'anti_fraud_decision' => (string)($decision['decision'] ?? 'unknown'),
                'reward_amount' => $rewardAmount,
                'reward_paid' => $rewardPaid,
                'submitted_at' => date('Y-m-d H:i:s')
            ]);

            $this->realTime->notifyExecutionSubmitted($executionId, (int)($exec->advertiser_id ?? 0), $exec->task_type ?? 'Unknown');
            $this->model->commit();

            return ['success' => true, 'message' => $finalStatus === 'approved' ? 'تسک با موفقیت تایید شد' : 'تسک رد شد', 'status' => $finalStatus, 'reward_paid' => (bool)$rewardPaid, 'reward_amount' => $rewardAmount, 'risk_score' => (float)($score['score'] ?? 0), 'decision' => (string)($decision['decision'] ?? 'unknown')];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            return ['success' => false, 'message' => 'خطا در ثبت نهایی تسک'];
        }
    }

    public function advertiserApprove(int $advertiserId, int $executionId): array
    {
        $exec = $this->model->getExecutionById($executionId);
        if (!$exec || (int)$exec->advertiser_id !== $advertiserId) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست'];
        }

        $this->model->updateExecutionStatus($executionId, 'approved');
        return ['success' => true, 'message' => 'اجرا تأیید شد'];
    }

    public function advertiserReject(int $advertiserId, int $executionId, string $reason): array
    {
        if (empty(trim($reason))) return ['success' => false, 'message' => 'دلیل رد الزامی است'];

        $exec = $this->model->getExecutionById($executionId);
        if (!$exec || (int)$exec->advertiser_id !== $advertiserId) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست'];
        }

        $this->model->updateExecutionStatus($executionId, 'rejected', ['reject_reason' => $reason]);
        $this->trust->penalizeRejection((int)$exec->executor_id, $executionId);

        return ['success' => true, 'message' => 'اجرا رد شد'];
    }

    public function getAdById(int $adId): ?object
    {
        return $this->model->getAdById($adId);
    }

    public function getUserAccounts(int $userId): array
    {
        $sql = "SELECT * FROM user_social_accounts WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC";
        return $this->model->getDb()->query($sql, [$userId])->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function addAccount(int $userId, string $platform, string $username, string $accessToken = ''): array
    {
        try {
            $this->model->beginTransaction();

            $existsSql = "SELECT COUNT(*) FROM user_social_accounts WHERE platform = ? AND username = ? AND deleted_at IS NULL";
            $count = (int)$this->model->getDb()->fetchColumn($existsSql, [$platform, $username]);
            if ($count > 0) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'این حساب کاربری قبلاً ثبت شده است'];
            }

            $sql = "INSERT INTO user_social_accounts (user_id, platform, username, profile_url, follower_count, following_count, post_count, engagement_rate, account_age_months, status, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, 0, 0, 0, 0.0, 0, 'pending', NOW(), NOW())";
            
            $profileUrl = "https://{$platform}.com/{$username}";
            $this->model->getDb()->query($sql, [$userId, $platform, $username, $profileUrl]);

            $accountId = (int)$this->model->getDb()->lastInsertId();
            $this->model->commit();

            return ['success' => true, 'id' => $accountId, 'message' => 'حساب با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            return ['success' => false, 'message' => 'خطا در ثبت حساب: ' . $e->getMessage()];
        }
    }

    public function getExecutorStats(int $userId): object
    {
        return $this->model->getExecutorStats($userId) ?: (object)['total' => 0, 'approved' => 0, 'soft_approved' => 0, 'rejected' => 0, 'avg_score' => 0, 'success_rate' => 0];
    }

    public function getAdvertiserAdStats(int $advertiserId, int $adId): ?object
    {
        return $this->model->getAdvertiserAdStats($adId, $advertiserId);
    }

    public function getExecutorHistory(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->model->getExecutorHistory($userId, $limit, $offset);
    }

    private function sanitizeSearch(string $str): string
    {
        return preg_replace('/[^\p{L}\p{N}\s]/u', '', $str) ?: '';
    }

    private function mergeBehaviorSignals(array $prev, array $new): array
    {
        foreach ($new as $k => $v) {
            if (is_numeric($v)) {
                $prev[$k] = ($prev[$k] ?? 0) + $v;
            } else {
                $prev[$k] = $v;
            }
        }
        return $prev;
    }

    /**
     * گزارش تخلف تسک شبکه اجتماعی (سوشیال تسک)
     */
    public function reportTask(int $reporterId, int $adId, string $reason, string $description = ''): array
    {
        $ad = $this->model->getAdById($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        try {
            $ok = $this->ratingService->report([
                'reporter_id' => $reporterId,
                'ref_type' => 'social_task',
                'ref_id' => $adId,
                'reason' => $reason,
                'description' => $description
            ]);

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت گزارش'];
            }

            return ['success' => true, 'message' => 'گزارش با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }

    /**
     * امتیازدهی به تسک شبکه اجتماعی (سوشیال تسک)
     */
    public function rateTask(int $raterId, int $adId, int $stars, string $comment = ''): array
    {
        $ad = $this->model->getAdById($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        $stars = max(1, min(5, $stars));

        try {
            $ok = $this->ratingService->rate(
                $raterId,
                (int)$ad->user_id,
                'social_task',
                $adId,
                $stars,
                $comment
            );

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت امتیاز'];
            }

            return ['success' => true, 'message' => 'امتیاز با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }
}

