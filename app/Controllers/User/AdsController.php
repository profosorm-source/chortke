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
    private AdSystemManager $adManager;
    private Ads $adModel;
    private BannerPlacement $placementModel;
    public function __construct(
        AdSystemManager $adManager,
        Ads $adModel,
        BannerPlacement $placementModel
    , ?\App\Contracts\LoggerInterface $logger = null) {        $this->adManager = $adManager;
        $this->adModel = $adModel;
        $this->placementModel = $placementModel;

        parent::__construct(null, null, null, null, $logger);
    }

    /**
     * Unified Dashboard / "My Ads" Listing.
     */
    public function index(): string
    {
        $userId = (int)user_id();
        
        $ads = $this->adManager->getUserAds($userId);
        $summaryData = $this->adManager->getAdSummary($userId);

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

        $result = $this->adManager->toggleAdStatus($adId, $userId);
        echo json_encode($result);
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

        $executions = $this->adManager->getAdExecutions($adId, $ad->type);
        $stats = []; // Detailed stats logic should be in a service if needed

        return view('user.ads.show', compact('ad', 'executions', 'stats'));
    }
}
