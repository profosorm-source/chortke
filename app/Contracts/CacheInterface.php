<?php
namespace App\Contracts;

interface CacheInterface
{
    public function get(string $key, $default = null): mixed;
    public function set(string $key, mixed $value, ?int $ttlSeconds = null): bool;
    public function delete(string $key): bool;
    public function increment(string $key, int $step = 1): int;
    public function decrement(string $key, int $step = 1): int;
    public function getOrSet(string $key, callable $callback, ?int $ttlSeconds = null): mixed;
    public function remember(string $key, ?int $ttlSeconds, \Closure $callback): mixed;
    public function has(string $key): bool;
    public function ttl(string $key): int;
    public function flush(): bool;
    public function driver(): string;
    public function tags(array $tags): self;
}
