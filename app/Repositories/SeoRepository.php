<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Ads;
use App\Models\SeoExecution;
use App\Models\User;
use Core\Database;

class SeoRepository
{
    public function __construct(
        private Database $db,
        private Ads $adModel,
        private SeoExecution $executionModel,
        private User $userModel
    ) {}

    // -- Ads --
    public function getAd(int $adId): ?object
    {
        return $this->adModel->find($adId);
    }

    public function getAdForUpdate(int $adId): ?object
    {
        return $this->adModel->findByIdForUpdate($adId);
    }

    public function createAd(array $data): int|false
    {
        return $this->adModel->create($data);
    }

    public function updateAd(int $adId, array $data): bool
    {
        return $this->adModel->update($adId, $data);
    }

    // -- Executions --
    public function getExecution(int $executionId): ?object
    {
        return $this->executionModel->find($executionId);
    }

    public function getExecutionByUser(int $executionId, int $userId): ?object
    {
        return $this->executionModel->findByUser($executionId, $userId);
    }

    public function getExecutionForUpdate(int $executionId): ?object
    {
        return $this->executionModel->findByIdForUpdate($executionId);
    }

    public function createExecution(array $data): int|false
    {
        return $this->executionModel->create($data);
    }

    public function updateExecutionStatus(int $executionId, string $status): bool
    {
        return (bool)$this->db->query("UPDATE seo_executions SET status = ? WHERE id = ?", [$status, $executionId]);
    }

    public function completeExecution(int $executionId, array $scores, float $payout): bool
    {
        return $this->executionModel->complete($executionId, $scores, $payout);
    }

    public function rejectExecution(int $executionId, string $reason): bool
    {
        return $this->executionModel->reject($executionId, $reason);
    }

    public function markExecutionAsFraud(int $executionId, array $flags): bool
    {
        return $this->executionModel->markAsFraud($executionId, $flags);
    }

    public function executionExistsToday(int $adId, int $userId): bool
    {
        return $this->executionModel->existsByAdAndUserToday($adId, $userId);
    }

    public function countUserExecutionsToday(int $userId): int
    {
        return $this->executionModel->countByUserToday($userId);
    }

    public function countUserExecutionsLastHour(int $userId): int
    {
        return $this->executionModel->countByUserLastHour($userId);
    }

    public function countIpExecutionsLastHour(string $ip): int
    {
        return $this->executionModel->countByIPLastHour($ip);
    }

    // -- Users --
    public function getUser(int $userId): ?object
    {
        return $this->userModel->findById($userId);
    }
}
