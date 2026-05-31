<?php

declare(strict_types=1);

namespace App\Services;

class PredictionService
{
    public function __construct() {}

    public function placeBet(int $userId, int $gameId, string $prediction, float $amount, ?string $idempotencyKey = null): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Prediction\PlaceBetJob::class);
        return $job->handle($userId, $gameId, $prediction, $amount, $idempotencyKey);
    }

    public function settleGame(int $gameId, string $result, int $adminId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Prediction\SettleGameJob::class);
        return $job->handle($gameId, $result, $adminId);
    }

    public function cancelGame(int $gameId, int $adminId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Prediction\CancelGameJob::class);
        return $job->handle($gameId, $adminId);
    }
}
