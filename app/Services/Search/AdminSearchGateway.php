<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * Read-only adapter for admin search operations.
 *
 * Search نباید از سرویس‌های business/write مسیر بگیرد؛ این gateway فقط queryهای read-oriented
 * سبک اجرا می‌کند تا DI سرویس‌های سنگین مثل Wallet/Withdrawal/Investment وارد مسیر search نشوند.
 */
final class AdminSearchGateway
{
    /** @var array<string, array{table:string, alias:string, columns:array, joins?:string, filters?:array, order?:string, deleted?:string|null, type?:string|null}> */
        'bank_cards' => ['table' => 'bank_cards', 'alias' => 'bc', 'columns' => ['card_number', 'sheba', 'bank_name', 'status'], 'joins' => 'LEFT JOIN users u ON u.id = bc.user_id', 'filters' => ['status', 'user_id'], 'order' => 'bc.created_at DESC'],
        'kyc' => ['table' => 'kyc_verifications', 'alias' => 'kyc', 'columns' => ['national_id', 'status', 'rejection_reason'], 'joins' => 'LEFT JOIN users u ON u.id = kyc.user_id', 'filters' => ['status', 'user_id'], 'order' => 'kyc.created_at DESC'],
        'manual_deposits' => ['table' => 'manual_deposits', 'alias' => 'd', 'columns' => ['tracking_code', 'status', 'transaction_id'], 'joins' => 'LEFT JOIN users u ON u.id = d.user_id LEFT JOIN bank_cards c ON c.id = d.card_id', 'filters' => ['status', 'user_id'], 'order' => 'd.created_at DESC'],
        'crypto_deposits' => ['table' => 'crypto_deposits', 'alias' => 'd', 'columns' => ['tx_hash', 'network', 'verification_status', 'transaction_id'], 'joins' => 'LEFT JOIN users u ON u.id = d.user_id', 'filters' => ['verification_status', 'status', 'network', 'user_id'], 'order' => 'd.created_at DESC'],
        'withdrawals' => ['table' => 'withdrawals', 'alias' => 'w', 'columns' => ['tracking_code', 'transaction_id', 'status', 'currency'], 'joins' => 'LEFT JOIN users u ON u.id = w.user_id LEFT JOIN bank_cards c ON c.id = w.card_id', 'filters' => ['status', 'currency', 'user_id'], 'order' => 'w.created_at DESC'],
        'transactions' => ['table' => 'transactions', 'alias' => 't', 'columns' => ['transaction_id', 'description', 'gateway_transaction_id', 'ref_id', 'idempotency_key'], 'joins' => 'LEFT JOIN users u ON u.id = t.user_id', 'filters' => ['status', 'type', 'currency', 'user_id'], 'order' => 't.created_at DESC'],
        'tickets' => ['table' => 'tickets', 'alias' => 't', 'columns' => ['subject', 'ticket_id', 'status', 'priority'], 'joins' => 'LEFT JOIN users u ON u.id = t.user_id LEFT JOIN ticket_categories tc ON tc.id = t.category_id', 'filters' => ['status', 'priority', 'category_id', 'assigned_to', 'user_id'], 'order' => 't.updated_at DESC'],
        'ads' => ['table' => 'ads', 'alias' => 'a', 'columns' => ['title', 'description', 'keyword', 'type', 'platform'], 'joins' => 'LEFT JOIN users u ON u.id = a.user_id', 'filters' => ['type', 'status', 'task_type', 'platform', 'category', 'user_id'], 'order' => 'a.created_at DESC'],
        'tasks' => ['table' => 'custom_task_submissions', 'alias' => 's', 'columns' => ['status'], 'joins' => 'LEFT JOIN ads a ON a.id = s.task_id LEFT JOIN users u ON u.id = s.worker_id', 'filters' => ['status', 'worker_id', 'user_id'], 'order' => 's.created_at DESC'],
        'vitrines' => ['table' => 'vitrine_listings', 'alias' => 'vl', 'columns' => ['title', 'description', 'username'], 'joins' => 'LEFT JOIN users u ON u.id = vl.user_id', 'filters' => ['status', 'user_id'], 'order' => 'vl.created_at DESC', 'deleted' => 'vl.deleted_at IS NULL'],
        'contents' => ['table' => 'content_submissions', 'alias' => 'cs', 'columns' => ['title', 'description', 'video_url', 'category', 'platform'], 'joins' => 'LEFT JOIN users u ON u.id = cs.user_id', 'filters' => ['status', 'platform', 'category', 'user_id'], 'order' => 'cs.created_at DESC', 'deleted' => 'cs.is_deleted = 0'],
        'user_levels' => ['table' => 'user_level_history', 'alias' => 'ul', 'columns' => ['reason'], 'joins' => 'LEFT JOIN users u ON u.id = ul.user_id', 'filters' => ['old_level', 'new_level', 'user_id'], 'order' => 'ul.created_at DESC'],
        'score_history' => ['table' => 'score_history', 'alias' => 'sh', 'columns' => ['reason', 'source_type'], 'joins' => 'LEFT JOIN users u ON u.id = sh.user_id', 'filters' => ['source_type', 'user_id'], 'order' => 'sh.created_at DESC'],
        'coupons' => ['table' => 'coupons', 'alias' => 'c', 'columns' => ['code', 'name', 'description', 'status'], 'filters' => ['status'], 'order' => 'c.created_at DESC'],
        'referrals' => ['table' => 'referral_commissions', 'alias' => 'r', 'columns' => ['status', 'level'], 'joins' => 'LEFT JOIN users u ON u.id = r.user_id', 'filters' => ['status', 'user_id'], 'order' => 'r.created_at DESC'],
        'lottery' => ['table' => 'lottery_rounds', 'alias' => 'lr', 'columns' => ['title', 'status', 'description'], 'filters' => ['status'], 'order' => 'lr.created_at DESC'],
        'prediction' => ['table' => 'prediction_games', 'alias' => 'pg', 'columns' => ['title', 'description', 'status'], 'filters' => ['status'], 'order' => 'pg.created_at DESC'],
        'direct_messages' => ['table' => 'direct_messages', 'alias' => 'dm', 'columns' => ['message', 'subject', 'status'], 'filters' => ['status', 'sender_id', 'receiver_id', 'user_id'], 'order' => 'dm.created_at DESC'],
        'security_logs' => ['table' => 'security_logs', 'alias' => 'sl', 'columns' => ['event_type', 'message', 'ip_address', 'user_agent'], 'filters' => ['event_type', 'severity', 'user_id'], 'order' => 'sl.created_at DESC'],
        'audit_trail' => ['table' => 'audit_trail', 'alias' => 'at', 'columns' => ['action', 'description', 'ip_address', 'metadata'], 'filters' => ['user_id', 'action'], 'order' => 'at.created_at DESC'],
        'settings' => ['table' => 'system_settings', 'alias' => 's', 'columns' => ['key', 'value', 'description', 'group'], 'filters' => ['group'], 'order' => 's.updated_at DESC'],
        'feature_flags' => ['table' => 'feature_flags', 'alias' => 'ff', 'columns' => ['key', 'name', 'description', 'status'], 'filters' => ['status'], 'order' => 'ff.updated_at DESC'],
        'risk_policies' => ['table' => 'risk_policies', 'alias' => 'rp', 'columns' => ['name', 'description', 'action', 'status'], 'filters' => ['status', 'action'], 'order' => 'rp.updated_at DESC'],
        'notifications' => ['table' => 'notifications', 'alias' => 'n', 'columns' => ['title', 'message', 'type', 'channel'], 'filters' => ['type', 'channel', 'user_id'], 'order' => 'n.created_at DESC'],
        'social_accounts' => ['table' => 'social_accounts', 'alias' => 'sa', 'columns' => ['username', 'platform', 'status'], 'filters' => ['platform', 'status', 'user_id'], 'order' => 'sa.created_at DESC'],
        'data_exports' => ['table' => 'data_exports', 'alias' => 'de', 'columns' => ['type', 'status', 'file_path'], 'filters' => ['type', 'status', 'user_id'], 'order' => 'de.created_at DESC'],
        'account_deletion_logs' => ['table' => 'account_deletion_logs', 'alias' => 'adl', 'columns' => ['reason', 'status', 'admin_note'], 'filters' => ['status', 'user_id'], 'order' => 'adl.created_at DESC'],
    ];

    private static array $columnsCache = [];
    private static array $tableCache = [];

    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    ) {}

    public function quickSearchUsers(string $q, int $limit): array
    {
        return $this->searchTable('users', 'u', ['full_name', 'email', 'mobile', 'username'], $q, [], $limit, 0, '', ['status'], 'u.created_at DESC')['items'];
    }

    public function quickSearchTransactions(string $q, ?int $userId, int $limit): array
    {
        $filters = $userId ? ['user_id' => $userId] : [];
        return $this->searchTable(
            'transactions', 't', ['transaction_id', 'description', 'gateway_transaction_id', 'ref_id', 'idempotency_key'],
            $q, $filters, $limit, 0,
            'LEFT JOIN users u ON u.id = t.user_id', ['status', 'type', 'currency', 'user_id'], 't.created_at DESC'
        )['items'];
    }

    public function quickSearchTickets(string $q, ?int $userId, int $limit): array
    {
        $filters = $userId ? ['user_id' => $userId] : [];
        return $this->searchTable(
            'tickets', 't', ['subject', 'ticket_id', 'status', 'priority'],
            $q, $filters, $limit, 0,
            'LEFT JOIN users u ON u.id = t.user_id LEFT JOIN ticket_categories tc ON tc.id = t.category_id',
            ['status', 'priority', 'category_id', 'assigned_to', 'user_id'], 't.updated_at DESC'
        )['items'];
    }

    public function quickSearchWithdrawals(string $q, int $limit): array
    {
        return $this->searchTable(
            'withdrawals', 'w', ['tracking_code', 'transaction_id', 'status', 'currency'],
            $q, [], $limit, 0,
            'LEFT JOIN users u ON u.id = w.user_id LEFT JOIN bank_cards c ON c.id = w.card_id',
            ['status', 'currency', 'user_id'], 'w.created_at DESC'
        )['items'];
    }

    public function quickSearchDeposits(string $q, int $limit): array
    {
        $manual = $this->searchTable(
            'manual_deposits', 'd', ['tracking_code', 'transaction_id', 'status'],
            $q, [], $limit, 0,
            'LEFT JOIN users u ON u.id = d.user_id LEFT JOIN bank_cards c ON c.id = d.card_id',
            ['status', 'user_id'], 'd.created_at DESC'
        )['items'];

        $crypto = $this->searchTable(
            'crypto_deposits', 'd', ['tx_hash', 'network', 'verification_status', 'transaction_id'],
            $q, [], $limit, 0,
            'LEFT JOIN users u ON u.id = d.user_id',
            ['verification_status', 'network', 'user_id'], 'd.created_at DESC'
        )['items'];

        $results = array_merge($manual, $crypto);
        usort($results, fn($a, $b) => strtotime((string)($b->created_at ?? '')) <=> strtotime((string)($a->created_at ?? '')));
        return array_slice($results, 0, max(1, min(100, $limit)));
    }

    public function quickSearchAds(string $q, ?int $userId, int $limit): array
    {
        $filters = $userId ? ['user_id' => $userId] : [];
        return $this->searchTable('ads', 'a', ['title', 'description', 'keyword', 'type'], $q, $filters, $limit, 0, 'LEFT JOIN users u ON u.id = a.user_id', ['type', 'status', 'user_id'], 'a.created_at DESC')['items'];
    }

    public function searchBanners(string $q, array $filters, int $limit, int $offset): array
    {
        $filters['type'] = $filters['type'] ?? 'banner';
        return $this->searchTable('ads', 'a', ['title', 'description', 'placement', 'status'], $q, $filters, $limit, $offset, 'LEFT JOIN users u ON u.id = a.user_id', ['type', 'status', 'placement', 'banner_type', 'category', 'is_active', 'user_id'], 'a.created_at DESC');
    }

    public function searchContent(string $q, array $filters, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        return $this->searchTable('content_submissions', 'cs', ['title', 'description', 'video_url', 'category', 'platform'], $q, $filters, $limit, $offset, 'LEFT JOIN users u ON u.id = cs.user_id', ['status', 'platform', 'category', 'user_id'], 'cs.created_at DESC', 'cs.is_deleted = 0');
    }

    public function searchContentExport(string $q, array $filters, int $limit, int $offset): array
    {
        $limit = max(1, min(5000, $limit));
        return $this->searchTable('content_submissions', 'cs', ['title', 'description', 'video_url', 'category', 'platform'], $q, $filters, $limit, $offset, 'LEFT JOIN users u ON u.id = cs.user_id', ['status', 'platform', 'category', 'user_id'], 'cs.created_at DESC', 'cs.is_deleted = 0');
    }

    public function searchTokens(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable('api_tokens', 'at', ['name', 'scopes', 'secret_version'], $q, $filters, $limit, $offset, 'LEFT JOIN users u ON u.id = at.user_id', ['revoked', 'user_id', 'secret_version'], 'at.created_at DESC');
    }

    public function searchEmails(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable('email_queue', 'eq', ['to_email', 'subject', 'status', 'error_message'], $q, $filters, $limit, $offset, 'LEFT JOIN users u ON u.id = eq.user_id', ['status', 'priority', 'user_id', 'type'], 'eq.created_at DESC');
    }

    public function searchAdTasks(string $q, array $filters, int $limit, int $offset): array
    {
        $filters['type'] = $filters['type'] ?? 'custom_task';
        return $this->searchTable('ads', 'a', ['title', 'description', 'task_type', 'platform', 'status'], $q, $filters, $limit, $offset, 'LEFT JOIN users u ON u.id = a.user_id', ['type', 'status', 'task_type', 'platform', 'category', 'user_id'], 'a.created_at DESC');
    }

    public function searchInvestments(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable('investments', 'i', ['status'], $q, $filters, $limit, $offset, 'LEFT JOIN users u ON u.id = i.user_id', ['status', 'user_id'], 'i.created_at DESC', 'i.deleted_at IS NULL');
    }

    public function searchTicketsAdmin(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable('tickets', 't', ['subject', 'ticket_id', 'status', 'priority'], $q, $filters, $limit, $offset, 'LEFT JOIN users u ON u.id = t.user_id LEFT JOIN ticket_categories tc ON tc.id = t.category_id', ['status', 'priority', 'category_id', 'assigned_to', 'user_id'], 't.updated_at DESC');
    }

    public function searchInfluencersAdmin(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable('influencer_profiles', 'ip', ['username', 'bio', 'platform', 'category', 'status'], $q, $filters, $limit, $offset, 'LEFT JOIN users u ON u.id = ip.user_id', ['status', 'platform', 'category', 'user_id'], 'ip.created_at DESC', 'ip.deleted_at IS NULL');
    }

    public function quickSearchSubmissions(string $q, ?int $userId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $params = [];
        $where = ['1=1'];

        if ($userId !== null) {
            $where[] = "s.worker_id = :worker_id";
            $params['worker_id'] = $userId;
        }

        if ($q !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $where[] = "a.title LIKE :q ESCAPE '\\\\'";
            $params['q'] = '%' . $escaped . '%';
        }

        $select = $userId !== null 
            ? "s.id, s.status, s.reward_amount, s.created_at, a.title as ad_title"
            : "s.*, a.title as ad_title";

        $whereSql = implode(' AND ', $where);
        try {
            return $this->db->fetchAll("SELECT {$select} FROM custom_task_submissions s JOIN ads a ON a.id = s.task_id WHERE {$whereSql} ORDER BY s.created_at DESC LIMIT {$limit}", $params);
        } catch (\Throwable $e) {
            $this->logger->warning('search.quick_submissions_failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function searchRegistered(string $module, string $q, array $filters, int $limit, int $offset): array
    {
        $module = strtolower(trim($module));
        if (!isset($this->registry[$module])) {
            return ['items' => [], 'total' => 0, 'facets' => []];
        }

        $def = $this->registry[$module];
        return $this->searchTable(
            $def['table'],
            $def['alias'],
            $def['columns'],
            $q,
            $filters,
            $limit,
            $offset,
            $def['joins'] ?? '',
            $def['filters'] ?? [],
            $def['order'] ?? ($def['alias'] . '.created_at DESC'),
            $def['deleted'] ?? null
        );
    }

    public function registeredModules(): array
    {
        return array_keys($this->registry);
    }

    private function searchTable(
        string $table,
        string $alias,
        array $columns,
        string $q,
        array $filters,
        int $limit,
        int $offset,
        string $joins = '',
        array $allowedFilters = [],
        string $orderBy = '',
        ?string $fixedWhere = null
    ): array {
        if (!$this->tableExists($table)) {
            return ['items' => [], 'total' => 0, 'facets' => []];
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $params = [];
        $where = ['1=1'];

        if ($fixedWhere) {
            $where[] = $fixedWhere;
        }

        $likeWhere = $this->buildLikeWhere($table, $alias, $columns, $q, $params, $joins !== '');
        if ($likeWhere !== '') {
            $where[] = $likeWhere;
        }

        $this->applyAllowedFilters($table, $alias, $filters, $allowedFilters, $where, $params);

        $relevanceSelect = '';
        $qClean = trim(mb_substr($q, 0, 100));
        
        if ($qClean !== '') {
            $scoreParts = [];
            $exactKey = 'rel_exact_' . count($params);
            $startKey = 'rel_start_' . count($params);
            $anyKey   = 'rel_any_'   . count($params);
            
            $params[$exactKey] = $qClean;
            $params[$startKey] = $qClean . '%';
            $params[$anyKey]   = '%' . $qClean . '%';
            
            foreach ($columns as $col) {
                if ($this->hasColumn($table, $col)) {
                    $qualifiedCol = $this->qualified($alias, $col);
                    $scoreParts[] = "(CASE 
                        WHEN {$qualifiedCol} = :{$exactKey} THEN 10 
                        WHEN {$qualifiedCol} LIKE :{$startKey} THEN 5 
                        WHEN {$qualifiedCol} LIKE :{$anyKey} THEN 2 
                        ELSE 0 
                    END)";
                }
            }
            
            if ($joins !== '' && $this->tableExists('users')) {
                foreach (['email', 'full_name', 'mobile'] as $userCol) {
                    if ($this->hasColumn('users', $userCol)) {
                        $qualifiedCol = $this->qualified('u', $userCol);
                        $scoreParts[] = "(CASE 
                            WHEN {$qualifiedCol} = :{$exactKey} THEN 10 
                            WHEN {$qualifiedCol} LIKE :{$startKey} THEN 5 
                            WHEN {$qualifiedCol} LIKE :{$anyKey} THEN 2 
                            ELSE 0 
                        END)";
                    }
                }
            }
            
            if (!empty($scoreParts)) {
                $relevanceSelect = ', (' . implode(' + ', $scoreParts) . ') AS relevance_score';
            }
        }

        $select = "{$alias}.*";
        if ($joins !== '') {
            $select .= $this->tableExists('users') ? ", u.full_name as user_name, u.email as user_email" : '';
        }
        $select .= $relevanceSelect;

        $whereSql = implode(' AND ', $where);
        
        $baseOrderBy = $this->safeOrderBy($orderBy, $alias);
        $finalOrderBy = ($relevanceSelect !== '') ? "relevance_score DESC, {$baseOrderBy}" : $baseOrderBy;

        try {
            $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM {$table} {$alias} {$joins} WHERE {$whereSql}", $params);
            $items = $this->db->fetchAll("SELECT {$select} FROM {$table} {$alias} {$joins} WHERE {$whereSql} ORDER BY {$finalOrderBy} LIMIT {$limit} OFFSET {$offset}", $params);
            return ['items' => $items, 'total' => $total, 'facets' => []];
        } catch (\Throwable $e) {
            $this->logger->warning('search.read_query_failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
            return ['items' => [], 'total' => 0, 'facets' => []];
        }
    }

    private static array $ftsCache = [];

    private function getFullTextGroups(string $table): array
    {
        if (isset(self::$ftsCache[$table])) {
            return self::$ftsCache[$table];
        }
        
        try {
            $rows = $this->db->fetchAll("SHOW INDEX FROM `{$table}` WHERE Index_type = 'FULLTEXT'");
            $groups = [];
            foreach ($rows as $row) {
                $keyName = $row->Key_name ?? '';
                $colName = $row->Column_name ?? '';
                if ($keyName !== '' && $colName !== '') {
                    $groups[$keyName][] = $colName;
                }
            }
            self::$ftsCache[$table] = $groups;
            return $groups;
        } catch (\Throwable) {
            self::$ftsCache[$table] = [];
            return [];
        }
    }

    private function buildLikeWhere(string $table, string $alias, array $columns, string $q, array &$params, bool $includeUser = false): string
    {
        $q = trim(mb_substr($q, 0, 100));
        if ($q === '') {
            return '';
        }

        $parts = [];
        $columns = array_values(array_unique($columns));
        
        // 🚀 FULLTEXT Index optimization path (prevents Full Table Scans on 100K+ rows)
        $ftsGroups = $this->getFullTextGroups($table);
        $usedFtsColumns = [];

        if (!empty($ftsGroups)) {
            foreach ($ftsGroups as $indexName => $ftsCols) {
                $intersect = array_intersect($columns, $ftsCols);
                if (!empty($intersect)) {
                    $key = 'fts_' . count($params);
                    $qualifiedCols = array_map(fn($col) => $this->qualified($alias, $col), $ftsCols);
                    
                    // MATCH (col1, col2) AGAINST (:q IN BOOLEAN MODE)
                    $parts[] = "MATCH(" . implode(', ', $qualifiedCols) . ") AGAINST(:{$key} IN BOOLEAN MODE)";
                    $params[$key] = $q . '*';
                    
                    $usedFtsColumns = array_merge($usedFtsColumns, $ftsCols);
                }
            }
        }

        // Fallback LIKE prefix scan for columns not indexed with FULLTEXT
        // SECURITY/PERFORMANCE: Changed from '%q%' to 'q%' to allow B-Tree index utilization!
        // This resolves the full table scan bottleneck reported in search optimization phase.
        $remainingColumns = array_diff($columns, $usedFtsColumns);
        foreach ($remainingColumns as $column) {
            if (!$this->hasColumn($table, $column)) {
                continue;
            }
            $key = 'q_' . count($params);
            $parts[] = $this->qualified($alias, $column) . " LIKE :{$key} ESCAPE '\\\\'";
            $params[$key] = $this->escapeLike($q) . '%'; // PREFIX LIKE
        }

        if ($includeUser && $this->tableExists('users')) {
            foreach (['email', 'full_name', 'mobile'] as $userColumn) {
                if (!$this->hasColumn('users', $userColumn)) {
                    continue;
                }
                $key = 'q_' . count($params);
                $parts[] = $this->qualified('u', $userColumn) . " LIKE :{$key} ESCAPE '\\\\'";
                $params[$key] = $this->escapeLike($q) . '%'; // PREFIX LIKE
            }
        }

        if (ctype_digit($q) && $this->hasColumn($table, 'id')) {
            $key = 'id_' . count($params);
            $parts[] = $this->qualified($alias, 'id') . " = :{$key}";
            $params[$key] = (int)$q;
        }

        return $parts ? '(' . implode(' OR ', $parts) . ')' : '';
    }

    private function applyAllowedFilters(string $table, string $alias, array $filters, array $allowed, array &$where, array &$params): void
    {
        foreach ($allowed as $column) {
            $sourceColumn = $column === 'status' && $table === 'crypto_deposits' ? 'verification_status' : $column;
            if (!array_key_exists($column, $filters) && !array_key_exists($sourceColumn, $filters)) {
                continue;
            }
            $value = $filters[$column] ?? $filters[$sourceColumn] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (!$this->hasColumn($table, $sourceColumn)) {
                continue;
            }
            $key = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $sourceColumn) . '_' . count($params);
            $where[] = $this->qualified($alias, $sourceColumn) . " = :{$key}";
            $params[$key] = is_numeric($value) ? (int)$value : (string)$value;
        }
    }

    private function safeOrderBy(string $orderBy, string $alias): string
    {
        if (preg_match('/^[a-zA-Z0-9_]+\.[a-zA-Z0-9_]+\s+(ASC|DESC)$/i', trim($orderBy))) {
            return $orderBy;
        }
        return "{$alias}.created_at DESC";
    }


    private function qualified(string $alias, string $column): string
    {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 't';
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?: 'id';
        return "{$alias}.`{$column}`";
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }
        if (array_key_exists($table, self::$tableCache)) {
            return self::$tableCache[$table];
        }
        try {
            self::$tableCache[$table] = (bool) $this->db->fetchColumn('SHOW TABLES LIKE ?', [$table]);
            return self::$tableCache[$table];
        } catch (\Throwable) {
            self::$tableCache[$table] = false;
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    private function columns(string $table): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return [];
        }
        if (isset(self::$columnsCache[$table])) {
            return self::$columnsCache[$table];
        }
        try {
            $rows = $this->db->fetchAll("SHOW COLUMNS FROM {$table}");
            self::$columnsCache[$table] = array_map(fn($r) => (string)($r->Field ?? ''), $rows);
            return self::$columnsCache[$table];
        } catch (\Throwable) {
            self::$columnsCache[$table] = [];
            return [];
        }
    }
}
