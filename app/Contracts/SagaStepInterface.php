<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Interface for Class-based Saga Steps.
 * Enables serialization and background recovery of stalled sagas.
 */
interface SagaStepInterface
{
    /**
     * Get the unique name of the step for logging and state tracking.
     */
    public function getName(): string;

    /**
     * Execute the step.
     * 
     * @param mixed $payload The input payload or result of the previous step.
     * @return mixed The result to pass to the next step.
     * @throws \Throwable If the step fails.
     */
    public function execute(mixed $payload): mixed;

    /**
     * Compensate (rollback) the step if a subsequent step fails.
     * 
     * @param mixed $payload The input payload or result of the previous step.
     * @param mixed $result The result returned by this step's execute() method (if successful).
     * @param \Throwable $originalError The error that caused the saga to fail.
     */
    public function compensate(mixed $payload, mixed $result, \Throwable $originalError): void;
}
