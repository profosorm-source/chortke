<?php

namespace App\Services\Adapters;

use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use App\Models\Banner;
use App\Services\WalletService;
use Core\Database;
use App\Services\SettingService;

class BannerAdapter extends AdapterBase implements AdSystemContract
{
    public function __construct(
        private Banner $bannerModel,
        private WalletService $walletService,
        private Database $db,
        LoggerInterface $logger,
        SettingService $settingService
    ) {
        parent::__construct($logger, $settingService);
    }

    public function getType(): string { return 'banner'; }

    public function create(int $userId, array $data): array
    {
        try {
            $this->validateData($data);
        } catch (\Core\Exceptions\ValidationException $e) {
            return $this->errorResponse('داده‌های ورودی نامعتبر', $e->getErrors());
        }

        $budget = (float) ($data['budget'] ?? 0);
        $feePercent = (float) $this->settingService->get('banner_site_fee_percent', 12);
        $totalWithFee = $budget + ($budget * $feePercent / 100);

        try {
            $this->db->beginTransaction();

            $txId = $this->walletService->withdraw(
                $userId,
                $totalWithFee,
                'irt',
                ['type' => 'banner_budget', 'idempotency_key' => "banner_" . time()]
            );

            if (!$txId) {
                $this->db->rollBack();
                return $this->errorResponse('موجودی کافی نیست');
            }

            $banner = $this->bannerModel->create([
                'created_by' => $userId,
                'title' => $data['title'],
                'budget' => $budget,
                'status' => 'pending',
                'is_active' => 0,
            ]);

            $this->db->commit();
            return $this->successResponse('بنر ایجاد شد', ['id' => $banner->id]);
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('create', $e->getMessage());
            return $this->errorResponse('خطا: ' . $e->getMessage());
        }
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        if (empty($data['title'])) $errors[] = 'عنوان الزامی است';
        if (empty($data['budget']) || (float)$data['budget'] <= 0) $errors[] = 'بودجه باید مثبت باشد';
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function isExpired(int $adId): bool
    {
        $banner = $this->bannerModel->find($adId);
        return !$banner || !$banner->is_active;
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        return $amount * ((float) $this->settingService->get('banner_site_fee_percent', 12) / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        $result = $this->walletService->withdraw($userId, $amount, $currency, ['type' => 'banner_payment']);
        return $result ?
            $this->successResponse('پرداخت انجام شد', ['transaction_id' => $result]) :
            $this->errorResponse('پرداخت ناموفق');
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $this->logInfo('track', ['event' => $eventType, 'ad_id' => $adId, 'user_id' => $userId]);
        return $this->successResponse('رویداد ثبت شد');
    }

    public function getStatus(int $adId): ?array
    {
        $banner = $this->bannerModel->find($adId);
        return $banner ? ['id' => $banner->id, 'type' => 'banner', 'status' => $banner->status] : null;
    }
}
