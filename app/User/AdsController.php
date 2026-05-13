<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\AdSystemManager;
use App\Models\Ads;
use App\Models\BannerPlacement;

/**
 * AdsController - The ultimate command center for Unified Modern Advertising (Unified UI).
 */
class AdsController extends BaseController
{
    public function __construct(
        private AdSystemManager $adManager,
        private Ads $adModel,
        private BannerPlacement $placementModel
    ) {
        parent::__construct();
    }

    /**
     * Unified Dashboard / "My Ads" Listing.
     */
    public function index(): string
    {
        $userId = (int)user_id();
        
        // استفاده از Model برای دریافت آگهی‌های کاربر
        $ads = $this->adModel->where('user_id', '=', $userId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'DESC')
            ->get();

        // محاسبه خلاصه کلی برای کارت‌های داشبورد
        $summary = $this->adModel->where('user_id', '=', $userId)
            ->whereNull('deleted_at')
            ->select(['id', 'budget', 'impressions', 'clicks'])
            ->get();

        $summaryData = [
            'total_count' => count($ads),
            'total_invested' => array_sum(array_column((array)$summary, 'budget')),
            'total_impressions' => array_sum(array_column((array)$summary, 'impressions')),
            'total_clicks' => array_sum(array_column((array)$summary, 'clicks'))
        ];

        return view('user.ads.index', compact('ads', 'summaryData'));
    }

    /**
     * The AJAX Ad Wizard - Single Entry Point.
     */
    public function create(): string
    {
        // دریافت Placements از Model
        $placements = $this->placementModel->where('is_active', '=', 1)->get();
        
        return view('user.ads.create', compact('placements'));
    }

    /**
     * High-speed AJAX storage directly forwarding payload to mapped Adapter strategies.
     */
    public function store(): void
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $userId = (int)user_id();

        $type = $data['ad_type'] ?? null;

        if (!$type) {
            echo json_encode(['success' => false, 'message' => 'نوع تبلیغ نامعتبر است.']);
            return;
        }

        try {
            // Direct delegation to registry managed by Strategy Pattern
            $result = $this->adManager->create($type, $userId, $data);
            echo json_encode($result);
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false, 
                'message' => 'بروز خطا در حین ثبت: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Pause/Resume interaction directly from the list view.
     */
    public function toggleStatus(): void
    {
        header('Content-Type: application/json');
        $adId = (int) ($this->request->post('ad_id') ?? 0);
        $userId = (int)user_id();

        $ad = $this->adModel->find($adId);
        if (!$ad || (int)$ad->user_id !== $userId) {
            echo json_encode(['success' => false, 'message' => 'آگهی متعلق به شما یافت نشد.']);
            return;
        }

        $newActive = (int)$ad->is_active === 1 ? 0 : 1;
        $this->adModel->update($adId, ['is_active' => $newActive]);

        $msg = $newActive ? 'آگهی مجدداً فعال شد.' : 'آگهی به صورت موقت متوقف شد.';
        echo json_encode(['success' => true, 'message' => $msg, 'is_active' => $newActive]);
    }

    /**
     * Ultimate Unified Analytics & Execution Log.
     */
    public function show(): string
    {
        $adId = (int)$this->request->param('id');
        $userId = (int)user_id();

        $ad = $this->adModel->where('id', '=', $adId)
            ->where('user_id', '=', $userId)
            ->whereNull('deleted_at')
            ->first();
        
        if (!$ad) {
            $this->session->setFlash('error', 'آگهی یافت نشد.');
            return redirect(url('/ads'));
        }

        // استفاده از Model برای دریافت execution logs
        $executions = [];
        switch ($ad->type) {
            case 'social_task':
                $executions = $this->adModel->db->table('social_task_executions')
                    ->select('e.id', 'e.status', 'e.created_at', 'u.full_name as executor')
                    ->join('users u', 'u.id', '=', 'e.executor_id', 'LEFT')
                    ->where('e.ad_id', '=', $adId)
                    ->orderBy('e.created_at', 'DESC')
                    ->limit(50)
                    ->get();
                break;
            
            case 'seo':
                $executions = $this->adModel->db->table('seo_executions')
                    ->select('e.id', 'e.status', 'e.created_at', 'u.full_name as executor')
                    ->join('users u', 'u.id', '=', 'e.user_id', 'LEFT')
                    ->where('e.ad_id', '=', $adId)
                    ->orderBy('e.created_at', 'DESC')
                    ->limit(50)
                    ->get();
                break;

            case 'custom_task':
                $executions = $this->adModel->db->table('custom_task_submissions')
                    ->select('e.id', 'e.status', 'e.created_at', 'u.full_name as executor')
                    ->join('users u', 'u.id', '=', 'e.user_id', 'LEFT')
                    ->where('e.task_id', '=', $adId)
                    ->orderBy('e.created_at', 'DESC')
                    ->limit(50)
                    ->get();
                break;
        }

        // دریافت آمار (در production باید از جدول tracking اختصاصی استفاده شود)
        $stats = $this->adModel->db->table('ads')
            ->select(['id', 'created_at'])
            ->where('id', '=', $adId)
            ->get();

        return view('user.ads.show', compact('ad', 'executions', 'stats'));
    }
}
