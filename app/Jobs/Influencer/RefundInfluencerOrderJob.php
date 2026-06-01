<?php

declare(strict_types=1);

namespace App\Jobs\Influencer;

class RefundInfluencerOrderJob
{
    private \App\Models\InfluencerOrder $orderModel;
    private \App\Contracts\WalletServiceInterface $walletService;
    private \App\Services\Settings\AppSettings $appSettings;
    private \App\Contracts\LoggerInterface $logger;
    private \Core\TransactionWrapper $transactionWrapper;
    public function __construct(
        \App\Models\InfluencerOrder $orderModel,
        \App\Contracts\WalletServiceInterface $walletService,
        \App\Services\Settings\AppSettings $appSettings,
        \App\Contracts\LoggerInterface $logger,
        \Core\TransactionWrapper $transactionWrapper
    ) {        $this->orderModel = $orderModel;
        $this->walletService = $walletService;
        $this->appSettings = $appSettings;
        $this->logger = $logger;
        $this->transactionWrapper = $transactionWrapper;
}

    public function handle(int $orderId, int $actorId, float $refundPercent = 100.0, string $reason = ''): array
{
    $order = $this->orderModel->find($orderId);
    if (!$order) {
        return ['success' => false, 'message' => 'سفارش یافت نشد.'];
    }

    $refundAmount = round((float)$order->price * ($refundPercent / 100), 2);

    try {
        $this->transactionWrapper->runWithRetry(function() use ($order, $orderId, $refundAmount, $refundPercent, $reason, $actorId) {
            // Refund customer
            $refundPayload = [
                'user_id' => (int)$order->customer_id,
                'amount' => $refundAmount,
                'currency' => $order->currency,
                'metadata' => [
                    'type' => 'refund',
                    'description' => "بازگشت سفارش #{$orderId}",
                    'idempotency_key' => "story_refund_{$orderId}",
                    'order_id' => $orderId,
                ],
            ];

            if ($this->outboxService) {
                $ok = $this->outboxService->record('influencer_order', $orderId, \App\Events\Registry\EventRegistry::INFLUENCER_ORDER_REFUNDED, $refundPayload);
                if (!$ok) {
                    throw new \Exception('خطا در ثبت رکورد خروجی بازگشت وجه.');
                }
            } else {
                $refundResult = $this->walletService->deposit(
                    (int)$order->customer_id,
                    $refundAmount,
                    $order->currency,
                    ['type' => 'refund', 'description' => "بازگشت سفارش #{$orderId}", 'idempotency_key' => "story_refund_{$orderId}"]
                );
                if (!($refundResult['success'] ?? false)) {
                    throw new \Exception('خطا در بازگشت وجه.');
                }
            }

            if ($refundPercent < 100) {
                $feePercent = (float) $this->appSettings->get('influencer_fee_percent', 15);
                $remainingAmount = (float)$order->price - $refundAmount;
                $influencerShare = round($remainingAmount * (1 - $feePercent / 100), 2);

                if ($influencerShare > 0) {
                    $partialPayload = [
                        'user_id' => (int)$order->influencer_user_id,
                        'amount' => $influencerShare,
                        'currency' => $order->currency,
                        'metadata' => [
                            'type' => 'partial_earning',
                            'description' => "درآمد جزئی سفارش #{$orderId}",
                            'idempotency_key' => "story_partial_{$orderId}",
                            'order_id' => $orderId,
                        ],
                    ];

                    if ($this->outboxService) {
                        $ok2 = $this->outboxService->record('influencer_order', $orderId, \App\Events\Registry\EventRegistry::INFLUENCER_ORDER_PARTIAL_REFUNDED, $partialPayload);
                        if (!$ok2) {
                            throw new \Exception('خطا در ثبت رکورد خروجی پرداخت جزئی.');
                        }
                    } else {
                        $partialResult = $this->walletService->deposit(
                            (int)$order->influencer_user_id,
                            $influencerShare,
                            $order->currency,
                            ['type' => 'partial_earning', 'description' => "درآمد جزئی سفارش #{$orderId}", 'idempotency_key' => "story_partial_{$orderId}"]
                        );
                        if (!($partialResult['success'] ?? false)) {
                            throw new \Exception('خطا در پرداخت جزئی به اینفلوئنسر.');
                        }
                    }
                }
            }

            $this->orderModel->update($orderId, [
                'status'      => $refundPercent >= 100 ? 'refunded' : 'partially_refunded',
                'admin_note'  => $reason,
                'reviewed_by' => $actorId,
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);
        });

        // Replace direct cache/audit calls with domain event
        $this->eventDispatcher->dispatchAsync('influencer.order_refunded', [
            'order_id' => $orderId,
            'actor_id' => $actorId,
            'refund_percent' => $refundPercent,
            'amount' => $refundAmount,
            'reason' => $reason,
            'customer_id' => (int)$order->customer_id,
            'influencer_user_id' => (int)$order->influencer_user_id
        ]);

        return ['success' => true, 'message' => "بازگشت {$refundPercent}٪ وجه انجام شد."];

    } catch (\Exception $e) {
        $this->logger->error('story.refund_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        return ['success' => false, 'message' => in_array($e->getMessage(), ['خطا در بازگشت وجه.', 'خطا در پرداخت جزئی به اینفلوئنسر.']) ? $e->getMessage() : 'خطای سیستمی در بازگشت وجه.'];
    }
}
}
