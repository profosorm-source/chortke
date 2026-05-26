<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * Read-only adapter for admin search operations.
 * Optimized for FULLTEXT search and high scalability.
 */
final class AdminSearchGateway
{
    /** @var array<string, array{table:string, alias:string, columns:array, joins?:string, filters?:array, order?:string, deleted?:string|null, type?:string|null}> */
    private array $registry = [
        'bank_cards' => ['table' => 'bank_cards', 'alias' => 'bc', 'columns' => ['card_number', 'sheba', 'bank_name', 'status'], 'joins' => 'LEFT JOIN users u ON u.id = bc.user_id', 'filters' => ['status', 'user_id'], 'order' => 'bc.created_at DESC'],
        'kyc' => ['table' => 'kyc_verifications', 'alias' => 'kyc', 'columns' => ['national_id', 'status', 'rejection_reason'], 'joins' => 'LEFT JOIN users u ON u.id = kyc.user_id', 'filters' => ['status', 'user_id'], 'order' => 'kyc.created_at DESC'],
        'manual_deposits' => ['table' => 'manual_deposits', 'alias' => 'd', 'columns' => ['tracking_code', 'status', 'transaction_id'], 'joins' => 'LEFT JOIN users u ON u.id = d.user_id LEFT JOIN bank_cards c ON c.id = d.card_id', 'filters' => ['status', 'user_id'], 'order' => 'd.created_at DESC'],
        'crypto_deposits' => ['table' => 'crypto_deposits', 'alias' => 'd', 'columns' => ['tx_hash', 'network', 'verification_status', 'transaction_id'], 'joins' => 'LEFT JOIN users u ON u.id = d.user_id', 'filters' => ['verification_status', 'status', 'network', 'user_id'], 'order' => 'd.created_at DESC'],
        'social_accounts' => ['table' => 'social_accounts', 'alias' => 'sa', 'columns' => ['username', 'platform', 'status'], 'filters' => ['platform', 'status', 'user_id'], 'order' => 'sa.created_at DESC'],
        'data_exports' => ['table' => 'data_exports', 'alias' => 'de', 'columns' => ['type', 'status', 'file_path'], 'filters' => ['type', 'status', 'user_id'], 'order' => 'de.created_at DESC'],
        'account_deletion_logs' => ['table' => 'account_deletion_logs', 'alias' => 'adl', 'columns' => ['reason', 'status', 'admin_note'], 'filters' => ['status', 'user_id'], 'order' => 'adl.created_at DESC'],
        'investment' => ['table' => 'investments', 'alias' => 'i', 'columns' => ['status'], 'joins' => 'LEFT JOIN users u ON u.id = i.user_id', 'filters' => ['status', 'user_id'], 'order' => 'i.created_at DESC', 'deleted' => 'i.deleted_at IS NULL'],
        'bug_report' => ['table' => 'bug_reports', 'alias' => 'br', 'columns' => ['subject', 'description', 'status'], 'joins' => 'LEFT JOIN users u ON u.id = br.user_id', 'filters' => ['status', 'user_id'], 'order' => 'br.created_at DESC'],
        'escrow' => ['table' => 'escrows', 'alias' => 'es', 'columns' => ['status', 'transaction_id'], 'joins' => 'LEFT JOIN users u ON u.id = es.buyer_id', 'filters' => ['status', 'buyer_id', 'seller_id'], 'order' => 'es.created_at DESC'],
    ];

    private static array $columnsCache = [];
    private static array $tableCache = [];
    private static array $ftsCache = [];

    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    ) {}

    public function quickSearchUsers(SearchQuery $query): SearchResult
    {
        return $this->searchTable($query, 'users', 'u', ['full_name', 'email', 'mobile', 'username'], '', ['status']);
    }

    public function quickSearchTransactions(SearchQuery $query): SearchResult
    {
        return $this->searchTable(
            $query, 'transactions', 't', ['transaction_id', 'description', 'gateway_transaction_id', 'ref_id'],
            'LEFT JOIN users u ON u.id = t.user_id', ['status', 'type', 'currency', 'user_id']
        );
    }

    public function quickSearchTickets(SearchQuery $query): SearchResult
    {
        return $this->searchTable(
            $query, 'tickets', 't', ['subject', 'ticket_id', 'status', 'priority'],
            'LEFT JOIN users u ON u.id = t.user_id LEFT JOIN ticket_categories tc ON tc.id = t.category_id',
            ['status', 'priority', 'category_id', 'assigned_to', 'user_id']
        );
    }

    public function quickSearchWithdrawals(SearchQuery $query): SearchResult
    {
        return $this->searchTable(
            $query, 'withdrawals', 'w', ['tracking_code', 'transaction_id', 'status', 'currency'],
            'LEFT JOIN users u ON u.id = w.user_id LEFT JOIN bank_cards c ON c.id = w.card_id',
            ['status', 'currency', 'user_id']
        );
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

    public function searchRegistered(string $module, SearchQuery $query): SearchResult
    {
        $module = strtolower(trim($module));
        if (!isset($this->registry[$module])) {
            return new SearchResult([], 0);
        }

        $def = $this->registry[$module];
        return $this->searchTable(
            $query,
            $def['table'],
            $def['alias'],
            $def['columns'],
            $def['joins'] ?? '',
            $def['filters'] ?? [],
            $def['deleted'] ?? null
        );
    }
    

    private function searchTable(
        SearchQuery $query,
        string $table,
        string $alias,
        array $columns,
        string $joins = '',
        array $allowedFilters = [],
        ?string $fixedWhere = null
    ): SearchResult {
        if (!$this->tableExists($table)) {
            return new SearchResult([], 0);
        }

        $limit = $query->getLimit();
        $offset = $query->getOffset();
        $q = $query->getTerm() ?? '';
        $params = [];
        $where = ['1=1'];

        $likeWhere = $this->buildLikeWhere($table, $alias, $columns, $q, $params, $joins !== '');
        if ($likeWhere !== '') {
            $where[] = $likeWhere;
        }

        $this->applyAllowedFilters($table, $alias, $query->getFilters(), $allowedFilters, $where, $params);

        $relevanceSelect = '';
        $qClean = trim(mb_substr($q, 0, 100));
        
        if ($qClean !== '') {
            $scoreParts = [];

        $whereSql = implode(' AND ', $where);
        
        $baseOrderBy = $this->safeOrderBy($query->getSort(), $alias);
        $finalOrderBy = ($relevanceSelect !== '') ? "relevance_score DESC, {$baseOrderBy}" : $baseOrderBy;

        try {
            $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM {$table} {$alias} {$joins} WHERE {$whereSql}", $params);
            $items = $this->db->fetchAll("SELECT {$select} FROM {$table} {$alias} {$joins} WHERE {$whereSql} ORDER BY {$finalOrderBy} LIMIT {$limit} OFFSET {$offset}", $params);
            return new SearchResult($items, $total);
        } catch (\Throwable $e) {
            $this->logger->warning('search.read_query_failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
            return new SearchResult([], 0);
        }
    }
	}
}