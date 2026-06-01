<?php
// app/Services/LotteryService.php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Events\ScoreUpdatedEvent;
use Core\Database;
use Core\Cache;
use Core\EventDispatcher;

class LotteryService
{
    private WalletServiceInterface $walletService;
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    private ?\App\Contracts\OutboxServiceInterface $outboxService = null;
    private LoggerInterface $logger;
    private Cache $cache;
    private EventDispatcher $eventDispatcher;
    private const MATCH_TYPES = ['value', 'position', 'value_position', 'signal'];
    private const MAX_CODE_GENERATION_ATTEMPTS = 100;
    private const MAX_DAILY_VOTES_PER_USER = 1;
    private int $cacheTTL = 300;
    private \Core\Database $db;
    private \App\Models\LotteryRound $roundModel;
    private \App\Models\LotteryParticipation $participationModel;
    private \App\Models\LotteryDailyNumber $dailyModel;
    private \App\Models\LotteryVote $voteModel;
    private \App\Models\LotteryChanceLog $chanceLogModel;

    public function __construct(
        \Core\Database $db,
        \App\Models\LotteryRound $roundModel,
        \App\Models\LotteryParticipation $participationModel,
        \App\Models\LotteryDailyNumber $dailyModel,
        \App\Models\LotteryVote $voteModel,
        \App\Models\LotteryChanceLog $chanceLogModel,
        LoggerInterface $logger,
        Cache $cache,
        EventDispatcher $eventDispatcher,
        WalletServiceInterface $walletService,
        \App\Services\Shared\IdempotencyService $idempotencyService,
        ?\App\Contracts\OutboxServiceInterface $outboxService = null
    ) {
        $this->db = $db;
        $this->roundModel = $roundModel;
        $this->participationModel = $participationModel;
        $this->dailyModel = $dailyModel;
        $this->voteModel = $voteModel;
        $this->chanceLogModel = $chanceLogModel;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->eventDispatcher = $eventDispatcher;
        $this->walletService = $walletService;
        $this->idempotencyService = $idempotencyService;
        $this->outboxService = $outboxService;
    }

    

    

    

    private function generateUniqueCode(int $roundId): ?string
    {
        for ($attempt = 0; $attempt < self::MAX_CODE_GENERATION_ATTEMPTS; $attempt++) {
            $digits = range(0, 9);
            // H11 Fix: استفاده از بر هم زدن امن به روش Fisher-Yates و تابع CSPRNG سیستم‌عامل
            for ($i = count($digits) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                $tmp = $digits[$i];
                $digits[$i] = $digits[$j];
                $digits[$j] = $tmp;
            }
            $code = implode('', $digits);

            $exists = $this->db->query(
                "SELECT 1 FROM lottery_participations WHERE round_id = ? AND ticket_number = ? AND is_deleted = 0 LIMIT 1",
                [$roundId, $code]
            )->fetch();

            if (!$exists) {
                return $code;
            }
        }

        $timestamp = microtime(true);
        $unique = hash('sha256', $roundId . $timestamp . bin2hex(random_bytes(16)));
        $code = '';
        
        for ($i = 0; $i < 10; $i++) {
            $code .= hexdec($unique[$i]) % 10;
        }
        
        return $code;
    }

    

    

    

    private function checkMatch(string $code, int $selectedNumber, string $matchType): bool
    {
        $digits = str_split($code);

        switch ($matchType) {
            case 'value':
                // The code contains this digit somewhere
                return in_array((string)$selectedNumber, $digits, true);
            case 'position':
                // The digit at position $selectedNumber equals $selectedNumber
                $pos = $selectedNumber;
                return isset($digits[$pos]) && (int)$digits[$pos] === $selectedNumber;
            case 'value_position':
                // L-2 Fix: digit must appear at exactly its own positional index.
                // e.g. selectedNumber=4 → code must have '4' at index 4.
                // Prevents always-true for even digits in even positions.
                return isset($digits[$selectedNumber]) && (int)$digits[$selectedNumber] === $selectedNumber;
            case 'signal':
                // Sum of first 5 digits; last digit of sum must equal selectedNumber
                $sum = array_sum(array_map('intval', array_slice($digits, 0, 5)));
                return ($sum % 10) === $selectedNumber;
            default:
                return in_array((string)$selectedNumber, $digits, true);
        }
    }

    private function applyNoVoteDecay(object $participation, string $date): void
    {
        $scoreBefore = (float)$participation->chance_score;
        $scoreAfter = round($scoreBefore * LotteryParticipation::DECAY_FACTOR, 4);

        if ($scoreAfter < LotteryParticipation::MIN_CHANCE) {
            $scoreAfter = LotteryParticipation::MIN_CHANCE;
        }

        $this->participationModel->update($participation->id, ['chance_score' => $scoreAfter]);

        $this->eventDispatcher->dispatchAsync(ScoreUpdatedEvent::class, new ScoreUpdatedEvent(
            $participation->user_id,
            $scoreBefore,
            $scoreAfter,
            'lottery_no_vote_decay'
        ));

        $this->chanceLogModel->create([
            'participation_id' => $participation->id,
            'user_id' => $participation->user_id,
            'round_id' => $participation->round_id,
            'date' => $date,
            'score_before' => $scoreBefore,
            'score_change' => $scoreAfter - $scoreBefore,
            'score_after' => $scoreAfter,
            'reason' => 'no_participation',
        ]);
    }

    public function selectWinner(int $roundId, int $adminId): array
    {
        try {
            $this->db->beginTransaction();
            // ✅ Use app() helper instead of service locator injection
            $saga = app(\App\Services\SagaOrchestrator::class);

            $winner = null;
            $finalSeed = null;
            $participants = [];
            $round = null;

            $saga->addStep(
                'lock_and_validate',
                function () use ($roundId, &$winner, &$finalSeed, &$participants, &$round) {
                    // 🔒 PESSIMISTIC LOCKING: Lock the round row to prevent concurrent winner selection
                    $round = $this->db->selectOne(
                        "SELECT * FROM lottery_rounds WHERE id = ? FOR UPDATE",
                        [$roundId]
                    );

                    if (!$round) {
                        throw new \Exception('دوره یافت نشد.');
                    }

                    // 🔒 RECHECK after acquiring lock: Ensure round is still ACTIVE
                    if ($round->status === \App\Models\LotteryRound::STATUS_COMPLETED) {
                        throw new \Exception('برنده قبلاً انتخاب شده.');
                    }

                    $participants = $this->participationModel->getAllActiveByRound($roundId);

                    if (empty($participants)) {
                        throw new \Exception('شرکت‌کننده‌ای وجود ندارد.');
                    }

                    $totalScore = $this->participationModel->getTotalChanceScore($roundId);

                    // L-3: If all scores have decayed to near-zero, reset everyone to DEFAULT_CHANCE
                    if ($totalScore < 1.0) {
                        foreach ($participants as $p) {
                            $this->participationModel->update($p->id, [
                                'chance_score' => LotteryParticipation::DEFAULT_CHANCE
                            ]);
                        }
                        $totalScore = LotteryParticipation::DEFAULT_CHANCE * count($participants);
                        $this->logger->warning('lottery.chance_reset', [
                            'round_id' => $roundId,
                            'participants' => count($participants),
                            'new_total' => $totalScore,
                        ]);
                    }

                    if ($totalScore <= 0) {
                        throw new \Exception('مجموع امتیازات صفر است.');
                    }

                    // Perform weighted random selection
                    // H11 Fix: انتخاب نقطه تصادفی برنده با متد کاملاً امن رمزنگاری شده CSPRNG سیستم‌عامل
                    $randomPoint = (random_int(0, (int)($totalScore * 100000)) / 100000);
                    $cumulative = 0;

                    foreach ($participants as $p) {
                        $cumulative += (float)$p->chance_score;
                        if ($randomPoint <= $cumulative) {
                            $winner = $p;
                            break;
                        }
                    }

                    if (!$winner && !empty($participants)) {
                        $winner = $participants[random_int(0, count($participants) - 1)];
                    }

                    if (!$winner) {
                        throw new \Exception('خطا در انتخاب برنده.');
                    }

                    // Generate final seed for transparency
                    $finalSeedData = implode('|', [$roundId, $winner->user_id, $winner->chance_score, $totalScore, $randomPoint, microtime(true), bin2hex(random_bytes(16))]);
                    $finalSeed = hash('sha256', $finalSeedData);

                    // L-4 Fix: Update round status FIRST (within the lock), THEN pay.
                    $this->roundModel->update($roundId, [
                        'status' => \App\Models\LotteryRound::STATUS_COMPLETED,
                        'winner_user_id' => $winner->user_id,
                        'winner_chance_score' => $winner->chance_score,
                        'final_seed' => $finalSeed,
                    ]);

                    // Mark participants inside the transaction (still within the lock)
                    $this->participationModel->update($winner->id, ['status' => 'winner']);

                    foreach ($participants as $p) {
                        if ($p->id !== $winner->id) {
                            $this->participationModel->update($p->id, ['status' => 'completed']);
                        }
                    }

                    return true;
                },
                function () {}
            )->addStep(
                'pay_prize',
                function () use ($roundId, &$winner, &$round) {
                    if ($round->prize_amount > 0) {
                        $payload = [
                            'user_id' => $winner->user_id,
                            'amount' => (float)$round->prize_amount,
                            'currency' => $round->currency,
                            'metadata' => [
                                'type' => 'lottery_prize',
                                'round_id' => $roundId,
                                'description' => "جایزه قرعه‌کشی: {$round->title}",
                                'idempotency_key' => "lottery_winner_{$roundId}_{$winner->user_id}"
                            ],
                        ];

                        if ($this->outboxService) {
                            $ok = $this->outboxService->record('lottery_round', $roundId, \App\Events\Registry\EventRegistry::LOTTERY_ROUND_FINISHED, $payload);
                            if (!$ok) {
                                throw new \Exception('خطا در ثبت رکورد خروجی پرداخت جایزه.');
                            }
                        } else {
                            $depositResult = $this->walletService->deposit(
                                $winner->user_id,
                                (float)$round->prize_amount,
                                $round->currency,
                                $payload['metadata']
                            );

                            if (!$depositResult['success']) {
                                throw new \Exception('خطا در واریز جایزه: ' . ($depositResult['message'] ?? ''));
                            }

                            $this->eventDispatcher->dispatchAsync('wallet.updated', ['user_id' => $winner->user_id]);
                        }
                    }
                    return true;
                },
                function (\Throwable $e) use ($roundId) {
                    $this->logger->warning('saga.compensating.lottery_prize_payment', ['round_id' => $roundId]);
                }
            );

            $saga->execute();
            $this->db->commit();

            $this->logger->info('lottery.select_winner.success', [
                'round_id' => $roundId,
                'winner_user_id' => $winner->user_id,
                'total_participants' => count($participants),
                'admin_id' => $adminId
            ]);

            return [
                'success' => true,
                'message' => 'برنده با موفقیت انتخاب شد.',
                'round_id' => $roundId,
                'winner_user_id' => $winner->user_id,
                'winner_username' => $winner->username ?? 'Unknown',
                'prize_amount' => $round->prize_amount,
                'final_seed' => $finalSeed
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('lottery.select_winner.failed', [
                'round_id' => $roundId,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی در انتخاب برنده.'];
        }
    }

    public function cancelRound(int $roundId, int $adminId, string $reason = ''): array
    {
        $round = $this->roundModel->find($roundId);
        
        if (!$round) {
            return ['success' => false, 'message' => 'دوره یافت نشد.'];
        }

        if ($round->status === LotteryRound::STATUS_COMPLETED) {
            return ['success' => false, 'message' => 'دوره تکمیل شده را نمی‌توان لغو کرد.'];
        }

        try {
            $this->db->beginTransaction();
            $saga = app(\App\Services\SagaOrchestrator::class);
            $participants = [];

            $saga->addStep(
                'load_participants_and_refund',
                function () use ($roundId, $round, &$participants) {
                    $participants = $this->participationModel->getAllActiveByRound($roundId);
                    
                    foreach ($participants as $p) {
                        // 🛡️ H10 Fix: Use participation->price_paid instead of round->entry_fee
                        // Each participant may have paid a different amount
                        if ($p->transaction_id && $p->price_paid > 0) {
                            $refundPayload = [
                                'user_id' => $p->user_id,
                                'amount' => $p->price_paid,
                                'currency' => $round->currency,
                                'metadata' => [
                                    'type' => 'lottery_refund',
                                    'round_id' => $roundId,
                                    'description' => "بازگشت هزینه: {$round->title}",
                                ],
                            ];

                            if ($this->outboxService) {
                                $ok = $this->outboxService->record('lottery_participation', $p->id, \App\Events\Registry\EventRegistry::LOTTERY_ROUND_CANCELLED, $refundPayload);
                                if (!$ok) {
                                    throw new \Exception('خطا در ثبت رکورد خروجی برای بازگشت وجه');
                                }
                            } else {
                                $res = $this->walletService->deposit(
                                    $p->user_id,
                                    $p->price_paid,
                                    $round->currency,
                                    $refundPayload['metadata']
                                );
                                if (empty($res['success'])) {
                                    throw new \Exception('خطا در بازگشت وجه به کیف پول');
                                }

                                $this->eventDispatcher->dispatchAsync('wallet.updated', ['user_id' => $p->user_id]);
                            }
                        }

                        $this->participationModel->update($p->id, ['status' => 'cancelled']);
                    }

                    $this->roundModel->update($roundId, ['status' => LotteryRound::STATUS_CANCELLED]);
                    return true;
                },
                function (\Throwable $e) use ($roundId) {
                    $this->logger->warning('saga.compensating.lottery_refund_cancel', ['round_id' => $roundId]);
                }
            );

            $saga->execute();
            $this->db->commit();

            $this->clearCache('active_round');

            $this->logger->info('lottery_cancelled', ['message' => "Round {$roundId} by admin {$adminId}"]);

            return ['success' => true, 'message' => 'دوره لغو و هزینه‌ها بازگشت داده شد.', 'refunded_count' => count($participants)];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('lottery_cancel_error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    public function getRoundStatistics(int $roundId): array
    {
        $cacheKey = "round_stats_{$roundId}";
        $cached = $this->getCacheValue($cacheKey);
        
        if ($cached !== null) {
            return $cached;
        }

        $round = $this->roundModel->find($roundId);
        
        if (!$round) {
            return ['success' => false, 'message' => 'دوره یافت نشد.'];
        }

        $participants = $this->participationModel->getAllActiveByRound($roundId);
        $totalScore = $this->participationModel->getTotalChanceScore($roundId);
        $distribution = $this->participationModel->getChanceDistribution($roundId);
        $dailyNumbers = $this->dailyModel->getByRound($roundId);

        $stats = [
            'success' => true,
            'round' => $round,
            'total_participants' => count($participants),
            'total_chance_score' => $totalScore,
            'average_score' => count($participants) > 0 ? round($totalScore / count($participants), 2) : 0,
            'distribution' => $distribution,
            'daily_numbers_count' => count($dailyNumbers),
            'top_participants' => array_slice($participants, 0, 10),
        ];

        $this->setCache($cacheKey, $stats);
        return $stats;
    }

    

    public function getTransparencyText(): string
    {
        return <<<EOT
🎯 شفافیت و اعتمادسازی سیستم قرعه‌کشی چرتکه

✅ ویژگی‌ها:
• وزن‌دهی خودکار روزانه
• عدم حذف کاربران - فقط تغییر شانس
• کف شانس تضمینی: 5.0
• انتخاب وزن‌دار - شانس بالا ≠ تضمین برد
• شفافیت کامل - Seed ها و لاگ‌ها قابل بررسی

🔒 امنیت:
• الگوریتم‌های تصادفی امن
• جلوگیری از الگوهای قابل پیش‌بینی
• ثبت کامل تغییرات

💡 نتیجه: رأی کاربران + وزن‌دهی سیستم = عادلانه‌ترین روش
EOT;
    }

    private function validateRoundData(array $data): array
    {
        if (empty($data['title']) || strlen($data['title']) < 3) {
            return ['valid' => false, 'message' => 'عنوان باید حداقل ۳ کاراکتر باشد.'];
        }

        if (empty($data['start_date']) || empty($data['end_date'])) {
            return ['valid' => false, 'message' => 'تاریخ‌ها الزامی است.'];
        }

        if (isset($data['entry_fee']) && $data['entry_fee'] < 0) {
            return ['valid' => false, 'message' => 'هزینه نمی‌تواند منفی باشد.'];
        }

        return ['valid' => true];
    }

    private function generateSecureRandomNumbers(int $count, int $min, int $max): array
    {
        $numbers = [];
        $range = range($min, $max);
        
        while (count($numbers) < $count && !empty($range)) {
            $index = random_int(0, count($range) - 1);
            $numbers[] = $range[$index];
            unset($range[$index]);
            $range = array_values($range);
        }
        
        return $numbers;
    }

    private function checkRateLimit(int $userId, string $action, int $maxAttempts, int $timeWindow): bool
    {
        $cacheKey = "rate_limit_{$userId}_{$action}";
        $attempts = $this->getCacheValue($cacheKey) ?? 0;
        
        if ($attempts >= $maxAttempts) {
            return false;
        }
        
        $this->setCache($cacheKey, $attempts + 1, $timeWindow);
        return true;
    }

    private function getCacheValue(string $key)
    {
        return $this->cache->get($key);
    }

    private function setCache(string $key, $data, int $ttl = null): void
    {
        $ttl = $ttl ?? $this->cacheTTL;
        $minutes = max(1, (int)ceil($ttl / 60));
        $this->cache->put($key, $data, $minutes);
    }

    private function clearCache(string $key = null): void
    {
        if ($key !== null) {
            $this->eventDispatcher->dispatchAsync('cache.invalidate', ['key' => $key]);
        }
    }

    private function sanitizeInput(?string $input): ?string
    {
        return $input === null ? null : e(trim($input), ENT_QUOTES, 'UTF-8');
    }

    private function notify(int $userId, string $title, string $message, string $type): void
    {
        try {
            $this->eventDispatcher->dispatchAsync('notification.requested', [
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => []
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('notification_error', ['message' => $e->getMessage()]);
        }
    }
}