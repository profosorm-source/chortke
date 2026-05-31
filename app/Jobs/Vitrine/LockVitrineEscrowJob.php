<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

class LockVitrineEscrowJob
{
    public function __construct(
        private \Core\Database $db,
        private \App\Contracts\LoggerInterface $logger
    ) {}

    public function handle(int $buyerId, int $listingId): array
    {
        $check = $this->canTrade($buyerId);
        if (!$check['ok']) return ['success' => false, 'message' => $check['message']];

        try {
            $this->db->beginTransaction();
            $saga = $this->context->getContainer()->make(\App\Services\SagaOrchestrator::class);

            $listing = null;
            $finalPrice = null;
            $deadline = null;

            $saga->addStep(
                'lock_and_validate',
                function () use ($listingId, $buyerId, &$listing, &$finalPrice) {
                    $listing = $this->db->query("SELECT * FROM vitrine_listings WHERE id = ? FOR UPDATE", [$listingId])->fetch(\PDO::FETCH_OBJ);

                    if (!$listing || $listing->status !== \App\Models\VitrineListing::STATUS_ACTIVE) {
                        throw new \Exception('آگهی فعال نیست.');
                    }
                    if ((int) $listing->seller_id === $buyerId) {
                        throw new \Exception('نمی‌توانید آگهی خود را بخرید.');
                    }

                    $finalPrice = $listing->offer_price_usdt ?? $listing->price_usdt;
                    return true;
                },
                function () {}
            )->addStep(
                'pay_escrow',
                function () use ($buyerId, $listingId, &$finalPrice) {
                    $this->eventDispatcher->dispatch('vitrine.escrow_payment_requested', [
                        'buyer_id' => $buyerId,
                        'amount' => $finalPrice,
                        'currency' => 'usdt',
                        'listing_id' => $listingId
                    ]);
                    return true;
                },
                function (\Throwable $e) use ($listingId) {
                    $this->logger->warning('saga.compensating.vitrine_escrow', ['listing_id' => $listingId]);
                }
            )->addStep(
                'update_listing',
                function () use ($buyerId, $listingId, &$deadline) {
                    $escrowDays  = (int) $this->settings->get('vitrine_escrow_days', '3');
                    $deadline    = date('Y-m-d H:i:s', strtotime("+{$escrowDays} days"));

                    $ok = $this->listing->updateStatus($listingId, \App\Models\VitrineListing::STATUS_IN_ESCROW, [
                        'buyer_id'         => $buyerId,
                        'escrow_locked_at' => date('Y-m-d H:i:s'),
                        'escrow_deadline'  => $deadline,
                    ]);
                    if (!$ok) {
                        throw new \Exception('خطا در آپدیت وضعیت.');
                    }
                    return true;
                },
                function () {}
            );

            $saga->execute();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('vitrine.escrow.lock_failed', ['id' => $listingId, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }

        $this->eventDispatcher->dispatch('vitrine.escrow.locked', [
            'buyer_id' => $buyerId,
            'listing_id' => $listingId,
            'amount' => $finalPrice
        ]);

        return ['success' => true, 'deadline' => $deadline, 'amount' => $finalPrice];
    }
}
