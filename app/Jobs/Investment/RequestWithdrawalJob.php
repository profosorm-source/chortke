<?php

declare(strict_types=1);

namespace App\Jobs\Investment;

class RequestWithdrawalJob
{
    private \Core\Database $db;
    private \App\Models\Investment $investmentModel;
    private \App\Models\InvestmentWithdrawal $withdrawalModel;
    public function __construct(
        \Core\Database $db,
        \App\Models\Investment $investmentModel,
        \App\Models\InvestmentWithdrawal $withdrawalModel
    ) {        $this->db = $db;
        $this->investmentModel = $investmentModel;
        $this->withdrawalModel = $withdrawalModel;
}

    public function handle(int $userId, array $data): array
    {
        $request = new RequestWithdrawalRequest($data);
        $validated = $request->validateOrFail();

        try {
            $this->db->beginTransaction();
            $amount = 0;
            $withdrawType = $validated['withdrawal_type'] ?? \App\Models\InvestmentWithdrawal::TYPE_PROFIT_ONLY;

            $investment = $this->db->query("SELECT * FROM investments WHERE user_id = ? AND status = ? FOR UPDATE", [$userId, \App\Models\Investment::STATUS_ACTIVE])->fetch(\PDO::FETCH_OBJ);
            if (!$investment) {
                throw new \Core\Exceptions\EntityNotFoundException('سرمایه‌گذاری فعالی ندارید.');
            }

            $canWithdraw = $this->investmentModel->canWithdraw($userId);
            if (!$canWithdraw['allowed']) {
                throw new \Core\Exceptions\InvalidStateException($canWithdraw['reason']);
            }

            if ($this->withdrawalModel->hasPending($userId)) {
                throw new \Core\Exceptions\InvalidStateException('شما یک درخواست برداشت در حال بررسی دارید.');
            }

            $currentBalance = (float)$investment->current_balance;
            $originalAmount = (float)$investment->amount;

            if ($withdrawType === \App\Models\InvestmentWithdrawal::TYPE_PROFIT_ONLY) {
                $profit = $currentBalance - $originalAmount;
                if ($profit <= 0) {
                    throw new \Core\Exceptions\BusinessException('سودی برای برداشت وجود ندارد. موجودی فعلی کمتر یا برابر سرمایه اولیه است.');
                }
                $amount = $profit;
            } else {
                $amount = $currentBalance;
            }

            $newBalance = $currentBalance - $amount;
            if ($newBalance < 0) {
                throw new \Core\Exceptions\InsufficientBalanceException('موجودی کافی برای برداشت وجود ندارد.');
            }

            $withdrawalId = $this->withdrawalModel->create([
                'investment_id'   => $investment->id,
                'user_id'         => $userId,
                'amount'          => $amount,
                'withdrawal_type' => $withdrawType,
                'status'          => \App\Models\InvestmentWithdrawal::STATUS_PENDING,
            ]);

            // H-I5 Fix: Deduct and reserve the balance immediately during request stage
            $this->investmentModel->update($investment->id, [
                'current_balance' => $newBalance
            ]);
            
            $this->db->commit();

            $this->auditTrail->record('investment.withdrawal_requested', $userId, [
                'withdrawal_id' => $withdrawalId,
                'amount'        => $amount,
                'type'          => $withdrawType,
            ]);

            return ['success' => true, 'message' => 'درخواست برداشت سود شما با موفقیت ثبت شد و در انتظار تأیید است.'];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
