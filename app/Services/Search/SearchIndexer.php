<?php
declare(strict_types=1);

namespace App\Services\Search;

use Core\Database;

/**
 * SearchIndexer — نگه‌دارنده‌ی Read-Model جستجو (search_projections)
 *
 * این کلاس تنها نقطه‌ی نوشتن (Single Writer) در جدول projection است.
 * Listenerها و Backfill از طریق همین کلاس projection را به‌روز نگه می‌دارند تا
 * مسیر خواندن (SearchProjectionRepository) بتواند با MATCH ... AGAINST و بدون
 * JOIN به جداول live و بدون LIKE '%...%'، جستجوی Scale‌پذیر انجام دهد.
 *
 * فیلدها:
 *   entity_type : نوع موجودیت (مثلاً 'transaction', 'ticket', 'vitrine')
 *   entity_id   : شناسه‌ی رکورد در جدول مبدأ
 *   owner_id    : مالک رکورد (برای ownership در User Search) — null برای رکوردهای عمومی
 *   scope       : 'admin' | 'user' | 'module'  (دامنه‌ی دیدِ projection)
 *   module      : نام ماژول منطقی برای فیلتر/تجمیع (مثلاً 'transactions')
 *   ref         : شناسه‌ی نمایشی کوتاه (tracking_code, transaction_id, ...) برای جستجوی دقیق
 *   title       : عنوان قابل‌جستجو (FULLTEXT)
 *   content     : متن کامل قابل‌جستجو (FULLTEXT)
 *   metadata    : payload نمایشی برای ساخت نتیجه بدون رجوع به جدول live
 */
final class SearchIndexer
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * درج یا بروزرسانی یک projection (UPSERT اتمیک بر اساس entity_type+entity_id).
     */
    public function index(
        string $entityType,
        int $entityId,
        ?string $title,
        ?string $content,
        array $metadata = [],
        bool $isActive = true,
        ?int $ownerId = null,
        string $scope = 'module',
        ?string $module = null,
        ?string $ref = null
    ): void {
        $sql = "
            INSERT INTO search_projections
                (entity_type, entity_id, owner_id, scope, module, ref, title, content, metadata, is_active, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                owner_id   = VALUES(owner_id),
                scope      = VALUES(scope),
                module     = VALUES(module),
                ref        = VALUES(ref),
                title      = VALUES(title),
                content    = VALUES(content),
                metadata   = VALUES(metadata),
                is_active  = VALUES(is_active),
                updated_at = NOW()
        ";

        $this->db->execute($sql, [
            $entityType,
            $entityId,
            $ownerId,
            $scope,
            $module ?? $entityType,
            $ref !== null ? mb_substr($ref, 0, 190) : null,
            $title,
            $content,
            json_encode($metadata, JSON_UNESCAPED_UNICODE),
            $isActive ? 1 : 0,
        ]);
    }

    /**
     * نمایه‌گذاری دسته‌ای (برای Backfill) — هر سطر به همان قرارداد index() است.
     *
     * @param array<int,array{
     *   entity_type:string, entity_id:int, title?:?string, content?:?string,
     *   metadata?:array, is_active?:bool, owner_id?:?int, scope?:string, module?:?string, ref?:?string
     * }> $rows
     * @return int تعداد سطرهای پردازش‌شده
     */
    public function indexBatch(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $placeholders = [];
        $params = [];
        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
            $params[] = (string)$row['entity_type'];
            $params[] = (int)$row['entity_id'];
            $params[] = isset($row['owner_id']) ? (int)$row['owner_id'] : null;
            $params[] = (string)($row['scope'] ?? 'module');
            $params[] = (string)($row['module'] ?? $row['entity_type']);
            $params[] = isset($row['ref']) && $row['ref'] !== null ? mb_substr((string)$row['ref'], 0, 190) : null;
            $params[] = $row['title'] ?? null;
            $params[] = $row['content'] ?? null;
            $params[] = json_encode($row['metadata'] ?? [], JSON_UNESCAPED_UNICODE);
            $params[] = (!isset($row['is_active']) || $row['is_active']) ? 1 : 0;
        }

        $sql = "
            INSERT INTO search_projections
                (entity_type, entity_id, owner_id, scope, module, ref, title, content, metadata, is_active, created_at, updated_at)
            VALUES " . implode(', ', $placeholders) . "
            ON DUPLICATE KEY UPDATE
                owner_id   = VALUES(owner_id),
                scope      = VALUES(scope),
                module     = VALUES(module),
                ref        = VALUES(ref),
                title      = VALUES(title),
                content    = VALUES(content),
                metadata   = VALUES(metadata),
                is_active  = VALUES(is_active),
                updated_at = NOW()
        ";

        $this->db->execute($sql, $params);
        return count($rows);
    }

    /**
     * حذف نرم (soft) یک projection — رکورد می‌ماند ولی از نتایج خارج می‌شود.
     */
    public function deactivate(string $entityType, int $entityId): void
    {
        $this->db->execute(
            "UPDATE search_projections SET is_active = 0, updated_at = NOW() WHERE entity_type = ? AND entity_id = ?",
            [$entityType, $entityId]
        );
    }

    /**
     * حذف کامل یک projection از سیستم جستجو.
     */
    public function remove(string $entityType, int $entityId): void
    {
        $this->db->execute(
            "DELETE FROM search_projections WHERE entity_type = ? AND entity_id = ?",
            [$entityType, $entityId]
        );
    }
}
