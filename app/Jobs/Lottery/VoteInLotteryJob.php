<?php

declare(strict_types=1);

namespace App\Jobs\Lottery;

class VoteInLotteryJob
{
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->logger = $logger;
}

public function handle(int $userId, int $dailyNumberId, int $selectedNumber): array
    {
        if ($selectedNumber < 0 || $selectedNumber > 9) {
            return ['success' => false, 'message' => '??? ??????? ???? ??? 0 ?? 9 ????.'];
        }

        $dailyNumber = $this->repository->findDailyNumber($dailyNumberId);
        if (!$dailyNumber) {
            return ['success' => false, 'message' => '????? ?????? ???? ???.'];
        }

        $round = $this->repository->findRound($dailyNumber->round_id);
        if (!$round || $round->status !== \App\Models\LotteryRound::STATUS_VOTING) {
            return ['success' => false, 'message' => '???????? ???? ????.'];
        }

        $participation = $this->repository->findParticipationByUserAndRound($userId, $dailyNumber->round_id);
        
        if (!$participation || $participation->status !== 'active') {
            return ['success' => false, 'message' => '??? ?? ??? ???? ???? ?????????.'];
        }

        $this->db->beginTransaction();

        try {
            $existingVote = $this->db->query(
                "SELECT id FROM lottery_votes WHERE user_id = ? AND daily_number_id = ? FOR UPDATE",
                [$userId, $dailyNumberId]
            )->fetch();

            if ($existingVote) {
                $this->db->rollBack();
                return ['success' => false, 'message' => '??? ????? ????? ??? ????????.'];
            }

            $voteId = $this->repository->createVote([
                'user_id' => $userId,
                'round_id' => $dailyNumber->round_id,
                'daily_number_id' => $dailyNumberId,
                'voted_number' => $selectedNumber,
                'participation_id' => $participation->id,
            ]);

            if (!$voteId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => '??? ?? ??? ???.'];
            }

            $this->db->commit();
            $this->logger->info('lottery_vote', ['message' => "User {$userId}, daily {$dailyNumberId}, number {$selectedNumber}"]);

            return ['success' => true, 'message' => '??? ??? ??? ??!', 'vote_id' => $voteId];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('lottery_vote_error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => '???? ??????.'];
        }
    }
}
