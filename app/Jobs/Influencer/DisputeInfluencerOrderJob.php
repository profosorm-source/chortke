<?php

declare(strict_types=1);

namespace App\Jobs\Influencer;

class DisputeInfluencerOrderJob
{
    public function __construct(
        private \App\Models\InfluencerOrder $orderModel,
        private \App\Services\Settings\AppSettings $appSettings
    ) {}

    public function handle(int $orderId, int $customerId, string $reason): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order || (int)$order->customer_id !== $customerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if ($order->status !== 'awaiting_buyer_check') {
            return ['success' => false, 'message' => 'وضعیت سفارش اجازه این عملیات را نمی‌دهد.'];
        }
        if ($this->countRecentDisputes($customerId, 24) >= (int)$this->appSettings->get('influencer_dispute_rate_limit', 3)) {
            return ['success' => false, 'message' => 'تعداد اعتراض در روز به حداکثر رسیده است.'];
        }

        $this->orderModel->update($orderId, [
            'status'                     => 'peer_resolution',
            'peer_resolution_started_at' => \date('Y-m-d H:i:s'),
        ]);

        $this->eventDispatcher->dispatchAsync('influencer.dispute_opened', [
            'order_id'           => $orderId,
            'customer_id'        => $customerId,
            'influencer_user_id' => (int)$order->influencer_user_id,
            'reason'             => $reason
        ]);

        return ['success' => true, 'message' => 'اعتراض ثبت شد. وارد پنل گفت‌وگو شوید.', 'order_id' => $orderId];
    }
}
