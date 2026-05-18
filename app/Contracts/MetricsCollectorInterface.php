<?php

declare(strict_types=1);

namespace App\Contracts;

interface MetricsCollectorInterface
{
    public function increment(string $metric, array $tags = []): void;
    public function gauge(string $metric, float $value, array $tags = []): void;
    public function timing(string $metric, float $seconds, array $tags = []): void;
}
