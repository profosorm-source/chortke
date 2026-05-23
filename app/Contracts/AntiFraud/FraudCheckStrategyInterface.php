<?php

declare(strict_types=1);

namespace App\Contracts\AntiFraud;

interface FraudCheckStrategyInterface
{
    /**
     * Unified strategy check runner.
     *
     * @param int $userId The user initiating the action.
     * @param string $action The specific action being performed (e.g. 'auth.login').
     * @param array $context Contextual details for processing the action.
     * @return array Raw check results compiled by this specific strategy.
     */
    public function check(int $userId, string $action, array $context): array;
}
