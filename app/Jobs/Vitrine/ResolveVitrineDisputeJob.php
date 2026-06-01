<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

class ResolveVitrineDisputeJob
{
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->logger = $logger;
}

    public function handle(int $listingId, string $winner, int $adminId): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing) return ['success' => false, 'message' => 'آگهی یافت نشد.'];

        if ($winner === 'seller') {
            $result = $this->releaseFundsToSeller($listing, 'admin_resolve');
        } else {
            $amount = $listing->offer_price_usdt ?? $listing->price_usdt;
            $this->db->beginTransaction();
            try {
                $saga = $this->context->getContainer()->make(\App\Services\SagaOrchestrator::class);

                $saga->addStep(
                    'refund_buyer',
                    function () use ($listing, $amount, $listingId) {
                        $this->eventDispatcher->dispatch('vitrine.refund_requested', [
                            'buyer_id' => (int)$listing->buyer_id,
                            'amount' => $amount,
                            'listing_id' => $listingId
                        ]);
                        return true;
                    },
                    function (\Throwable $e) use ($listingId) {
                        $this->logger->warning('saga.compensating.vitrine_refund_buyer', ['listing_id' => $listingId]);
                    }
                )->addStep(
                    'cancel_listing',
                    function () use ($listingId) {
                        $this->listing->updateStatus($listingId, VitrineListing::STATUS_CANCELLED);
                        return true;
                    },
                    function () {}
                );

                $saga->execute();
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطای سیستمی.'];
            }

            $result = ['success' => true];
        }

        $this->eventDispatcher->dispatch('vitrine.dispute_resolved', [
            'admin_id' => $adminId,
            'listing_id' => $listingId,
            'decision' => $winner
        ]);

        $this->logger->info('vitrine.dispute_resolved', [
            'listing_id' => $listingId,
            'winner'     => $winner,
            'admin_id'   => $adminId,
        ]);

        return $result;
    }
}
