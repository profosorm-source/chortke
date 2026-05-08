<?php

namespace App\Services\Adapters;

use App\Contracts\AdSystemContract;
use App\Models\SeoAd;
use App\Services\WalletService;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\SettingService;

class SeoAdAdapter extends AdapterBase implements AdSystemContract
{
    public function __construct(
        private SeoAd $adModel,
        private WalletService $walletService,
        private Database $db,
        LoggerInterface $logger,
        SettingService $settingService
    ) {
        parent::__construct($logger, $settingService);
    }

    public function getType(): string { return 'seo'; }

    public function create(int $userId, array $data): array
    {
        $this->validateData($data, [
            'title' => 'required|string',
            'budget' => 'required|numeric|min:0'
        ]);

        $budget = (float) ($data['budget'] ?? 0);
        $feePercent = (float) $this->settingService->get('seo_ad_site_fee_percent', 15);
        $totalWithFee = $budget + ($budget * $feePercent / 100);

        try {
            $this->db->beginTransaction();

            $txId = $this->walletService->withdraw(
                $userId,
                $totalWithFee,
                'irt',
                ['type' => 'seo_ad_budget', 'idempotency_key' => "seo_" . time()]
            );

            if (!$txId) {
                $this->db->rollBack();
                return $this->errorResponse('موجودی کافی نیست');
            }

            $ad = $this->adModel->create([
                'user_id' => $userId,
                'title' => $data['title'],
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
}
