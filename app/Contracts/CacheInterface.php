<?php
namespace App\Contracts;

interface CacheInterface
{
    public function get(string $key, $default = null);
    public function set(string $key, $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function increment(string $key, int $step = 1): int;
    public function getOrSet(string $key, callable $callback, ?int $ttl = null);
    public function has(string $key): bool;
    public function ttl(string $key): int;
    public function flush(): bool;
    public function redis(): ?\Redis;
    public function driver(): string;
    public function tags(array $tags);
}
