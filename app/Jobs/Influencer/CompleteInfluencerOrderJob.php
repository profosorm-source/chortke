<?php

declare(strict_types=1);

namespace App\Jobs\Influencer;

class CompleteInfluencerOrderJob
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

    public function handle(int $orderId, int $actorId, string $reason = 'completed'): array
{
    $order = $this->orderModel->find($orderId);
    if (!$order) {
        return ['success' => false, 'message' => 'سفارش یافت نشد.'];
    }

    // ✅ FIX: تشخیص اینکه عملیات توسط سیستم انجام شده یا ادمین
    $isSystemAction = ($actorId === self::SYSTEM_ACTOR_ID || $actorId === 0);
    $actorType = $isSystemAction ? 'system' : 'admin';

    try {
        $this->transactionWrapper->runWithRetry(function() use ($order, $orderId, $isSystemAction, $actorId, $reason) {
            $payload = [
                'user_id' => (int)$order->influencer_user_id,
                'amount' => (float)$order->influencer_earning,
                'currency' => $order->currency,
                'metadata' => [
                    'type' => 'earning',
                    'description' => "درآمد سفارش #{$orderId}",
                    'idempotency_key' => "story_payout_{$orderId}",
                    'order_id' => $orderId,
                ],
            ];

            if ($this->outboxService) {
                $ok = $this->outboxService->record('influencer_order', $orderId, \App\Events\Registry\EventRegistry::INFLUENCER_ORDER_COMPLETED, $payload);
                if (!$ok) {
                    throw new \Exception('خطا در ثبت رکورد خروجی پرداخت');
                }
                $this->orderModel->update($orderId, [
                    'status'                => 'completed',
                    'buyer_confirmed_at'    => date('Y-m-d H:i:s'),
                    'payout_transaction_id' => null,
                ]);
            } else {
                $payoutResult = $this->walletService->deposit(
                    (int)$order->influencer_user_id,
                    (float)$order->influencer_earning,
                    $order->currency,
                    ['type' => 'earning', 'description' => "درآمد سفارش #{$orderId}", 'idempotency_key' => "story_payout_{$orderId}"]
                );
                if (!($payoutResult['success'] ?? false)) {
                    throw new \Exception('خطا در پرداخت به اینفلوئنسر.');
                }
                $this->orderModel->update($orderId, [
                    'status'                => 'completed',
                    'buyer_confirmed_at'    => date('Y-m-d H:i:s'),
                    'payout_transaction_id' => $payoutResult['transaction_id'] ?? null,
                ]);
            }
        });

        $this->eventDispatcher->dispatchAsync('influencer.order_completed', [
            'order_id'           => $orderId,
            'influencer_user_id' => (int)$order->influencer_user_id,
            'influencer_id'      => (int)$order->influencer_id,
            'amount'             => $order->influencer_earning,
            'actor_id'           => $actorId,
            'actor_type'         => $actorType,
            'points'             => (int)$this->appSettings->get('influencer_rep_complete_points', 10)
        ]);

        return ['success' => true, 'message' => 'سفارش تکمیل و درآمد واریز شد.'];
    } catch (\Exception $e) {
        $this->logger->error('story.complete_order_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        return ['success' => false, 'message' => $e->getMessage() === 'خطا در پرداخت به اینفلوئنسر.' ? $e->getMessage() : 'خطای سیستمی در تسویه.'];
    }
}
}
