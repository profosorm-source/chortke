<?php

declare(strict_types=1);

namespace App\Jobs\Lottery;

class ParticipateInLotteryJob
{
    public function __construct(
        private \App\Services\Shared\IdempotencyService $idempotencyService,
        private \Core\Database $db,
        private \App\Contracts\WalletServiceInterface $walletService,
        private \App\Contracts\LoggerInterface $logger
    ) {}

public function handle(int $userId, int $roundId, ?string $idempotencyKey = null): array
    {
        $execute = function() use ($userId, $roundId, $idempotencyKey) {
            if (!$this->featureFlagService->isEnabled('lottery', $userId)) {
                throw new \Core\Exceptions\InvalidStateException('????? ???????? ?????? ??????? ???.');
            }

            if (!$this->checkRateLimit($userId, 'participate', 10, 3600)) {
                throw new \Core\Exceptions\RateLimitExceededException('????? ???????? ??? ??? ?? ?? ???? ???.');
            }

            $round = $this->repository->findRound($roundId);
            if (!$round || $round->status !== \App\Models\LotteryRound::STATUS_ACTIVE) {
                throw new \Core\Exceptions\EntityNotFoundException('???? ???????? ???? ????.');
            }

            $now = time();
            if ($now < strtotime($round->start_date)) {
                throw new \Core\Exceptions\InvalidStateException('???? ???? ???? ???? ??? ?????? ???.');
            }
            
            if ($now > strtotime($round->end_date)) {
                throw new \Core\Exceptions\InvalidStateException('???? ??????? ?? ????? ????? ???.');
            }

            if ($this->repository->isParticipating($userId, $roundId)) {
                throw new \Core\Exceptions\InvalidStateException('??? ????? ?? ??? ???? ???? ????????.');
            }

            $payload = [
                'user_id' => $userId,
                'round_id' => $roundId,
                'ticket_price' => $round->ticket_price,
                'currency' => $round->currency,
            ];

            $explicitKey = $idempotencyKey !== null && $idempotencyKey !== ''
                ? $idempotencyKey
                : \Core\IdempotencyKey::generateFromPayload('lottery_participation', $payload);

            return $this->idempotencyService->execute('lottery.participate', $userId, $payload, function () use (
                $userId,
                $roundId,
                $round,
                $explicitKey
            ) {
                $this->db->beginTransaction();

                try {
                    $saga = \Core\Container::getInstance()->make(\App\Services\SagaOrchestrator::class);
                    $code = null;
                    $participationId = null;

                    $saga->addStep(
                        'lock_and_validate',
                        function () use ($roundId) {
                            $roundLock = $this->db->query("SELECT id, max_tickets FROM lottery_rounds WHERE id = ? FOR UPDATE", [$roundId])->fetch(\PDO::FETCH_OBJ);
                            if (!$roundLock) {
                                throw new \Core\Exceptions\EntityNotFoundException('???? ???? ???.');
                            }

                            $currentCount = (int)$this->db->query("SELECT COUNT(*) FROM lottery_participations WHERE round_id = ? AND is_deleted = 0", [$roundId])->fetchColumn();
                            $maxCeiling = (int)($roundLock->max_tickets ?? 10000);

                            if ($currentCount >= $maxCeiling) {
                                throw new \Core\Exceptions\InvalidStateException('????? ???? ?? ??? ???? ????? ??? ???.');
                            }
                            return true;
                        },
                        function () {}
                    )->addStep(
                        'deduct_fee',
                        function () use ($userId, $roundId, $round, $explicitKey) {
                            $transactionId = null;
                            if ($round->ticket_price > 0) {
                                if (isset($this->escrowService) && $this->escrowService) {
                                    $result = $this->escrowService->holdFunds(
                                        $roundId,
                                        'lottery_ticket',
                                        $userId,
                                        -1,
                                        (string)$round->ticket_price,
                                        $round->currency
                                    );
                                    if (empty($result['ok'])) {
                                        throw new \Core\Exceptions\InsufficientBalanceException('??? ?? ????? ???? ???? ????: ' . ($result['error'] ?? ''));
                                    }
                                    $transactionId = $result['escrow_id'] ?? null;
                                } else {
                                    $result = $this->walletService->withdraw(
                                        $userId, 
                                        (float)$round->ticket_price, 
                                        $round->currency, 
                                        [
                                            'type' => 'lottery_entry',
                                            'round_id' => $roundId,
                                            'description' => "???? ?? ????????: {$round->title}",
                                            'idempotency_key' => $explicitKey,
                                        ]
                                    );
                                    
                                    if (!$result['success']) {
                                        throw new \Core\Exceptions\InsufficientBalanceException('?????? ???? ????. ' . ($result['message'] ?? ''));
                                    }
                                    
                                    $transactionId = $result['transaction_id'] ?? null;
                                }
                            }
                            return $transactionId;
                        },
                        function (\Throwable $e) use ($userId, $roundId) {
                            $this->logger->warning('saga.compensating.lottery_deduct_fee', ['user_id' => $userId, 'round_id' => $roundId]);
                        }
                    )->addStep(
                        'create_participation',
                        function ($transactionId) use ($userId, $roundId, $round, &$code, &$participationId) {
                            $code = $this->generateUniqueCode($roundId);
                            
                            if (!$code) {
                                throw new \Core\Exceptions\BusinessException('??? ?? ????? ?? ????.');
                            }

                            $participationId = $this->repository->createParticipation([
                                'round_id' => $roundId,
                                'user_id' => $userId,
                                'ticket_number' => $code,
                                'chance_score' => LotteryParticipation::DEFAULT_CHANCE,
                                'price_paid' => $round->ticket_price,
                                'currency' => $round->currency,
                                'status' => 'active',
                            ]);

                            if (!$participationId) {
                                throw new \Exception('??? ?? ??? ??????.');
                            }
                            return true;
                        },
                        function (\Throwable $e) use ($userId, $roundId) {
                            $this->logger->warning('saga.compensating.lottery_participation_record', ['user_id' => $userId, 'round_id' => $roundId]);
                        }
                    );

                    $saga->execute();
                    $this->db->commit();

                    $this->eventDispatcher->dispatchAsync('lottery.participated', [
                        'user_id' => $userId,
                        'round_id' => $roundId,
                        'code' => $code,
                        'price' => $round->ticket_price
                    ]);

                    $this->eventDispatcher->dispatchAsync('cache.invalidate', ['key' => 'wallet_' . $userId]);

                    $this->logger->info('lottery_participation', ['message' => "User {$userId} joined round #{$roundId}, code: {$code}"]);

                    return [
                        'success' => true,
                        'message' => '?? ?????? ??????? ????!',
                        'code' => $code,
                        'chance_score' => LotteryParticipation::DEFAULT_CHANCE,
                        'participation_id' => $participationId,
                    ];

                } catch (\Throwable $e) {
                    $this->db->rollBack();
                    $this->logger->error('lottery_participation_error', ['message' => $e->getMessage()]);
                    return ['success' => false, 'message' => '???? ??????.'];
                }
            }, $explicitKey);
        };
        
        if (isset($this->lockService) && $this->lockService) {
            return $this->lockService->synchronized("lottery_participate_{$roundId}_{$userId}", $execute);
        }
        
        return $execute();
    }
}
