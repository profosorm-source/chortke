<?php

declare(strict_types=1);

namespace App\Jobs\Influencer;

class CreateInfluencerOrderJob
{
    private \App\Services\Settings\AppSettings $appSettings;
    private \App\Models\InfluencerOrder $orderModel;
    private \App\Services\Shared\EscrowService $escrowService;
    private \App\Contracts\WalletServiceInterface $walletService;
    private \App\Contracts\LoggerInterface $logger;
    private \Core\TransactionWrapper $transactionWrapper;
    public function __construct(
        \App\Services\Settings\AppSettings $appSettings,
        \App\Models\InfluencerOrder $orderModel,
        \App\Services\Shared\EscrowService $escrowService,
        \App\Contracts\WalletServiceInterface $walletService,
        \App\Contracts\LoggerInterface $logger,
        \Core\TransactionWrapper $transactionWrapper
    ) {        $this->appSettings = $appSettings;
        $this->orderModel = $orderModel;
        $this->escrowService = $escrowService;
        $this->walletService = $walletService;
        $this->logger = $logger;
        $this->transactionWrapper = $transactionWrapper;
}

    public function handle(int $customerId, int $influencerId, array $data): array
    {
        if (!$this->appSettings->get('influencer_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم غیرفعال است.'];
        }

        $recentOrders = $this->countRecentOrders($customerId, 1);
        $maxPerHour   = (int) $this->appSettings->get('influencer_order_rate_limit_per_hour', 5);
        if ($recentOrders >= $maxPerHour) {
            return ['success' => false, 'message' => 'تعداد سفارش در ساعت به حداکثر رسیده است.'];
        }

        $profile = $this->profileModel->find($influencerId);
        if (!$profile || $profile->status !== 'verified' || !(int)$profile->is_active) {
            return ['success' => false, 'message' => 'اینفلوئنسر فعال نیست.'];
        }
        if ((int)$profile->user_id === $customerId) {
            return ['success' => false, 'message' => 'نمی‌توانید برای پیج خودتان سفارش دهید.'];
        }

        $orderType = $data['order_type'] ?? 'story';
        $duration  = (int)($data['duration_hours'] ?? 24);
        $price     = $this->calculatePrice($profile, $orderType, $duration);

        if ($price <= 0) {
            return ['success' => false, 'message' => 'قیمت نامعتبر است.'];
        }

        $feePercent        = (float) $this->appSettings->get('influencer_fee_percent', 15);
        $feeAmount         = \round($price * ($feePercent / 100), 2);
        $influencerEarning = $price - $feeAmount;
        $idempotencyKey    = "influencer_order_{$customerId}_{$influencerId}_" . \md5(\serialize($data));

        try {
            $order = null;
            $this->transactionWrapper->runWithRetry(function() use ($customerId, $influencerId, $profile, $orderType, $duration, $price, $feeAmount, $feePercent, $influencerEarning, $data, $idempotencyKey, &$order) {
                
                $order = $this->orderModel->create([
                    'customer_id'            => $customerId,
                    'influencer_id'          => $influencerId,
                    'influencer_user_id'     => (int)$profile->user_id,
                    'order_type'             => $orderType,
                    'duration_hours'         => $duration,
                    'media_path'             => $data['media_path'] ?? null,
                    'caption'                => $data['caption'] ?? null,
                    'link'                   => $data['link'] ?? null,
                    'preferred_publish_time' => $data['preferred_publish_time'] ?? null,
                    'verification_code'      => $this->orderModel->generateVerificationCode(),
                    'price'                  => $price,
                    'currency'               => $profile->currency,
                    'site_fee_percent'       => $feePercent,
                    'site_fee_amount'        => $feeAmount,
                    'influencer_earning'     => $influencerEarning,
                    'status'                 => 'pending',
                    'payment_transaction_id' => null,
                    'idempotency_key'        => $idempotencyKey,
                ]);

                if (!$order) {
                    throw new \Exception('خطا در ثبت سفارش.');
                }

                if ($this->escrowService) {
                    $escrowResult = $this->escrowService->holdFunds(
                        (int)$order->id,
                        'influencer_order',
                        $customerId,
                        (int)$profile->user_id,
                        (string)$price,
                        $profile->currency
                    );
                    
                    if (empty($escrowResult['ok'])) {
                        throw new \Exception($escrowResult['error'] ?? 'خطا در بلوکه کردن مبلغ سفارش');
                    }
                } else {
                    $txResult = $this->walletService->withdraw(
                        $customerId,
                        $price,
                        $profile->currency,
                        ['type' => 'escrow', 'description' => "سفارش {$orderType} - @{$profile->username}", 'idempotency_key' => $idempotencyKey]
                    );
                    if (!($txResult['success'] ?? false)) {
                        throw new \Exception('موجودی کافی نیست.');
                    }
                    $this->orderModel->update($order->id, ['payment_transaction_id' => $txResult['transaction_id'] ?? null]);
                }

                // 🚀 Side effects moved to central listener
                $this->eventDispatcher->dispatchAsync('influencer.order_created', [
                    'order_id'           => $order->id,
                    'customer_id'        => $customerId,
                    'influencer_user_id' => (int)$profile->user_id,
                    'price'              => $price,
                    'currency'           => $profile->currency,
                    'order_type'         => $orderType
                ]);
                $this->profileModel->update($influencerId, [
                    'total_orders' => (int)$profile->total_orders + 1,
                ]);
            });

            return ['success' => true, 'message' => 'سفارش ثبت و مبلغ در صندوق امانی قفل شد.', 'order' => $order];

        } catch (\Exception $e) {
            $this->logger->error('story.order_create_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => in_array($e->getMessage(), ['موجودی کافی نیست.', 'خطا در ثبت سفارش.']) ? $e->getMessage() : 'خطای سیستمی در ثبت سفارش.'];
        }
    }
}
