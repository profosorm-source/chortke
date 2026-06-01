<?php

declare(strict_types=1);

namespace App\Jobs\Investment;

class RejectWithdrawalJob
{
    private \Core\Database $db;
    private \App\Models\Investment $investmentModel;
    private \App\Models\InvestmentWithdrawal $withdrawalModel;
    private \Core\EventDispatcher $eventDispatcher;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Models\Investment $investmentModel,
        \App\Models\InvestmentWithdrawal $withdrawalModel,
        \Core\EventDispatcher $eventDispatcher,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->investmentModel = $investmentModel;
        $this->withdrawalModel = $withdrawalModel;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
}

    public function handle(int $withdrawalId, int $adminId, string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $withdrawal = $this->db->query("SELECT * FROM investment_withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId])->fetch(\PDO::FETCH_OBJ);
            if (!$withdrawal || $withdrawal->status !== \App\Models\InvestmentWithdrawal::STATUS_PENDING) {
                throw new \Exception('درخواست معتبر نیست.');
            }

            $investment = $this->db->query("SELECT * FROM investments WHERE id = ? FOR UPDATE", [$withdrawal->investment_id])->fetch(\PDO::FETCH_OBJ);

            if ($investment) {
                // H-I5 Fix: Restore the reserved balance back to investment's current balance
                $restoredBalance = (float)$investment->current_balance + (float)$withdrawal->amount;
                $this->investmentModel->update($investment->id, [
                    'current_balance' => $restoredBalance
                ]);
            }

            $this->withdrawalModel->update($withdrawalId, [
                'status'           => \App\Models\InvestmentWithdrawal::STATUS_REJECTED,
                'rejection_reason' => $reason,
            ]);
            $this->db->commit();

            $this->auditTrail->record('investment.closed', (int)$withdrawal->user_id, [
                'action'        => 'withdrawal_rejected',
                'withdrawal_id' => $withdrawalId,
                'reason'        => $reason,
                'admin_id'      => $adminId,
            ], $adminId);

            $this->eventDispatcher->dispatchAsync('investment.withdrawal_rejected', [
                'user_id' => $withdrawal->user_id,
                'reason' => $reason
            ]);
            
            $this->logger->info('investment_withdrawal_rejected', ['message' => "Admin {$adminId} rejected withdrawal #{$withdrawalId}"]);

            return ['success' => true, 'message' => 'درخواست رد شد.'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('investment_withdrawal_reject_failed', [
                'withdrawal_id' => $withdrawalId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage()
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
