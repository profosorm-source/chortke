<?php

declare(strict_types=1);

namespace App\Services\Withdrawal\Steps;

use App\Contracts\SagaStepInterface;
use App\Models\Withdrawal;
use App\Contracts\LoggerInterface;

class CreateRecordStep implements SagaStepInterface
{
    private Withdrawal $model;
public function __construct(Withdrawal $model, LoggerInterface $logger)
    {
        $this->model = $model;
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'create_record';
    }

    public function execute(mixed $payload): mixed
    {
        $userId = $payload['user_id'];
        $amount = (string)($payload['amount'] ?? '0');
        $currency = strtolower((string)($payload['currency'] ?? 'irt'));
        $bankCardId = (int)($payload['bank_card_id'] ?? 0);
        $idempotencyKey = $payload['idempotency_key'];
        
        // This came from the previous step
        $withdrawResult = $payload['withdraw_result'] ?? [];

        $withdrawal = $this->model->create([
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'card_id' => $bankCardId > 0 ? $bankCardId : null,
            'transaction_id' => $withdrawResult['transaction_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $payload['withdrawal_id'] = $withdrawal->id;
        return $payload; // Pass along for final result extraction
    }

    public function compensate(mixed $payload, mixed $result, \Throwable $originalError): void
    {
        $userId = $payload['user_id'] ?? null;
        $withdrawalId = $result['withdrawal_id'] ?? ($payload['withdrawal_id'] ?? null);

        if ($userId && $withdrawalId) {
            $this->logger->warning('saga.compensating.withdrawal_create_record', ['user_id' => $userId, 'withdrawal_id' => $withdrawalId]);
            $withdrawal = clone $this->model;
            $withdrawal->id = $withdrawalId; // Mocking model object behavior (if custom ORM allows this or use find)
            
            // safer approach:
            $found = $this->model->find($withdrawalId);
            if ($found) {
                $found->update(['status' => 'failed']);
            }
        }
    }
}

