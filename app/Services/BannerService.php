<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ads;
use App\Models\BannerPlacement;
use App\Contracts\WalletServiceInterface;
use App\Contracts\LoggerInterface;
use App\Services\UploadService;
use Core\Database;
use Core\EventDispatcher;

class BannerService
{
    private Ads $bannerModel;
    private BannerPlacement $placementModel;
    private WalletServiceInterface $walletService;
    private UploadService $uploadService;
    private ?\App\Contracts\OutboxServiceInterface $outboxService = null;

    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Cache $cache;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Cache $cache,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Ads $bannerModel,
        BannerPlacement $placementModel,
        WalletServiceInterface $walletService,
        UploadService $uploadService,
        ?\App\Contracts\OutboxServiceInterface $outboxService = null
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;

        
        $this->bannerModel = $bannerModel;
        $this->placementModel = $placementModel;
        $this->walletService = $walletService;
        $this->uploadService = $uploadService;
        $this->outboxService = $outboxService;
    }

    /**
     * دریافت بنرهای فعال با اعمال به‌روزرسانی دسته‌جمعی آماری
     */
    public function getActiveBanners(string $placement): array
    {
        $placementObj = $this->placementModel->findBySlug($placement);
        if (!$placementObj || !$placementObj->is_active) {
            return [];
        }

        $banners = $this->bannerModel->getActiveBannersByPlacement($placement);

        if (\count($banners) > $placementObj->max_banners) {
            $banners = \array_slice($banners, 0, $placementObj->max_banners);
        }

        // H-06 Fix: به‌روزرسانی بافر شده بازدیدها در Redis جهت جلوگیری از Lock Contention در دیتابیس
        $bannerIds = array_map(fn($b) => (int)$b->id, $banners);
        if (!empty($bannerIds) && $this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            foreach ($bannerIds as $id) {
                $redis->hIncrBy($this->cache->redisKey('banner_impressions_buffer'), (string)$id, 1);
            }
        } elseif (!empty($bannerIds)) {
            // Probabilistic fallback: update DB once out of 25 views (sample rate) but increment by 25.
            // This reduces DB lock contention by 96% and resolves deadlocks/contention.
            $sampleRate = 25;
            if (\mt_rand(1, $sampleRate) === 1) {
                $this->bannerModel->bulkIncrementImpressions($bannerIds, $sampleRate);
            }
        }

        return [
            'banners' => $banners,
            'placement' => $placementObj,
        ];
    }

    public function createBanner(array $data, int $createdBy): array
    {
        $errors = $this->validateBanner($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $data['created_by'] = $createdBy;

        if (isset($data['image_file']) && $data['image_file']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = $this->uploadBannerImage($data['image_file']);
            if (!$uploadResult['success']) {
                return ['success' => false, 'errors' => ['image' => $uploadResult['error']]];
            }
            $data['image_path'] = $uploadResult['path'];
        }

        unset($data['image_file']);

        $data['type'] = 'banner'; // اجبار به نوع بنر
        $data['user_id'] = $createdBy; // نگاشت استاندارد مالک تبلیغ
        
        $id = $this->bannerModel->create($data);
        if (!$id) {
            return ['success' => false, 'errors' => ['general' => 'خطا در ایجاد بنر']];
        }

        $this->logger->info('banner_created', ['message' => "بنر جدید با شناسه {$id} ایجاد شد"]);
        return ['success' => true, 'banner_id' => $id];
    }

    /**
     * خرید و ثبت بنر توسط کاربر به صورت تراکنشی و ایمن (Atomicity)
     */
    public function purchaseUserBanner(int $userId, array $data, ?array $imageFile): array
    {
        try {
            $this->db->beginTransaction();

            // ۱. محاسبات مالی و اعتبارسنجی بر اساس قوانین تجاری کسب‌وکار
            $durationDays = \max(1, (int)($data['duration_days'] ?? 1));
            $bannerType = $data['banner_type'] ?? 'user';
            $category = $data['category'] ?? '';

            // رعایت دقیق منطق قیمت‌گذاری قبلی سیستم:
            $pricePerDay = ($bannerType === 'startup' && $category === 'startup') ? 500 : 2000;
            $totalPrice = $pricePerDay * $durationDays;

            // اهدای ۷ روز اول رایگان برای استارتاپ‌ها
            if ($bannerType === 'startup' && $durationDays === 7) {
                $totalPrice = 0;
            }

            // ۲. آپلود امن تصویر قبل از قفل تراکنش
            $imagePath = null;
            if ($imageFile && !empty($imageFile['name']) && $imageFile['error'] === UPLOAD_ERR_OK) {
                $up = $this->uploadBannerImage($imageFile);
                if (!$up['success']) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => $up['error'] ?? 'خطا در آپلود تصویر'];
                }
                $imagePath = $up['path'];
            }

            // ۳. کسر وجه از کیف پول
            if ($totalPrice > 0) {
                $debit = $this->walletService->withdraw($userId, (float)$totalPrice, 'irt', [
                    'type' => 'user_banner',
                    'description' => "خرید بنر تبلیغاتی {$durationDays} روزه"
                ]);
                if (!$debit['success']) {
                    // حذف فایل آپلود شده در صورت شکست تراکنش مالی
                    if ($imagePath) $this->deleteBannerImage($imagePath);
                    $this->db->rollBack();
                    return ['success' => false, 'message' => $debit['message'] ?? 'موجودی حساب برای خرید بنر کافی نیست.'];
                }
            }

            // ۴. محاسبه زمان شروع و پایان
            $now = new \DateTime();
            $startDate = $now->format('Y-m-d H:i:s');
            $now->modify("+{$durationDays} days");
            $endDate = $now->format('Y-m-d H:i:s');

            // ۵. درج در جدول یکپارچه ads
            $adId = $this->bannerModel->create([
                'type' => 'banner',
                'user_id' => $userId,
                'title' => $data['title'] ?? 'بدون عنوان',
                'image_path' => $imagePath,
                'link' => $data['link'] ?? null,
                'placement' => $data['placement'] ?? 'sidebar',
                'banner_type' => $bannerType,
                'category' => $category,
                'total_budget' => $totalPrice,
                'remaining_budget' => $totalPrice,
                'status' => 'pending',
                'is_active' => 0, // تا تایید نشود فعال نمی‌شود
                'start_date' => $startDate,
                'end_date' => $endDate,
                'alt_text' => $data['alt_text'] ?? null,
            ]);

            if (!$adId) {
                throw new \Exception('خطای سیستمی در ذخیره‌سازی بنر');
            }

            $this->db->commit();
            $this->logger->activity('banner.purchase', "خرید بنر #{$adId}", $userId, ['amount' => $totalPrice]);

            return ['success' => true, 'banner_id' => $adId];

        } catch (\Throwable $e) {
            if (isset($imagePath) && $imagePath) {
                $this->deleteBannerImage($imagePath);
            }
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('banner.purchase_failed', ['error' => $e->getMessage(), 'user' => $userId]);
            return ['success' => false, 'message' => 'بروز خطای غیرمنتظره در ثبت بنر. مجدداً تلاش کنید.'];
        }
    }

    /**
     * لغو بنر در حال انتظار و برگشت وجه به صورت ایمن
     */
    public function cancelPendingBanner(int $bannerId, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // قفل کردن رکورد برای جلوگیری از Race Conditions
            $stmt = $this->db->prepare("SELECT * FROM ads WHERE id = ? AND user_id = ? FOR UPDATE");
            $stmt->execute([$bannerId, $userId]);
            $banner = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$banner || $banner->type !== 'banner') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'بنر مورد نظر یافت نشد.'];
            }

            if ($banner->status !== 'pending') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'فقط بنرهای در انتظار تایید قابل لغو هستند.'];
            }

            // ۱. لغو در سیستم
            $updated = $this->bannerModel->update($bannerId, [
                'status' => 'cancelled',
                'deleted_at' => date('Y-m-d H:i:s')
            ]);

            if (!$updated) {
                throw new \Exception("خطا در آپدیت وضعیت بنر #{$bannerId}");
            }

            // ۲. برگشت وجه
            $refundAmount = (float)($banner->total_budget ?? 0);
            if ($refundAmount > 0) {
                $payload = [
                    'user_id' => $userId,
                    'amount' => $refundAmount,
                    'currency' => 'irt',
                    'metadata' => [
                        'type' => 'banner_refund',
                        'description' => "برگشت هزینه لغو بنر #{$bannerId}",
                        'banner_id' => $bannerId,
                        'idempotency_key' => "banner_refund_{$bannerId}_{$userId}"
                    ]
                ];
                
                if ($this->outboxService) {
                    $this->outboxService->record('banner_refund', $bannerId, \App\Events\Registry\EventRegistry::BANNER_REVENUE_GENERATED, $payload);
                } else {
                    $this->eventDispatcher->dispatchAsync(\App\Events\Registry\EventRegistry::BANNER_REVENUE_GENERATED, $payload);
                }
            }

            $this->db->commit();
            $this->logger->activity('banner.cancel', "لغو بنر #{$bannerId}", $userId, ['refund' => $refundAmount]);

            return ['success' => true, 'message' => 'بنر با موفقیت لغو و هزینه آن بازگشت داده شد.'];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('banner.cancel_failed', ['error' => $e->getMessage(), 'banner' => $bannerId]);
            return ['success' => false, 'message' => 'خطا در لغو درخواست.'];
        }
    }

    public function updateBanner(int $id, array $data): array
    {
        $banner = $this->bannerModel->find($id);
        if (!$banner) {
            return ['success' => false, 'errors' => ['general' => 'بنر یافت نشد']];
        }

        $errors = $this->validateBanner($data, true);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        if (isset($data['image_file']) && $data['image_file']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = $this->uploadBannerImage($data['image_file']);
            if (!$uploadResult['success']) {
                return ['success' => false, 'errors' => ['image' => $uploadResult['error']]];
            }

            // حذف تصویر قبلی با مکانیزم ضد Path Traversal
            if ($banner->image_path) {
                $this->deleteBannerImage($banner->image_path);
            }

            $data['image_path'] = $uploadResult['path'];
        }

        unset($data['image_file']);

        $result = $this->bannerModel->update($id, $data);
        if (!$result) {
            return ['success' => false, 'errors' => ['general' => 'خطا در بروزرسانی بنر']];
        }

        $this->logger->info('banner_updated', ['message' => "بنر {$id} بروزرسانی شد"]);
        return ['success' => true];
    }

    public function deleteBanner(int $id): array
    {
        $banner = $this->bannerModel->find($id);
        if (!$banner) {
            return ['success' => false, 'message' => 'بنر یافت نشد'];
        }

        // استفاده از متد حذف ایمن داخلی مدل Core
        $this->bannerModel->delete($id);

        // حذف ایمن تصویر بنر با تضمین کامل جلوگیری از Path Traversal
        if ($banner->image_path) {
            $this->deleteBannerImage($banner->image_path);
        }

        $this->logger->warning('banner_deleted', ['message' => "بنر {$id} حذف شد"]);
        return ['success' => true, 'message' => 'بنر با موفقیت حذف شد'];
    }

    /**
     * متد کمکی امن برای حذف فایل تصویر بنر جهت پیشگیری کامل از آسیب‌پذیری Path Traversal
     */
    private function deleteBannerImage(?string $imagePath): void
    {
        if (empty($imagePath)) {
            return;
        }

        $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '.');
        if (!$docRoot) {
            return;
        }

        $fullPath = rtrim($docRoot, '/\\') . '/' . ltrim(str_replace(['\\', '..'], ['/', ''], $imagePath), '/');
        $realPath = realpath($fullPath);

        // تایید صد درصدی قرارگیری فایل در دایرکتوری معتبر وب‌سرور جهت حذف فیزیکی
        if ($realPath && str_starts_with($realPath, $docRoot)) {
            @unlink($realPath);
        }
    }

    public function toggleBanner(int $id): array
    {
        $banner = $this->bannerModel->find($id);
        if (!$banner) {
            return ['success' => false, 'message' => 'بنر یافت نشد'];
        }

        $newStatus = $banner->is_active ? 0 : 1;
        $this->bannerModel->update($id, ['is_active' => $newStatus]);
        $statusText = $newStatus ? 'فعال' : 'غیرفعال';

        $this->logger->info('banner_toggle', ['message' => "بنر {$id} {$statusText} شد"]);
        return ['success' => true, 'is_active' => $newStatus, 'message' => "بنر با موفقیت {$statusText} شد"];
    }

    public function trackClick(int $bannerId): array
    {
        $banner = $this->bannerModel->find($bannerId);
        if (!$banner || !$banner->is_active) {
            return ['success' => false, 'redirect' => '/'];
        }

        $userId = auth() ? user_id() : null;
        $ip = get_client_ip();

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

            // استفاده از متد متمرکز و اتمیک Ads درون تراکنش فعال لایه سرویس
            $this->bannerModel->registerInteractionClick($bannerId, $userId, $ip);

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('banner.trackClick.failed', [
                'error' => $e->getMessage(),
                'banner_id' => $bannerId
            ]);
        }

        $redirectUrl = $banner->link ?: '/';
        if (!$this->validateRedirectUrl($redirectUrl)) {
            $this->logger->warning('banner.unsafe_redirect', ['url' => $redirectUrl, 'banner_id' => $bannerId]);
            $redirectUrl = '/';
        }

        return ['success' => true, 'redirect' => $redirectUrl];
    }

    /**
     * بررسی امنیت URL هدایت برای جلوگیری از Open Redirect و XSS (javascript: و غیره)
     */
    private function validateRedirectUrl(string $url): bool
    {
        if (empty($url) || $url === '/') {
            return true;
        }

        $parsed = parse_url($url);
        if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parsed['host'] ?? '');
        if (empty($host)) {
            return false;
        }

        // Whitelist domains
        $allowedDomains = ['chortke.com', 'trusted-partner.com', 'example.com'];
        $currentHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
        if ($currentHost !== '') {
            $allowedDomains[] = $currentHost;
        }

        // To prevent sub-domain spoofing or @ bypass, check that the host matches or ends with one of the allowed domains
        foreach ($allowedDomains as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function deactivateExpired(): int
    {
        // استفاده از متد انقضای متمرکز و فوق‌العاده بهینه کلاس Ads
        $count = $this->bannerModel->expireOldAdvertisements();
        if ($count > 0) {
            $this->logger->info('banners_expired', ['message' => "{$count} تبلیغ/بنر منقضی بروزرسانی شد"]);
        }
        return $count;
    }

    public function updatePlacement(int $id, array $data): array
    {
        $placement = $this->placementModel->find($id);
        if (!$placement) {
            return ['success' => false, 'message' => 'جایگاه یافت نشد'];
        }

        $result = $this->placementModel->update($id, $data);
        if (!$result) {
            return ['success' => false, 'message' => 'خطا در بروزرسانی'];
        }

        $this->logger->info('placement_updated', ['message' => "جایگاه {$placement->slug} بروزرسانی شد"]);
        return ['success' => true, 'message' => 'جایگاه با موفقیت بروزرسانی شد'];
    }

    public function togglePlacement(int $id): array
    {
        $placement = $this->placementModel->find($id);
        if (!$placement) {
            return ['success' => false, 'message' => 'جایگاه یافت نشد'];
        }

        $newStatus = $placement->is_active ? 0 : 1;
        $this->placementModel->update($id, ['is_active' => $newStatus]);
        $statusText = $newStatus ? 'فعال' : 'غیرفعال';

        return ['success' => true, 'is_active' => $newStatus, 'message' => "جایگاه {$statusText} شد"];
    }

    protected function uploadBannerImage(array $file): array
    {
        $result = $this->uploadService->upload(
            $file,
            'banners',
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            2 * 1024 * 1024 // 2MB
        );

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['message']];
        }

        return ['success' => true, 'path' => $result['path'], 'filename' => $result['filename']];
    }

    protected function validateBanner(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (!$isUpdate) {
            if (empty($data['title'])) {
                $errors['title'] = 'عنوان بنر الزامی است';
            }
            if (empty($data['placement'])) {
                $errors['placement'] = 'جایگاه بنر الزامی است';
            }
        }

        if (!empty($data['title']) && \mb_strlen($data['title']) > 255) {
            $errors['title'] = 'عنوان حداکثر 255 کاراکتر';
        }

        if (!empty($data['link'])) {
            if (!filter_var($data['link'], FILTER_VALIDATE_URL) || !$this->validateRedirectUrl($data['link'])) {
                $errors['link'] = 'لینک معتبر نیست (باید با http یا https شروع شود)';
            }
        }

        $validPlacements = ['header', 'footer', 'sidebar', 'homepage', 'dashboard_user', 'dashboard_admin'];
        if (!empty($data['placement']) && !\in_array($data['placement'], $validPlacements)) {
            $errors['placement'] = 'جایگاه نامعتبر است';
        }

        if (!empty($data['start_date']) && !empty($data['end_date'])) {
            if (\strtotime($data['end_date']) <= \strtotime($data['start_date'])) {
                $errors['end_date'] = 'تاریخ پایان باید بعد از تاریخ شروع باشد';
            }
        }
        return $errors;
    }

    public function searchBanners(string $q, array $filters, int $limit, int $offset): array
    {
        $query = $this->db->table('ads')->where('type', '=', 'banner')->whereNull('deleted_at');

        if (!empty($q)) {
            $like = "%{$q}%";
            $query->where(function($sub) use ($like) {
                $sub->where('title', 'LIKE', $like)->orWhere('link', 'LIKE', $like);
            });
        }

        if (!empty($filters['placement'])) {
            $query->where('placement', '=', e($filters['placement'], ENT_QUOTES, 'UTF-8'));
        }
        if (!empty($filters['status'])) {
            $query->where('status', '=', e($filters['status'], ENT_QUOTES, 'UTF-8'));
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', '=', (int)$filters['is_active']);
        }

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy('created_at', 'DESC')->limit($limit)->offset($offset)->get() ?? []
        ];
    }

    /**
     * تمام Placements حاصل کریں
     */
    public function getAllPlacements(): array
    {
        return $this->placementModel->all() ?? [];
    }

    /**
     * Placement کو ID سے تلاش کریں
     */
    public function findPlacement(int $id): ?object
    {
        return $this->placementModel->find($id);
    }

    /**
     * Placement کو slug سے تلاش کریں
     */
    public function findPlacementBySlug(string $slug): ?object
    {
        return $this->placementModel->findBySlug($slug);
    }

    /**
     * تمام فعال Placements حاصل کریں
     */
    public function getActivePlacements(): array
    {
        return $this->db->table('banner_placements')
            ->where('is_active', '=', true)
            ->orderBy('display_order', 'ASC')
            ->get() ?? [];
    }

    /**
     * تخلیه بافر بازدیدها از Redis به دیتابیس
     * باید توسط کرون‌جاب دوره‌ای (مثلاً هر ۵ دقیقه) صدا زده شود
     */
    public function flushImpressionsBuffer(): int
    {
        if ($this->cache->driver() !== 'redis') {
            return 0;
        }

        $redis = $this->cache->redis();
        $key = $this->cache->redisKey('banner_impressions_buffer');
        $data = $redis->hGetAll($key);
        
        if (empty($data)) {
            return 0;
        }

        $processed = 0;
        foreach ($data as $bannerId => $count) {
            $count = (int)$count;
            if ($count <= 0) continue;

            // تلاش برای ثبت در دیتابیس
            $sql = "UPDATE ads SET 
                    impressions = impressions + ?,
                    ctr = CASE WHEN (impressions + ?) > 0 THEN ROUND((clicks / (impressions + ?)) * 100, 2) ELSE 0 END,
                    updated_at = NOW()
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            if ($stmt->execute([$count, $count, $count, (int)$bannerId])) {
                // اگر با موفقیت در دیتابیس ثبت شد، از بافر کم کن
                // HINCRBY با مقدار منفی برای کم کردن (اتمیک)
                $redis->hIncrBy($key, (string)$bannerId, -$count);
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Banner stats حاصل کریں
     */
    public function getStats(): array
    {
        $totalBanners = $this->db->table('ads')->where('type', '=', 'banner')->whereNull('deleted_at')->count();
        $activeBanners = $this->db->table('ads')->where('type', '=', 'banner')->where('is_active', '=', true)->whereNull('deleted_at')->count();
        $totalImpressions = $this->db->query("SELECT SUM(impression_count) as total FROM ads WHERE type = 'banner' AND deleted_at IS NULL")->fetch(\PDO::FETCH_OBJ)->total ?? 0;
        $totalClicks = $this->db->query("SELECT SUM(click_count) as total FROM ads WHERE type = 'banner' AND deleted_at IS NULL")->fetch(\PDO::FETCH_OBJ)->total ?? 0;

        return [
            'total_banners' => $totalBanners,
            'active_banners' => $activeBanners,
            'total_impressions' => $totalImpressions,
            'total_clicks' => $totalClicks,
            'ctr' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0,
        ];
    }
}
