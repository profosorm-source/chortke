<?php

namespace App\Controllers\User;

use App\Models\ReferralCommission;
use App\Services\Shared\ReferralService;
use App\Controllers\User\BaseUserController;

/**
 * ReferralController - Consolidated Referral System
 * 
 * استفاده از نئی ReferralService (Sprint 4 - Consolidation)
 * تمام referral functionality اب ایک service میں ہے
 */
class ReferralController extends BaseUserController
{
    private ReferralCommission $referralCommissionModel;
    private ReferralService    $referralService;

    public function __construct(
        ReferralCommission $referralCommissionModel,
        ReferralService    $referralService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->referralCommissionModel = $referralCommissionModel;
        $this->referralService = $referralService;
    }

    /**
     * صفحه اصلی زیرمجموعه‌گیری
     */
    public function index()
    {
        $userId = $this->userId();
        $user   = $this->userService->find($userId);

        // آمار کلی کمیسیون‌ها - نئی ReferralService سے
        $stats = $this->referralService->getReferrerStats($userId);

        // تعداد و لیست زیرمجموعه‌ها
        $referredCount = $this->referralService->countReferredUsers($userId);
        $referredUsers = $this->referralService->getReferredUsers($userId, 10, 0);

        // آخرین ۱۰ کمیسیون
        $recentCommissions = $this->referralService->getByReferrer($userId, [], 10, 0);

        // برچسب‌گذاری کمیسیون‌ها
        foreach ($recentCommissions as $c) {
            $c->source_label = $c->source_type ?? 'ناشناخته';
            $c->status_label = self::statusLabel($c->status);
            $c->status_class = self::statusClass($c->status);
        }

        // لینک و درصدهای فعال
        $referralLink = url('/register?ref=' . ($user->referral_code ?? ''));

        $percents = [
            'task_reward'  => setting('referral_commission_task_percent',       10),
            'investment'   => setting('referral_commission_investment_percent',   5),
            'vip_purchase' => setting('referral_commission_vip_percent',          8),
            'story_order'  => setting('referral_commission_story_percent',        5),
        ];

        return view('user.referral.index', [
            'user'              => $user,
            'stats'             => $stats,
            'referredCount'     => $referredCount,
            'referredUsers'     => $referredUsers,
            'recentCommissions' => $recentCommissions,
            'referralLink'      => $referralLink,
            'percents'          => $percents,
            'sourceTypes'       => [],
        ]);
    }

    /**
     * لیست کمیسیون‌ها (AJAX/JSON)
     */
    public function commissions()
    {
        $userId = $this->userId();

        $filters = array_filter([
            'status'      => $this->request->get('status'),
            'source_type' => $this->request->get('source_type'),
            'currency'    => $this->request->get('currency'),
        ]);

        $page   = max(1, (int) $this->request->get('page', 1));
        $limit  = 15;
        $offset = ($page - 1) * $limit;

        $commissions = $this->referralService->getByReferrer($userId, $filters, $limit, $offset);
        $total       = $this->referralService->countByReferrer($userId, $filters);

        foreach ($commissions as $c) {
            $c->created_at_jalali = to_jalali($c->created_at ?? '');
            $c->paid_at_jalali    = $c->paid_at ? to_jalali($c->paid_at) : null;
            $c->source_label      = $c->source_type ?? 'ناشناخته';
            $c->status_label      = self::statusLabel($c->status);
            $c->status_class      = self::statusClass($c->status);
        }

        $this->response->json([
            'success'     => true,
            'commissions' => $commissions,
            'total'       => $total,
            'page'        => $page,
            'pages'       => (int) ceil($total / $limit),
        ]);
    }

    /**
     * لیست زیرمجموعه‌ها (AJAX/JSON)
     */
    public function referredUsers()
    {
        $userId = $this->userId();

        $page   = max(1, (int) $this->request->get('page', 1));
        $limit  = 15;
        $offset = ($page - 1) * $limit;

        $users = $this->referralService->getReferredUsers($userId, $limit, $offset);
        $total = $this->referralService->countReferredUsers($userId);

        foreach ($users as $u) {
            $u->joined_at_jalali = to_jalali($u->joined_at ?? '');
        }

        $this->response->json([
            'success' => true,
            'users'   => $users,
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int) ceil($total / $limit),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────

    private static function statusLabel(string $status): string
    {
        return [
            'pending'   => 'در انتظار',
            'paid'      => 'پرداخت شده',
            'cancelled' => 'لغو شده',
            'failed'    => 'ناموفق',
        ][$status] ?? $status;
    }

    private static function statusClass(string $status): string
    {
        return [
            'pending'   => 'ref-badge--pending',
            'paid'      => 'ref-badge--paid',
            'cancelled' => 'ref-badge--danger',
            'failed'    => 'ref-badge--danger',
        ][$status] ?? 'ref-badge--muted';
    }

    // ── New Features ───────────────────────────────────────────

    /**
     * داشبورد پیشرفته با Analytics - نئی ReferralService
     */
    public function dashboard()
    {
        $userId = $this->userId();

        // اطلاعات پایه
        $user = $this->userService->find($userId);
        $stats = $this->referralCommissionModel->getReferrerStats($userId);

        // Tier فعلی و پیشرفت
        $currentTier = $this->referralService->getCurrentTier($userId);
        $nextTierProgress = $this->referralService->checkAndUpgrade($userId);

        // Quality Score
        $qualityScore = $this->referralService->getScore($userId);
        $improvementSuggestions = $this->referralService->calculateScore($userId);

        // Milestones
        $achievedMilestones = $this->referralService->getUserAchievedMilestones($userId);
        $nextMilestone = $this->referralService->checkAndAwardMilestones($userId);

        // Analytics
        $analytics = $this->referralService->getReferralTrend($userId, 30);

        // Multi-tier earnings (اگر فعال باشه)
        $multiTierEarnings = null;
        if (setting('referral_multi_tier_enabled', 0)) {
            $multiTierEarnings = $this->referralService->processMultiTierCommissions($userId, 0, 'irt'); // Fixed missing args
        }

        return view('user.referral.dashboard', [
            'user' => $user,
            'stats' => $stats,
            'current_tier' => $currentTier,
            'next_tier_progress' => $nextTierProgress,
            'quality_score' => $qualityScore,
            'improvement_suggestions' => $improvementSuggestions,
            'achieved_milestones' => $achievedMilestones,
            'next_milestone' => $nextMilestone,
            'analytics' => $analytics,
            'multi_tier_earnings' => $multiTierEarnings,
            'referralLink' => url('/register?ref=' . ($user->referral_code ?? ''))
        ]);
    }

    /**
     * صفحه Analytics - نئی ReferralService
     */
    public function analytics()
    {
        $userId = $this->userId();

        $dashboard = $this->referralService->getReferralTrend($userId, 90);
        $comparison = $this->referralService->getCommissionTrend($userId, 30);

        return view('user.referral.analytics', [
            'dashboard' => $dashboard,
            'comparison' => $comparison
        ]);
    }

    /**
     * صفحه Milestones - نئی ReferralService
     */
    public function milestones()
    {
        $userId = $this->userId();

        $achieved = $this->referralService->getUserAchievedMilestones($userId);

        return view('user.referral.milestones', [
            'achieved' => $achieved,
        ]);
    }

    /**
     * صفحه شبکه (Network) - Multi-tier - نئی ReferralService
     */
    public function network()
    {
        $userId = $this->userId();

        if (!setting('referral_multi_tier_enabled', 0)) {
            return redirect('/user/referral')->with('error', 'این قابلیت فعال نیست');
        }

        $indirectEarnings = $this->referralService->getIndirectEarnings($userId);

        return view('user.referral.network', [
            'indirect_earnings' => $indirectEarnings
        ]);
    }

    /**
     * API: دریافت آمار لحظه‌ای - نئی ReferralService
     */
    public function apiStats()
    {
        $userId = $this->userId();

        $this->response->json([
            'success' => true,
            'data' => [
                'tier' => $this->referralService->getCurrentTier($userId),
                'quality_score' => $this->referralService->getScore($userId),
                'conversion_rate' => $this->referralService->getConversionRate($userId, 7),
            ]
        ]);
    }
}

