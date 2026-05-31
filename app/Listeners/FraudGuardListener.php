<?php

declare(strict_types=1);

namespace App\Listeners;

use Core\Contracts\EventListenerInterface;
use App\Events\WalletTransferInitiatingEvent;
use App\Services\AntiFraud\FraudGuardService;

class FraudGuardListener implements EventListenerInterface
{
    public function __construct(private FraudGuardService $fraudGuard) {}

    public function handle($event): void
    {
        if ($event instanceof WalletTransferInitiatingEvent) {
            $risk = $this->fraudGuard->checkAction($event->fromUserId, 'wallet.transfer', [
                'to_user_id' => $event->toUserId,
                'amount' => $event->amount,
                'currency' => $event->currency
            ]);

            if (!$risk['allowed']) {
                throw new \RuntimeException('انتقال وجه مسدود گردید. دلیل: ' . $risk['reason']);
            }
        }
    }
}
