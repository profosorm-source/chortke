<?php

declare(strict_types=1);

namespace App\Listeners;

use Core\Event;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\Notification\NotificationService;
use App\Services\WebSocketService;
use App\Services\Shared\ReferralService;

/**
 * Vitrine Event Listeners
 */
class VitrineEventListeners
{
    protected LoggerInterface $logger;
    protected WalletServiceInterface $walletService;
    protected NotificationService $notificationService;
    protected WebSocketService $webSocket;
    protected ReferralService $referralService;
    public function __construct(
        LoggerInterface $logger,
        WalletServiceInterface $walletService,
        NotificationService $notificationService,
        WebSocketService $webSocket,
        ReferralService $referralService
    ) {        $this->logger = $logger;
        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
        $this->webSocket = $webSocket;
        $this->referralService = $referralService;

    }

    /**
     * پرداخت هزینه اسکرو برای آگهی ویترین
     */
    public function handleEscrowPaymentRequested(Event $event): void
    {
        try {
            $data = $event->getData();
            $buyerId = (int)($data['buyer_id'] ?? 0);
            $amount = (float)($data['amount'] ?? 0);
            $currency = (string)($data['currency'] ?? 'usdt');
            $listingId = $data['listing_id'] ?? null;

            if ($buyerId <= 0 || $amount <= 0) {
                return;
            }

            $debit = $this->walletService->pay($buyerId, $amount, $currency, [
                'type' => 'vitrine_escrow',
                'description' => "اسکرو ویترین #{$listingId}"
            ]);

            if (empty($debit['success'])) {
                throw new \RuntimeException($debit['message'] ?? 'موجودی کافی نیست.');
            }

        } catch (\Throwable $e) {
            $this->logger->error('vitrine.escrow_payment.failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * بازگشت وجه خریدار به دلیل رد آگهی توسط مدیریت
     */
    public function handleRefundRequested(Event $event): void
    {
        try {
            $data = $event->getData();
            $buyerId = (int)($data['buyer_id'] ?? 0);
            $amount = (float)($data['amount'] ?? 0);
            $currency = (string)($data['currency'] ?? 'usdt');
            $listingId = $data['listing_id'] ?? null;

            if ($buyerId <= 0 || $amount <= 0) {
                return;
            }

            $credit = $this->walletService->deposit($buyerId, (string)$amount, $currency, [
                'type' => 'vitrine_refund',
                'description' => "بازگشت وجه ویترین #{$listingId}"
            ]);

            if (empty($credit['success'])) {
                throw new \RuntimeException($credit['message'] ?? 'خطا در بازگشت وجه خریدار.');
            }

        } catch (\Throwable $e) {
            $this->logger->error('vitrine.refund.failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * اطلاع‌رسانی تایید آگهی
     */
    public function handleListingApproved(Event $event): void
    {
        try {
            $data = $event->getData();
            $listingId = $data['listing_id'] ?? null;
            $sellerId = (int)($data['seller_id'] ?? 0);

            if ($listingId && $sellerId > 0) {
                $this->webSocket->notifyListingApproved($listingId, $sellerId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('vitrine.listing_approved.failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
