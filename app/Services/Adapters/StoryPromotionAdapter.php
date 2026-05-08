<?php

namespace App\Services\Adapters;

use App\Contracts\AdSystemContract;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\SettingService;

class StoryPromotionAdapter extends AdapterBase implements AdSystemContract
{
    public function __construct(private Database $db, LoggerInterface $logger, SettingService $settingService)
    {
        parent::__construct($logger, $settingService);
    }

    public function getType(): string { return 'story_promotion'; }

    public function create(int $userId, array $data): array
    {
        $this->validateData($data, [
            'title' => 'required|string',
            'budget' => 'required|numeric|min:0'
        ]);

        try {
            $result = $this->db->query(
                "INSERT INTO story_promotions (user_id, title, budget, status, created_at)
                 VALUES (?, ?, ?, 'pending', NOW())",
                [$userId, $data['title'], $data['budget'] ?? 0]
            );

            return $result ?
                $this->successResponse('تبلیغ داستان ایجاد شد', ['id' => $this->db->lastInsertId()]) :
                $this->errorResponse('خطا در ایجاد');
        } catch (\Exception $e) {
            $this->logError('create', $e->getMessage());
            return $this->errorResponse('خطا: ' . $e->getMessage());
        }
    }

    public function isExpired(int $adId): bool
    {
        $result = $this->db->query(
            "SELECT status FROM story_promotions WHERE id = ? LIMIT 1",
            [$adId]
        )->fetch();
        return !$result || in_array($result->status, ['expired', 'completed']);
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        return $amount * ((float) $this->settingService->get('story_promotion_site_fee_percent', 8) / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        return $this->successResponse('پرداخت پردازش شد', ['transaction_id' => 0]);
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $this->logInfo('track', ['event' => $eventType, 'ad_id' => $adId, 'user_id' => $userId]);
        return $this->successResponse('رویداد ثبت شد');
    }

    public function getStatus(int $adId): ?array
    {
        $result = $this->db->query(
            "SELECT id, status FROM story_promotions WHERE id = ? LIMIT 1",
            [$adId]
        )->fetch();
        return $result ? ['id' => $result->id, 'type' => 'story_promotion', 'status' => $result->status] : null;
    }
}
