<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\AdSystemManager;
use App\Models\Ads;
use App\Models\BannerPlacement; // Needed for banner step configurations
use Core\Database;

/**
 * AdsController - The ultimate command center for Unified Modern Advertising (Unified UI).
 */
class AdsController extends BaseController
{
    public function __construct(
        private AdSystemManager $adManager,
        private Ads $adModel,
        private Database $db
    ) {
        parent::__construct();
    }

    /**
     * Unified Dashboard / "My Ads" Listing.
     */
    public function index(): string
    {
        $userId = user_id();
        
        // Fetch all ads regardless of type
        $ads = $this->db->fetchAll(
            "SELECT * FROM ads WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC",
            [$userId]
        );

        // Calculate aggregated summary for top dashboard cards
        $summary = $this->db->fetch(
            "SELECT 
                COUNT(*) as total_count, 
                SUM(budget) as total_invested,
                SUM(impressions) as total_impressions,
                SUM(clicks) as total_clicks
             FROM ads WHERE user_id = ? AND deleted_at IS NULL",
            [$userId]
        );

        return view('user.ads.index', compact('ads', 'summary'));
    }

    /**
     * The AJAX Ad Wizard - Single Entry Point.
     */
    public function create(): string
    {
        // Provide essential lookups needed for the wizard upfront
        $placements = $this->db->fetchAll("SELECT * FROM banner_placements WHERE is_active = 1");
        
        return view('user.ads.create', compact('placements'));
    }

    /**
     * High-speed AJAX storage directly forwarding payload to mapped Adapter strategies.
     */
    public function store(): void
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $userId = user_id();

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
        $adId = (int) ($_POST['ad_id'] ?? 0);
        $userId = user_id();

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
        $userId = user_id();

        $ad = $this->db->fetch("SELECT * FROM ads WHERE id = ? AND user_id = ? AND deleted_at IS NULL", [$adId, $userId]);
        
        if (!$ad) {
            $this->session->setFlash('error', 'آگهی یافت نشد.');
            $this->response->redirect(url('/ads'));
            exit;
        }

        // Strategy: Dynamically resolve execution/history log table based on type
        $executions = [];
        switch ($ad->type) {
            case 'social_task':
                $executions = $this->db->fetchAll("
                    SELECT e.id, e.status, e.created_at, u.full_name as executor
                    FROM social_task_executions e
                    LEFT JOIN users u ON u.id = e.executor_id
                    WHERE e.ad_id = ? ORDER BY e.created_at DESC LIMIT 50
                ", [$adId]);
                break;
            
            case 'seo':
                $executions = $this->db->fetchAll("
                    SELECT e.id, e.status, e.created_at, u.full_name as executor
                    FROM seo_executions e
                    LEFT JOIN users u ON u.id = e.user_id
                    WHERE e.ad_id = ? ORDER BY e.created_at DESC LIMIT 50
                ", [$adId]);
                break;

            case 'custom_task':
                $executions = $this->db->fetchAll("
                    SELECT e.id, e.status, e.created_at, u.full_name as executor
                    FROM custom_task_submissions e
                    LEFT JOIN users u ON u.id = e.user_id
                    WHERE e.task_id = ? ORDER BY e.created_at DESC LIMIT 50
                ", [$adId]);
                break;
        }

        // Quick Chart stats (Mock/Aggregated based on real impressions if available)
        $stats = $this->db->fetchAll("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM (
                SELECT created_at FROM ads WHERE id = ? -- Simplified for structure
            ) d GROUP BY DATE(created_at) LIMIT 7
        ", [$adId]); // In production replace this with dedicated tracking table aggregation

        return view('user.ads.show', compact('ad', 'executions', 'stats'));
    }
}
