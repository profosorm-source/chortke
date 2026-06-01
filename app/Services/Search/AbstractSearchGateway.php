<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * AbstractSearchGateway — پایه‌ی مشترک تمام Gatewayهای جستجو روی جداول live.
 *
 * منطق تکراری که پیش از این در سه Gateway کپی شده بود را متمرکز می‌کند:
 *   - تشخیص و استفاده‌ی FULLTEXT با fallback به LIKE (مسیر امن)
 *   - introspection اسکیما از طریق SchemaInspector (با cache سراسری)
 *   - escape امن ورودی برای LIKE
 *   - مرتب‌سازی امن (whitelist ستون‌ها)
 *
 * این مسیر «live/fallback» است؛ مسیر اصلی و Scale‌پذیر، خواندن از
 * SearchProjectionRepository است. Gatewayها وقتی projection آماده نباشد یا
 * feature-flag خاموش باشد، از این مسیر استفاده می‌کنند (dual-read).
 */
abstract class AbstractSearchGateway
{
    protected Database $db;
    protected LoggerInterface $logger;
    protected SchemaInspector $schema;
    public function __construct(
        Database $db,
        LoggerInterface $logger,
        SchemaInspector $schema
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->schema = $schema;

    }

    /**
     * ساخت شرط WHERE جستجو: ترجیح FULLTEXT، در غیر این صورت LIKE امن.
     *
     * @param array<int,string> $columns ستون‌های قابل‌جستجو (بدون alias)
     * @param array<string,mixed> $params  (by-ref) پارامترهای bind
     * @return string بخش WHERE یا رشته‌ی خالی
     */
    protected function buildSearchWhere(string $table, string $alias, array $columns, string $q, array &$params): string
    {
        $q = trim($q);
        $columns = $this->schema->filterExistingColumns($table, $columns);
        if ($q === '' || empty($columns)) {
            return '';
        }

        // مسیر سریع: FULLTEXT BOOLEAN MODE (در صورت وجود ایندکس دقیق)
        if ($this->schema->hasFullTextIndex($table, $columns)) {
            $boolean = $this->toBooleanQuery($q);
            if ($boolean !== '') {
                $params['ft_term'] = $boolean;
                $qualified = implode(', ', array_map(static fn($c) => "{$alias}.{$c}", $columns));
                return "MATCH({$qualified}) AGAINST(:ft_term IN BOOLEAN MODE)";
            }
        }

        // مسیر fallback: LIKE امن (با ESCAPE)
        $conditions = [];
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100));
        foreach ($columns as $index => $column) {
            $param = "q_{$index}";
            $conditions[] = "{$alias}.{$column} LIKE :{$param} ESCAPE '\\\\'";
            $params[$param] = '%' . $escaped . '%';
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    /**
     * تبدیل عبارت کاربر به کوئری امن BOOLEAN MODE.
     */
    protected function toBooleanQuery(string $q): string
    {
        $q = mb_substr($q, 0, 100);
        $clean = preg_replace('/[+\-><\(\)~*\"@]+/u', ' ', $q);
        $words = preg_split('/\s+/u', trim((string)$clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $parts = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 1) {
                $parts[] = '+' . $w . '*';
            }
        }

        return implode(' ', $parts);
    }

    /**
     * مرتب‌سازی امن بر اساس whitelist ستون‌های موجود.
     */
    protected function safeOrderBy(string $requested, string $alias, array $existingColumns, string $default): string
    {
        $requested = trim($requested);
        if ($requested === '') {
            return $default;
        }

        $parts = explode(' ', $requested);
        $col = preg_replace('/[^a-zA-Z0-9_]/', '', $parts[0] ?? '');
        $dir = strtoupper($parts[1] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        if ($col !== '' && in_array($col, $existingColumns, true)) {
            return "{$alias}.{$col} {$dir}";
        }

        return $default;
    }

    protected function tableExists(string $table): bool
    {
        return $this->schema->tableExists($table);
    }

    protected function getExistingColumns(string $table): array
    {
        return $this->schema->getColumns($table);
    }
}
