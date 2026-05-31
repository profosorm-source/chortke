<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Models\LotteryVote;
use App\Models\LotteryChanceLog;

class LotteryRepository
{
    public function __construct(
        private LotteryRound $roundModel,
        private LotteryParticipation $participationModel,
        private LotteryDailyNumber $dailyModel,
        private LotteryVote $voteModel,
        private LotteryChanceLog $chanceLogModel
    ) {}

    // Round Methods
    public function getActiveRound(): ?LotteryRound
    {
        return $this->roundModel->getActiveRound();
    }

    public function createRound(array $data): int
    {
        return (int) $this->roundModel->create($data);
    }

    public function findRound(int $id): ?LotteryRound
    {
        return $this->roundModel->find($id);
    }

    public function updateRound(int $id, array $data): bool
    {
        return (bool) $this->roundModel->update($id, $data);
    }

    // Participation Methods
    public function isParticipating(int $userId, int $roundId): bool
    {
        return $this->participationModel->isParticipating($userId, $roundId);
    }

    public function createParticipation(array $data): int
    {
        return (int) $this->participationModel->create($data);
    }

    public function findParticipationByUserAndRound(int $userId, int $roundId): ?LotteryParticipation
    {
        return $this->participationModel->findByUserAndRound($userId, $roundId);
    }

    public function getAllActiveParticipationsByRound(int $roundId): array
    {
        return $this->participationModel->getAllActiveByRound($roundId);
    }

    public function updateParticipation(int $id, array $data): bool
    {
        return (bool) $this->participationModel->update($id, $data);
    }

    public function getTotalChanceScore(int $roundId): float
    {
        return (float) $this->participationModel->getTotalChanceScore($roundId);
    }

    public function getChanceDistribution(int $roundId): array
    {
        return $this->participationModel->getChanceDistribution($roundId);
    }

    // Daily Number Methods
    public function getDailyNumberByRoundAndDate(int $roundId, string $date): ?LotteryDailyNumber
    {
        return $this->dailyModel->getByRoundAndDate($roundId, $date);
    }

    public function createDailyNumber(array $data): int
    {
        return (int) $this->dailyModel->create($data);
    }

    public function findDailyNumber(int $id): ?LotteryDailyNumber
    {
        return $this->dailyModel->find($id);
    }

    public function getDailyNumbersByRound(int $roundId): array
    {
        return $this->dailyModel->getByRound($roundId);
    }

    // Vote Methods
    public function createVote(array $data): int
    {
        return (int) $this->voteModel->create($data);
    }

    public function getUserVote(int $userId, int $dailyNumberId): ?LotteryVote
    {
        return $this->voteModel->getUserVote($userId, $dailyNumberId);
    }

    // Chance Log Methods
    public function createChanceLog(array $data): int
    {
        return (int) $this->chanceLogModel->create($data);
    }

    public function getChanceLogsByParticipation(int $participationId, int $limit = 50): array
    {
        return $this->chanceLogModel->getByParticipation($participationId, $limit);
    }
}
