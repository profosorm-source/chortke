<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\BusinessException;
use App\Models\Withdrawal;
use Core\Database;
use App\Services\KYCService;
use App\Services\BankCardService;
use App\Services\AntiFraud\FraudGuardService;
use App\Services\OutboxService;
use App\Services\WalletService;
use App\Contracts\LoggerInterface;

trait WithdrawalHelperTrait
{
    /**
     * ====================== BUSINESS GUARD ======================
     * اعتبارسنجی جامع بیزینسی درخواست برداشت
     */
    public function guardCanCreateWithdrawal(int $userId, array $data): void
    {
        $amount   = (string)($data['amount'] ?? '0');
        $currency = strtoupper((string)($data['currency'] ?? 'IRT'));
        $ip       = (string)($data['ip'] ?? get_client_ip());

        if (bccomp($amount, '0', 8) <= 0) {
            throw new BusinessException('مبلغ برداشت نامعتبر است.');
        }

        if (!$this->kycService->isApproved($userId)) {
            throw new BusinessException('برای برداشت وجه باید احراز هویت (KYC) تکمیل شده باشد.');
        }

        if ($this->hasPendingWithdrawal($userId, true)) {
            throw new BusinessException('شما در حال حاضر یک درخواست برداشت در حال بررسی دارید.');
        }

        $summary = $this->wallet->getWalletSummary($userId);
        if (empty($summary->can_withdraw_today ?? false)) {
            throw new BusinessException('شما امروز قبلاً یک برداشت انجام داده‌اید.');
        }

        // بررسی ضد تقلب و ریسک عملیات
        $risk = $this->fraudGuard->checkAction($userId, 'withdrawal.create', [
            'amount'      => $amount,
            'currency'    => $currency,
            'ip'          => $ip,
            'fingerprint' => $data['fingerprint'] ?? generate_device_fingerprint(),
            'user_agent'  => get_user_agent(),
        ]);

        if (empty($risk['allowed'])) {
            $this->logger->warning('withdrawal.blocked_by_fraud_guard', [
                'user_id'  => $userId,
                'amount'   => $amount,
                'currency' => $currency,
                'reason'   => $risk['reason'] ?? 'unknown'
            ]);
            throw new BusinessException('درخواست برداشت به دلایل امنیتی مسدود شد. لطفاً با پشتیبانی تماس بگیرید.');
        }

        $this->logger->info('withdrawal.business_guard.passed', [
            'user_id'  => $userId,
            'amount'   => $amount,
            'currency' => $currency,
        ]);
    }

    /**
     * ثبت درخواست برداشت توسط کاربر همراه با کنترل تکرار اتمیک (Idempotency) و تراکنش امن
     */
    public function requestFromUser(int $userId, array $payload): array
    {
        $amount         = (string)($payload['amount'] ?? '0');
        $currency       = strtolower((string)($payload['currency'] ?? 'irt'));
        $bankCardId     = (int)($payload['bank_card_id'] ?? 0);
        $idempotencyKey = $payload['idempotency_key']
            ?? hash('sha256', $userId . '|' . $amount . '|' . $currency . '|' . ($payload['bank_card_id'] ?? '') . '|' . date('YmdHi'));

        return $this->idempotent(
            'withdrawal.request',
            $userId,
            [
                'amount'       => $amount,
                'currency'     => $currency,
                'bank_card_id' => $bankCardId,
                'user_id'      => $userId,
                'key'          => $idempotencyKey,
            ],
            function () use ($userId, $payload, $amount, $currency, $bankCardId, $idempotencyKey) {
                $startedTransaction = !$this->db->inTransaction();

                try {
                    if ($startedTransaction) {
                        $this->db->beginTransaction();
                    }

                    // ۱. اعمال قفل بدبینانه روی کیف پول کاربر
                    $walletLock = $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [$userId])->fetch();
                    if (!$walletLock) {
                        throw new BusinessException('کیف پول یافت نشد');
                    }

                    // ۲. بررسی قوانین بیزینسی تحت تراکنش و قفل
                    $this->guardCanCreateWithdrawal($userId, $payload);

                    // ۳. کارت بانکی اجباری برای ریال (IRT) و بررسی مالکیت کارت
                    if ($currency === 'irt') {
                        if ($bankCardId <= 0) {
                            throw new BusinessException('کارت بانکی الزامی است');
                        }
                        $card = $this->bankCardService->findVerifiedCardForUser($userId, $bankCardId);
                        if (!$card) {
                            throw new BusinessException('کارت بانکی معتبر یافت نشد');
                        }
                    }

                    // ۴. قفل کردن موجودی در کیف پول (توسط WalletService)
                    $withdrawResult = $this->wallet->withdraw($userId, $amount, $currency, [
                        'request_id'      => $payload['request_id'] ?? get_request_id(),
                        'ip_address'      => $payload['ip'] ?? get_client_ip(),
                        'idempotency_key' => "wth_req_wallet_" . $idempotencyKey,
                        'description'     => 'برداشت وجه از کیف پول',
                        'card_id'         => $bankCardId,
                    ]);

                    if (empty($withdrawResult['success'])) {
                        throw new BusinessException($withdrawResult['message'] ?? 'خطا در ثبت برداشت از کیف پول');
                    }

                    $txId = $withdrawResult['transaction_id'] ?? null;

                    // ۵. ایجاد درخواست برداشت در جدول withdrawals
                    $withdrawal = $this->model->create([
                        'user_id'         => $userId,
                        'amount'          => $amount,
                        'currency'        => $currency,
                        'status'          => 'pending',
                        'card_id'         => $bankCardId > 0 ? $bankCardId : null,
                        'transaction_id'  => $txId,
                        'idempotency_key' => $idempotencyKey,
                        'created_at'      => date('Y-m-d H:i:s'),
                        'updated_at'      => date('Y-m-d H:i:s'),
                    ]);

                    if (!$withdrawal) {
                        throw new \RuntimeException('خطا در ایجاد رکورد برداشت');
                    }

                    $result = [
                        'success' => true,
                        'message' => 'درخواست برداشت با موفقیت ثبت شد',
                        'data'    => [
                            'idempotency_key' => $idempotencyKey,
                            'withdrawal_id'   => $withdrawal->id,
                            'transaction_id'  => $txId,
                        ]
                    ];

                    if ($startedTransaction) {
                        $this->db->commit();
                    }

                    \Core\EventDispatcher::getInstance()->dispatch('withdrawal.status_changed', [
                        'withdrawal_id' => $withdrawal->id,
                        'user_id' => $userId,
                        'old_status' => null,
                        'new_status' => 'pending',
                        'amount' => $amount,
                        'currency' => $currency
                    ]);

                    $this->logger->info('withdrawal.request.success', [
                        'user_id' => $userId,
                        'amount'  => $amount,
                        'currency'=> $currency,
                        'tx_id'   => $txId,
                    ]);

                    return $result;
                } catch (BusinessException $e) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return ['success' => false, 'message' => $e->getMessage()];
                } catch (\Throwable $e) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $this->logger->error('withdrawal.request.failed', [
                        'user_id' => $userId,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString()
                    ]);
                    return ['success' => false, 'message' => 'خطای سیستمی رخ داد'];
                }
            },
            $idempotencyKey
        );
    }

    /**
     * بررسی وجود درخواست برداشت معلق
     */
    public function hasPendingWithdrawal(int $userId, bool $forUpdate = false): bool
    {
        return $this->model->hasPendingWithdrawal($userId, $forUpdate);
    }

    /**
     * دریافت لیست درخواست‌های برداشت کاربر
     */
    public function getUserWithdrawals(int $userId): array
    {
        return $this->model->getUserWithdrawals($userId);
    }

    /**
     * دریافت اطلاعات سقف‌های مالی برداشت کاربر
     */
    public function getLimitsForUser(int $userId, string $currency): array
    {
        $currency = strtoupper($currency);
        $dailyLimit = $currency === 'USDT' ? '5000.00000000' : '50000000.0000';
        
        $sql = "SELECT SUM(amount) as used_today FROM withdrawals 
                WHERE user_id = ? AND currency = ? 
                  AND status IN ('pending', 'processing', 'completed')
                  AND created_at >= DATE(NOW())";
        $row = $this->db->selectOne($sql, [$userId, strtolower($currency)]);
        $usedToday = (string)($row->used_today ?? '0');
        
        $remaining = bcsub($dailyLimit, $usedToday, $currency === 'USDT' ? 8 : 4);
        if (bccomp($remaining, '0', 8) < 0) {
            $remaining = '0';
        }

        return [
            'daily_limit'     => $dailyLimit,
            'used_today'      => $usedToday,
            'remaining_limit' => $remaining,
        ];
    }

    /**
     * تایید نهایی و دستی برداشت توسط ادمین به صورت کاملاً اتمیک و تراکنشی
     */
    public function adminApprove(int $withdrawalId, int $adminId): array
    {
        $startedTx = !$this->db->inTransaction();
        try {
            if ($startedTx) {
                $this->db->beginTransaction();
            }

            // ۱. قفل بدبینانه روی ردیف برداشت جهت جلوگیری از تداخل ادمین‌ها
            $withdrawal = $this->db->query("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId])->fetch(\PDO::FETCH_OBJ);
            if (!$withdrawal) {
                throw new BusinessException('درخواست برداشت یافت نشد');
            }

            if ($withdrawal->status === 'completed') {
                if ($startedTx) { $this->db->commit(); }
                return ['success' => true, 'message' => 'این برداشت قبلاً تأیید و نهایی شده است'];
            }

            if ($withdrawal->status !== 'pending' && $withdrawal->status !== 'processing') {
                throw new BusinessException('این درخواست در وضعیت معتبر برای تایید قرار ندارد');
            }

            // ۲. ثبت تسویه نهایی تراکنش در کیف پول
            $walletResult = $this->wallet->completeWithdrawal(
                (int)$withdrawal->user_id,
                (string)$withdrawal->amount,
                (string)$withdrawal->currency,
                (string)$withdrawal->transaction_id
            );

            if (!$walletResult) {
                throw new \RuntimeException('خطا در تسویه نهایی مبالغ قفل شده کیف پول');
            }

            // ۳. آپدیت وضعیت درخواست برداشت به completed
            if (!$this->model->updateStatus($withdrawalId, 'completed', null, $adminId)) {
                throw new \RuntimeException('خطا در بروزرسانی وضعیت برداشت به تکمیل شده');
            }

            if ($startedTx) {
                $this->db->commit();
            }

            \Core\EventDispatcher::getInstance()->dispatch('withdrawal.status_changed', [
                'withdrawal_id' => $withdrawalId,
                'user_id' => (int)$withdrawal->user_id,
                'old_status' => $withdrawal->status,
                'new_status' => 'completed',
                'amount' => $withdrawal->amount,
                'currency' => $withdrawal->currency,
                'admin_id' => $adminId
            ]);

            $this->auditTrail->record('withdrawal.admin_approved', (int)$withdrawal->user_id, [
                'withdrawal_id'  => $withdrawalId,
                'amount'         => $withdrawal->amount,
                'currency'       => $withdrawal->currency,
                'transaction_id' => $withdrawal->transaction_id,
            ], $adminId);

            return ['success' => true, 'message' => 'برداشت با موفقیت تأیید و تسویه شد'];

        } catch (BusinessException $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('withdrawal.admin_approve.failed', [
                'withdrawal_id' => $withdrawalId,
                'error'         => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی رخ داد'];
        }
    }

    /**
     * رد برداشت توسط ادمین و بازگشت مبالغ قفل شده به کیف پول کاربر (کاملاً اتمیک)
     */
    public function adminReject(int $withdrawalId, int $adminId, ?string $reason = null): array
    {
        $startedTx = !$this->db->inTransaction();
        try {
            if ($startedTx) {
                $this->db->beginTransaction();
            }

            // ۱. قفل بدبینانه روی ردیف برداشت جهت جلوگیری از تداخل ادمین‌ها
            $withdrawal = $this->db->query("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId])->fetch(\PDO::FETCH_OBJ);
            if (!$withdrawal) {
                throw new BusinessException('درخواست برداشت یافت نشد');
            }

            if ($withdrawal->status === 'rejected') {
                if ($startedTx) { $this->db->commit(); }
                return ['success' => true, 'message' => 'این برداشت قبلاً رد شده است'];
            }

            if ($withdrawal->status !== 'pending' && $withdrawal->status !== 'processing') {
                throw new BusinessException('این درخواست در وضعیت معتبر برای رد شدن قرار ندارد');
            }

            // ۲. آزاد کردن موجودی قفل شده کیف پول و برگشت آن به بالانس جاری کاربر
            $walletResult = $this->wallet->cancelWithdrawal(
                (int)$withdrawal->user_id,
                (string)$withdrawal->amount,
                (string)$withdrawal->currency,
                (string)$withdrawal->transaction_id
            );

            if (!$walletResult) {
                throw new \RuntimeException('خطا در بازگردانی مبالغ قفل شده به بالانس کیف پول');
            }

            // ۳. آپدیت وضعیت درخواست برداشت به rejected
            if (!$this->model->updateStatus($withdrawalId, 'rejected', $reason ?? 'توسط مدیریت رد شد', $adminId)) {
                throw new \RuntimeException('خطا در بروزرسانی وضعیت برداشت به رد شده');
            }

            if ($startedTx) {
                $this->db->commit();
            }

            \Core\EventDispatcher::getInstance()->dispatch('withdrawal.status_changed', [
                'withdrawal_id' => $withdrawalId,
                'user_id' => (int)$withdrawal->user_id,
                'old_status' => $withdrawal->status,
                'new_status' => 'rejected',
                'amount' => $withdrawal->amount,
                'currency' => $withdrawal->currency,
                'reason' => $reason,
                'admin_id' => $adminId
            ]);

            $this->auditTrail->record('withdrawal.admin_rejected', (int)$withdrawal->user_id, [
                'withdrawal_id'  => $withdrawalId,
                'amount'         => $withdrawal->amount,
                'currency'       => $withdrawal->currency,
                'reason'         => $reason,
                'transaction_id' => $withdrawal->transaction_id,
            ], $adminId);

            return ['success' => true, 'message' => 'برداشت با موفقیت رد شد و مبالغ به بالانس بازگشت داده شد'];

        } catch (BusinessException $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('withdrawal.admin_reject.failed', [
                'withdrawal_id' => $withdrawalId,
                'error'         => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی رخ داد'];
        }
    }

    /**
     * فرآیند حل خودکار برداشت‌های معلقِ گیرکرده در وضعیت processing که تراکنش مرتبط با آن‌ها شکست خورده است
     */
    public function autoResolveStuck(?int $adminBotId = null, int $stableMinutes = 30, int $limit = 50): array
    {
        $adminBotId = $adminBotId ?? 0;
        
        $candidates = $this->reconciliation->findAutoFixCandidates($stableMinutes, $limit);
        $result = ['scanned' => 0, 'fixed' => 0, 'escalated' => 0, 'errors' => 0];

        foreach ($candidates as $c) {
            $result['scanned']++;
            
            // ۱. انتقال وضعیت بازبینی به in_progress جهت قفل موقت
            $affected = $this->reconciliation->markReviewInProgress((int)$c->review_id, $adminBotId);
            if ($affected === 0) {
                $result['skipped']++;
                continue;
            }

            try {
                // ۲. اجرای لغو اتمیک برداشت گیرکرده (آزاد کردن بالانس و ثبت در لجر)
                $rejectRes = $this->adminReject(
                    (int)$c->withdrawal_id,
                    $adminBotId,
                    'Auto-resolved: withdrawal stuck in processing with failed/cancelled gateway transaction.'
                );

                if (!empty($rejectRes['success'])) {
                    $result['fixed']++;
                    // ۳. ثبت نهایی وضعیت بازبینی به عنوان auto_resolved
                    $this->reconciliation->markReviewAutoResolved(
                        (int)$c->review_id,
                        $adminBotId,
                        'Auto-fix success: refunded stuck lock balance successfully.'
                    );
                } else {
                    $result['errors']++;
                    // برگشت دادن بازبینی به وضعیت open در صورت بروز خطای بیزینسی
                    $this->reconciliation->markReviewOpenAgain(
                        (int)$c->review_id,
                        'Auto-fix business failure: ' . ($rejectRes['message'] ?? 'unknown')
                    );
                }
            } catch (\Throwable $e) {
                $result['errors']++;
                $this->logger->error('withdrawal.auto_resolve.failed_one', [
                    'withdrawal_id' => $c->withdrawal_id,
                    'error'         => $e->getMessage(),
                ]);
                $this->reconciliation->markReviewOpenAgain(
                    (int)$c->review_id,
                    'Auto-fix system error: ' . $e->getMessage()
                );
            }
        }

        return $result;
    }
}
