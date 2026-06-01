<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * Read-only adapter for admin search operations.
 * Uses direct table search with schema-aware fallbacks.
 */
final class AdminSearchGateway
{
    /** @var array<string, array{table:string, alias:string, columns:array<int,string>, joins?:string, filters?:array<int,string>, order?:string, deleted?:string|null}> */
    private array $registry = [
        'bank_cards' => ['table' => 'bank_cards', 'alias' => 'bc', 'columns' => ['card_number', 'sheba', 'bank_name', 'status'], 'joins' => 'LEFT JOIN users u ON u.id = bc.user_id', 'filters' => ['status', 'user_id'], 'order' => 'bc.created_at DESC', 'deleted' => 'bc.deleted_at IS NULL'],
        'kyc' => ['table' => 'kyc_verifications', 'alias' => 'kyc', 'columns' => ['national_id', 'status', 'rejection_reason'], 'joins' => 'LEFT JOIN users u ON u.id = kyc.user_id', 'filters' => ['status', 'user_id'], 'order' => 'kyc.created_at DESC'],
        'manual_deposits' => ['table' => 'manual_deposits', 'alias' => 'md', 'columns' => ['tracking_code', 'status', 'transaction_id'], 'joins' => 'LEFT JOIN users u ON u.id = md.user_id', 'filters' => ['status', 'user_id'], 'order' => 'md.created_at DESC'],
        'crypto_deposits' => ['table' => 'crypto_deposits', 'alias' => 'cd', 'columns' => ['tx_hash', 'network', 'verification_status', 'transaction_id'], 'joins' => 'LEFT JOIN users u ON u.id = cd.user_id', 'filters' => ['verification_status', 'status', 'network', 'user_id'], 'order' => 'cd.created_at DESC'],
        'social_accounts' => ['table' => 'social_accounts', 'alias' => 'sa', 'columns' => ['username', 'platform', 'status'], 'joins' => 'LEFT JOIN users u ON u.id = sa.user_id', 'filters' => ['platform', 'status', 'user_id'], 'order' => 'sa.created_at DESC'],
        'data_exports' => ['table' => 'data_exports', 'alias' => 'de', 'columns' => ['type', 'status', 'file_path'], 'joins' => 'LEFT JOIN users u ON u.id = de.user_id', 'filters' => ['type', 'status', 'user_id'], 'order' => 'de.created_at DESC'],
        'account_deletion_logs' => ['table' => 'account_deletion_logs', 'alias' => 'adl', 'columns' => ['reason', 'status', 'admin_note'], 'joins' => 'LEFT JOIN users u ON u.id = adl.user_id', 'filters' => ['status', 'user_id'], 'order' => 'adl.created_at DESC'],
        'investment' => ['table' => 'investments', 'alias' => 'i', 'columns' => ['status'], 'joins' => 'LEFT JOIN users u ON u.id = i.user_id', 'filters' => ['status', 'user_id'], 'order' => 'i.created_at DESC', 'deleted' => 'i.deleted_at IS NULL'],
        'bug_report' => ['table' => 'bug_reports', 'alias' => 'br', 'columns' => ['subject', 'description', 'status'], 'joins' => 'LEFT JOIN users u ON u.id = br.user_id', 'filters' => ['status', 'user_id'], 'order' => 'br.created_at DESC'],
        'escrow' => ['table' => 'escrows', 'alias' => 'es', 'columns' => ['status', 'transaction_id'], 'joins' => 'LEFT JOIN users u ON u.id = es.buyer_id', 'filters' => ['status', 'buyer_id', 'seller_id'], 'order' => 'es.created_at DESC'],
        'prediction' => ['table' => 'prediction_games', 'alias' => 'pg', 'columns' => ['title', 'team_home', 'team_away', 'sport_type', 'status'], 'filters' => ['status', 'sport_type'], 'order' => 'pg.created_at DESC', 'deleted' => 'pg.deleted_at IS NULL'],
        'lottery' => ['table' => 'lottery_rounds', 'alias' => 'lr', 'columns' => ['status', 'type'], 'filters' => ['status', 'type'], 'order' => 'lr.created_at DESC'],
        'coupons' => ['table' => 'coupons', 'alias' => 'c', 'columns' => ['code', 'type', 'applicable_to'], 'filters' => ['active', 'type'], 'order' => 'c.created_at DESC', 'deleted' => 'c.deleted_at IS NULL'],
        'direct_messages' => ['table' => 'direct_messages', 'alias' => 'dm', 'columns' => ['message'], 'joins' => 'LEFT JOIN users s ON s.id = dm.sender_id LEFT JOIN users r ON r.id = dm.recipient_id', 'filters' => ['sender_id', 'recipient_id'], 'order' => 'dm.created_at DESC'],
        'content' => ['table' => 'content_submissions', 'alias' => 'cs', 'columns' => ['title', 'description', 'video_url', 'platform', 'status'], 'joins' => 'LEFT JOIN users u ON u.id = cs.user_id', 'filters' => ['status', 'platform', 'user_id'], 'order' => 'cs.created_at DESC', 'deleted' => 'cs.is_deleted = 0'],
    ];

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

    public function quickSearchUsers(SearchQuery $query): SearchResult
    {
        return $this->searchTable($query, 'users', 'u', ['full_name', 'email', 'mobile', 'username'], '', ['status']);
    }

    public function quickSearchTransactions(SearchQuery $query): SearchResult
    {
        return $this->searchTable($query, 'transactions', 't', ['transaction_id', 'description', 'gateway_transaction_id', 'ref_id'], 'LEFT JOIN users u ON u.id = t.user_id', ['status', 'type', 'currency', 'user_id']);
    }

    public function quickSearchTickets(SearchQuery $query): SearchResult
    {
        return $this->searchTable($query, 'tickets', 't', ['subject', 'ticket_id', 'status', 'priority'], 'LEFT JOIN users u ON u.id = t.user_id LEFT JOIN ticket_categories tc ON tc.id = t.category_id', ['status', 'priority', 'category_id', 'assigned_to', 'user_id']);
    }

    public function quickSearchWithdrawals(SearchQuery $query): SearchResult
    {
        return $this->searchTable($query, 'withdrawals', 'w', ['tracking_code', 'transaction_id', 'status', 'currency'], 'LEFT JOIN users u ON u.id = w.user_id LEFT JOIN bank_cards c ON c.id = w.card_id', ['status', 'currency', 'user_id']);
    }

    public function quickSearchDeposits(SearchQuery $query): array
    {
        $manual = $this->searchRegistered('manual_deposits', $query)->getItems();
        $crypto = $this->searchRegistered('crypto_deposits', $query)->getItems();
        $results = array_merge($manual, $crypto);
        usort($results, fn($a, $b) => strtotime((string)($b->created_at ?? '')) <=> strtotime((string)($a->created_at ?? '')));
        return array_slice($results, 0, $query->getLimit());
    }

    public function quickSearchAds(SearchQuery $query): SearchResult
    {
        return $this->searchTable($query, 'ads', 'a', ['title', 'description', 'keyword'], 'LEFT JOIN users u ON u.id = a.user_id', ['type', 'status', 'user_id']);
    }

    public function searchRegistered(string $module, SearchQuery|string $queryOrTerm, array $filters = [], int $limit = 20, int $offset = 0): SearchResult
    {
        $module = strtolower(trim($module));
        if (!isset($this->registry[$module])) {
            return new SearchResult([], 0);
        }

        $query = $queryOrTerm instanceof SearchQuery
            ? $queryOrTerm
            : new SearchQuery((string)$queryOrTerm, $filters, $limit, $offset);

        $def = $this->registry[$module];
        return $this->searchTable(
            $query,
            $def['table'],
            $def['alias'],
            $def['columns'],
            $def['joins'] ?? '',
            $def['filters'] ?? [],
            $def['deleted'] ?? null,
            $def['order'] ?? null
        );
    }

    public function registeredModules(): array
    {
        return array_keys($this->registry);
    }

    public function searchBanners(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable(new SearchQuery($q, $filters, $limit, $offset), 'ads', 'a', ['title', 'description', 'keyword'], '', ['status', 'type', 'user_id'], "a.type = 'banner'")->toArray();
    }

    public function searchContent(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchRegistered('content', new SearchQuery($q, $filters, $limit, $offset))->toArray();
    }

    public function searchContentExport(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchContent($q, $filters, $limit, $offset);
    }

    public function searchTokens(string $q, array $filters, int $limit, int $offset): array
    {
        return (new SearchResult([], 0, ['warning' => 'crypto_tokens table is not available in current schema']))->toArray();
    }

    public function searchEmails(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable(new SearchQuery($q, $filters, $limit, $offset), 'email_queue', 'eq', ['to_email', 'subject', 'status'], '', ['status', 'user_id', 'priority'])->toArray();
    }

    public function searchAdTasks(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable(new SearchQuery($q, $filters, $limit, $offset), 'ads', 'a', ['title', 'description', 'keyword'], 'LEFT JOIN users u ON u.id = a.user_id', ['status', 'type', 'user_id'])->toArray();
    }

    public function searchInvestments(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchRegistered('investment', new SearchQuery($q, $filters, $limit, $offset))->toArray();
    }

    public function searchTicketsAdmin(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->quickSearchTickets(new SearchQuery($q, $filters, $limit, $offset))->toArray();
    }

    public function searchInfluencersAdmin(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable(new SearchQuery($q, $filters, $limit, $offset), 'influencer_profiles', 'ip', ['username', 'bio', 'page_url', 'platform', 'status'], 'LEFT JOIN users u ON u.id = ip.user_id', ['status', 'platform', 'category', 'user_id'], 'ip.deleted_at IS NULL')->toArray();
    }

    public function quickSearchSubmissions(string $q, ?int $userId, int $limit): array
    {
        $filters = $userId ? ['user_id' => $userId] : [];
        return $this->searchTable(new SearchQuery($q, $filters, $limit, 0), 'custom_task_submissions', 'cts', ['proof_url', 'proof_text', 'status'], '', ['status', 'user_id', 'task_id'])->toArray();
    }

    private function searchTable(
        SearchQuery $query,
        string $table,
        string $alias,
        array $columns,
        string $joins = '',
        array $allowedFilters = [],
        ?string $fixedWhere = null,
        ?string $defaultOrder = null
    ): SearchResult {
        if (!$this->schema->tableExists($table)) {
            return new SearchResult([], 0, ['warning' => "table {$table} not found"]);
        }

        $existingColumns = $this->schema->getColumns($table);
        $searchableColumns = array_values(array_filter($columns, fn(string $col) => in_array($col, $existingColumns, true)));

        $limit = max(1, min(200, $query->getLimit()));
        $offset = max(0, $query->getOffset());
        $params = [];
        $where = ['1=1'];

        if ($fixedWhere) {
            $where[] = $fixedWhere;
        }

        $likeWhere = $this->buildSearchWhere($table, $alias, $searchableColumns, (string)($query->getTerm() ?? ''), $params);
        if ($likeWhere !== '') {
            $where[] = $likeWhere;
        }

        $this->applyAllowedFilters($alias, $table, $query->getFilters(), $allowedFilters, $where, $params);

        $whereSql = implode(' AND ', $where);
        $orderBy = $this->safeOrderBy($query->getSort(), $alias, $existingColumns, $defaultOrder ?? "{$alias}.created_at DESC");
        $select = "{$alias}.*";

        try {
            $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM {$table} {$alias} {$joins} WHERE {$whereSql}", $params);
            $items = $this->db->fetchAll("SELECT {$select} FROM {$table} {$alias} {$joins} WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}", $params);
            return new SearchResult($items, $total);
        } catch (\Throwable $e) {
            $this->logger->warning('search.read_query_failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
            return new SearchResult([], 0, ['error' => $e->getMessage()]);
        }
    }

    private function buildSearchWhere(string $table, string $alias, array $columns, string $q, array &$params): string
    {
        $q = trim($q);
        if ($q === '' || empty($columns)) {
            return '';
        }

        if ($this->schema->hasFullTextIndex($table, $columns)) {
            $boolean = $this->toBooleanQuery($q);
            if ($boolean !== '') {
                $params['ft_term'] = $boolean;
                $qualified = implode(', ', $columns);
                return "MATCH({$qualified}) AGAINST(:ft_term IN BOOLEAN MODE)";
            }
        }

        $conditions = [];
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100));
        foreach ($columns as $index => $column) {
            $param = "q_{$index}";
            $conditions[] = "{$alias}.{$column} LIKE :{$param} ESCAPE '\\\\'";
            $params[$param] = '%' . $escaped . '%';
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    private function applyAllowedFilters(string $alias, string $table, array $queryFilters, array $allowedFilters, array &$where, array &$params): void
    {
        $existingColumns = $this->schema->getColumns($table);
        foreach ($queryFilters as $key => $value) {
            if (!in_array($key, $allowedFilters, true)) {
                continue;
            }
            if (!in_array($key, $existingColumns, true)) {
                continue;
            }
            $paramName = 'f_' . str_replace('.', '_', $key);
            $where[] = "{$alias}.{$key} = :{$paramName}";
            $params[$paramName] = $value;
        }
    }

    private function safeOrderBy(?string $sort, string $alias, array $existingColumns, string $defaultOrder): string
    {
        if (empty($sort)) {
            return $defaultOrder;
        }

        $sort = preg_replace('/[^a-zA-Z0-9_\.\s]/', '', $sort) ?: '';
        if ($sort === '') {
            return $defaultOrder;
        }

        [$column, $direction] = array_pad(preg_split('/\s+/', trim($sort)) ?: [], 2, 'DESC');
        $column = str_replace($alias . '.', '', $column);
        if (!in_array($column, $existingColumns, true)) {
            return $defaultOrder;
        }

        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        return "{$alias}.{$column} {$direction}";
    }



    private function toBooleanQuery(string $keyword): string
    {
        $terms = preg_split('/\s+/u', trim($keyword)) ?: [];
        $terms = array_values(array_filter(array_map(
            fn($term) => preg_replace('/[^\pL\pN_\-]/u', '', (string)$term),
            $terms
        )));

        if (empty($terms)) {
            return '';
        }

        return implode(' ', array_map(fn($term) => '+' . $term . '*', $terms));
    }
}
