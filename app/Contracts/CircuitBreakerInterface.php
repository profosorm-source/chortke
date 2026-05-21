<?php

declare(strict_types=1);

namespace App\Contracts;

interface CircuitBreakerInterface
{
    /**
     * Executes an operation behind the circuit breaker.
     *
     * @param string $serviceName
     * @param callable $operation
     * @return mixed
     * @throws \Throwable
     */
    public function call(string $serviceName, callable $operation);

    /**
     * Returns true when the circuit for the given service is open.
     */
    public function isOpen(string $serviceName): bool;
}
