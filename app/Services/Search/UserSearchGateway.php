<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * UserSearchGateway — جستجوی scope=user (رکوردهای متعلق به یک کاربر).
 *
 * معماری dual-read (CQRS با مهاجرت امن):
 *   1) اگر projection برای scope=user/module آماده باشد → خواندن از
 *      SearchProjectionRepository با MATCH AGAINST و ownership روی owner_id.
 *   2) در غیر این صورت → fallback به جدول live از طریق AbstractSearchGateway
 *      که اکنون نیز از FULLTEXT (و نه LIKE خام) استفاده می‌کند.
 *
 * این رفع، Full Table Scanِ LIKE '%...%' را در مسیر کاربر حذف می‌کند و
 * دانش schema تمام دامنه‌ها را از Search خارج می‌نماید (وقتی projection فعال است).
 */
final class UserSearchGateway extends AbstractSearchGateway
{
    /** @var array<string, array{table:string, alias:string, columns:array<int,string>, select:string, module:string}> */
    private array $registry = [
        'transactions' => ['table' => 'transactions', 'alias' => 't', 'columns' => ['transaction_id', 'description', 'ref_id'], 'select' => 't.id, t.transaction_id, t.amount, t.status, t.type, t.currency, t.created_at, t.description', 'module' => 'transactions'],
        'tickets' => ['table' => 'tickets', 'alias' => 't', 'columns' => ['subject', 'ticket_id'], 'select' => 't.id, t.ticket_id, t.subject, t.status, t.priority, t.created_at', 'module' => 'tickets'],
        'ads' => ['table' => 'ads', 'alias' => 'a', 'columns' => ['title', 'description', 'keyword'], 'select' => 'a.id, a.title, a.type, a.status, a.created_at', 'module' => 'ads'],
        'tasks' => ['table' => 'ads', 'alias' => 'a', 'columns' => ['title', 'description', 'keyword'], 'select' => 'a.id, a.title, a.type, a.status, a.created_at', 'module' => 'ads'],
        'vitrines' => ['table' => 'vitrine_listings', 'alias' => 'v', 'columns' => ['title', 'description', 'username'], 'select' => 'v.id, v.title, v.price_usdt, v.status, v.created_at', 'module' => 'vitrines'],
        'withdrawals' => ['table' => 'withdrawals', 'alias' => 'w', 'columns' => ['tracking_code', 'transaction_id'], 'select' => 'w.id, w.tracking_code, w.amount, w.status, w.created_at', 'module' => 'withdrawals'],
        'manual_deposits' => ['table' => 'manual_deposits', 'alias' => 'md', 'columns' => ['tracking_code', 'transaction_id'], 'select' => 'md.id, md.tracking_code, md.amount, md.status, md.created_at', 'module' => 'manual_deposits'],
        'crypto_deposits' => ['table' => 'crypto_deposits', 'alias' => 'cd', 'columns' => ['tx_hash', 'transaction_id'], 'select' => 'cd.id, cd.tx_hash, cd.amount, cd.status, cd.created_at', 'module' => 'crypto_deposits'],
        'bank_cards' => ['table' => 'bank_cards', 'alias' => 'bc', 'columns' => ['card_number', 'sheba', 'bank_name'], 'select' => 'bc.id, bc.card_number, bc.status, bc.created_at', 'module' => 'bank_cards'],
        'kyc' => ['table' => 'kyc_verifications', 'alias' => 'kyc', 'columns' => ['national_id', 'status'], 'select' => 'kyc.id, kyc.status, kyc.created_at', 'module' => 'kyc'],
        'contents' => ['table' => 'content_submissions', 'alias' => 'cs', 'columns' => ['title', 'description', 'video_url'], 'select' => 'cs.id, cs.title, cs.video_url, cs.status, cs.created_at', 'module' => 'content'],
        'direct_messages' => ['table' => 'direct_messages', 'alias' => 'dm', 'columns' => ['message'], 'select' => 'dm.id, dm.sender_id, dm.recipient_id, dm.created_at, dm.read_at', 'module' => 'direct_messages'],
        'referrals' => ['table' => 'users', 'alias' => 'u', 'columns' => ['username', 'full_name'], 'select' => 'u.id, u.username, u.full_name, u.created_at', 'module' => 'referrals'],
        'user_levels' => ['table' => 'user_level_histories', 'alias' => 'ulh', 'columns' => ['from_level', 'to_level', 'change_type', 'reason'], 'select' => 'ulh.id, ulh.from_level, ulh.to_level, ulh.change_type, ulh.created_at', 'module' => 'user_levels'],
        'notifications' => ['table' => 'notifications', 'alias' => 'n', 'columns' => ['title', 'message'], 'select' => 'n.id, n.title, n.created_at, n.is_read', 'module' => 'notifications'],
        'score_history' => ['table' => 'score_events', 'alias' => 'se', 'columns' => ['domain', 'source'], 'select' => 'se.id, se.domain, se.delta AS score_change, se.source AS reason, se.created_at', 'module' => 'score_history'],
        'audit_trail' => ['table' => 'audit_trail', 'alias' => 'al', 'columns' => ['event'], 'select' => 'al.id, al.event AS action, al.created_at', 'module' => 'audit_trail'],
    ];

    private SearchProjectionRepository $projection;
    private bool $projectionEnabled;
    public function __construct(
        Database $db,
        LoggerInterface $logger,
        SchemaInspector $schema,
        SearchProjectionRepository $projection,
        bool $projectionEnabled = true
    ) {        $this->projection = $projection;
        $this->projectionEnabled = $projectionEnabled;

        parent::__construct($db, $logger, $schema);
    }

    public function searchRegistered(string $module, SearchQuery $query): SearchResult
    {
        $module = strtolower(trim($module));
        if (!isset($this->registry[$module])) {
            return new SearchResult([], 0);
        }

        $userId = (int)($query->getFilters()['user_id'] ?? 0);
        if ($userId <= 0) {
            return new SearchResult([], 0);
        }

        $def = $this->registry[$module];

        // ── مسیر اصلی: Read-Model (CQRS) ──
        if ($this->projectionEnabled && $this->projection->isReady('user', $def['module'])) {
            $result = $this->projection->search(
                (string)($query->getTerm() ?? ''),
                [
                    'scope'    => 'user',
                    'module'   => $def['module'],
                    'owner_id' => $userId,
                ],
                $query->getLimit(),
                $query->getOffset()
            );
            // اگر projection خطا داد، به live برمی‌گردیم (resiliency)
            if (empty($result->getMetadata()['error'])) {
                return $result;
            }
        }

        // ── مسیر fallback: جدول live (اکنون با FULLTEXT از طریق پایه) ──
        return $this->searchLive(
            $query,
            $module,
            $def['table'],
            $def['alias'],
            $def['columns'],
            $def['select'],
            $this->ownershipPredicate($module, $def['alias'], $userId)
        );
    }

    private function searchLive(
        SearchQuery $query,
        string $module,
        string $table,
        string $alias,
        array $columns,
        string $select,
        array $ownership
    ): SearchResult {
        if (!$this->tableExists($table)) {
            return new SearchResult([], 0);
        }

        $limit = max(1, min(200, $query->getLimit()));
        $offset = max(0, $query->getOffset());
        $q = trim((string)($query->getTerm() ?? ''));
        $params = $ownership['params'];
        $where = [$ownership['sql']];

        if ($q !== '') {
            $searchSql = $this->buildSearchWhere($table, $alias, $columns, $q, $params);
            if ($searchSql !== '') {
                $where[] = $searchSql;
            }
        }

        $whereSql = implode(' AND ', $where);

        try {
            $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM {$table} {$alias} WHERE {$whereSql}", $params);
            $items = $this->db->fetchAll("SELECT {$select} FROM {$table} {$alias} WHERE {$whereSql} ORDER BY {$alias}.created_at DESC LIMIT {$limit} OFFSET {$offset}", $params);
            return new SearchResult($items, $total, ['source' => 'live']);
        } catch (\Throwable $e) {
            $this->logger->warning('search.user_query_failed', ['table' => $table, 'module' => $module, 'error' => $e->getMessage()]);
            return new SearchResult([], 0, ['error' => $e->getMessage()]);
        }
    }

    private function ownershipPredicate(string $module, string $alias, int $userId): array
    {
        return match ($module) {
            'direct_messages' => ['sql' => "({$alias}.sender_id = :uid OR {$alias}.recipient_id = :uid2)", 'params' => ['uid' => $userId, 'uid2' => $userId]],
            'vitrines' => ['sql' => "({$alias}.seller_id = :uid OR {$alias}.buyer_id = :uid2)", 'params' => ['uid' => $userId, 'uid2' => $userId]],
            'referrals' => ['sql' => "{$alias}.referred_by = :uid", 'params' => ['uid' => $userId]],
            'audit_trail' => ['sql' => "({$alias}.user_id = :uid OR {$alias}.actor_id = :uid2)", 'params' => ['uid' => $userId, 'uid2' => $userId]],
            'score_history' => ['sql' => "{$alias}.entity_type = 'user' AND {$alias}.entity_id = :uid", 'params' => ['uid' => $userId]],
            default => ['sql' => "{$alias}.user_id = :uid", 'params' => ['uid' => $userId]],
        };
    }
}
