<?php

namespace App\Services;

use App\Contracts\WalletServiceInterface;
use App\Models\InfluencerModel;
use App\Models\StoryOrder;
use App\Contracts\LoggerInterface;
use Core\Database;
use Core\EventDispatcher;
use App\Services\SettingService;
use App\Enums\ModuleContext;

class InfluencerService extends \App\Services\BaseService
{
    const SYSTEM_ACTOR_ID = -1;

    private InfluencerModel $profileModel;
    private StoryOrder      $orderModel;
    private WalletServiceInterface $walletService;
    private SettingService  $settingService;
    private ?\App\Services\OutboxService $outboxService = null;
    private ?\App\Services\FinancialEscrowService $escrowService = null;

    public function __construct(
        InfluencerModel $profileModel,
        StoryOrder      $orderModel,
        WalletServiceInterface $walletService,
        SettingService  $settingService,
        LoggerInterface $logger,
        Database $db,
        EventDispatcher $eventDispatcher,
        ?\App\Services\FinancialEscrowService $escrowService = null
    ) {
        // انتقال زیرساخت به والد
        parent::__construct($logger, null, $db, null, null, null, null, $eventDispatcher);
        $this->profileModel = $profileModel;
        $this->orderModel   = $orderModel;
        $this->walletService = $walletService;
        $this->settingService = $settingService;
        // optional OutboxService for async deposits
        try {
            $this->outboxService = container()->get(\App\Services\OutboxService::class);
        } catch (\Throwable $e) {
            $this->outboxService = null;
        }
        $container = function_exists('container') ? container() : null;
        $this->escrowService = $escrowService ?? ($container ? $container->get(\App\Services\FinancialEscrowService::class) : null);
    }

    // ══════════════════════════════════════════════════════
    //  ثبت / بروزرسانی پروفایل اینفلوئنسر
    // ══════════════════════════════════════════════════════

    public function registerInfluencer(int $userId, array $data): array
    {
        if (!$this->settingService->get('influencer_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم تبلیغات غیرفعال است.'];
        }

        $existing = $this->profileModel->findByUserId($userId);
        if ($existing) {
            return ['success' => false, 'message' => 'شما قبلاً یک پیج ثبت کرده‌اید.'];
        }

        $minFollowers = (int) $this->settingService->get('influencer_min_followers', 1000);
        if ((int)($data['follower_count'] ?? 0) < $minFollowers) {
            return ['success' => false, 'message' => "حداقل فالوور مورد نیاز: {$minFollowers}"];
        }

        $verificationCode = 'CK-' . \strtoupper(\substr(\md5(\random_bytes(16)), 0, 8));

        $profile = $this->profileModel->create(\array_merge($data, [
            'user_id'           => $userId,
            'currency'          => $this->settingService->get('currency_mode', 'irt'),
            'status'            => 'pending',
            'verification_code' => $verificationCode,
        ]));

        if (!$profile) {
            return ['success' => false, 'message' => 'خطا در ثبت پیج.'];
        }

        $this->eventDispatcher->dispatchAsync('influencer.profile_registered', [
            'user_id' => $userId,
            'profile_id' => $profile->id,
            'username' => $profile->username,
        ]);

        return [
            'success'           => true,
            'message'           => 'پیج ثبت شد. کد تایید را در پیج خود منتشر کنید.',
            'profile'           => $profile,
            'verification_code' => $verificationCode,
        ];
    }

    /**
     * کاربر لینک پست تایید مالکیت را ثبت می‌کند
     */
    public function submitVerificationPost(int $userId, string $postUrl): array
    {
        $profile = $this->profileModel->findByUserId($userId);
        if (!$profile) {
            return ['success' => false, 'message' => 'پروفایل یافت نشد.'];
        }
        if (!\in_array($profile->status, ['pending', 'rejected'])) {
            return ['success' => false, 'message' => 'وضعیت پروفایل اجازه این عملیات را نمی‌دهد.'];
        }

        $this->profileModel->update((int)$profile->id, [
            'verification_post_url' => $postUrl,
            'status'                => 'pending_admin_review',
        ]);

        $this->eventDispatcher->dispatchAsync('influencer.verification_submitted', [
            'user_id' => $userId,
            'profile_id' => $profile->id,
            'post_url' => $postUrl,
        ]);

        return ['success' => true, 'message' => 'لینک پست ثبت شد. منتظر بررسی مدیر باشید.'];
    }

    // ══════════════════════════════════════════════════════
    //  ثبت سفارش با Escrow
    // ══════════════════════════════════════════════════════

    public function createOrder(int $customerId, int $influencerId, array $data): array
    {
        if (!$this->settingService->get('influencer_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم غیرفعال است.'];
        }

        $recentOrders = $this->countRecentOrders($customerId, 1);
        $maxPerHour   = (int) $this->settingService->get('influencer_order_rate_limit_per_hour', 5);
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

        $feePercent        = (float) $this->settingService->get('influencer_fee_percent', 15);
        $feeAmount         = \round($price * ($feePercent / 100), 2);
        $influencerEarning = $price - $feeAmount;
        $idempotencyKey    = "influencer_order_{$customerId}_{$influencerId}_" . \md5(\serialize($data));

        try {
            $order = null;
            $this->transaction(function() use ($customerId, $influencerId, $profile, $orderType, $duration, $price, $feeAmount, $feePercent, $influencerEarning, $data, $idempotencyKey, &$order) {
                
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

    // ══════════════════════════════════════════════════════
    //  پذیرش / رد سفارش توسط اینفلوئنسر
    // ══════════════════════════════════════════════════════

    public function respondToOrder(int $orderId, int $influencerUserId, string $decision, ?string $reason = null): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order || (int)$order->influencer_user_id !== $influencerUserId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if ($order->status !== 'paid') {
            return ['success' => false, 'message' => 'وضعیت سفارش اجازه این عملیات را نمی‌دهد.'];
        }

        if ($decision === 'accept') {
            $this->orderModel->update($orderId, ['status' => 'accepted']);
            $this->eventDispatcher->dispatchAsync('influencer.order_accepted', [
                'order_id'           => $orderId,
                'customer_id'        => (int)$order->customer_id,
                'influencer_user_id' => $influencerUserId
            ]);
            return ['success' => true, 'message' => 'سفارش پذیرفته شد.'];
        }

        $this->orderModel->update($orderId, [
            'status'           => 'rejected_by_influencer',
            'rejection_reason' => $reason ?? 'رد توسط اینفلوئنسر',
        ]);

        $this->refundCustomer($order, 'rejected_by_influencer');
        
        $this->eventDispatcher->dispatchAsync('influencer.order_rejected', [
            'order_id'           => $orderId,
            'customer_id'        => (int)$order->customer_id,
            'influencer_user_id' => $influencerUserId,
            'points'             => (int)$this->settingService->get('influencer_rep_reject_points', -3)
        ]);

        return ['success' => true, 'message' => 'سفارش رد شد و مبلغ به تبلیغ‌دهنده بازگشت.'];
    }

    // ══════════════════════════════════════════════════════
    //  ارسال مدرک → نوتیف فوری به buyer
    // ══════════════════════════════════════════════════════

    public function submitProof(int $orderId, int $influencerUserId, array $proofData): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order || (int)$order->influencer_user_id !== $influencerUserId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if (!\in_array($order->status, ['accepted', 'published'])) {
            return ['success' => false, 'message' => 'وضعیت سفارش مناسب نیست.'];
        }

        $buyerCheckHours    = (int) $this->settingService->get('influencer_buyer_check_hours', 24);
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

    // ══════════════════════════════════════════════════════
    //  تایید buyer
    // ══════════════════════════════════════════════════════

    public function buyerConfirm(int $orderId, int $customerId): array
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

    // ══════════════════════════════════════════════════════
    //  اعتراض buyer → peer_resolution
    // ══════════════════════════════════════════════════════

    public function buyerDispute(int $orderId, int $customerId, string $reason): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order || (int)$order->customer_id !== $customerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if ($order->status !== 'awaiting_buyer_check') {
            return ['success' => false, 'message' => 'وضعیت سفارش اجازه این عملیات را نمی‌دهد.'];
        }
        if ($this->countRecentDisputes($customerId, 24) >= (int)$this->settingService->get('influencer_dispute_rate_limit', 3)) {
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

    // ══════════════════════════════════════════════════════
    //  تسویه نهایی — پرداخت به اینفلوئنسر
    // ══════════════════════════════════════════════════════

    public function completeOrder(int $orderId, int $actorId, string $reason = 'completed'): array
{
    $order = $this->orderModel->find($orderId);
    if (!$order) {
        return ['success' => false, 'message' => 'سفارش یافت نشد.'];
    }

    // ✅ FIX: تشخیص اینکه عملیات توسط سیستم انجام شده یا ادمین
    $isSystemAction = ($actorId === self::SYSTEM_ACTOR_ID || $actorId === 0);
    $actorType = $isSystemAction ? 'system' : 'admin';

    try {
        $this->transaction(function() use ($order, $orderId, $isSystemAction, $actorId, $reason) {
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
                $ok = $this->outboxService->record('influencer_order', $orderId, 'wallet.deposit.requested', $payload);
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
            'points'             => (int)$this->settingService->get('influencer_rep_complete_points', 10)
        ]);

        return ['success' => true, 'message' => 'سفارش تکمیل و درآمد واریز شد.'];
    } catch (\Exception $e) {
        $this->logger->error('story.complete_order_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        return ['success' => false, 'message' => $e->getMessage() === 'خطا در پرداخت به اینفلوئنسر.' ? $e->getMessage() : 'خطای سیستمی در تسویه.'];
    }
}

    // ══════════════════════════════════════════════════════
    //  بازگشت وجه (کامل یا جزئی)
    // ══════════════════════════════════════════════════════

    public function refundOrder(int $orderId, int $actorId, float $refundPercent = 100.0, string $reason = ''): array
{
    $order = $this->orderModel->find($orderId);
    if (!$order) {
        return ['success' => false, 'message' => 'سفارش یافت نشد.'];
    }

    $refundAmount = round((float)$order->price * ($refundPercent / 100), 2);

    try {
        $this->transaction(function() use ($order, $orderId, $refundAmount, $refundPercent, $reason, $actorId) {
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
                $ok = $this->outboxService->record('influencer_order', $orderId, 'wallet.deposit.requested', $refundPayload);
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
                $feePercent = (float) $this->settingService->get('influencer_fee_percent', 15);
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
                        $ok2 = $this->outboxService->record('influencer_order', $orderId, 'wallet.deposit.requested', $partialPayload);
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

        $this->cacheInvalidation->invalidateWallet((int)$order->customer_id);
        if ($refundPercent < 100) {
            $this->cacheInvalidation->invalidateWallet((int)$order->influencer_user_id);
        }

        $this->eventDispatcher->dispatchAsync('notification.requested', [
            'user_id' => (int)$order->customer_id,
            'type' => 'influencer_order_refunded',
            'title' => 'بازگشت وجه سفارش',
            'message' => number_format($refundAmount) . " به کیف پول شما بازگشت.",
            'data' => ['order_id' => $orderId],
            'action_url' => url('/influencer/ads/my-orders'),
            'action_text' => 'مشاهده سفارش‌ها'
        ]);

        $this->auditTrail->record('influencer.order.refunded', $actorId, [
    'channel' => 'influencer',
    'order_id' => $orderId,
    'refund_percent' => $refundPercent,
    'amount' => $refundAmount,
    'reason' => $reason,
], $actorId);

        return ['success' => true, 'message' => "بازگشت {$refundPercent}٪ وجه انجام شد."];

    } catch (\Exception $e) {
        $this->logger->error('story.refund_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        return ['success' => false, 'message' => in_array($e->getMessage(), ['خطا در بازگشت وجه.', 'خطا در پرداخت جزئی به اینفلوئنسر.']) ? $e->getMessage() : 'خطای سیستمی در بازگشت وجه.'];
    }
}


    // ══════════════════════════════════════════════════════
    //  CronJobs
    // ══════════════════════════════════════════════════════

    public function processExpiredBuyerChecks(): int
    {
        $expired = $this->orderModel->getExpiredBuyerChecks();
        $count = 0;
        foreach ($expired as $o) {
            $result = $this->completeOrder((int)$o->id, 0, 'auto_approved_buyer_timeout');
            if ($result['success']) {
                $count++;
                $this->logger->info('story.auto_approved', ['order_id' => $o->id]);
            }
        }
        return $count;
    }

    public function processExpiredPendingAcceptance(): int
{
    $expired = $this->orderModel->getExpiredPendingAcceptance();
    $count   = 0;

    foreach ($expired as $o) {
        // ✅ فقط اگر status هنوز pending_acceptance باشه update کن (atomic)
        $affected = $this->orderModel->updateWhere(
            [
                'id'     => (int)$o->id,
                'status' => 'pending_acceptance',   // ← شرط race condition رو می‌بنده
            ],
            [
                'status'           => 'rejected_by_influencer',
                'rejection_reason' => 'عدم پاسخ در مهلت مقرر',
            ]
        );

        // اگر update نخورد یعنی وضعیت عوض شده بود، skip کن
        if (!$affected) {
            continue;
        }

        $order = $this->orderModel->find((int)$o->id);
        if (!$order) continue;

        $this->refundCustomer($order, 'influencer_no_response');

        $profile = $this->profileModel->findByUserId((int)$order->influencer_user_id);
        if ($profile) {
            $pts = (int) $this->settingService->get('influencer_rep_reject_points', -3);
            if ($this->eventDispatcher) {
                $this->eventDispatcher->dispatchAsync('influencer.score_update_requested', [
                    'entity_type' => 'profile',
                    'entity_id' => (int)$profile->id,
                    'type' => 'reputation',
                    'points' => $pts,
                    'reason' => 'order_rejected',
                    'metadata' => [
                        'user_id' => (int)$order->influencer_user_id,
                        'order_id' => (int)$o->id,
                        'note' => 'عدم پاسخ در مهلت مقرر',
                    ]
                ]);
            }
        }

        $count++;
    }

    if ($count > 0) {
        $this->logger->info('influencer.auto_rejected_no_response', ['count' => $count]);
    }

    return $count;
}


    public function cleanupOldFiles(int $days = 3): int
    {
        $stmt = $this->db->prepare("
            SELECT id, proof_screenshot, media_path FROM story_orders
            WHERE status IN ('completed','refunded','cancelled')
            AND updated_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND (proof_screenshot IS NOT NULL OR media_path IS NOT NULL)
        ");
        $stmt->execute([$days]);
        $orders = $stmt->fetchAll(\PDO::FETCH_OBJ);
        $count = 0;
        foreach ($orders as $o) {
            $this->cleanupProofFiles($o);
            $this->orderModel->update($o->id, ['proof_screenshot' => null, 'media_path' => null]);
            $count++;
        }
        return $count;
    }

    // ══════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════

    private function refundCustomer(object $order, string $reason = ''): void
{
    try {
        $this->transaction(function() use ($order, $reason) {
            $payload = [
                'user_id' => (int)$order->customer_id,
                'amount' => (float)$order->price,
                'currency' => $order->currency,
                'metadata' => [
                    'type' => 'refund',
                    'description' => "بازگشت سفارش #{$order->id}",
                    'idempotency_key' => "story_refund_{$order->id}",
                    'order_id' => $order->id,
                ],
            ];

            if ($this->outboxService) {
                $ok = $this->outboxService->record('influencer_order', $order->id, 'wallet.deposit.requested', $payload);
                if (!$ok) {
                    $this->logger->error('story.refund_outbox_failed', ['order_id' => $order->id]);
                    throw new \Exception('wallet deposit rejected');
                }
            } else {
                $result = $this->walletService->deposit(
                    (int)$order->customer_id,
                    (float)$order->price,
                    $order->currency,
                    [
                        'type'            => 'refund',
                        'description'     => "بازگشت سفارش #{$order->id}",
                        'idempotency_key' => "story_refund_{$order->id}",
                    ]
                );

                if (!($result['success'] ?? false)) {
                    $this->logger->error('story.refund_customer_failed', [
                        'order_id' => $order->id,
                        'reason'   => 'wallet deposit rejected',
                    ]);
                    throw new \Exception('wallet deposit rejected');
                }
            }

            $this->orderModel->update((int)$order->id, [
                'status'     => 'refunded',
                'admin_note' => $reason,
            ]);
        });

        $this->cacheInvalidation->invalidateWallet((int)$order->customer_id);

    } catch (\Exception $e) {
        $this->logger->error('story.refund_customer_failed', [
            'order_id' => $order->id,
            'error'    => $e->getMessage(),
        ]);
    }
}

    private function calculatePrice(object $profile, string $orderType, int $duration): float
    {
        if ($orderType === 'story') {
            return (float) $profile->story_price_24h;
        }
        return match ($duration) {
            48      => (float) $profile->post_price_48h,
            72      => (float) $profile->post_price_72h,
            default => (float) $profile->post_price_24h,
        };
    }

    private function cleanupProofFiles(object $order): void
    {
        $base = __DIR__ . '/../../';
        foreach (['proof_screenshot', 'media_path'] as $f) {
            if (!empty($order->$f) && \file_exists($base . $order->$f)) {
                \unlink($base . $order->$f);
            }
        }
    }

    private function countRecentOrders(int $customerId, int $hours): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM story_orders
            WHERE customer_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$customerId, $hours]);
        return (int) $stmt->fetchColumn();
    }

    private function countRecentDisputes(int $customerId, int $hours): int
{
    // ✅ FIX: به جای جدول influencer_disputes که رکوردی توش ثبت نمیشه،
    // از خود جدول سفارش‌ها با شرط status استفاده می‌کنیم
    $stmt = $this->db->prepare("
        SELECT COUNT(*) FROM influencer_story_orders
        WHERE customer_id = ?
          AND status IN ('peer_resolution', 'refunded', 'partially_refunded')
          AND peer_resolution_started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
    ");
    $stmt->execute([$customerId, $hours]);
    return (int) $stmt->fetchColumn();
}

    /**
     * ثبت گزارش تخلف برای سفارش تبلیغ/پست یوتیوب
     */
    public function reportOrder(int $reporterId, int $orderId, string $reason, string $description = ''): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order) {
            return ['success' => false, 'message' => 'سفارش یافت نشد'];
        }

        try {
            $ok = $this->ratingService->report([
                'reporter_id' => $reporterId,
                'ref_type' => 'story_order',
                'ref_id' => $orderId,
                'reason' => $reason,
                'description' => $description
            ]);

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت گزارش'];
            }

            return ['success' => true, 'message' => 'گزارش تخلف با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }

    /**
     * امتیازدهی به اینفلوئنسر/سفارش استوری
     */
    public function rateInfluencer(int $raterId, int $orderId, int $stars, string $comment = ''): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order) {
            return ['success' => false, 'message' => 'سفارش یافت نشد'];
        }

        $stars = max(1, min(5, $stars));

        try {
            $ok = $this->ratingService->rate(
                $raterId,
                (int)$order->influencer_user_id,
                'story_order',
                $orderId,
                $stars,
                $comment
            );

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت امتیاز'];
            }

            return ['success' => true, 'message' => 'امتیاز با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }

    public function searchInfluencers(array $filters, int $limit, int $offset): array
    {
        // Ensure only verified/active influencers are presented publicly
        $filters['status'] = 'verified';

        // Extract query string
        $q = $filters['q'] ?? '';

        // Sort strategy mapping to valid model columns
        $sort = $filters['sort'] ?? 'newest';
        [$sortCol, $sortDir] = match ($sort) {
            'oldest'     => ['created_at', 'ASC'],
            'followers'  => ['follower_count', 'DESC'],
            'rating'     => ['average_rating', 'DESC'],
            default      => ['created_at', 'DESC'],
        };

        // High performance delegation to Model leveraging Filterable Trait
        return $this->model->searchNative($q, $filters, $limit, $offset, $sortCol, $sortDir);
    }

    public function searchInfluencersAdmin(string $q, array $filters, int $limit, int $offset): array
    {
        // Full access unfiltered structural delegation leveraging Filterable Trait
        return $this->model->searchNative($q, $filters, $limit, $offset);
    }
}
