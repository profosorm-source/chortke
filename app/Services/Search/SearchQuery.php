<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * Lightweight DTO for unified search input.
 */
class SearchQuery
{
    public function __construct(
        public string $q = '',
        public array $filters = [],
        public int $limit = 20,
        public int $offset = 0,
        public array $sort = [],
        public ?int $actorId = null,
        public string $scope = 'admin'
    ) {
        $this->q = trim(mb_substr($this->q, 0, 100));
        $this->limit = max(1, min(100, $this->limit));
        $this->offset = max(0, $this->offset);
        $this->scope = strtolower(trim($this->scope));
    }
}
