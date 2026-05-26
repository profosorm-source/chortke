<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * SearchQuery - شیء مقدار برای یکپارچه‌سازی پارامترهای جستجو، فیلتر و مرتب‌سازی
 */
final class SearchQuery
{
    public function __construct(
        private ?string $term = null,
        private array $filters = [],
        private int $limit = 50,
        private int $offset = 0,
        private string $sort = 'created_at DESC'
    ) {}

    public function getTerm(): ?string { return $this->term; }
    public function getFilters(): array { return $this->filters; }
    public function getLimit(): int { return $this->limit; }
    public function getOffset(): int { return $this->offset; }
    public function getSort(): string { return $this->sort; }

    /**
     * ساخت سریع کوئری از ریکوئست
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['q'] ?? $data['term'] ?? null,
            $data['filters'] ?? [],
            (int)($data['limit'] ?? 50),
            (int)($data['offset'] ?? 0),
            (string)($data['sort'] ?? 'created_at DESC')
        );
    }
}