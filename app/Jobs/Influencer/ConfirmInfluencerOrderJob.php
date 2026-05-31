<?php

declare(strict_types=1);

namespace App\Jobs\Influencer;

class ConfirmInfluencerOrderJob
{
    public function __construct(
        private \App\Models\InfluencerOrder $orderModel
    ) {}

    public function handle(int $orderId, int $customerId): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order || (int)$order->customer_id !== $customerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if ($order->status !== 'awaiting_buyer_check') {
            return ['success' => false, 'message' => 'وضعیت سفارش اجازه این عملیات را نمی‌دهد.'];
        }
        return $this->completeOrder((int)$order->id, $customerId, 'buyer_confirmed');
    }
}
