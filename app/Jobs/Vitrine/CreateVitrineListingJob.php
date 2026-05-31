<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

class CreateVitrineListingJob
{
    public function __construct(
        
    ) {}

    public function handle(int $userId, array $data): array
    {
        $check = $this->canTrade($userId);
        if (!$check['ok']) return ['success' => false, 'message' => $check['message']];

        // محدودیت تعداد آگهی فعال
        $maxActive = (int) $this->settings->get('vitrine_max_active_per_user', '5');
        $activeCount = $this->listing->countActiveByUser($userId);
        if ($activeCount >= $maxActive) {
            return ['success' => false, 'message' => "حداکثر {$maxActive} آگهی فعال می‌توانید داشته باشید."];
        }

        $minPrice = (float) $this->settings->get('vitrine_min_price_usdt', '1');
        $maxPrice = (float) $this->settings->get('vitrine_max_price_usdt', '100000');
        $price    = (float) ($data['price_usdt'] ?? 0);

        if ($price < $minPrice || $price > $maxPrice) {
            return ['success' => false, 'message' => "قیمت باید بین {$minPrice} و {$maxPrice} USDT باشد."];
        }

        $data['seller_id'] = $userId;

        $result = $this->listing->createListing($data);
        if (!$result) {
            return ['success' => false, 'message' => 'خطا در ثبت آگهی. لطفاً دوباره تلاش کنید.'];
        }

        $this->eventDispatcher->dispatch('vitrine.listing_created', [
            'user_id' => $userId,
            'amount'  => $price,
            'currency' => 'usdt'
        ]);

        return ['success' => true, 'listing' => $result];
    }
}
