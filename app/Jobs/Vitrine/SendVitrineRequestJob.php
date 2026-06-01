<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

class SendVitrineRequestJob
{
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger
    ) {        $this->logger = $logger;
}

    public function handle(int $requesterId, int $listingId, array $data): array
    {
        $check = $this->canTrade($requesterId);
        if (!$check['ok']) return ['success' => false, 'message' => $check['message']];

        $listing = $this->listing->find($listingId);
        if (!$listing || $listing->status !== VitrineListing::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'آگهی فعال نیست.'];
        }
        if ((int) $listing->seller_id === $requesterId) {
            return ['success' => false, 'message' => 'نمی‌توانید به آگهی خود درخواست دهید.'];
        }
        if ($this->request->existsPending($listingId, $requesterId)) {
            return ['success' => false, 'message' => 'درخواست شما قبلاً ثبت شده و در انتظار پاسخ است.'];
        }

        $req = $this->request->create([
            'listing_id'   => $listingId,
            'requester_id' => $requesterId,
            'offer_price'  => !empty($data['offer_price']) ? (float) $data['offer_price'] : null,
            'message'      => trim($data['message'] ?? ''),
        ]);
        if (!$req) return ['success' => false, 'message' => 'خطا در ثبت درخواست.'];

        // اعلان به فروشنده
        $this->eventDispatcher->dispatch('notification.requested', [
            'user_id' => (int) $listing->seller_id,
            'listing_id' => $listingId,
            'request_id' => $req->id,
            'message' => 'درخواست خرید جدید برای آگهی شما ثبت شد'
        ]);

        $this->logger->info('vitrine.request_sent', [
            'listing_id'   => $listingId,
            'requester_id' => $requesterId,
            'offer_price'  => $req->offer_price,
        ]);
        return ['success' => true, 'request' => $req];
    }
}
