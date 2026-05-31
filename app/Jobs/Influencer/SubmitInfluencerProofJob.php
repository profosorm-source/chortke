<?php

declare(strict_types=1);

namespace App\Jobs\Influencer;

class SubmitInfluencerProofJob
{
    public function __construct(
        private \App\Models\InfluencerOrder $orderModel,
        private \App\Services\Settings\AppSettings $appSettings
    ) {}

    public function handle(int $orderId, int $influencerUserId, array $proofData): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order || (int)$order->influencer_user_id !== $influencerUserId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if (!\in_array($order->status, ['accepted', 'published'])) {
            return ['success' => false, 'message' => 'وضعیت سفارش مناسب نیست.'];
        }

        $buyerCheckHours    = (int) $this->appSettings->get('influencer_buyer_check_hours', 24);
        $buyerCheckDeadline = \date('Y-m-d H:i:s', \strtotime("+{$buyerCheckHours} hours"));
        $now                = \date('Y-m-d H:i:s');

        $updateData = [
            'status'                  => 'awaiting_buyer_check',
            'proof_submitted_at'      => $now,
            'buyer_check_notified_at' => $now,
            'buyer_check_deadline'    => $buyerCheckDeadline,
        ];
        if (!empty($proofData['proof_screenshot'])) $updateData['proof_screenshot'] = $proofData['proof_screenshot'];
        if (!empty($proofData['proof_link']))        $updateData['proof_link']        = $proofData['proof_link'];
        if (!empty($proofData['proof_notes']))       $updateData['proof_notes']       = $proofData['proof_notes'];

        $this->orderModel->update($orderId, $updateData);

        $this->eventDispatcher->dispatchAsync('influencer.proof_submitted', [
            'order_id'           => $orderId,
            'customer_id'        => (int)$order->customer_id,
            'influencer_user_id' => $influencerUserId,
            'deadline'           => $buyerCheckDeadline
        ]);

        return ['success' => true, 'message' => 'مدرک ثبت شد و به تبلیغ‌دهنده اطلاع‌رسانی شد.'];
    }
}
