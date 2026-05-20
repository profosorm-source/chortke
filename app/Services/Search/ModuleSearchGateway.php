<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/** Read-only adapter for module search reads. */
final class ModuleSearchGateway
{
    public function __construct(
        private Database $db,
        private LoggerInterface $logger,
        private AdminSearchGateway $reader
    ) {}

    public function searchSocialTasks(array $filters, int $limit, int $offset): array
    {
        $filters['type'] = $filters['type'] ?? 'social_task';
        return $this->reader->searchAdTasks((string)($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset);
    }

    public function searchInfluencers(array $filters, int $limit, int $offset): array
    {
        return $this->reader->searchInfluencersAdmin((string)($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset);
    }

    public function searchVitrine(array $filters, int $limit, int $offset): array
    {
        return $this->searchVitrineRead((string)($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset);
    }

    private function searchVitrineRead(string $q, array $filters, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $params = [];
        $where = ['vl.deleted_at IS NULL'];

        if ($q !== '') {
            $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim(mb_substr($q, 0, 100))) . '%';
            $where[] = "(vl.title LIKE :q1 ESCAPE '\\\\' OR vl.description LIKE :q2 ESCAPE '\\\\' OR vl.username LIKE :q3 ESCAPE '\\\\')";
            $params['q1'] = $needle;
            $params['q2'] = $needle;
            $params['q3'] = $needle;
        }

        foreach (['category', 'platform', 'listing_type', 'status'] as $filter) {
            if (!empty($filters[$filter])) {
                $key = 'f_' . $filter;
                $where[] = "vl.{$filter} = :{$key}";
                $params[$key] = (string)$filters[$filter];
            }
        }

        if (!empty($filters['min_price'])) {
            $where[] = 'vl.price_usdt >= :min_price';
            $params['min_price'] = (float)$filters['min_price'];
        }
        if (!empty($filters['max_price'])) {
            $where[] = 'vl.price_usdt <= :max_price';
            $params['max_price'] = (float)$filters['max_price'];
        }

        $whereSql = implode(' AND ', $where);
        try {
            if (!$this->tableExists('vitrine_listings')) {
                return ['items' => [], 'total' => 0, 'facets' => []];
            }
            $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM vitrine_listings vl WHERE {$whereSql}", $params);
            $items = $this->db->fetchAll("SELECT vl.* FROM vitrine_listings vl WHERE {$whereSql} ORDER BY vl.created_at DESC LIMIT {$limit} OFFSET {$offset}", $params);
            return ['items' => $items, 'total' => $total, 'facets' => []];
        } catch (\Throwable $e) {
            $this->logger->warning('search.vitrine_failed', ['error' => $e->getMessage()]);
            return ['items' => [], 'total' => 0, 'facets' => []];
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool)$this->db->fetchColumn('SHOW TABLES LIKE ?', [$table]);
        } catch (\Throwable) {
            return false;
        }
    }
}
