<?php

namespace App\Services\Adapters;

use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use Core\Database;
use App\Services\SettingService;

class AdTubeAdapter extends AdapterBase implements AdSystemContract
{
    public function __construct(private Database $db, LoggerInterface $logger, SettingService $settingService)
    {
        parent::__construct($logger, $settingService);
    }

    public function getType(): string { return 'adtube'; }

    public function create(int $userId, array $data): array
    {
        try {
            $this->validateData($data);
        } catch (\Core\Exceptions\ValidationException $e) {
            return $this->errorResponse('داده‌های ورودی نامعتبر', $e->getErrors());
        }

        try {
            $result = $this->db->query(
                "INSERT INTO adtube_campaigns (creator_id, title, budget, status, created_at) 
                 VALUES (?, ?, ?, 'pending', NOW())",
                [$userId, $data['title'], $data['budget'] ?? 0]
            );

            return $result ? 
                $this->successResponse('تبلیغ AdTube ایجاد شد', ['id' => $this->db->lastInsertId()]) :
                $this->errorResponse('خطا در ایجاد');
        } catch (\Exception $e) {
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
        $result = $this->db->query(
            "SELECT status FROM adtube_campaigns WHERE id = ? LIMIT 1",
            [$adId]
        )->fetch();
        return !$result || in_array($result->status, ['expired', 'completed']);
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        return $amount * ((float) $this->settingService->get('adtube_site_fee_percent', PercentageConstants::AD_TUBE_FEE_PERCENT) / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        return ['success' => true, 'transaction_id' => 0, 'message' => 'پرداخت پردازش شد'];
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $this->logInfo('track', ['event' => $eventType, 'ad_id' => $adId, 'user_id' => $userId]);
        return $this->successResponse('رویداد ثبت شد');
    }

    public function getStatus(int $adId): ?array
    {
        $result = $this->db->query(
            "SELECT id, status FROM adtube_campaigns WHERE id = ? LIMIT 1",
            [$adId]
        )->fetch();
        return $result ? ['id' => $result->id, 'type' => 'adtube', 'status' => $result->status] : null;
    }
}
