<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Models\Ads;
use App\Models\BannerPlacement;
use App\Services\AdSystemManager;
use App\Services\UploadService;

/**
 * BannerController - Consolidated User Interaction Layer for Banner Ads.
 */
class BannerController extends BaseUserController
{
    private Ads $ads;
    private BannerPlacement $placement;
    private AdSystemManager $adManager;
    private UploadService $uploadService;
    public function __construct(
        Ads $ads,
        BannerPlacement $placement,
        AdSystemManager $adManager,
        UploadService $uploadService
    , ?\App\Contracts\LoggerInterface $logger = null) {        $this->ads = $ads;
        $this->placement = $placement;
        $this->adManager = $adManager;
        $this->uploadService = $uploadService;

        parent::__construct(null, null, null, null, $logger);
    }

    /**
     * Displays the unified user advertising dashboard filtered for Banners.
     */
    public function index(): void
    {
        $userId = (int)user_id();
        // Fetch user banners from centralized ecosystem
        $banners = $this->ads->getByAdvertiser($userId, 50, 0, 'banner');
        
        view('user.banner.index', compact('banners'));
    }

    /**
     * Form to initiate a new localized banner request.
     */
    public function create(): void
    {
        // 1. Fetch actively available placements
        $placements = $this->placement->all(['is_active' => 1]);
        
        view('user.banner.create', [
            'placements' => $placements,
            'title' => 'ثبت بنر تبلیغاتی جدید'
        ]);
    }

    /**
     * Stores new banner dynamically routing requests through AdSystemManager.
     */
    public function store(): void
    {
        $userId = (int)user_id();
        $body = $this->request->body();

        // 1. Handle Image Provisioning
        $file = $_FILES['image'] ?? null;
        if (!$file || empty($file['name'])) {
            $this->session->setFlash('error', 'فایل تصویر بنر الزامی است.');
            redirect(url('/banner/create'));
            return;
        }

        $upload = $this->uploadService->upload($file, 'banners');
        if (!$upload['success']) {
            $this->session->setFlash('error', 'خطا در آپلود تصویر: ' . ($upload['error'] ?? 'نامشخص'));
            redirect(url('/banner/create'));
            return;
        }

        // 2. Prepare Strategy payload
        $payload = [
            'title'      => $body['title'] ?? '',
            'description'=> $body['description'] ?? '',
            'link'       => $body['link'] ?? '',
            'placement'  => $body['placement'] ?? '',
            'budget'     => (float)($body['budget'] ?? 0),
            'image_path' => $upload['path'],
            'is_startup' => !empty($body['is_startup']),
            'target_devices' => $body['devices'] ?? ['web', 'mobile']
        ];

        // 3. Hand off to dynamic Ad Strategy Engine
        $result = $this->adManager->create('banner', $userId, $payload);

        if ($result['success']) {
            $this->session->setFlash('success', $result['message'] ?? 'بنر شما با موفقیت ثبت و از کیف پول پرداخت شد.');
            redirect(url('/banner'));
        } else {
            // Cleanup if failed
            $this->uploadService->delete($upload['path']);
            
            $this->session->setFlash('error', $result['message'] ?? 'خطا در ثبت تبلیغ بنر.');
            redirect(url('/banner/create'));
        }
    }

    /**
     * Allows user to request immediate pausing/cancellation of active campaign.
     */
    public function cancel(): void
    {
        $id = (int)$this->request->param('id');
        $userId = (int)user_id();

        $banner = $this->ads->find($id);
        if (!$banner || (int)$banner->user_id !== $userId) {
            $this->response->json(['success' => false, 'message' => 'تبلیغ یافت نشد.']);
            return;
        }

        // Can only cancel pending or draft items through simple controller, 
        // Active cancellation is managed via AdSystemManager refund pipelines ideally.
        if ($banner->status !== 'pending') {
             $this->response->json(['success' => false, 'message' => 'تبلیغات در حال اکران را نمی‌توان مستقیماً کنسل کرد.']);
             return;
        }

        // Basic state switch
        $this->ads->update($id, ['status' => 'cancelled', 'is_active' => 0]);

        $this->response->json(['success' => true, 'message' => 'درخواست بنر با موفقیت لغو شد.']);
    }
}
