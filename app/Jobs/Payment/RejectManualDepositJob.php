<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class RejectManualDepositJob
{
    private \Core\Database $db;
    private \App\Models\ManualDeposit $model;
    private \Core\EventDispatcher $eventDispatcher;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Models\ManualDeposit $model,
        \Core\EventDispatcher $eventDispatcher,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->model = $model;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
}

    public function handle(int $adminId, int $depositId, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $saga = $this->context->getContainer()->make(\App\Services\SagaOrchestrator::class);
            $d = null;

            $saga->addStep(
                'lock_and_validate',
                function () use ($depositId, &$d) {
                    $temp = $this->db->query("SELECT user_id FROM manual_deposits WHERE id = ?", [$depositId])->fetch(\PDO::FETCH_OBJ);
                    if (!$temp) {
                        throw new \Exception('درخواست یافت نشد');
                    }

                    // 1. Lock Wallet row
                    $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [$temp->user_id])->fetch();

                    // 2. Lock manual deposit row
                    $d = $this->db->query("SELECT * FROM manual_deposits WHERE id = ? FOR UPDATE", [$depositId])->fetch(\PDO::FETCH_OBJ);

                    if (!$d) {
                        throw new \Exception('درخواست یافت نشد');
                    }

                    if (!in_array($d->status, ['pending', 'under_review'], true)) {
                        throw new \Exception('این درخواست قبلاً بررسی شده است');
                    }

                    return $d;
                },
                function () {}
            )->addStep(
                'update_status',
                function ($d) use ($depositId, $adminId, $reason) {
                    $this->model->updateStatus(
                        $depositId,
                        'rejected',
                        $reason,
                        $adminId,
                        null,
                        $reason,
                        ['pending', 'under_review']
                    );
                    return true;
                },
                function () {}
            );

            $saga->execute();
            $this->db->commit();

            $this->eventDispatcher->dispatchAsync('deposit.manual_rejected', [
                'user_id' => (int)$d->user_id,
                'deposit_id' => $depositId,
                'amount' => (string)$d->amount,
                'reason' => $reason,
                'admin_id' => $adminId
            ]);

            return ['success' => true, 'message' => 'رد شد'];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('manual_deposit.reject.failed', [
                'channel' => 'deposit',
                'id' => $depositId, 
                'err' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطا در رد درخواست واریز'];
        }
    }
    private function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }

}
