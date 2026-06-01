<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use App\Contracts\SearchProviderInterface;
use App\Contracts\SearchServiceInterface;
use Core\RateLimiter;

/**
 * SearchOrchestrator - هماهنگ‌کننده نهایی جستجو
 */
class SearchOrchestrator implements SearchServiceInterface
{
    /** @var SearchProviderInterface[] */
    private array $providers = [];

    private \App\Contracts\LoggerInterface $logger;
    private ?RateLimiter $rateLimiter;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        ?RateLimiter $rateLimiter = null
    ) {        $this->logger = $logger;
        $this->rateLimiter = $rateLimiter;

            }

    public function registerProvider(SearchProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    private function allowSearch(string $scope, ?int $actorId = null, int $max = 60, int $minutes = 1): bool
    {
        if (!$this->rateLimiter) {
            return true;
        }

        $identity = $actorId !== null && $actorId > 0
            ? (string)$actorId
            : (function_exists('get_client_ip') ? get_client_ip() : 'unknown');

        $key = 'search:' . $scope . ':' . $identity;
        $allowed = $this->rateLimiter->attempt($key, $max, $minutes, false);
        if (!$allowed) {
            $this->logger->warning('search.rate_limited', ['scope' => $scope, 'actor_id' => $actorId, 'key' => $key]);
        }

        return $allowed;
    }

    public function searchQuery(SearchQuery $query): SearchResult
    {
        $scope = (string)($query->getFilters()['scope'] ?? 'unknown');
        $actorId = (int)($query->getFilters()['user_id'] ?? 0);

        if (!$this->allowSearch($scope, $actorId > 0 ? $actorId : null)) {
            return new SearchResult([], 0, ['rate_limited' => true]);
        }

        foreach ($this->providers as $provider) {
            if ($provider->supports($scope)) {
                return $provider->search($query);
            }
        }

        return new SearchResult([], 0, ['error' => 'No search provider found for scope: ' . $scope]);
    }

    public function searchAdmin(SearchQuery $query): array
    {
        /** @var AdminSearchProvider|null $provider */
        $provider = $this->findProvider(AdminSearchProvider::class);
        return $provider ? $provider->searchAdmin($query) : ['items' => [], 'total' => 0];
    }

    public function searchUser(SearchQuery $query, int $userId): array
    {
        /** @var UserSearchProvider|null $provider */
        $provider = $this->findProvider(UserSearchProvider::class);
        return $provider ? $provider->searchUser($query, $userId) : ['items' => [], 'total' => 0];
    }

    public function searchModules($modules, SearchQuery $query): array
    {
        /** @var ModuleSearchProvider|null $provider */
        $provider = $this->findProvider(ModuleSearchProvider::class);
        return $provider ? $provider->searchModules($modules, $query) : [];
    }

    public function searchAdminModule(string $module, SearchQuery $query): array
    {
        /** @var AdminSearchProvider|null $provider */
        $provider = $this->findProvider(AdminSearchProvider::class);
        return $provider ? $provider->searchRegisteredModule($module, $query) : ['items' => [], 'total' => 0];
    }

    public function invalidateModuleCache(string $module): void
    {
        foreach ($this->providers as $provider) {
            if (method_exists($provider, 'invalidateModuleCache')) {
                $provider->invalidateModuleCache($module);
            }
        }
    }

    public function registeredAdminModules(): array
    {
        /** @var AdminSearchProvider|null $provider */
        $provider = $this->findProvider(AdminSearchProvider::class);
        return $provider ? $provider->registeredModules() : [];
    }

    public function __call(string $method, array $parameters)
    {
        foreach ($this->providers as $provider) {
            if (method_exists($provider, $method)) {
                return $provider->{$method}(...$parameters);
            }
        }

        throw new \BadMethodCallException("Method {$method} does not exist in SearchOrchestrator or its providers.");
    }

    private function findProvider(string $class): ?SearchProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof $class) {
                return $provider;
            }
        }

        return null;
    }
}
