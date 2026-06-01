<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

class ReleaseVitrineFundsJob
{
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\ScoreService $scoreService;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\ScoreService $scoreService
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->scoreService = $scoreService;
}

    public function handle(object $listing, string $reason = 'manual'): array
    {
        $commission = (float) $this->settings->get('vitrine_commission_percent', '5') / 100;
        $amount     = $listing->offer_price_usdt ?? $listing->price_usdt;
        $net        = round($amount * (1 - $commission), 6);

        $this->db->beginTransaction();
        try {
            $saga = $this->context->getContainer()->make(\App\Services\SagaOrchestrator::class);

            $saga->addStep(
                'pay_seller',
                function () use ($listing, $net) {
                    $this->eventDispatcher->dispatch('vitrine.release_funds_requested', [
                        'seller_id' => (int)$listing->seller_id,
                        'amount' => $net,
                        'listing_id' => (int)$listing->id
                    ]);
                    return true;
                },
                function (\Throwable $e) use ($listing) {
                    $this->logger->warning('saga.compensating.vitrine_release_seller', ['listing_id' => $listing->id]);
                }
            )->addStep(
                'update_listing_status',
                function () use ($listing, $reason) {
                    $extra = ['auto_confirmed' => ($reason === 'auto_cron') ? 1 : 0];
                    $ok    = $this->listing->updateStatus((int) $listing->id, \App\Models\VitrineListing::STATUS_SOLD, $extra);
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
            $this->logger->error('vitrine.escrow.release_failed', ['id' => $listing->id, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }

        $this->eventDispatcher->dispatch('vitrine.escrow.released', [
            'seller_id' => (int)$listing->seller_id,
            'listing_id' => $listing->id
        ]);
        
        if (isset($this->scoreService)) {
            // Reward seller for successful trade
            $this->scoreService->applyDelta('user', (int)$listing->seller_id, 'vitrine_rating', 5.0, 'vitrine_sale_success_'.$listing->id);
        }

        return ['success' => true, 'net_amount' => $net];
    }
}
