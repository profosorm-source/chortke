<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Contracts\LoggerInterface;
use Core\IdempotencyKey;
use Core\TransactionWrapper;

class IdempotencyService
{
    private IdempotencyKey $idempotencyKey;
    private TransactionWrapper $transactionWrapper;
    private LoggerInterface $logger;
    public function __construct(
        IdempotencyKey $idempotencyKey,
        TransactionWrapper $transactionWrapper,
        LoggerInterface $logger
    ) {        $this->idempotencyKey = $idempotencyKey;
        $this->transactionWrapper = $transactionWrapper;
        $this->logger = $logger;

    }

    /**
     * @template T
     * @param string         $scope
     * @param int            $actorId
     * @param array          $payload    داده‌هایی که عمل را منحصربه‌فرد می‌کنند
     * @param callable():T   $callback
     * @param string|null    $explicitKey  در صورت ارسال، جایگزین payload-based key می‌شود
     * @return T
     */
    public function execute(
        string $scope,
        int $actorId,
        array $payload,
        callable $callback,
        ?string $explicitKey = null
    ): mixed {
        if ($explicitKey !== null && $explicitKey !== '') {
            $key = $explicitKey;
        } else {
            $key = $this->idempotencyKey->keyFromPayload($scope, $payload);
        }

        return $this->idempotencyKey->run($scope, $actorId, $key, $callback, $payload);
    }

    /**
     * اجرای عملیات با پشتیبانی همزمان از Idempotency، Database Transaction و Automatic Retry
     * این متد تمام نیازهای سرویس‌های حیاتی مالی و هویتی را پوشش می‌دهد.
     */
    public function executeWithTransaction(
        string $scope,
        int $actorId,
        array $payload,
        callable $callback,
        ?string $explicitKey = null,
        int $maxRetries = 3
    ): mixed {
        if ($explicitKey !== null && $explicitKey !== '') {
            $key = $explicitKey;
        } else {
            $key = $this->idempotencyKey->keyFromPayload($scope, $payload);
        }

        // استفاده از متد جدید در TransactionWrapper
        return $this->transactionWrapper->runIdempotentWithRetry(
            $this->idempotencyKey,
            $scope . '_' . $key,
            $actorId,
            $scope,
            $callback,
            $maxRetries
        );
    }
}
