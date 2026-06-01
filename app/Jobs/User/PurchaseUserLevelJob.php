<?php

declare(strict_types=1);

namespace App\Jobs\User;

class PurchaseUserLevelJob
{
    private \App\Services\Settings\AppSettings $appSettings;
    private \App\Models\UserLevel $levelModel;
    private \Core\Database $db;
    private \App\Contracts\WalletServiceInterface $walletService;
    private \App\Contracts\LoggerInterface $logger;
    private \Core\TransactionWrapper $transactionWrapper;
    public function __construct(
        \App\Services\Settings\AppSettings $appSettings,
        \App\Models\UserLevel $levelModel,
        \Core\Database $db,
        \App\Contracts\WalletServiceInterface $walletService,
        \App\Contracts\LoggerInterface $logger,
        \Core\TransactionWrapper $transactionWrapper
    ) {        $this->appSettings = $appSettings;
        $this->levelModel = $levelModel;
        $this->db = $db;
        $this->walletService = $walletService;
        $this->logger = $logger;
        $this->transactionWrapper = $transactionWrapper;
}

    public function handle(int $userId, string $levelSlug, string $currency = 'irt'): array
    {
        if (!$this->isEnabled() || !$this->appSettings->get('level_purchase_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم خرید سطح غیرفعال است.'];
        }

        $level = $this->levelModel->findBySlug($levelSlug);
        if (!$level || !$level->is_active) {
            return ['success' => false, 'message' => 'سطح مورد نظر یافت نشد.'];
        }

        $price = $currency === 'usdt' ? (float) $level->purchase_price_usdt : (float) $level->purchase_price_irt;
        if ($price <= 0) {
            return ['success' => false, 'message' => 'این سطح قابل خرید نیست.'];
        }

        // Ensure safe idempotency token and boundary check to block concurrent duplicate execution rolls
        // Ensure safe idempotency token - include hour to allow retry later in day if previous expired
        $idempotencyKey = "level_purch_{$userId}_{$levelSlug}_" . \date('YmdH');

        try {
            $referralPayload = null;

            $result = $this->transactionWrapper->runWithRetry(function() use ($userId, $levelSlug, $level, $price, $currency, $idempotencyKey, &$referralPayload) {
                // 🛡️ Pessimistic Lock on Users and Wallet together to prevent race conditions (CRIT-04)
                $stmt = $this->db->prepare("SELECT level_slug, level_expires_at, level_type FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $u = $stmt->fetch(\PDO::FETCH_OBJ);

                if ($u && $u->level_slug === $levelSlug && $u->level_type === 'purchased') {
                    if ($u->level_expires_at && \strtotime($u->level_expires_at) > \time()) {
                        return ['success' => false, 'message' => 'شما در حال حاضر اشتراک فعال برای این سطح را دارا هستید.'];
                    }
                }

                // Lock latest history record to prevent double history insertion concurrently (MED-08)
                $stmtHist = $this->db->prepare("SELECT id FROM user_level_histories WHERE user_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
                $stmtHist->execute([$userId]);
                $stmtHist->fetch();

                // Check idempotency INSIDE transaction with lock
                $stmt = $this->db->prepare("SELECT id FROM user_level_purchases WHERE idempotency_key = ? FOR UPDATE");
                $stmt->execute([$idempotencyKey]);
                if ($stmt->fetch()) {
                    return ['success' => false, 'message' => 'شما امروز درخواست مشابهی برای ارتقای این سطح ثبت کرده‌اید.'];
                }

                // 🛡️ MED-12 Fix (CRITICAL): Implement atomic transaction-level locking
                $stmt = $this->db->prepare("SELECT balance_irt, balance_usdt FROM wallets WHERE user_id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $wallet = $stmt->fetch(\PDO::FETCH_OBJ);
                
                if (!$wallet) {
                    return ['success' => false, 'message' => 'کیف پول کاربر یافت نشد.'];
                }

                $balanceField = ($currency === 'usdt') ? 'balance_usdt' : 'balance_irt';
                $currentBalance = (float)$wallet->$balanceField;
                
                if (\Core\ValueObjects\Money::fromString((string)((string)$price))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)((string)$currentBalance)))) {
                    return ['success' => false, 'message' => 'موجودی کافی نیست.'];
                }

                $withdrawResult = $this->walletService->withdrawInTransaction(
                    $userId,
                    $price,
                    $currency,
                    [
                        'type' => 'vip_purchase',
                        'description' => "خرید سطح {$level->name}",
                        'ref_type' => 'user_level_purchase',
                        'ref_id' => null,
                    ]
                );

                if (empty($withdrawResult['success'])) {
                    return ['success' => false, 'message' => $withdrawResult['message'] ?? 'موجودی کافی نیست.'];
                }

                $txId = $withdrawResult['transaction_id'] ?? null;

                $duration = (int) $level->purchase_duration_days;
                $expiresAt = \date('Y-m-d H:i:s', \strtotime("+{$duration} days"));

                $stmt = $this->db->prepare("
                    INSERT INTO user_level_purchases 
                    (user_id, level_slug, amount, currency, duration_days, starts_at, expires_at, status, transaction_id, idempotency_key)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?, 'active', ?, ?)
                ");
                $stmt->execute([$userId, $levelSlug, $price, $currency, $duration, $expiresAt, $txId, $idempotencyKey]);

                $stmt = $this->db->prepare("SELECT level_slug FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $currentUser = $stmt->fetch(\PDO::FETCH_OBJ);
                $fromLevel = $currentUser->level_slug ?? 'bronze';

                $stmt = $this->db->prepare("
                    UPDATE users SET 
                        level_slug = ?, 
                        level_type = 'purchased', 
                        level_expires_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([$levelSlug, $expiresAt, $userId]);

                $this->historyModel->create([
                    'user_id' => $userId,
                    'from_level' => $fromLevel,
                    'to_level' => $levelSlug,
                    'change_type' => 'purchase',
                    'reason' => "خرید سطح {$level->name} به مدت {$duration} روز",
                    'metadata' => ['price' => $price, 'currency' => $currency, 'duration' => $duration],
                ]);

                // آماده‌سازی payload کمیسیون معرفی — dispatch بعد از commit انجام می‌شود
                $referralPayload = [
                    'referrer_id' => $userId,
                    'amount' => $price,
                    'currency' => $currency,
                    'source_user_id' => $userId,
                    'context' => [
                        'action' => 'vip_purchase',
                        'level' => $levelSlug,
                        'duration' => $duration
                    ]
                ];

                $this->logger->info('User level purchased', [
                    'user_id' => $userId,
                    'level' => $levelSlug,
                    'price' => $price,
                    'currency' => $currency,
                ]);

                return [
                    'success' => true,
                    'message' => "سطح «{$level->name}» با موفقیت خریداری شد.",
                    'level' => $level,
                    'expires_at' => $expiresAt,
                ];
            });

            // 🚀 Migrated to event-driven referral commission — بعد از commit تراکنش، async ارسال می‌شود
            if (!empty($result['success']) && $referralPayload !== null && $this->eventDispatcher) {
                $this->eventDispatcher->dispatchAsync('referral.commission.process', $referralPayload);
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('level.purchase.failed', [
                'channel' => 'level',
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['success' => false, 'message' => 'خطا در خرید سطح. لطفاً دوباره تلاش کنید.'];
        }
    }
}
