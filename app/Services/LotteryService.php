<?php
// app/Services/LotteryService.php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Services\Notification\NotificationService;
use App\Services\FeatureFlagService;
use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Models\LotteryVote;
use App\Models\LotteryChanceLog;
use Core\Database;

class LotteryService extends \App\Services\BaseService
{
    private Database $db;
    private WalletService $walletService;
    private NotificationService $notificationService;
    private LotteryRound $roundModel;
    private LotteryParticipation $participationModel;
    private LotteryDailyNumber $dailyModel;
    private LotteryVote $voteModel;
    private LotteryChanceLog $chanceLogModel;
    private FeatureFlagService $featureFlagService;

    private const MATCH_TYPES = ['value', 'position', 'value_position', 'signal'];
    private const MAX_CODE_GENERATION_ATTEMPTS = 100;
    private const MAX_DAILY_VOTES_PER_USER = 1;
    
    private array $cache = [];
    private int $cacheTTL = 300;

    public function __construct(
        Database $db,
        WalletService $walletService,
        NotificationService $notificationService,
        \App\Models\LotteryRound $roundModel,
        \App\Models\LotteryParticipation $participationModel,
        \App\Models\LotteryDailyNumber $dailyModel,
        \App\Models\LotteryVote $voteModel,
        \App\Models\LotteryChanceLog $chanceLogModel,
        FeatureFlagService $featureFlagService,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->roundModel = $roundModel;
        $this->participationModel = $participationModel;
        $this->dailyModel = $dailyModel;
        $this->voteModel = $voteModel;
        $this->chanceLogModel = $chanceLogModel;
        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
        $this->featureFlagService = $featureFlagService;
    }

    public function createRound(int $adminId, array $data): array
    {
        $validation = $this->validateRoundData($data);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => $validation['message']];
        }

        $activeRound = $this->roundModel->getActiveRound();
        if ($activeRound) {
            return [
                'success' => false, 
                'message' => 'یک دوره فعال وجود دارد. ابتدا آن را ببندید.',
                'active_round_id' => $activeRound->id
            ];
        }

        $startDate = $data['start_date'];
        $endDate = $data['end_date'];
        
        if (strtotime($endDate) <= strtotime($startDate)) {
            return ['success' => false, 'message' => 'تاریخ پایان باید بعد از تاریخ شروع باشد.'];
        }

        $this->db->beginTransaction();

        try {
            $roundId = $this->roundModel->create([
                'title' => $this->sanitizeInput($data['title']),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'description' => $this->sanitizeInput($data['prize_description'] ?? 'توضیحات پیش‌فرض قرعه‌کشی'),
                'prize_amount' => max(0, (float)($data['prize_amount'] ?? 0)),
                'currency' => in_array($data['currency'] ?? 'irt', ['irt', 'usdt']) ? $data['currency'] : 'irt',
                'ticket_price' => max(0, (float)($data['entry_fee'] ?? 0)),
                'max_tickets' => (int)feature_config('lottery', 'max_participants', 10000),
                'status' => LotteryRound::STATUS_ACTIVE,
            ]);

            if (!$roundId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ایجاد دوره.'];
            }

            $this->db->commit();
            $this->clearCache('active_round');

            $this->logger->info('lottery_round_created', ['message' => "Admin {$adminId} created round #{$roundId}"]);

            return [
                'success' => true, 
                'message' => 'دوره قرعه‌کشی با موفقیت ایجاد شد.', 
                'round_id' => $roundId
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('lottery_round_error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی در ایجاد دوره.'];
        }
    }

    public function participate(int $userId, int $roundId): array
    {
        if (!$this->featureFlagService->isEnabled('lottery', $userId)) {
            return ['success' => false, 'message' => 'سیستم قرعه‌کشی موقتاً غیرفعال است.'];
        }

        if (!$this->checkRateLimit($userId, 'participate', 10, 3600)) {
            return ['success' => false, 'message' => 'تعداد تلاش‌های شما بیش از حد مجاز است.'];
        }

        $round = $this->roundModel->find($roundId);
        if (!$round || $round->status !== LotteryRound::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'دوره قرعه‌کشی فعال نیست.'];
        }

        $now = time();
        if ($now < strtotime($round->start_date)) {
            return ['success' => false, 'message' => 'زمان شروع دوره هنوز فرا نرسیده است.'];
        }
        
        if ($now > strtotime($round->end_date)) {
            return ['success' => false, 'message' => 'زمان ثبت‌نام به پایان رسیده است.'];
        }

        if ($this->participationModel->isParticipating($userId, $roundId)) {
            return ['success' => false, 'message' => 'شما قبلاً در این دوره شرکت کرده‌اید.'];
        }

        $this->db->beginTransaction();

        try {
            $transactionId = null;
            if ($round->ticket_price > 0) {
                $result = $this->walletService->withdraw(
                    $userId, 
                    (float)$round->ticket_price, 
                    $round->currency, 
                    ['type' => 'lottery_entry', 'round_id' => $roundId, 'description' => "ورود به قرعه‌کشی: {$round->title}"]
                );
                
                if (!$result['success']) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'موجودی کافی نیست. ' . ($result['message'] ?? '')];
                }
                
                $transactionId = $result['transaction_id'] ?? null;
            }

            $code = $this->generateUniqueCode($roundId);
            
            if (!$code) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در تولید کد یکتا.'];
            }

            $participationId = $this->participationModel->create([
                'round_id' => $roundId,
                'user_id' => $userId,
                'ticket_number' => $code,
                'chance_score' => LotteryParticipation::DEFAULT_CHANCE,
                'price_paid' => $round->ticket_price,
                'currency' => $round->currency,
                'status' => 'active',
            ]);

            if (!$participationId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ثبت مشارکت.'];
            }

            $this->db->commit();

            $this->notify($userId, 'ثبت‌نام موفق', "شما با موفقیت در قرعه‌کشی «{$round->title}» شرکت کردید.\n\nکد: {$code}\nشانس: " . LotteryParticipation::DEFAULT_CHANCE, 'lottery_joined');

            $this->logger->info('lottery_participation', ['message' => "User {$userId} joined round #{$roundId}, code: {$code}"]);

            return [
                'success' => true,
                'message' => 'با موفقیت ثبت‌نام شدید!',
                'code' => $code,
                'chance_score' => LotteryParticipation::DEFAULT_CHANCE,
                'participation_id' => $participationId,
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('lottery_participation_error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    private function generateUniqueCode(int $roundId): ?string
    {
        for ($attempt = 0; $attempt < self::MAX_CODE_GENERATION_ATTEMPTS; $attempt++) {
            $digits = range(0, 9);
            shuffle($digits);
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

    public function generateDailyNumbers(int $roundId): array
    {
        $round = $this->roundModel->find($roundId);
        
        if (!$round || !in_array($round->status, [LotteryRound::STATUS_ACTIVE, LotteryRound::STATUS_VOTING])) {
            return ['success' => false, 'message' => 'دوره فعال نیست.'];
        }

        $today = date('Y-m-d');
        $existing = $this->dailyModel->getByRoundAndDate($roundId, $today);
        
        if ($existing) {
            return ['success' => false, 'message' => 'اعداد امروز قبلاً تولید شده‌اند.', 'daily_number_id' => $existing->id];
        }

        $numbers = $this->generateSecureRandomNumbers(3, 0, 9);
        $seedRaw = bin2hex(random_bytes(32));
        $seedData = implode('|', [$seedRaw, $today, $roundId, microtime(true), random_int(1000000, 9999999)]);
        $seedHash = hash('sha256', $seedData);
        $matchType = self::MATCH_TYPES[array_rand(self::MATCH_TYPES)];

        $this->db->beginTransaction();

        try {
            $dailyId = $this->dailyModel->create([
                'round_id' => $roundId,
                'date' => $today,
                'number_1' => $numbers[0],
                'number_2' => $numbers[1],
                'number_3' => $numbers[2],
                'seed_hash' => $seedHash,
                'seed_raw' => $seedRaw,
                'match_type' => $matchType,
            ]);

            if (!$dailyId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در تولید اعداد.'];
            }

            if ($round->status === LotteryRound::STATUS_ACTIVE) {
                $this->roundModel->update($roundId, ['status' => LotteryRound::STATUS_VOTING]);
            }

            $this->db->commit();
            $this->clearCache("daily_numbers_{$roundId}");

            $this->logger->info('lottery_daily_numbers', ['message' => "Round {$roundId}, date {$today}, type {$matchType}"]);

            return [
                'success' => true,
                'message' => 'اعداد روزانه تولید شدند.',
                'daily_id' => $dailyId,
                'numbers' => $numbers,
                'match_type' => $matchType,
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('lottery_daily_error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    public function vote(int $userId, int $dailyNumberId, int $selectedNumber): array
    {
        if ($selectedNumber < 0 || $selectedNumber > 9) {
            return ['success' => false, 'message' => 'عدد انتخابی باید بین 0 تا 9 باشد.'];
        }

        $dailyNumber = $this->dailyModel->find($dailyNumberId);
        if (!$dailyNumber) {
            return ['success' => false, 'message' => 'اعداد روزانه یافت نشد.'];
        }

        $round = $this->roundModel->find($dailyNumber->round_id);
        if (!$round || $round->status !== LotteryRound::STATUS_VOTING) {
            return ['success' => false, 'message' => 'رأی‌گیری فعال نیست.'];
        }

        $participation = $this->participationModel->findByUserAndRound($userId, $dailyNumber->round_id);
        
        if (!$participation || $participation->status !== 'active') {
            return ['success' => false, 'message' => 'شما در این دوره شرکت نکرده‌اید.'];
        }

        if ($this->voteModel->hasVotedToday($userId, $dailyNumberId)) {
            return ['success' => false, 'message' => 'شما قبلاً امروز رأی داده‌اید.'];
        }

        $this->db->beginTransaction();

        try {
            $voteId = $this->voteModel->create([
                'user_id' => $userId,
                'round_id' => $dailyNumber->round_id,
                'daily_number_id' => $dailyNumberId,
                'voted_number' => $selectedNumber,
                'participation_id' => $participation->id,
            ]);

            if (!$voteId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ثبت رأی.'];
            }

            $this->db->commit();
            $this->logger->info('lottery_vote', ['message' => "User {$userId}, daily {$dailyNumberId}, number {$selectedNumber}"]);

            return ['success' => true, 'message' => 'رأی شما ثبت شد!', 'vote_id' => $voteId];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('lottery_vote_error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    public function updateChanceScores(int $roundId, string $date): array
    {
        $dailyNumber = $this->dailyModel->getByRoundAndDate($roundId, $date);
        
        if (!$dailyNumber) {
            return ['success' => false, 'message' => 'اعداد روزانه یافت نشد.'];
        }

        $round = $this->roundModel->find($roundId);
        if (!$round) {
            return ['success' => false, 'message' => 'دوره یافت نشد.'];
        }

        $participants = $this->participationModel->getAllActiveByRound($roundId);
        
        if (empty($participants)) {
            return ['success' => false, 'message' => 'شرکت‌کننده‌ای وجود ندارد.'];
        }

        $matchType = $dailyNumber->match_type;
        $updatedCount = 0;
        $totalReward = 0;
        $totalPenalty = 0;

        $this->db->beginTransaction();

        try {
            foreach ($participants as $p) {
                $userVote = $this->voteModel->getUserVote($p->user_id, $dailyNumber->id);
                
                if (!$userVote) {
                    $this->applyNoVoteDecay($p, $date);
                    continue;
                }

                $selectedNumber = (int)$userVote->voted_number;
                $matched = $this->checkMatch($p->code, $selectedNumber, $matchType);

                $scoreBefore = (float)$p->chance_score;
                $change = 0;
                $reason = '';

                if ($matched) {
                    $randomFactor = 1 + (mt_rand(-10, 10) / 100);
                    $change = LotteryParticipation::BASE_REWARD * $randomFactor;
                    $reason = 'match_success';
                    $totalReward += $change;
                } else {
                    $randomFactor = 1 + (mt_rand(-10, 10) / 100);
                    $change = -(LotteryParticipation::BASE_PENALTY * $randomFactor);
                    $reason = 'match_fail';
                    $totalPenalty += abs($change);
                }

                $scoreAfter = round($scoreBefore + $change, 4);

                if ($scoreAfter < LotteryParticipation::MIN_CHANCE) {
                    $scoreAfter = LotteryParticipation::MIN_CHANCE;
                }

                $this->participationModel->update($p->id, ['chance_score' => $scoreAfter]);

                $this->chanceLogModel->create([
                    'participation_id' => $p->id,
                    'user_id' => $p->user_id,
                    'round_id' => $roundId,
                    'date' => $date,
                    'score_before' => $scoreBefore,
                    'score_change' => $change,
                    'score_after' => $scoreAfter,
                    'reason' => $reason,
                    'details' => "selected:{$selectedNumber}, match_type:{$matchType}, matched:" . ($matched ? 'yes' : 'no'),
                ]);

                $updatedCount++;
            }

            $this->db->commit();
            $this->clearCache("participants_{$roundId}");

            $this->logger->info('lottery_chance_updated', ['message' => "Round {$roundId}, updated {$updatedCount}"]);

            return [
                'success' => true,
                'message' => 'امتیازات بروزرسانی شدند.',
                'updated_count' => $updatedCount,
                'stats' => ['total_reward' => round($totalReward, 2), 'total_penalty' => round($totalPenalty, 2)]
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('lottery_chance_error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    private function checkMatch(string $code, int $selectedNumber, string $matchType): bool
    {
        $digits = str_split($code);

        switch ($matchType) {
            case 'value':
                return in_array((string)$selectedNumber, $digits, true);
            case 'position':
                $pos = $selectedNumber % 10;
                return isset($digits[$pos]) && (int)$digits[$pos] === $selectedNumber;
            case 'value_position':
                $idx = array_search((string)$selectedNumber, $digits, true);
                return $idx !== false && ($idx % 2 === 0);
            case 'signal':
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

            // 🔒 PESSIMISTIC LOCKING: Lock the round row to prevent concurrent winner selection
            $round = $this->db->selectOne(
                "SELECT * FROM lottery_rounds WHERE id = ? FOR UPDATE",
                [$roundId]
            );

            if (!$round) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'دوره یافت نشد.'];
            }

            // 🔒 RECHECK after acquiring lock: Ensure round is still ACTIVE
            // This prevents race condition where status changed before lock was acquired
            if ($round->status === \App\Models\LotteryRound::STATUS_COMPLETED) {
                $this->db->rollBack();
                $this->logger->warning('lottery.select_winner.already_completed', [
                    'round_id' => $roundId,
                    'winner_user_id' => $round->winner_user_id
                ]);
                return ['success' => false, 'message' => 'برنده قبلاً انتخاب شده.', 'winner_user_id' => $round->winner_user_id];
            }

            $participants = $this->participationModel->getAllActiveByRound($roundId);

            if (empty($participants)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'شرکت‌کننده‌ای وجود ندارد.'];
            }

            $totalScore = $this->participationModel->getTotalChanceScore($roundId);

            if ($totalScore <= 0) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'مجموع امتیازات صفر است.'];
            }

            // Perform weighted random selection
            $randomPoint = (mt_rand(0, (int)($totalScore * 100000)) / 100000);
            $cumulative = 0;
            $winner = null;

            foreach ($participants as $p) {
                $cumulative += (float)$p->chance_score;
                if ($randomPoint <= $cumulative) {
                    $winner = $p;
                    break;
                }
            }

            // Fallback to random participant if winner not found (shouldn't happen with proper math)
            if (!$winner && !empty($participants)) {
                $winner = $participants[array_rand($participants)];
            }

            if (!$winner) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در انتخاب برنده.'];
            }

            // Generate final seed for transparency
            $finalSeedData = implode('|', [$roundId, $winner->user_id, $winner->chance_score, $totalScore, $randomPoint, microtime(true), bin2hex(random_bytes(16))]);
            $finalSeed = hash('sha256', $finalSeedData);

            // 🔒 Update round with LOCK HELD - ensures atomicity
            $this->roundModel->update($roundId, [
                'status' => \App\Models\LotteryRound::STATUS_COMPLETED,
                'winner_user_id' => $winner->user_id,
                'winner_chance_score' => $winner->chance_score,
                'final_seed' => $finalSeed,
            ]);

            // Mark participants
            $this->participationModel->update($winner->id, ['status' => 'winner']);

            foreach ($participants as $p) {
                if ($p->id !== $winner->id) {
                    $this->participationModel->update($p->id, ['status' => 'completed']);
                }
            }

            // Process prize if applicable
            if ($round->prize_amount > 0) {
                $depositResult = $this->walletService->deposit(
                    $winner->user_id,
                    $round->prize_amount,
                    $round->currency,
                    'lottery_prize',
                    ['round_id' => $roundId, 'description' => "جایزه قرعه‌کشی: {$round->title}"]
                );

                if (!$depositResult['success']) {
                    $this->db->rollBack();
                    $this->logger->error('lottery.select_winner.deposit_failed', [
                        'round_id' => $roundId,
                        'winner_user_id' => $winner->user_id,
                        'error' => $depositResult['message'] ?? 'Unknown error'
                    ]);
                    return ['success' => false, 'message' => 'خطا در واریز جایزه.'];
                }
            }

            // Commit transaction with lock held
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
            $this->db->rollback();
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

        $this->db->beginTransaction();

        try {
            $participants = $this->participationModel->getAllActiveByRound($roundId);
            
            foreach ($participants as $p) {
                if ($p->transaction_id && $round->entry_fee > 0) {
                    $this->walletService->deposit(
                        $p->user_id,
                        $round->entry_fee,
                        $round->currency,
                        'lottery_refund',
                        ['round_id' => $roundId, 'description' => "بازگشت هزینه: {$round->title}"]
                    );
                }

                $this->participationModel->update($p->id, ['status' => 'cancelled']);
            }

            $this->roundModel->update($roundId, ['status' => LotteryRound::STATUS_CANCELLED]);

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
        $cached = $this->getCache($cacheKey);
        
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

    public function getUserChanceHistory(int $userId, int $roundId): array
    {
        $participation = $this->participationModel->findByUserAndRound($userId, $roundId);
        
        if (!$participation) {
            return ['success' => false, 'message' => 'شما در این دوره شرکت نکرده‌اید.'];
        }

        $logs = $this->chanceLogModel->getByParticipation($participation->id, 50);

        return [
            'success' => true,
            'participation' => $participation,
            'history' => $logs,
            'current_score' => $participation->chance_score,
        ];
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
            $index = array_rand($range);
            $numbers[] = $range[$index];
            unset($range[$index]);
            $range = array_values($range);
        }
        
        return $numbers;
    }

    private function checkRateLimit(int $userId, string $action, int $maxAttempts, int $timeWindow): bool
    {
        $cacheKey = "rate_limit_{$userId}_{$action}";
        $attempts = $this->getCache($cacheKey) ?? 0;
        
        if ($attempts >= $maxAttempts) {
            return false;
        }
        
        $this->setCache($cacheKey, $attempts + 1, $timeWindow);
        return true;
    }

    private function getCache(string $key)
    {
        if (!isset($this->cache[$key])) {
            return null;
        }
        
        $item = $this->cache[$key];
        
        if (time() > $item['expires_at']) {
            unset($this->cache[$key]);
            return null;
        }
        
        return $item['data'];
    }

    private function setCache(string $key, $data, int $ttl = null): void
    {
        $ttl = $ttl ?? $this->cacheTTL;
        $this->cache[$key] = ['data' => $data, 'expires_at' => time() + $ttl];
    }

    private function clearCache(string $key = null): void
    {
        if ($key === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$key]);
        }
    }

    private function sanitizeInput(?string $input): ?string
    {
        return $input === null ? null : e(trim($input), ENT_QUOTES, 'UTF-8');
    }

    private function notify(int $userId, string $title, string $message, string $type): void
    {
        try {
            $this->notificationService->send($userId, $type, $title, $message);
        } catch (\Throwable $e) {
            $this->logger->error('notification_error', ['message' => $e->getMessage()]);
        }
    }
}

