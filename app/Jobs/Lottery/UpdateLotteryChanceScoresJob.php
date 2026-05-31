<?php

declare(strict_types=1);

namespace App\Jobs\Lottery;

class UpdateLotteryChanceScoresJob
{
    public function __construct(
        private \Core\Database $db,
        private \App\Services\ScoreService $scoreService,
        private \App\Contracts\LoggerInterface $logger
    ) {}

public function handle(int $roundId, string $date): array
    {
        $dailyNumber = $this->repository->getDailyNumberByRoundAndDate($roundId, $date);
        
        if (!$dailyNumber) {
            return ['success' => false, 'message' => '????? ?????? ???? ???.'];
        }

        $round = $this->repository->findRound($roundId);
        if (!$round) {
            return ['success' => false, 'message' => '???? ???? ???.'];
        }

        $participants = $this->repository->getAllActiveParticipationsByRound($roundId);
        
        if (empty($participants)) {
            return ['success' => false, 'message' => '????????????? ???? ?????.'];
        }

        $matchType = $dailyNumber->match_type;
        $updatedCount = 0;
        $totalReward = 0;
        $totalPenalty = 0;

        $this->db->beginTransaction();

        try {
            foreach ($participants as $p) {
                $userVote = $this->repository->getUserVote($p->user_id, $dailyNumber->id);
                
                if (!$userVote) {
                    $this->applyNoVoteDecay($p, $date);
                    continue;
                }

                $selectedNumber = (int)$userVote->voted_number;
                $matched = $this->checkMatch($p->ticket_number ?? $p->code, $selectedNumber, $matchType);

                $scoreBefore = (float)$p->chance_score;
                $change = 0;
                $reason = '';

                if ($matched) {
                    $change = (float)LotteryParticipation::BASE_REWARD;
                    $reason = 'match_success';
                    $totalReward += $change;
                } else {
                    $change = -(float)LotteryParticipation::BASE_PENALTY;
                    $reason = 'match_fail';
                    $totalPenalty += abs($change);
                }

                $scoreAfter = round($scoreBefore + $change, 4);

                if ($scoreAfter < LotteryParticipation::MIN_CHANCE) {
                    $scoreAfter = LotteryParticipation::MIN_CHANCE;
                }

                $this->repository->updateParticipation($p->id, ['chance_score' => $scoreAfter]);

                if ($this->scoreService) {
                    $this->scoreService->applyDelta('user', $p->user_id, 'lottery_chance', $change, 'lottery_daily_vote');
                }

                $this->repository->createChanceLog([
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
            $this->eventDispatcher->dispatchAsync('cache.invalidate', ['key' => "participants_{$roundId}"]);

            $this->logger->info('lottery_chance_updated', ['message' => "Round {$roundId}, updated {$updatedCount}"]);

            return [
                'success' => true,
                'message' => '???????? ????????? ????.',
                'updated_count' => $updatedCount,
                'stats' => ['total_reward' => round($totalReward, 2), 'total_penalty' => round($totalPenalty, 2)]
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('lottery_chance_error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => '???? ??????.'];
        }
    }
}
