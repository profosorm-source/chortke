<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

class ConfirmVitrineDeliveryJob
{
    public function __construct(
        
    ) {}

    public function handle(int $buyerId, int $listingId): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing || (int) $listing->buyer_id !== $buyerId || $listing->status !== VitrineListing::STATUS_IN_ESCROW) {
            return ['success' => false, 'message' => 'عملیات غیرمجاز.'];
        }

        return $this->releaseFundsToSeller($listing, 'buyer_confirm');
    }
}
