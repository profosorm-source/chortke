<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\CustomTaskService;
use App\Services\TicketService;
use App\Services\WalletService;

/** Explicit adapter for user-scoped search reads. */
final class UserSearchGateway
{
    public function __construct(
        private WalletService $walletService,
        private TicketService $ticketService,
        private CustomTaskService $customTaskService
    ) {}

    public function searchTransactions(string $q, int $userId, int $limit): array
    {
        return $this->walletService->quickSearchTransactions($q, $userId, $limit);
    }

    public function searchTickets(string $q, int $userId, int $limit): array
    {
        return $this->ticketService->quickSearchTickets($q, $userId, $limit);
    }

    public function searchAds(string $q, int $userId, int $limit): array
    {
        return $this->customTaskService->quickSearchAds($q, $userId, $limit);
    }

    public function searchTasks(string $q, int $userId, int $limit): array
    {
        return $this->customTaskService->quickSearchSubmissions($q, $userId, $limit);
    }
}
