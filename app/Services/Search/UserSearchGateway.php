<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/** Read-only adapter for user-scoped search operations. */
final class UserSearchGateway
{
    public function __construct(
        private Database $db,
        private LoggerInterface $logger,
        private AdminSearchGateway $reader
    ) {}

    public function searchTransactions(SearchQuery $query): SearchResult
    {
        return $this->reader->quickSearchTransactions($query);
    }

    public function searchTickets(SearchQuery $query): SearchResult
    {
        return $this->reader->quickSearchTickets($query);
    }

    public function searchAds(SearchQuery $query): SearchResult
    {
        return $this->reader->quickSearchAds($query);
    }

    public function searchTasks(SearchQuery $query): SearchResult
    {
        return $this->reader->searchRegistered('tasks', $query);
    }

    public function searchVitrines(SearchQuery $query): SearchResult
    {
        return $this->reader->searchRegistered('vitrines', $query);
    }
}
