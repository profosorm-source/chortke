<?php

namespace App\Controllers\Admin;

use App\Services\ReferralManagementService;
use App\Controllers\Admin\BaseAdminController;

class ReferralManagementController extends BaseAdminController
{
    private ReferralManagementService $referralManagementService;

    public function __construct(ReferralManagementService $referralManagementService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->referralManagementService = $referralManagementService;
    }

    /**
     * داشبورد کلی سیستم رفرال
     */
    public function dashboard()
    {
        $data = $this->referralManagementService->getDashboardData(10, 'irt');

        return view('admin.referral.dashboard', [
            'global_stats' => $data['globalStats'],
            'tier_stats' => $data['tierStats'],
            'milestone_stats' => $data['milestoneStats'],
            'current_leaderboard' => $data['currentLeaderboard'],
            'top_referrers' => $data['topReferrers'],
        ]);
    }

    /**
     * مدیریت Tiers
     */
    public function tiers()
    {
        return view('admin.referral.tiers', [
            'tiers' => $this->referralManagementService->getAllTiers(),
            'stats' => $this->referralManagementService->getGlobalTierStats(),
        ]);
    }

    /**
     * مدیریت Milestones
     */
    public function milestones()
    {
        return view('admin.referral.milestones', [
            'milestones' => $this->referralManagementService->getActiveMilestones(),
            'stats' => $this->referralManagementService->getGlobalTierStats(),
        ]);
    }

    /**
     * مدیریت Leaderboard
     */
    public function leaderboard()
    {
        $periodKey = $this->request->get('period', date('Y-m'));

        return view('admin.referral.leaderboard', [
            'leaderboard' => $this->referralManagementService->getLeaderboard($periodKey, 100),
            'period_key' => $periodKey,
        ]);
    }

    /**
     * بروزرسانی Leaderboard (Manual)
     */
    public function updateLeaderboard()
    {
        $count = $this->referralManagementService->updateCurrentLeaderboard();

        return redirect('/admin/referral/leaderboard')
            ->with('success', "لیدربورد بروز شد. {$count} کاربر در لیست قرار گرفتند");
    }

    /**
     * پرداخت جوایز Leaderboard
     */
    public function distributeLeaderboardRewards()
    {
        $results = $this->referralManagementService->distributeMonthlyRewards();

        return redirect('/admin/referral/leaderboard')
            ->with('success', sprintf(
                'پاداش‌های ماهیانه اعمال شد. بونس: %s',
                number_format($results['bonus_percent'] ?? 0)
            ));
    }

    /**
     * نمایش جزئیات یک معرف
     */
    public function showReferrer($id)
    {
        $userId = (int) $id;
        $details = $this->referralManagementService->getReferrerDetails($userId);

        if (!$details) {
            return redirect('/admin/referral')->with('error', 'کاربر یافت نشد');
        }

        return view('admin.referral.referrer-details', [
            'user' => $details['user'],
            'stats' => $details['stats'],
            'current_tier' => $details['currentTier'],
            'tier_history' => $details['tierHistory'],
            'quality_score' => $details['qualityScore'],
            'quality_interpretation' => $details['qualityInterpretation'],
            'achieved_milestones' => $details['achievedMilestones'],
            'analytics' => $details['analytics'],
        ]);
    }

    /**
     * محاسبه مجدد Quality Score (Manual)
     */
    public function recalculateQualityScore($id)
    {
        $userId = (int) $id;
        $newScore = $this->referralManagementService->recalculateQualityScore($userId);

        return redirect("/admin/referral/referrer/{$userId}")
            ->with('success', "Quality Score محاسبه شد: " . round($newScore, 2));
    }

    /**
     * جریمه/پاداش Quality Score
     */
    public function adjustQualityScore($id)
    {
        $userId = (int) $id;
        $action = $this->request->post('action', 'penalize');
        $amount = (float) ($this->request->post('amount') ?? 0);
        $reason = $this->request->post('reason', '');

        if ($amount <= 0 || empty($reason)) {
            return redirect("/admin/referral/referrer/{$userId}")
                ->with('error', 'مقدار و دلیل الزامی است');
        }

        $this->referralManagementService->adjustQualityScore($userId, $action, $amount, $reason);

        $message = $action === 'reward'
            ? "Quality Score افزایش یافت (+{$amount})"
            : "Quality Score کاهش یافت (-{$amount})";

        return redirect("/admin/referral/referrer/{$userId}")
            ->with('success', $message);
    }

    /**
     * بررسی و ارتقای سطح کاربر (Manual)
     */
    public function checkTierUpgrade($id)
    {
        $userId = (int) $id;
        $newTier = $this->referralManagementService->checkTierUpgrade($userId);

        $tierLabel = $newTier->tier_name ?? $newTier->name_fa ?? 'بدون تغییر';

        return redirect("/admin/referral/referrer/{$userId}")
            ->with('success', "سطح بررسی شد: " . $tierLabel);
    }

    /**
     * بررسی Milestones کاربر (Manual)
     */
    public function checkMilestones($id)
    {
        $userId = (int) $id;
        $awarded = $this->referralManagementService->checkMilestones($userId);

        $message = count($awarded) > 0
            ? sprintf('%d milestone جدید دریافت شد', count($awarded))
            : 'Milestone جدیدی یافت نشد';

        return redirect("/admin/referral/referrer/{$userId}")
            ->with('success', $message);
    }

    /**
     * گزارش Quality Score همه کاربران
     */
    public function qualityScoreReport()
    {
        $page = (int) ($this->request->get('page', 1) ?: 1);
        $report = $this->referralManagementService->getQualityScoreReport($page, 50);

        return view('admin.referral.quality-report', [
            'users' => $report['users'],
            'page' => $report['page'],
            'pages' => $report['pages'],
        ]);
    }

    /**
     * بروزرسانی دسته‌ای Quality Score (Batch)
     */
    public function batchRecalculateQuality()
    {
        $limit = (int) ($this->request->post('limit') ?? 100);
        $count = $this->referralManagementService->batchRecalculateQuality($limit);

        return redirect('/admin/referral/quality-report')
            ->with('success', "{$count} کاربر پردازش شد");
    }
}
