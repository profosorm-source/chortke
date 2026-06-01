<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AlertRequestedEvent;
use App\Services\Sentry\Alerting\AlertDispatcher;
use App\Contracts\LoggerInterface;

class AlertRequestListener
{
    private AlertDispatcher $dispatcher;
    private LoggerInterface $logger;
    public function __construct(
        AlertDispatcher $dispatcher,
        LoggerInterface $logger
    ) {        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
}

    public function handle(AlertRequestedEvent $event): void
    {
        try {
            if (!$this->dispatcher->handleAlertRequest($event)) {
                $this->logger->warning('alert.request.listener_failed', [
                    'alert' => $event->alert,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('alert.request.listener_exception', [
                'error' => $e->getMessage(),
                'alert' => $event->alert,
            ]);
        }
    }
}
