<?php

namespace App\Services;

use App\Models\InfluencerModel;
use App\Models\InfluencerReputation;
use App\Models\StoryOrder;
use Core\Database;

use App\Contracts\LoggerInterface;
use App\Services\SettingService;

class InfluencerReputationService extends \App\Services\BaseService
{
    private InfluencerReputation $reputationModel;
    private InfluencerModel      $profileModel;
    private Database             $db;
    private SettingService       $settingService;

    public function __construct(
        Database             $db,
        InfluencerReputation $reputationModel,
        InfluencerModel      $profileModel,
        SettingService       $settingService,
        LoggerInterface      $logger
    ) {
        parent::__construct($logger);
        $this->db              = $db;
        $this->reputationModel = $reputationModel;
        $this->profileModel    = $profileModel;
        $this->settingService  = $settingService;
    }

    /**
     * امتیازدهی بعد از تکمیل موفق سفارش (بدون اختلاف)
     * ✅ Transaction management
     */
    public function scoreOrderCompleted(int $profileId, int $influencerUserId, int $orderId): void
    {
        try {
            $this->db->beginTransaction();

            $pts = (int) $this->settingService->get('influencer_rep_complete_points', 10);
            $this->reputationModel->addEvent([
                'profile_id' => $profileId,
                'user_id'    => $influencerUserId,
                'order_id'   => $orderId,
                'event_type' => 'order_completed',
                'points'     => $pts,
                'note'       => 'تحویل موفق سفارش',
            ]);
            $this->refreshProfileRating($profileId);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * امتیازدهی بعد از حل اختلاف
     * ✅ Transaction management
     */
    public function scoreAfterDisputeResolution(object $dispute, string $verdict, string $resolvedBy): void
    {
        try {
            $this->db->beginTransaction();

            $profileId        = $this->getProfileIdFromInfluencerUserId((int)$dispute->influencer_user_id);
            $influencerPoints = 0;
            $customerPoints   = 0;
            $influencerNote   = '';
            $customerNote     = '';

            switch ($verdict) {
                case 'favor_influencer':
                    // تبلیغ‌دهنده شکایت بی‌اساس داشته
                    $influencerPoints = (int) $this->settingService->get('influencer_rep_dispute_won_points',  5);
                    $customerPoints   = (int) $this->settingService->get('influencer_rep_false_claim_points', -5);
                    $influencerNote   = 'برنده اختلاف';
                    $customerNote     = 'شکایت بی‌اساس';
                    break;

                case 'favor_customer':
                    // اینفلوئنسر تقصیرکار بوده
                    $influencerPoints = (int) $this->settingService->get('influencer_rep_dispute_lost_points', -15);
                    $customerPoints   = 0;
                    $influencerNote   = 'بازنده اختلاف';
                    break;

                case 'partial':
                    // هر دو مقصر — امتیاز کمتر
                    $influencerPoints = (int) $this->settingService->get('influencer_rep_partial_points', -5);
                    $customerPoints   = 0;
                    $influencerNote   = 'تسویه جزئی';
                    break;
            }

            if ($profileId && $influencerPoints !== 0) {
                $this->reputationModel->addEvent([
                    'profile_id' => $profileId,
                    'user_id'    => (int)$dispute->influencer_user_id,
                    'order_id'   => (int)$dispute->order_id,
                    'event_type' => 'dispute_' . $verdict,
                    'points'     => $influencerPoints,
                    'note'       => $influencerNote . ' (' . $resolvedBy . ')',
                ]);
                $this->refreshProfileRating($profileId);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        // امتیاز منفی برای buyer در صورت شکایت بی‌اساس
        // (این بخش توسعه‌پذیر است — اگر buyer هم پروفایل داشت)
    }

    /**
     * امتیاز منفی برای رد سفارش یا عدم پاسخ
     */
    public function scoreOrderRejectedByInfluencer(int $profileId, int $orderId): void
    {
        try {
            $this->db->beginTransaction();

            $profile = $this->profileModel->find($profileId);
            $influencerUserId = $profile ? (int)$profile->user_id : $profileId;

            $pts = (int) $this->settingService->get('influencer_rep_reject_points', -3);
            $this->reputationModel->addEvent([
                'profile_id' => $profileId,
                'user_id'    => $influencerUserId,
                'order_id'   => $orderId,
                'event_type' => 'order_rejected',
                'points'     => $pts,
                'note'       => 'رد سفارش یا عدم پاسخ',
            ]);
            $this->refreshProfileRating($profileId);

            $this->db->commit();
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * بروزرسانی average_rating در پروفایل از روی امتیازها
     */
    public function refreshProfileRating(int $profileId): void
    {
        $startedTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTransaction = true;
        }

        try {
            // قفل ردیف پروفایل برای جلوگیری از Race Condition در زمان بروزرسانی امتیاز
            // فرض بر این است که متد findByIdForUpdate در مدل وجود دارد یا از prepare استفاده می‌کنیم
            $stmt = $this->db->prepare("SELECT id FROM influencer_profiles WHERE id = ? FOR UPDATE");
            $stmt->execute([$profileId]);
            if (!$stmt->fetch()) {
                if ($startedTransaction) $this->db->rollBack();
                return;
            }

            $stats = $this->reputationModel->getProfileStats($profileId);
            
            // امتیازدهی باید نرمالایز شده باشد (بازه ۰ تا ۵) نه جمع کل امتیازات خام
            // منطق: میانگین امتیاز به ازای هر سفارش با ضریب تعدیل
            $totalPoints = (float)($stats->total_points ?? 0);
            $totalOrders = (int)($stats->total_orders ?? 0);
            
            // فرمول پیشنهادی: میانگین امتیازات تقسیم بر ۲ (برای نگاشت به بازه ۵ ستاره) با رعایت سقف و کف
            $normalizedRating = min(5.0, max(0.0, 
                ($totalOrders > 0 ? ($totalPoints / $totalOrders) : 0) * 0.5
            ));

            $this->profileModel->update($profileId, [
                'average_rating' => $normalizedRating,
            ]);

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (\Exception $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * دریافت آمار کامل یک پروفایل برای نمایش عمومی
     */
    public function getPublicStats(int $profileId): object
    {
        return $this->reputationModel->getProfileStats($profileId);
    }

    private function getProfileIdFromInfluencerUserId(int $userId): ?int
    {
        $profile = $this->profileModel->findByUserId($userId);
        return $profile ? (int)$profile->id : null;
    }
}

