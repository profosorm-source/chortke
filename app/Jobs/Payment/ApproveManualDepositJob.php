<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class ApproveManualDepositJob
{
    private \Core\Database $db;
    private \App\Models\ManualDeposit $model;
    private \App\Contracts\WalletServiceInterface $wallet;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\Payment\ReconciliationService $reconciliationService;
    private \Core\EventDispatcher $eventDispatcher;
    private \App\Services\UploadService $uploadService;
    public function __construct(
        \Core\Database $db,
        \App\Models\ManualDeposit $model,
        \App\Contracts\WalletServiceInterface $wallet,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Payment\ReconciliationService $reconciliationService,
        \Core\EventDispatcher $eventDispatcher,
        \App\Services\UploadService $uploadService
    ) {        $this->db = $db;
        $this->model = $model;
        $this->wallet = $wallet;
        $this->logger = $logger;
        $this->reconciliationService = $reconciliationService;
        $this->eventDispatcher = $eventDispatcher;
        $this->uploadService = $uploadService;
}

    public function handle(int $adminId, int $depositId, ?string $note): array
    {
        try {
            $this->db->beginTransaction();
            $saga = $this->context->getContainer()->make(\App\Services\SagaOrchestrator::class);

            $d = null;
            $ok = null;

            $saga->addStep(
                'lock_and_validate',
                function () use ($depositId, &$d) {
                    $temp = $this->db->query("SELECT user_id FROM manual_deposits WHERE id = ?", [$depositId])->fetch(\PDO::FETCH_OBJ);
                    if (!$temp) {
                        throw new \Exception('درخواست یافت نشد');
                    }

                    // 1. Lock Wallet row to establish consistent lock order hierarchy (Wallet -> ManualDeposit)
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
                'wallet_deposit',
                function ($d) use ($depositId, $adminId, &$ok) {
                    // Transition status to transitional 'processing' state first
                    $this->model->updateStatus(
                        $depositId,
                        'processing',
                        null,
                        $adminId,
                        null,
                        null,
                        ['pending', 'under_review']
                    );

                    $amountStr = (string)$d->amount;

                    $ok = $this->wallet->depositInTransaction(
                        (int)$d->user_id,
                        $amountStr,
                        'irt',
                        [
                            'type'          => 'manual_deposit',
                            'deposit_id'    => $depositId,
                            'tracking_code' => $d->tracking_code,
                            'approved_by'   => $adminId,
                            'description'   => 'واریز دستی (تأیید ادمین) - کد: ' . ($d->tracking_code ?? 'N/A'),
                        ]
                    );

                    if (!$ok['success']) {
                        throw new \Exception($ok['message'] ?? 'خطا در شارژ کیف پول');
                    }

                    return $d;
                },
                function (\Throwable $e) use ($depositId) {
                    $this->logger->warning('saga.compensating.manual_approve_wallet', ['deposit_id' => $depositId]);
                }
            )->addStep(
                'update_status_and_reconcile',
                function ($d) use ($depositId, $adminId, $note, &$ok) {
                    $this->model->updateStatus(
                        $depositId,
                        'approved',
                        null,
                        $adminId,
                        $ok['transaction_id'],
                        $note,
                        ['processing']
                    );

                    $amountStr = (string)$d->amount;

                    try {
                        $reconciliation = $this->reconciliationService->reconcilePayment([
                            'transaction_id' => (string)$ok['transaction_id'],
                            'reference_id'   => 'manual_deposit_' . $depositId,
                            'user_id'        => (int)$d->user_id,
                            'amount'         => $amountStr,
                            'currency'       => 'irt',
                            'status'         => 'success',
                            'gateway'        => 'manual_bank',
                            'is_internal'    => true,
                        ]);

                        if (!$reconciliation['success']) {
                            $this->logger->warning('manual_deposit.reconcile_failed', [
                                'deposit_id' => $depositId,
                                'error' => $reconciliation['message'] ?? 'Unknown'
                            ]);
                            $this->eventDispatcher->dispatchAsync('reconciliation.failed', [
                                'type' => 'manual_deposit',
                                'id' => $depositId,
                                'user_id' => (int)$d->user_id,
                                'amount' => $amountStr,
                                'error' => $reconciliation['message'] ?? 'Mismatch detected'
                            ]);
                        }
                    } catch (\Throwable $reconEx) {
                        $this->logger->error('manual_deposit.reconcile_exception', [
                            'deposit_id' => $depositId,
                            'error' => $reconEx->getMessage()
                        ]);
                    }

                    return true;
                },
                function (\Throwable $e) use ($depositId) {
                    $this->logger->warning('saga.compensating.manual_approve_status', ['deposit_id' => $depositId]);
                }
            );

            $saga->execute();
            $this->db->commit();

            // حذف فیزیکی فایل فیش از هاست پس از تایید ادمین جهت حفظ فضا و حریم خصوصی
            if (!empty($d->receipt_image)) {
                try {
                    $this->uploadService->delete($d->receipt_image);
                } catch (\Throwable $fileEx) {
                    $this->logger->warning('manual_deposit.file_deletion_failed', ['file' => $d->receipt_image, 'error' => $fileEx->getMessage()]);
                }
            }

            $this->eventDispatcher->dispatchAsync('deposit.manual_approved', [
                'user_id' => (int)$d->user_id,
                'deposit_id' => $depositId,
                'amount' => (string)$d->amount,
                'tracking_code' => $d->tracking_code,
                'admin_id' => $adminId,
                'transaction_id' => $ok['transaction_id']
            ]);

            return ['success' => true, 'message' => 'واریز تأیید شد و کیف پول شارژ گردید'];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('manual_deposit.approve.failed', [
                'channel' => 'deposit',
                'id' => $depositId, 
                'err' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطا در تأیید واریز'];
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
