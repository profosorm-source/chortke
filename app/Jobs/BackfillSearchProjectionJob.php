<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\LoggerInterface;
use App\Services\Search\SearchIndexer;
use App\Services\Search\SchemaInspector;
use Core\Database;

/**
 * BackfillSearchProjectionJob — پر کردن اولیه‌ی Read-Model جستجو از جداول live.
 *
 * این Job داده‌های موجود را به‌صورت chunk به جدول `search_projections` منتقل می‌کند
 * تا پس از فعال‌سازی CQRS، نتایج جستجو از همان لحظه کامل باشند (نه فقط رکوردهای جدید
 * که از طریق Listener می‌آیند).
 *
 * اجرای امن:
 *   - chunk-based با cursor مبتنی بر id (بدون OFFSET سنگین)
 *   - هر منبع جدا، با بررسی وجود جدول/ستون
 *   - قابل اجرای مجدد (UPSERT) — idempotent
 *
 * پارامترهای data:
 *   source     : نام منبع ('transactions','withdrawals',... یا 'all')
 *   batch_size : اندازه‌ی هر chunk (پیش‌فرض 500)
 *   max_id     : سقف id برای ادامه (داخلی)
 */
final class BackfillSearchProjectionJob
{
    private const DEFAULT_BATCH = 500;

    private Database $db;
    private SearchIndexer $indexer;
    private SchemaInspector $schema;
    private LoggerInterface $logger;
    public function __construct(
        Database $db,
        SearchIndexer $indexer,
        SchemaInspector $schema,
        LoggerInterface $logger
    ) {        $this->db = $db;
        $this->indexer = $indexer;
        $this->schema = $schema;
        $this->logger = $logger;

    }

    public function handle(array $data = []): void
    {
        $source = (string)($data['source'] ?? 'all');
        $batch = max(50, min(2000, (int)($data['batch_size'] ?? self::DEFAULT_BATCH)));

        $sources = $source === 'all' ? array_keys($this->map()) : [$source];
        $totals = [];

        foreach ($sources as $src) {
            try {
                $totals[$src] = $this->backfillSource($src, $batch);
            } catch (\Throwable $e) {
                $this->logger->error('search.backfill.source_failed', [
                    'source' => $src,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('search.backfill.completed', ['totals' => $totals]);
    }

    private function backfillSource(string $source, int $batch): int
    {
        $map = $this->map();
        if (!isset($map[$source])) {
            $this->logger->warning('search.backfill.unknown_source', ['source' => $source]);
            return 0;
        }

        $def = $map[$source];
        $table = $def['table'];
        if (!$this->schema->tableExists($table)) {
            return 0;
        }

        $existingCols = $this->schema->getColumns($table);
        $lastId = 0;
        $processed = 0;

        while (true) {
            $rows = $this->db->fetchAll(
                "SELECT * FROM `{$table}` WHERE id > ? ORDER BY id ASC LIMIT {$batch}",
                [$lastId]
            );
            if (empty($rows)) {
                break;
            }

            $projectionRows = [];
            foreach ($rows as $row) {
                $lastId = (int)$row->id;
                $projectionRows[] = ($def['build'])($row, $existingCols);
            }

            $this->indexer->indexBatch(array_filter($projectionRows));
            $processed += count($rows);

            if (count($rows) < $batch) {
                break;
            }
        }

        $this->logger->info('search.backfill.source_done', ['source' => $source, 'processed' => $processed]);
        return $processed;
    }

    /**
     * تعریف منابع backfill. هر build یک ردیف live را به ردیف projection تبدیل می‌کند.
     *
     * @return array<string,array{table:string, build:callable}>
     */
    private function map(): array
    {
        $col = static fn($row, $name, $default = null) => property_exists($row, $name) ? $row->$name : $default;

        return [
            'transactions' => [
                'table' => 'transactions',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'transaction',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->user_id ?? 0) ?: null,
                    'scope'       => 'user',
                    'module'      => 'transactions',
                    'ref'         => (string)($r->transaction_id ?? ''),
                    'title'       => trim((string)($r->type ?? 'transaction') . ' ' . (string)($r->amount ?? '')),
                    'content'     => trim((string)($r->description ?? '') . ' ' . (string)($r->transaction_id ?? '') . ' ' . (string)($r->ref_id ?? '')),
                    'metadata'    => [
                        'transaction_id' => $r->transaction_id ?? null,
                        'amount' => $r->amount ?? null,
                        'currency' => $r->currency ?? null,
                        'status' => $r->status ?? null,
                        'type' => $r->type ?? null,
                        'created_at' => $r->created_at ?? null,
                    ],
                ],
            ],
            'withdrawals' => [
                'table' => 'withdrawals',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'withdrawal',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->user_id ?? 0) ?: null,
                    'scope'       => 'user',
                    'module'      => 'withdrawals',
                    'ref'         => (string)($r->tracking_code ?? $r->transaction_id ?? ''),
                    'title'       => 'withdrawal ' . (string)($r->amount ?? ''),
                    'content'     => trim((string)($r->tracking_code ?? '') . ' ' . (string)($r->transaction_id ?? '') . ' ' . (string)($r->status ?? '')),
                    'metadata'    => ['tracking_code' => $r->tracking_code ?? null, 'amount' => $r->amount ?? null, 'status' => $r->status ?? null, 'created_at' => $r->created_at ?? null],
                ],
            ],
            'manual_deposits' => [
                'table' => 'manual_deposits',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'manual_deposit',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->user_id ?? 0) ?: null,
                    'scope'       => 'user',
                    'module'      => 'manual_deposits',
                    'ref'         => (string)($r->tracking_code ?? $r->transaction_id ?? ''),
                    'title'       => 'manual deposit ' . (string)($r->amount ?? ''),
                    'content'     => trim((string)($r->tracking_code ?? '') . ' ' . (string)($r->transaction_id ?? '') . ' ' . (string)($r->status ?? '')),
                    'metadata'    => ['tracking_code' => $r->tracking_code ?? null, 'amount' => $r->amount ?? null, 'status' => $r->status ?? null, 'created_at' => $r->created_at ?? null],
                ],
            ],
            'tickets' => [
                'table' => 'tickets',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'ticket',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->user_id ?? 0) ?: null,
                    'scope'       => 'user',
                    'module'      => 'tickets',
                    'ref'         => (string)($r->ticket_id ?? ''),
                    'title'       => (string)($r->subject ?? ''),
                    'content'     => trim((string)($r->subject ?? '') . ' ' . (string)($r->status ?? '')),
                    'metadata'    => ['subject' => $r->subject ?? null, 'status' => $r->status ?? null, 'priority' => $r->priority ?? null, 'created_at' => $r->created_at ?? null],
                ],
            ],
            'content' => [
                'table' => 'content_submissions',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'content',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->user_id ?? 0) ?: null,
                    'scope'       => 'user',
                    'module'      => 'content',
                    'ref'         => null,
                    'title'       => (string)($r->title ?? ''),
                    'content'     => trim((string)($r->title ?? '') . ' ' . (string)($r->description ?? '') . ' ' . (string)($r->video_url ?? '')),
                    'metadata'    => ['title' => $r->title ?? null, 'status' => $r->status ?? null, 'platform' => $r->platform ?? null, 'created_at' => $r->created_at ?? null],
                    'is_active'   => (int)($r->is_deleted ?? 0) === 0,
                ],
            ],
            'vitrines' => [
                'table' => 'vitrine_listings',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'vitrine',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->seller_id ?? 0) ?: null,
                    'scope'       => 'module',
                    'module'      => 'vitrines',
                    'ref'         => (string)($r->username ?? ''),
                    'title'       => (string)($r->title ?? ''),
                    'content'     => trim((string)($r->title ?? '') . ' ' . (string)($r->description ?? '') . ' ' . (string)($r->username ?? '')),
                    'metadata'    => ['title' => $r->title ?? null, 'price_usdt' => $r->price_usdt ?? null, 'status' => $r->status ?? null, 'username' => $r->username ?? null, 'created_at' => $r->created_at ?? null],
                    'is_active'   => empty($r->deleted_at),
                ],
            ],
            'influencers' => [
                'table' => 'influencer_profiles',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'influencer',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->user_id ?? 0) ?: null,
                    'scope'       => 'module',
                    'module'      => 'influencers',
                    'ref'         => (string)($r->username ?? ''),
                    'title'       => (string)($r->username ?? ''),
                    'content'     => trim((string)($r->username ?? '') . ' ' . (string)($r->bio ?? '') . ' ' . (string)($r->platform ?? '') . ' ' . (string)($r->page_url ?? '')),
                    'metadata'    => ['username' => $r->username ?? null, 'platform' => $r->platform ?? null, 'status' => $r->status ?? null, 'created_at' => $r->created_at ?? null],
                    'is_active'   => empty($r->deleted_at),
                ],
            ],
            'ads' => [
                'table' => 'ads',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'ad',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->user_id ?? 0) ?: null,
                    'scope'       => 'user',
                    'module'      => 'ads',
                    'ref'         => (string)($r->keyword ?? ''),
                    'title'       => (string)($r->title ?? ''),
                    'content'     => trim((string)($r->title ?? '') . ' ' . (string)($r->description ?? '') . ' ' . (string)($r->keyword ?? '')),
                    'metadata'    => ['title' => $r->title ?? null, 'type' => $r->type ?? null, 'status' => $r->status ?? null, 'created_at' => $r->created_at ?? null],
                ],
            ],
            'prediction' => [
                'table' => 'prediction_games',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'prediction',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => null,
                    'scope'       => 'module',
                    'module'      => 'prediction',
                    'ref'         => (string)($r->sport_type ?? ''),
                    'title'       => (string)($r->title ?? ''),
                    'content'     => trim((string)($r->title ?? '') . ' ' . (string)($r->team_home ?? '') . ' ' . (string)($r->team_away ?? '')),
                    'metadata'    => ['title' => $r->title ?? null, 'status' => $r->status ?? null, 'sport_type' => $r->sport_type ?? null, 'created_at' => $r->created_at ?? null],
                    'is_active'   => empty($r->deleted_at),
                ],
            ],
            'lottery' => [
                'table' => 'lottery_rounds',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'lottery',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => null,
                    'scope'       => 'module',
                    'module'      => 'lottery',
                    'ref'         => (string)($r->type ?? ''),
                    'title'       => 'Lottery ' . (string)($r->id ?? ''),
                    'content'     => trim('Lottery ' . (string)($r->type ?? '') . ' ' . (string)($r->status ?? '')),
                    'metadata'    => ['type' => $r->type ?? null, 'status' => $r->status ?? null, 'created_at' => $r->created_at ?? null],
                ],
            ],
            'investments' => [
                'table' => 'investments',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'investment',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->user_id ?? 0) ?: null,
                    'scope'       => 'module',
                    'module'      => 'investment',
                    'ref'         => (string)($r->id ?? ''),
                    'title'       => 'Investment ' . (string)($r->id ?? ''),
                    'content'     => trim('Investment ' . (string)($r->status ?? '')),
                    'metadata'    => ['status' => $r->status ?? null, 'created_at' => $r->created_at ?? null],
                    'is_active'   => empty($r->deleted_at),
                ],
            ],
            'direct_messages' => [
                'table' => 'direct_messages',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'direct_message',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->sender_id ?? 0) ?: null,
                    'scope'       => 'module',
                    'module'      => 'direct_messages',
                    'ref'         => (string)($r->id ?? ''),
                    'title'       => 'Direct Message ' . (string)($r->id ?? ''),
                    'content'     => trim((string)($r->message ?? '')),
                    'metadata'    => ['sender_id' => $r->sender_id ?? null, 'recipient_id' => $r->recipient_id ?? null, 'created_at' => $r->created_at ?? null],
                ],
            ],
            'coupons' => [
                'table' => 'coupons',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'coupon',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => null,
                    'scope'       => 'module',
                    'module'      => 'coupons',
                    'ref'         => (string)($r->code ?? ''),
                    'title'       => (string)($r->code ?? ''),
                    'content'     => trim((string)($r->code ?? '') . ' ' . (string)($r->type ?? '') . ' ' . (string)($r->applicable_to ?? '')),
                    'metadata'    => ['code' => $r->code ?? null, 'type' => $r->type ?? null, 'active' => $r->active ?? null, 'created_at' => $r->created_at ?? null],
                    'is_active'   => empty($r->deleted_at),
                ],
            ],
            'bank_cards' => [
                'table' => 'bank_cards',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'bank_card',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->user_id ?? 0) ?: null,
                    'scope'       => 'user',
                    'module'      => 'bank_cards',
                    'ref'         => (string)($r->sheba ?? ''),
                    'title'       => (string)($r->bank_name ?? ''),
                    'content'     => trim((string)($r->card_number ?? '') . ' ' . (string)($r->sheba ?? '') . ' ' . (string)($r->bank_name ?? '')),
                    'metadata'    => ['card_number' => $r->card_number ?? null, 'sheba' => $r->sheba ?? null, 'bank_name' => $r->bank_name ?? null, 'status' => $r->status ?? null, 'created_at' => $r->created_at ?? null],
                    'is_active'   => empty($r->deleted_at),
                ],
            ],
            'kyc_verifications' => [
                'table' => 'kyc_verifications',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'kyc',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->user_id ?? 0) ?: null,
                    'scope'       => 'user',
                    'module'      => 'kyc',
                    'ref'         => (string)($r->national_id ?? ''),
                    'title'       => 'KYC ' . (string)($r->national_id ?? ''),
                    'content'     => trim((string)($r->national_id ?? '') . ' ' . (string)($r->rejection_reason ?? '')),
                    'metadata'    => ['national_id' => $r->national_id ?? null, 'status' => $r->status ?? null, 'created_at' => $r->created_at ?? null],
                ],
            ],
            'escrows' => [
                'table' => 'escrows',
                'build' => static fn($r, $cols) => [
                    'entity_type' => 'escrow',
                    'entity_id'   => (int)$r->id,
                    'owner_id'    => (int)($r->buyer_id ?? 0) ?: null,
                    'scope'       => 'user',
                    'module'      => 'escrow',
                    'ref'         => (string)($r->transaction_id ?? ''),
                    'title'       => 'Escrow ' . (string)($r->transaction_id ?? ''),
                    'content'     => trim('Escrow ' . (string)($r->transaction_id ?? '') . ' ' . (string)($r->status ?? '')),
                    'metadata'    => ['transaction_id' => $r->transaction_id ?? null, 'status' => $r->status ?? null, 'created_at' => $r->created_at ?? null],
                ],
            ],
        ];
    }
}
