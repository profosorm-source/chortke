<?php

namespace App\Services\Adapters;

use App\Contracts\AdSystemContract;
use App\Models\VitrineListing;
use App\Services\WalletService;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\SettingService;

class VitrineAdapter extends AdapterBase implements AdSystemContract
{
    public function __construct(
        private VitrineListing $listingModel,
        private WalletService $walletService,
        private Database $db,
        LoggerInterface $logger,
        SettingService $settingService
    ) {
        parent::__construct($logger, $settingService);
    }

    public function getType(): string { return 'vitrine'; }

    public function create(int $userId, array $data): array
    {
        $this->validateData($data, [
            'title' => 'required|string',
            'price' => 'required|numeric|min:0',
            'description' => 'string'
        ]);

        $price = (float) ($data['price'] ?? 0);
        $feePercent = (float) $this->settingService->get('vitrine_site_fee_percent', 5);
        $feeAmount = $price * ($feePercent / 100);

        try {
            $this->db->beginTransaction();

            $listing = $this->listingModel->create([
                'seller_id' => $userId,
                'title' => $data['title'],
                'price' => $price,
                'status' => 'pending',
                'description' => $data['description'] ?? null,
            ]);

            $this->db->commit();
            return $this->successResponse('فهرست ویترین ایجاد شد', ['id' => $listing->id]);
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('create', $e->getMessage());
            return $this->errorResponse('خطا: ' . $e->getMessage());
        }
    }

    public function isExpired(int $adId): bool
    {
        $listing = $this->listingModel->find($adId);
        return !$listing || $listing->status === 'expired' || $listing->status === 'sold';
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        return $amount * ((float) $this->settingService->get('vitrine_site_fee_percent', 5) / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        $result = $this->walletService->withdraw($userId, $amount, $currency, ['type' => 'vitrine_payment']);
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
        $listing = $this->listingModel->find($adId);
        return $listing ? ['id' => $listing->id, 'type' => 'vitrine', 'status' => $listing->status] : null;
    }
}
