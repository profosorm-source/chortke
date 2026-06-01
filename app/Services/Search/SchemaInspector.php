<?php

declare(strict_types=1);

namespace App\Services\Search;

use Core\Database;
use Core\Cache;

/**
 * SchemaInspector — متمرکزسازی introspection اسکیمای دیتابیس برای لایه‌ی Search.
 *
 * استفاده از Cache مرکزی (Redis) و کوئری‌های بهینه INFORMATION_SCHEMA به جای
 * کش استاتیک (برای جلوگیری از Memory Leak در Swoole) و SHOW TABLES (کوئری‌های DDL کند).
 */
final class SchemaInspector
{
    private const TTL = 3600 * 24; // 24 ساعت کش
    private const TAG = 'schema:introspection';

    private Database $db;
    private Cache $cache;
    public function __construct(
        Database $db,
        Cache $cache
    ) {        $this->db = $db;
        $this->cache = $cache;

    }

    public function tableExists(string $table): bool
    {
        $table = str_replace('`', '', $table);
        $cacheKey = "schema:table_exists:{$table}";

        return $this->cache->tags([self::TAG])->rememberSeconds($cacheKey, self::TTL, function () use ($table) {
            try {
                $exists = (bool)$this->db->fetchColumn(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$table]
                );
                return $exists;
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /** @return array<int,string> */
    public function getColumns(string $table): array
    {
        $table = str_replace('`', '', $table);
        $cacheKey = "schema:columns:{$table}";

        return $this->cache->tags([self::TAG])->rememberSeconds($cacheKey, self::TTL, function () use ($table) {
            try {
                $rows = $this->db->fetchAll(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$table]
                );
                
                $columns = [];
                foreach ($rows as $row) {
                    $columns[] = (string)($row->COLUMN_NAME ?? $row->Column_name ?? current((array)$row));
                }
                return $columns;
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /**
     * فقط ستون‌هایی از $wanted را برمی‌گرداند که واقعاً در جدول موجودند.
     *
     * @param array<int,string> $wanted
     * @return array<int,string>
     */
    public function filterExistingColumns(string $table, array $wanted): array
    {
        $existing = $this->getColumns($table);
        return array_values(array_filter($wanted, static fn(string $c) => in_array($c, $existing, true)));
    }

    /**
     * بررسی اینکه آیا یک FULLTEXT index دقیقاً همه‌ی ستون‌های داده‌شده را پوشش می‌دهد.
     *
     * @param array<int,string> $columns
     */
    /**
     * @var array<string, array<int, string>>
     */
    private const STATIC_FULLTEXT_INDEXES = [
        'users' => ['full_name', 'email', 'mobile', 'username'],
        'transactions' => ['transaction_id', 'description', 'gateway_transaction_id', 'ref_id'],
        'tickets' => ['subject', 'ticket_id', 'status', 'priority'],
        'withdrawals' => ['tracking_code', 'transaction_id', 'status', 'currency'],
        'ads' => ['title', 'description', 'keyword'],
        'search_projections' => ['title', 'content', 'ref'],
        'content_submissions' => ['title', 'description', 'video_url', 'platform', 'status'],
        'influencer_profiles' => ['username', 'bio', 'page_url', 'platform', 'status'],
        'vitrine_listings' => ['title', 'description', 'username'],
        'bank_cards' => ['card_number', 'sheba', 'bank_name', 'status'],
        'kyc_verifications' => ['national_id', 'status', 'rejection_reason'],
        'manual_deposits' => ['tracking_code', 'status', 'transaction_id'],
        'crypto_deposits' => ['tx_hash', 'network', 'verification_status', 'transaction_id'],
        'social_accounts' => ['username', 'platform', 'status'],
        'data_exports' => ['type', 'status', 'file_path'],
        'account_deletion_logs' => ['reason', 'status', 'admin_note'],
        'bug_reports' => ['subject', 'description', 'status'],
        'escrows' => ['status', 'transaction_id'],
        'prediction_games' => ['title', 'team_home', 'team_away', 'sport_type', 'status'],
        'lottery_rounds' => ['status', 'type'],
        'coupons' => ['code', 'type', 'applicable_to'],
        'direct_messages' => ['message']
    ];

    /**
     * بررسی اینکه آیا یک FULLTEXT index دقیقاً همه‌ی ستون‌های داده‌شده را پوشش می‌دهد.
     * با استفاده از تنظیمات استاتیک برای جلوگیری از SHOW INDEX در دیتابیس لایو.
     *
     * @param array<int,string> $columns
     */
    public function hasFullTextIndex(string $table, array $columns): bool
    {
        if (empty($columns)) {
            return false;
        }

        $table = str_replace('`', '', $table);
        
        // مسیر بسیار سریع و استاتیک (بدون Memory Leak و Query Overhead)
        if (isset(self::STATIC_FULLTEXT_INDEXES[$table])) {
            $indexed = self::STATIC_FULLTEXT_INDEXES[$table];
            return count(array_intersect($columns, $indexed)) === count($columns);
        }

        return false;
    }

    /**
     * پاک‌سازی cache برای تمام جداول.
     */
    public function flush(): void
    {
        $this->cache->tags([self::TAG])->flush();
    }

    /**
     * گرم کردن کش برای جداول مشخص شده.
     */
    public function warm(array $tables): void
    {
        foreach ($tables as $table) {
            $this->tableExists($table);
            $this->getColumns($table);
        }
    }
}
