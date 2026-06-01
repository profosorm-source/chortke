<?php

declare(strict_types=1);

namespace App\Services\Lottery;

class LotteryParticipationService
{
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Models\LotteryParticipation $model;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \App\Models\LotteryParticipation $model
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->model = $model;
}

    public function participate(int $userId, int $roundId, ?string $idempotencyKey = null): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Lottery\ParticipateInLotteryJob::class);
        return $job->handle($userId, $roundId, $idempotencyKey);
    }

    public function vote(int $userId, int $dailyNumberId, int $selectedNumber): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Lottery\VoteInLotteryJob::class);
        return $job->handle($userId, $dailyNumberId, $selectedNumber);
    }

    public function updateChanceScores(int $roundId, string $date): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Lottery\UpdateLotteryChanceScoresJob::class);
        return $job->handle($roundId, $date);
    }

    public function getUserChanceHistory(int $userId, int $roundId): array
    {
        $participation = $this->model->findParticipationByUserAndRound($userId, $roundId);
        
        if (!$participation) {
            return ['success' => false, 'message' => '??? ?? ??? ???? ???? ?????????.'];
        }

        $logs = $this->model->getChanceLogsByParticipation($participation->id, 50);

        return [
            'success' => true,
            'participation' => $participation,
            'history' => $logs,
            'current_score' => $participation->chance_score,
        ];
    }
}
