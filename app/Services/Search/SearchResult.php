<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * Lightweight DTO for unified search output.
 */
class SearchResult
{
    public function __construct(
        public array $items = [],
        public int $total = 0,
        public array $facets = [],
        public array $raw = []
    ) {}

    public static function fromArray(array $result): self
    {
        if (array_key_exists('items', $result)) {
            return new self(
                $result['items'] ?? [],
                (int)($result['total'] ?? count($result['items'] ?? [])),
                $result['facets'] ?? [],
                $result
            );
        }

        return new self($result, (int)($result['total'] ?? count($result)), [], $result);
    }

    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => $this->total, 'facets' => $this->facets, 'raw' => $this->raw];
    }
}
