<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;

/**
 * SearchProjectionListener — همگام‌سازی Read-Model جستجو با رویدادهای دامنه (CQRS Write→Read).
 *
 * این Listener جایگزین فایل خالی پیشین `Search\DomainActivityListener` است و
 * تنها مسئولیت آن، به‌روزرسانی جدول `search_projections` در پاسخ به رویدادهای
 * دامنه است. هیچ side-effect دیگری (مالی/نوتیفیکیشن/...) اینجا انجام نمی‌شود تا
 * مرز مسئولیت با `App\Listeners\DomainActivityListener` حفظ شود.
 *
 * طراحی fail-safe: هر خطا فقط لاگ می‌شود و هرگز جریان اصلی دامنه را نمی‌شکند.
 */
final class SearchProjectionListener
{
    private SearchIndexer $indexer;
    private LoggerInterface $logger;
    public function __construct(
        SearchIndexer $indexer,
        LoggerInterface $logger
    ) {        $this->indexer = $indexer;
        $this->logger = $logger;

    }

    /**
     * نقطه‌ی ورود واحد؛ نام رویداد را به handler مناسب map می‌کند.
     */
    public function handle(string|object $event, array $data = []): void
    {
        [$eventName, $payload] = $this->normalize($event, $data);

        try {
            match ($eventName) {
                // ── معاملات / مالی ──
                'wallet.deposit.completed',
                'wallet.withdraw.completed',
                'wallet.pay.completed'        => $this->indexTransaction($payload),
                'withdrawal.created',
                'withdrawal.approved'         => $this->indexWithdrawal($payload),
                'deposit.manual_created',
                'deposit.manual_approved'     => $this->indexManualDeposit($payload),

                // ── محتوا و تسک ──
                'content.created',
                'content.approved'            => $this->indexContent($payload),

                // ── ویترین ──
                'vitrine.listing_created',
                'vitrine.listing_updated'     => $this->indexVitrine($payload),
                'vitrine.listing_removed',
                'vitrine.listing_expired'     => $this->deactivate('vitrine', $payload['id'] ?? $payload['listing_id'] ?? 0),

                // ── تیکت ──
                'ticket.created',
                'ticket.updated'              => $this->indexTicket($payload),

                // ── پیام مستقیم ──
                'direct_message.sent'         => $this->indexDirectMessage($payload),

                // ── اینفلوئنسر ──
                'influencer.profile_updated'  => $this->indexInfluencer($payload),

                // ── Prediction ──
                'prediction.created',
                'prediction.updated'          => $this->indexPrediction($payload),
                'prediction.deleted'          => $this->deactivate('prediction', $payload['id'] ?? 0),

                // ── Lottery ──
                'lottery.created',
                'lottery.updated'             => $this->indexLottery($payload),
                'lottery.deleted'             => $this->deactivate('lottery', $payload['id'] ?? 0),

                // ── Investment ──
                'investment.created',
                'investment.updated'          => $this->indexInvestment($payload),
                'investment.deleted'          => $this->deactivate('investment', $payload['id'] ?? 0),

                // ── Bank Card ──
                'bank_card.created',
                'bank_card.updated'           => $this->indexBankCard($payload),
                'bank_card.deleted'           => $this->deactivate('bank_card', $payload['id'] ?? 0),

                // ── KYC ──
                'kyc.created',
                'kyc.updated'                 => $this->indexKyc($payload),

                // ── Escrow ──
                'escrow.created',
                'escrow.updated'              => $this->indexEscrow($payload),

                // ── Social Task ──
                'social_task.created',
                'social_task.updated',
                'social_task_execution.created',
                'social_task_execution.updated' => $this->indexSocialTask($payload),
                'social_task.deleted'           => $this->deactivate('social_task', $payload['id'] ?? 0),

                // ── Crypto Deposit ──
                'crypto_deposit.created',
                'crypto_deposit.updated',
                'crypto_deposit.completed'      => $this->indexCryptoDeposit($payload),

                // ── Message Moderation (Ticket Messages) ──
                'ticket_message.created',
                'ticket_message.moderated'      => $this->indexTicketMessage($payload),

                // ── Coupon ──
                'coupon.created',
                'coupon.updated'              => $this->indexCoupon($payload),
                'coupon.deleted'              => $this->deactivate('coupon', $payload['id'] ?? 0),

                // ── حذف عمومی ──
                'account.deleted'             => $this->deactivateOwner((int)($payload['user_id'] ?? 0)),

                default                       => null,
            };
        } catch (\Throwable $e) {
            $this->logger->warning('search.projection.sync_failed', [
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Handlers
    // ─────────────────────────────────────────────────────────────

    private function indexTransaction(array $d): void
    {
        $result = $d['result'] ?? $d;
        $id = (int)($result['transaction_db_id'] ?? $d['transaction_db_id'] ?? 0);
        $txId = (string)($result['transaction_id'] ?? $d['transaction_id'] ?? '');
        $userId = (int)($d['user_id'] ?? 0);
        if ($id <= 0 && $txId === '') {
            return;
        }

        $title = trim(($result['type'] ?? 'transaction') . ' ' . ($result['amount'] ?? ''));
        $content = trim(($result['description'] ?? '') . ' ' . $txId);

        $this->dualScope('transaction', $id > 0 ? $id : crc32($txId), $userId, 'transactions', $txId, $title, $content, [
            'transaction_id' => $txId,
            'amount'   => $result['amount'] ?? null,
            'currency' => $result['currency'] ?? null,
            'status'   => $result['status'] ?? null,
            'type'     => $result['type'] ?? null,
        ]);
    }

    private function indexWithdrawal(array $d): void
    {
        $id = (int)($d['id'] ?? $d['withdrawal_id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $userId = (int)($d['user_id'] ?? 0);
        $ref = (string)($d['tracking_code'] ?? $d['transaction_id'] ?? '');
        $this->dualScope('withdrawal', $id, $userId, 'withdrawals', $ref,
            'withdrawal ' . ($d['amount'] ?? ''),
            trim($ref . ' ' . ($d['status'] ?? '')),
            [
                'tracking_code' => $d['tracking_code'] ?? null,
                'amount' => $d['amount'] ?? null,
                'status' => $d['status'] ?? null,
            ]
        );
    }

    private function indexManualDeposit(array $d): void
    {
        $id = (int)($d['deposit_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $userId = (int)($d['user_id'] ?? 0);
        $ref = (string)($d['tracking_code'] ?? $d['transaction_id'] ?? '');
        $this->dualScope('manual_deposit', $id, $userId, 'manual_deposits', $ref,
            'manual deposit ' . ($d['amount'] ?? ''),
            trim($ref . ' ' . ($d['status'] ?? '')),
            ['tracking_code' => $d['tracking_code'] ?? null, 'amount' => $d['amount'] ?? null, 'status' => $d['status'] ?? null]
        );
    }

    private function indexContent(array $d): void
    {
        $id = (int)($d['content_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $userId = (int)($d['user_id'] ?? 0);
        $this->dualScope('content', $id, $userId, 'content', (string)($d['title'] ?? ''),
            (string)($d['title'] ?? ''),
            trim((string)($d['description'] ?? '') . ' ' . (string)($d['video_url'] ?? '')),
            ['title' => $d['title'] ?? null, 'status' => $d['status'] ?? null, 'platform' => $d['platform'] ?? null]
        );
    }

    private function indexVitrine(array $d): void
    {
        $id = (int)($d['id'] ?? $d['listing_id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        // ویترین عمومی است: scope=module + قابل‌مشاهده برای مالک (seller)
        $this->indexer->index(
            'vitrine', $id,
            (string)($d['title'] ?? ''),
            trim((string)($d['description'] ?? '') . ' ' . (string)($d['username'] ?? '')),
            [
                'title' => $d['title'] ?? null,
                'price_usdt' => $d['price_usdt'] ?? null,
                'status' => $d['status'] ?? null,
                'username' => $d['username'] ?? null,
            ],
            ($d['status'] ?? 'active') !== 'deleted',
            (int)($d['seller_id'] ?? 0) ?: null,
            'module',
            'vitrines',
            (string)($d['username'] ?? '')
        );
    }

    private function indexTicket(array $d): void
    {
        $id = (int)($d['ticket_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $userId = (int)($d['user_id'] ?? 0);
        $this->dualScope('ticket', $id, $userId, 'tickets', (string)($d['ticket_code'] ?? ''),
            (string)($d['subject'] ?? ''),
            trim((string)($d['subject'] ?? '') . ' ' . (string)($d['status'] ?? '')),
            ['subject' => $d['subject'] ?? null, 'status' => $d['status'] ?? null, 'priority' => $d['priority'] ?? null]
        );
    }

    private function indexDirectMessage(array $d): void
    {
        $id = (int)($d['id'] ?? $d['message_id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        // پیام مستقیم بین دو طرف؛ هر دو طرف باید بتوانند جستجو کنند → دو رکورد
        $senderId = (int)($d['sender_id'] ?? 0);
        $recipientId = (int)($d['recipient_id'] ?? 0);
        $message = (string)($d['message'] ?? '');

        foreach (array_filter([$senderId, $recipientId]) as $ownerId) {
            $this->indexer->index(
                'direct_message',
                // entity_id منحصربه‌فرد per owner تا UNIQUE(entity_type, entity_id) نشکند
                (int)($id * 10 + ($ownerId === $senderId ? 1 : 2)),
                'message',
                $message,
                ['message_id' => $id, 'sender_id' => $senderId, 'recipient_id' => $recipientId],
                true,
                $ownerId,
                'user',
                'direct_messages',
                null
            );
        }
    }

    private function indexInfluencer(array $d): void
    {
        $id = (int)($d['profile_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $this->indexer->index(
            'influencer', $id,
            (string)($d['username'] ?? ''),
            trim((string)($d['bio'] ?? '') . ' ' . (string)($d['platform'] ?? '') . ' ' . (string)($d['page_url'] ?? '')),
            ['username' => $d['username'] ?? null, 'platform' => $d['platform'] ?? null, 'status' => $d['status'] ?? null],
            ($d['status'] ?? 'active') !== 'deleted',
            (int)($d['user_id'] ?? 0) ?: null,
            'module',
            'influencers',
            (string)($d['username'] ?? '')
        );
    }

    private function indexSocialTask(array $d): void
    {
        $id = (int)($d['execution_id'] ?? $d['task_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) return;
        
        $userId = (int)($d['user_id'] ?? 0);
        $title = (string)($d['title'] ?? 'Social Task');
        $platform = (string)($d['platform'] ?? '');
        $status = (string)($d['status'] ?? '');
        
        $this->dualScope(
            'social_task', $id, $userId, 'social_tasks', $platform, 
            $title, 
            trim($title . ' ' . $platform . ' ' . $status),
            ['platform' => $platform, 'status' => $status, 'title' => $title]
        );
    }

    private function indexCryptoDeposit(array $d): void
    {
        $id = (int)($d['deposit_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) return;
        
        $userId = (int)($d['user_id'] ?? 0);
        $txHash = (string)($d['tx_hash'] ?? $d['transaction_hash'] ?? '');
        $wallet = (string)($d['wallet_address'] ?? $d['address'] ?? '');
        $network = (string)($d['network'] ?? '');
        $amount = (string)($d['amount'] ?? '');
        
        $this->dualScope(
            'crypto_deposit', $id, $userId, 'crypto_deposits', $txHash, 
            'Crypto Deposit ' . $amount, 
            trim($txHash . ' ' . $wallet . ' ' . $network),
            ['tx_hash' => $txHash, 'wallet_address' => $wallet, 'network' => $network, 'amount' => $amount]
        );
    }

    private function indexTicketMessage(array $d): void
    {
        $id = (int)($d['message_id'] ?? $d['id'] ?? 0);
        if ($id <= 0) return;
        
        $userId = (int)($d['user_id'] ?? 0);
        $ticketId = (int)($d['ticket_id'] ?? 0);
        $content = (string)($d['content'] ?? $d['message'] ?? '');
        
        $this->dualScope(
            'ticket_message', $id, $userId, 'ticket_messages', (string)$ticketId, 
            'Ticket Message #' . $ticketId, 
            $content,
            ['ticket_id' => $ticketId]
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * نمایه‌گذاری در scope=user (مالک) — رکورد متعلق به یک کاربر.
     */
    private function dualScope(
        string $entityType,
        int $entityId,
        int $ownerId,
        string $module,
        ?string $ref,
        string $title,
        string $content,
        array $metadata
    ): void {
        if ($entityId <= 0) {
            return;
        }
        $this->indexer->index(
            $entityType,
            $entityId,
            $title !== '' ? $title : $entityType,
            $content,
            $metadata,
            true,
            $ownerId > 0 ? $ownerId : null,
            'user',
            $module,
            $ref
        );
    }

    private function deactivate(string $entityType, $entityId): void
    {
        $id = (int)$entityId;
        if ($id > 0) {
            $this->indexer->deactivate($entityType, $id);
        }
    }

    private function deactivateOwner(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        // غیرفعال‌سازی نرم همه‌ی projectionهای کاربر حذف‌شده
        // (پیاده‌سازی ساده: از طریق indexer در دسترس نیست، لاگ برای پیگیری دستی/Backfill)
        $this->logger->info('search.projection.owner_deletion_requested', ['user_id' => $userId]);
    }

    /**
     * @return array{0:string,1:array}
     */
    private function normalize(string|object $event, array $data): array
    {
        if (is_object($event)) {
            $eventData = method_exists($event, 'getData') ? (array)$event->getData() : [];
            if (method_exists($event, 'getName')) {
                return [(string)$event->getName(), $eventData];
            }
            $ref = new \ReflectionClass($event);
            $short = preg_replace('/Event$/', '', $ref->getShortName());
            $name = strtolower((string)preg_replace('/([a-z0-9])([A-Z])/', '$1.$2', (string)$short));
            return [$name, $eventData];
        }

        return [$event, $data];
    }
}
