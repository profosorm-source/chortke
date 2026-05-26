<?php

namespace App\Adapters;

use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use App\Models\Ads;
use App\Services\Wallet\WalletService;
use Core\Database;
use App\Services\SettingService;

class SeoAdAdapter extends AdapterBase implements AdSystemContract
{
    public function __construct(
        private Ads $adModel,
        private WalletService $walletService,
        private Database $db,
        LoggerInterface $logger,
        SettingService $settingService,
        ValidatorFactoryInterface $validatorFactory
    ) {
        parent::__construct($logger, $settingService, $validatorFactory);
    }

    public function getType(): string { return 'seo'; }

    public function create(int $userId, array $data): array
    {
        try {
            $this->validateData($data);
        } catch (\Core\Exceptions\ValidationException $e) {
            return $this->errorResponse('ورودی‌های تبلیغ سئو معتبر نیستند', $e->getErrors());
        }

        $budget = (float) ($data['budget'] ?? 0);
        $feePercent = (float) $this->settingService->get('seo_ad_site_fee_percent', 15);
        $totalWithFee = $budget + ($budget * $feePercent / 100);

        try {
            $this->db->beginTransaction();

            $txId = $this->walletService->withdraw(
                $userId,
                $totalWithFee,
                'irt',
                [
                    'type' => 'seo_ad_budget', 
                    'idempotency_key' => \Core\IdempotencyKey::generateFromPayload('seo_ad_budget', [
                        'user_id' => $userId,
                        'ts'      => microtime(true),
                        'title' => $data['title'] ?? 'untitled',
                        'amount' => $totalWithFee
                    ])
                ]
            );

            if (!$txId) {
                $this->db->rollBack();
                return $this->errorResponse('موجودی کافی نیست');
            }

            $ad = $this->adModel->create([
                'user_id' => $userId,
                'type' => 'seo',
                'title' => $data['title'],
                'site_url' => $data['site_url'] ?? null,
                'keyword' => $data['keyword'] ?? null,
                'description' => $data['description'] ?? null,
                'price_per_click' => (float)($data['price_per_click'] ?? 0),
                'deadline' => $data['deadline'] ?? null,
                'budget' => $budget,
                'status' => 'pending',
                'remaining_budget' => $budget,
            ]);

            $this->db->commit();
            return $this->successResponse('تبلیغ SEO ایجاد شد', ['id' => $ad->id]);
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('create', $e->getMessage());
            return $this->errorResponse('خطا: ' . $e->getMessage());
        }
    }

    public function isExpired(int $adId): bool
    {
        $ad = $this->adModel->find($adId);
        return !$ad || $ad->status === 'expired' || $ad->remaining_budget <= 0;
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        return $amount * ((float) $this->settingService->get('seo_ad_site_fee_percent', 15) / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        $result = $this->walletService->withdraw($userId, $amount, $currency, ['type' => 'seo_payment']);
        return $result ?
            $this->successResponse('پرداخت موفق', ['transaction_id' => $result]) :
            $this->errorResponse('خطا در پرداخت');
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $this->logInfo('track', ['event' => $eventType, 'ad_id' => $adId, 'user_id' => $userId]);
        return $this->successResponse('رویداد ثبت شد');
    }

    public function getStatus(int $adId): ?array
    {
        $ad = $this->adModel->find($adId);
        return $ad ? ['id' => $ad->id, 'type' => 'seo', 'status' => $ad->status] : null;
    }

    /**
     * پیاده‌سازی الزامی والد
     */
    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        if (empty($data['title'])) $errors[] = 'عنوان تبلیغ الزامی است.';
        if (empty($data['site_url']) || !filter_var($data['site_url'], FILTER_VALIDATE_URL)) $errors[] = 'آدرس سایت معتبر نیست.';
        if (empty($data['keyword'])) $errors[] = 'کلمه کلیدی الزامی است.';
        if (!isset($data['price_per_click']) || !is_numeric($data['price_per_click']) || $data['price_per_click'] < 0) $errors[] = 'هزینه هر کلیک نامعتبر است.';
        if (!isset($data['budget']) || !is_numeric($data['budget']) || $data['budget'] < 0) $errors[] = 'بودجه کل نامعتبر است.';

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}


