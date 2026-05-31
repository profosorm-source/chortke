<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ads;
use App\Contracts\LoggerInterface;

/**
 * SeoPayoutService — محاسبه پرداخت پویا
 * 
 * فرمول: Payout = MinPayout + ((FinalScore / 100) × (MaxPayout - MinPayout))
 */
class SeoPayoutService
{
    private Ads $seoAdModel;

    public function __construct(
        Ads $seoAdModel
    )
    {
                $this->seoAdModel = $seoAdModel;
    }

    /**
     * محاسبه پرداخت بر اساس امتیاز
     * 
     * @param int $adId شناسه آگهی
     * @param float $finalScore امتیاز نهایی (0-100)
     * @return array ['payout' => float, 'can_pay' => bool, 'message' => string]
     */
    public function calculatePayout(int $adId, float $finalScore): array
    {
        $ad = $this->seoAdModel->find($adId);
        
        if (!$ad) {
            return [
                'payout' => 0,
                'can_pay' => false,
                'message' => 'آگهی یافت نشد'
            ];
        }

        // بررسی وضعیت آگهی
        if ($ad->status !== 'active') {
            return [
                'payout' => 0,
                'can_pay' => false,
                'message' => 'آگهی فعال نیست'
            ];
        }

        // محاسبه پرداخت پویا
        $minPayout = $ad->min_payout ?? 1000;
        $maxPayout = $ad->max_payout ?? 5000;

        // فرمول: Min + (Score/100 × (Max - Min))
        $scoreRatio = $finalScore / 100;
        $payout = $minPayout + ($scoreRatio * ($maxPayout - $minPayout));
        $payout = round($payout, 2);

        // بررسی بودجه
        if ($ad->remaining_budget < $payout) {
            return [
                'payout' => 0,
                'can_pay' => false,
                'message' => 'بودجه آگهی کافی نیست'
            ];
        }

        // بررسی حداقل امتیاز قابل قبول (اختیاری)
        $minAcceptableScore = $ad->min_score ?? 40;
        if ($finalScore < $minAcceptableScore) {
            return [
                'payout' => 0,
                'can_pay' => false,
                'message' => "حداقل امتیاز قابل قبول {$minAcceptableScore} است"
            ];
        }

        return [
            'payout' => $payout,
            'can_pay' => true,
            'message' => 'پرداخت محاسبه شد',
            'details' => [
                'min_payout' => $minPayout,
                'max_payout' => $maxPayout,
                'score_ratio' => round($scoreRatio * 100, 2) . '%',
                'remaining_budget' => $ad->remaining_budget,
            ]
        ];
    }

    /**
     * کسر پرداخت از بودجه آگهی
     * 
     * @param int $adId
     * @param float $amount
     * @return bool
     */
    public function deductFromBudget(int $adId, float $amount): bool
    {
        // Atomic deduction directly in SQL to prevent race conditions
        $stmt = $this->seoAdModel->db->prepare(
            "UPDATE ads 
             SET remaining_budget = remaining_budget - ?,
                 executions_count = executions_count + 1,
                 status = CASE WHEN (remaining_budget - ?) <= 0 THEN 'exhausted' ELSE status END,
                 updated_at = NOW()
             WHERE id = ? AND remaining_budget >= ?"
        );
        
        $ok = $stmt->execute([$amount, $amount, $adId, $amount]);
        return $ok && $stmt->rowCount() > 0;
    }

    /**
     * برگشت بودجه (در صورت رد شدن یا تقلب)
     * 
     * @param int $adId
     * @param float $amount
     * @return bool
     */
    public function refundToBudget(int $adId, float $amount): bool
    {
        $ad = $this->seoAdModel->find($adId);
        
        if (!$ad) {
            return false;
        }

        $newBudget = $ad->remaining_budget + $amount;

        // اگر exhausted بود و حالا بودجه داره، فعالش کن
        $newStatus = $ad->status;
        if ($ad->status === 'exhausted' && $newBudget > 0) {
            $newStatus = 'active';
        }

        $stmt = $this->seoAdModel->db->prepare(
            "UPDATE ads 
             SET remaining_budget = ?,
                 executions_count = GREATEST(0, executions_count - 1),
                 status = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );

        return $stmt->execute([$newBudget, $newStatus, $adId]);
    }

    /**
     * پیش‌بینی بودجه مورد نیاز
     * 
     * @param array $config شامل: min_payout, max_payout, expected_users
     * @return array
     */
    public function estimateBudget(array $config): array
    {
        $minPayout = $config['min_payout'] ?? 1000;
        $maxPayout = $config['max_payout'] ?? 5000;
        $expectedUsers = $config['expected_users'] ?? 100;
        $avgScore = $config['avg_score'] ?? 70; // میانگین امتیاز پیش‌بینی شده

        // محاسبه میانگین پرداخت
        $avgPayout = $minPayout + (($avgScore / 100) * ($maxPayout - $minPayout));

        // بودجه کل مورد نیاز
        $totalBudget = $avgPayout * $expectedUsers;

        // سناریوهای مختلف
        $scenarios = [
            'worst_case' => [
                'description' => 'همه کاربران حداکثر امتیاز (100)',
                'budget' => $maxPayout * $expectedUsers,
            ],
            'average_case' => [
                'description' => "میانگین امتیاز {$avgScore}",
                'budget' => $totalBudget,
            ],
            'best_case' => [
                'description' => 'همه کاربران حداقل امتیاز (40)',
                'budget' => ($minPayout + (0.4 * ($maxPayout - $minPayout))) * $expectedUsers,
            ],
        ];

        return [
            'min_payout' => $minPayout,
            'max_payout' => $maxPayout,
            'expected_users' => $expectedUsers,
            'avg_payout' => round($avgPayout, 2),
            'recommended_budget' => round($totalBudget, 2),
            'scenarios' => $scenarios,
        ];
    }

    /**
     * محاسبه تعداد کاربران قابل پوشش با بودجه فعلی
     * 
     * @param int $adId
     * @return array
     */
    public function estimateReach(int $adId): array
    {
        $ad = $this->seoAdModel->find($adId);
        
        if (!$ad) {
            return ['error' => 'آگهی یافت نشد'];
        }

        $minPayout = $ad->min_payout ?? 1000;
        $maxPayout = $ad->max_payout ?? 5000;
        $avgPayout = ($minPayout + $maxPayout) / 2;

        $remainingBudget = $ad->remaining_budget;

        return [
            'remaining_budget' => $remainingBudget,
            'min_users' => floor($remainingBudget / $maxPayout), // بدترین حالت
            'max_users' => floor($remainingBudget / $minPayout), // بهترین حالت
            'avg_users' => floor($remainingBudget / $avgPayout), // حالت معمولی
        ];
    }
}

