<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

use App\Services\Payment\PaymentBaseService;
use Core\Database;
use App\Exceptions\BusinessException;
use App\Contracts\LoggerInterface;
use App\Models\Withdrawal;
use App\Services\Wallet\WalletService;
use App\Services\ReconciliationService;
use App\Services\AuditTrail;
use App\Events\WithdrawalApprovedEvent;
use Core\IdempotencyKey;
use Core\EventDispatcher;

/**
 * WithdrawalAdminService - مدیریت فرآیندهای مالی توسط ادمین و سیستم
 */
class WithdrawalAdminService extends PaymentBaseService
{
    private WalletService $wallet;
    private ReconciliationService $reconciliation;
    private AuditTrail $auditTrail;
    private Withdrawal $model;

    public function __construct(
        Database $db,
        WalletService $wallet,
        ReconciliationService $reconciliation,
        AuditTrail $auditTrail,
        Withdrawal $model,
        LoggerInterface $logger,
        IdempotencyKey $idempotencyKey
    ) {
        parent::__construct($logger, $idempotencyKey, $db);
        $this->wallet = $wallet;
        $this->reconciliation = $reconciliation;
        $this->auditTrail = $auditTrail;
        $this->model = $model;
    }

    public function adminApprove(int $withdrawalId, int $adminId, ?string $paymentReference = null): array
    {
        $result = $this->db->transaction(function() use ($withdrawalId, $adminId, $paymentReference) {
            $withdrawal = $this->db->query("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId])->fetch(\PDO::FETCH_OBJ);
            
            if (!$withdrawal || $withdrawal->status === 'completed') {
                return ['success' => true, 'message' => 'قبلاً تأیید شده است', '_skip_event' => true];
            }

            if (!$this->wallet->completeWithdrawal((int)$withdrawal->user_id, (string)$withdrawal->amount, (string)$withdrawal->currency, (string)$withdrawal->transaction_id)) {
                throw new \RuntimeException('خطا در تسویه کیف پول');
            }

            $this->model->updateStatus($withdrawalId, 'completed', null, $adminId);
            
            $this->auditTrail->record('withdrawal.admin_approved', (int)$withdrawal->user_id, [
                'id' => $withdrawalId,
                'payment_reference' => $paymentReference,
            ], $adminId);

            return [
                'success' => true,
                'message' => 'تأیید شد',
                '_withdrawal_snapshot' => [
                    'user_id' => (int)$withdrawal->user_id,
                    'amount' => (float)$withdrawal->amount,
                    'currency' => (string)$withdrawal->currency,
                ],
            ];
        });

        // 🚀 رویداد را بعد از commit تراکنش به صورت async ارسال می‌کنیم
        if (!empty($result['success']) && empty($result['_skip_event'])) {
            $snap = $result['_withdrawal_snapshot'];
            EventDispatcher::getInstance()->dispatchAsync(
                WithdrawalApprovedEvent::class,
                new WithdrawalApprovedEvent(
                    $snap['user_id'],
                    $withdrawalId,
                    $snap['amount'],
                    $snap['currency'],
                    $adminId
                )
            );
        }
        unset($result['_withdrawal_snapshot'], $result['_skip_event']);

        return $result;
    }

    public function adminReject(int $withdrawalId, int $adminId, ?string $reason = null): array
    {
        return $this->db->transaction(function() use ($withdrawalId, $adminId, $reason) {
            $withdrawal = $this->db->query("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId])->fetch(\PDO::FETCH_OBJ);
            
            if (!$withdrawal || $withdrawal->status === 'rejected') {
                return ['success' => true, 'message' => 'قبلاً رد شده است'];
            }

            if (!$this->wallet->cancelWithdrawal((int)$withdrawal->user_id, (string)$withdrawal->amount, (string)$withdrawal->currency, (string)$withdrawal->transaction_id)) {
                throw new \RuntimeException('خطا در بازگشت وجه');
            }

            $this->model->updateStatus($withdrawalId, 'rejected', $reason, $adminId);
            
            return ['success' => true, 'message' => 'رد شد'];
        });
    }

    public function autoResolveStuck(int $adminBotId = 0, int $stableMinutes = 30, int $limit = 50): array
    {
        $candidates = $this->reconciliation->findAutoFixCandidates($stableMinutes, $limit);
        $result = ['scanned' => 0, 'fixed' => 0];

        foreach ($candidates as $c) {
            $result['scanned']++;
            if ($this->reconciliation->markReviewInProgress((int)$c->review_id, $adminBotId)) {
                $res = $this->adminReject((int)$c->withdrawal_id, $adminBotId, 'Auto-resolved');
                if ($res['success']) {
                    $result['fixed']++;
                    $this->reconciliation->markReviewAutoResolved((int)$c->review_id, $adminBotId, 'Success');
                }
            }
        }
        return $result;
    }
}