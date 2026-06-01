<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * SearchQuery - شیء مقدار برای یکپارچه‌سازی پارامترهای جستجو، فیلتر و مرتب‌سازی
 */
final class SearchQuery
{
    private ?string $term;
    private array $filters;
    private int $limit;
    private int $offset;
    private string $sort;
    public function __construct(
        ?string $term = null,
        array $filters = [],
        int $limit = 50,
        int $offset = 0,
        string $sort = 'created_at DESC'
    ) {        $this->term = $term;
        $this->filters = $filters;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->sort = $sort;
}

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