<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * SearchResult - ساختار یکپارچه خروجی تمامی جستجوها
 */
final class SearchResult
{
    private array $items;
    private int $total;
    private array $metadata;
    public function __construct(
        array $items,
        int $total,
        array $metadata = []
    ) {        $this->items = $items;
        $this->total = $total;
        $this->metadata = $metadata;
}

    public function getItems(): array { return $this->items; }
    public function getTotal(): int { return $this->total; }
    public function getMetadata(): array { return $this->metadata; }
    public function toArray(): array { return ['items' => $this->items, 'total' => $this->total, 'metadata' => $this->metadata]; }
}