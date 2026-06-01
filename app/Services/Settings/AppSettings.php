<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use Core\Cache;
use App\Contracts\LoggerInterface;
use Core\Database;

class AppSettings
{
    private Setting $model;
    private ?array $runtimeCache = null;

    private const CACHE_KEY = 'system:settings:v2';
    private const CACHE_TTL = 60; // minutes

    private \Core\Cache $cache;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Cache $cache,
        \App\Contracts\LoggerInterface $logger,
        Setting $model
    ) {        $this->cache = $cache;
        $this->logger = $logger;

                $this->model = $model;
        }

    public function load(): array
    {
        if ($this->runtimeCache !== null) {
            return $this->runtimeCache;
        }

        $cachedData = $this->cache->get(self::CACHE_KEY);
        if (is_array($cachedData)) {
            $this->runtimeCache = $cachedData;
            return $cachedData;
        }

        try {
            $rawSettings = $this->model->getAll();
            $parsedSettings = [];

            foreach ($rawSettings as $row) {
                $key = (string)($row->key ?? '');
                if ($key === '') continue;
                $parsedSettings[$key] = $this->castValue($row->value ?? '', (string)($row->type ?? 'string'));
            }

            $this->cache->put(self::CACHE_KEY, $parsedSettings, self::CACHE_TTL);
            $this->runtimeCache = $parsedSettings;

            return $parsedSettings;

        } catch (\Throwable $e) {
            $this->logger->error('settings.load_failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->load();
        return $all[$key] ?? $default;
    }

    public function getByCategory(string $category): array
    {
        return $this->model->getByCategory($category);
    }

    public function find(int $id): ?object
    {
        return $this->model->find($id);
    }

    public function findByKey(string $key): ?object
    {
        return $this->model->findByKey($key);
    }

    public function loadAll(): array
    {
        return $this->load();
    }

    public function clearInstanceCache(): void
    {
        $this->runtimeCache = null;
    }

    private function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = strtolower(trim($type));

        switch ($type) {
            case 'boolean':
            case 'bool':
                if (in_array(strtolower($value), ['false', '0', 'no', 'off', ''], true)) {
                    return false;
                }
                return true;

            case 'integer':
            case 'int':
                return (int) $value;

            case 'float':
            case 'double':
            case 'numeric':
                return (float) $value;

            case 'json':
            case 'array':
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [];

            case 'string':
            default:
                return (string) $value;
        }
    }
}
