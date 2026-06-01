<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * SearchProjectionRepository — مسیر خواندن Read-Model جستجو (CQRS Read Side).
 *
 * همه‌ی جستجوها از این repository روی جدول واحد `search_projections` با
 * MATCH(title, content) AGAINST(... IN BOOLEAN MODE) اجرا می‌شوند:
 *   - بدون LIKE '%...%' و بدون Full Table Scan
 *   - بدون JOIN به ۱۷ جدول live (دانش schema دامنه‌ها از Search خارج شد)
 *   - ownership کاربر مستقیماً با ستون owner_id اعمال می‌شود
 *   - pagination یکپارچه (همان COUNT با SQL_CALC سبک‌تر روی یک جدول ایندکس‌دار)
 *
 * اگر projection هنوز برای یک scope/module آماده نباشد، متد isReady() به
 * Gatewayها اجازه می‌دهد تصمیم dual-read بگیرند (fallback به جدول live).
 */
final class SearchProjectionRepository
{
    private const TABLE = 'search_projections';

    private Database $db;
    private LoggerInterface $logger;
    private SchemaInspector $schema;
    public function __construct(
        Database $db,
        LoggerInterface $logger,
        SchemaInspector $schema
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->schema = $schema;

    }

    /**
     * آیا جدول projection وجود دارد و حداقل یک رکورد فعال در این scope دارد؟
     * (مبنای تصمیم dual-read؛ از فعال‌شدن جستجوی خالی جلوگیری می‌کند)
     */
    public function isReady(string $scope, ?string $module = null): bool
    {
        if (!$this->schema->tableExists(self::TABLE)) {
            $this->applyGracefulTimeout();
            return false;
        }

        try {
            $sql = "SELECT 1 FROM " . self::TABLE . " WHERE scope = ? AND is_active = 1";
            $params = [$scope];
            if ($module !== null) {
                $sql .= " AND module = ?";
                $params[] = $module;
            }
            $sql .= " LIMIT 1";
            $isReady = (bool)$this->db->fetchColumn($sql, $params);
            if (!$isReady) {
                $this->applyGracefulTimeout();
            }
            return $isReady;
        } catch (\Throwable $e) {
            $this->logger->warning('search.projection.ready_check_failed', ['error' => $e->getMessage()]);
            $this->applyGracefulTimeout();
            return false;
        }
    }

    /**
     * Calculates Eventual Consistency Lag in seconds.
     * Compares projection freshness and emits alerts if lag > 300s.
     */
    public function getReplicationLag(): int
    {
        try {
            $lastUpdate = $this->db->fetchColumn("SELECT MAX(updated_at) FROM " . self::TABLE);
            if (!$lastUpdate) {
                return 0;
            }
            $lag = time() - strtotime((string)$lastUpdate);
            
            if ($lag > 300) {
                $this->logger->warning('search.projection.high_lag_detected', ['lag_seconds' => $lag]);
            }
            return (int)max(0, $lag);
        } catch (\Throwable $e) {
            return -1;
        }
    }

    /**
     * Circuit Breaker & Timeout for Live Database Fallback
     * Prevents Live Search Queries from crushing the DB when CQRS projection is not ready.
     */
    private function applyGracefulTimeout(int $milliseconds = 2000): void
    {
        try {
            $this->db->query("SET SESSION max_execution_time = " . $milliseconds);
        } catch (\Throwable $e) {
            // Ignored if unsupported by DB driver
        }
    }

    /**
     * جستجوی واحد روی projection.
     *
     * @param array{
     *   scope?:string, module?:string, modules?:array<int,string>,
     *   owner_id?:int, entity_type?:string
     * } $filters
     */
    public function search(string $term, array $filters, int $limit, int $offset): SearchResult
    {
        if (!$this->schema->tableExists(self::TABLE)) {
            return new SearchResult([], 0, ['error' => 'projection_unavailable']);
        }

        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = ['is_active = 1'];
        $params = [];

        if (!empty($filters['scope'])) {
            $where[] = 'scope = ?';
            $params[] = (string)$filters['scope'];
        }

        // فیلتر ماژول (تک یا چندتایی)
        $modules = [];
        if (!empty($filters['modules']) && is_array($filters['modules'])) {
            $modules = array_values(array_filter(array_map('strval', $filters['modules'])));
        } elseif (!empty($filters['module'])) {
            $modules = [(string)$filters['module']];
        }
        if (!empty($modules)) {
            $where[] = 'module IN (' . implode(', ', array_fill(0, count($modules), '?')) . ')';
            foreach ($modules as $m) {
                $params[] = $m;
            }
        }

        if (!empty($filters['entity_type'])) {
            $where[] = 'entity_type = ?';
            $params[] = (string)$filters['entity_type'];
        }

        // Ownership کاربر — حیاتی برای امنیت User Search
        if (isset($filters['owner_id'])) {
            $where[] = 'owner_id = ?';
            $params[] = (int)$filters['owner_id'];
        }

        // عبارت جستجو: FULLTEXT BOOLEAN MODE با fallback به ref برای تطبیق دقیق
        $relevanceSelect = '0 AS relevance';
        $term = trim($term);
        if ($term !== '') {
            $boolean = $this->toBooleanQuery($term);
            if ($boolean !== '') {
                // ترکیب FULLTEXT روی متن + تطبیق دقیق روی ref (کدهای پیگیری/تراکنش)
                $where[] = "(MATCH(title, content) AGAINST(? IN BOOLEAN MODE) OR ref = ?)";
                $params[] = $boolean;
                $params[] = mb_substr($term, 0, 190);

                $relevanceSelect = 'MATCH(title, content) AGAINST(? IN BOOLEAN MODE) AS relevance';
                // پارامتر relevance باید قبل از WHERE قرار گیرد؛ پایین‌تر مدیریت می‌شود.
            }
        }

        $whereSql = implode(' AND ', $where);

        try {
            $total = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM " . self::TABLE . " WHERE {$whereSql}",
                $params
            );

            // مرتب‌سازی: ابتدا relevance (در صورت وجود term)، سپس جدیدترین
            if (str_contains($relevanceSelect, 'MATCH')) {
                // پارامتر اضافه‌ی relevance در ابتدای لیست
                $selectParams = array_merge([$this->toBooleanQuery($term)], $params);
                $items = $this->db->fetchAll(
                    "SELECT id, entity_type, entity_id, owner_id, scope, module, ref, title, content, metadata, updated_at, {$relevanceSelect}
                     FROM " . self::TABLE . "
                     WHERE {$whereSql}
                     ORDER BY relevance DESC, updated_at DESC
                     LIMIT " . (int)$limit . " OFFSET " . (int)$offset,
                    $selectParams
                );
            } else {
                $items = $this->db->fetchAll(
                    "SELECT id, entity_type, entity_id, owner_id, scope, module, ref, title, content, metadata, updated_at
                     FROM " . self::TABLE . "
                     WHERE {$whereSql}
                     ORDER BY updated_at DESC
                     LIMIT " . (int)$limit . " OFFSET " . (int)$offset,
                    $params
                );
            }

            return new SearchResult($this->hydrate($items), $total, ['source' => 'projection']);
        } catch (\Throwable $e) {
            $this->logger->warning('search.projection.query_failed', [
                'error' => $e->getMessage(),
                'scope' => $filters['scope'] ?? null,
            ]);
            return new SearchResult([], 0, ['error' => $e->getMessage()]);
        }
    }

    /**
     * تبدیل metadata (JSON) به فیلدهای قابل‌نمایش روی هر آیتم.
     *
     * @param array<int,object> $rows
     * @return array<int,array<string,mixed>>
     */
    private function hydrate(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $meta = [];
            if (!empty($row->metadata)) {
                $decoded = json_decode((string)$row->metadata, true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            // Preserve all meta by nesting it inside 'metadata' key, instead of flattening it to prevent key collision
            $out[] = [
                'id'          => (int)$row->entity_id,
                'entity_type' => (string)$row->entity_type,
                'module'      => $row->module ?? null,
                'ref'         => $row->ref ?? null,
                'title'       => $row->title ?? null,
                'updated_at'  => $row->updated_at ?? null,
                'metadata'    => $meta,
            ];
        }
        return $out;
    }

    /**
     * تبدیل عبارت کاربر به کوئری امنِ BOOLEAN MODE (هر واژه با + و * برای prefix match).
     */
    private function toBooleanQuery(string $term): string
    {
        $term = mb_substr($term, 0, 100);
        // حذف کاراکترهای کنترلی BOOLEAN MODE برای جلوگیری از خطای syntax/سوءاستفاده
        $clean = preg_replace('/[+\-><\(\)~*\"@]+/u', ' ', $term);
        $words = preg_split('/\s+/u', trim((string)$clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $parts = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 1) {
                continue;
            }
            // هر واژه الزامی (+) و با امکان prefix-match (*)
            $parts[] = '+' . $w . '*';
        }

        return implode(' ', $parts);
    }
}
