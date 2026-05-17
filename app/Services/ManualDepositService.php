<?php

namespace App\Services;

use App\Services\Notification\NotificationService;
use Core\Database;
use App\Models\ManualDeposit;
use App\Models\BankCard;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\UploadService;
use App\Services\CurrencyService;


use App\Contracts\LoggerInterface;
class ManualDepositService extends \App\Services\BaseService
{
    private \App\Models\User        $userModel;
    private \App\Models\BankCard    $bankCardModel;
    private Database                $db;
    private ManualDeposit           $model;
    private WalletService           $wallet;
    private NotificationService     $notifier;
    private AuditTrail              $auditTrail;
    private ReconciliationService   $reconciliationService;
    private UploadService           $uploadService;
    private CurrencyService         $currencyService;

    public function __construct(
        Database                $db,
        WalletService           $walletService,
        NotificationService     $notificationService,
        \App\Models\ManualDeposit $model,
        \App\Models\BankCard      $bankCardModel,
        \App\Models\User          $userModel,
        AuditTrail              $auditTrail,
        LoggerInterface         $logger,
        ReconciliationService   $reconciliationService,
        UploadService           $uploadService,
        CurrencyService         $currencyService
    ) {
        parent::__construct($logger);
        $this->db                     = $db;
        $this->model                  = $model;
        $this->wallet                 = $walletService;
        $this->notifier               = $notificationService;
        $this->bankCardModel          = $bankCardModel;
        $this->userModel              = $userModel;
        $this->auditTrail             = $auditTrail;
        $this->reconciliationService  = $reconciliationService;
        $this->uploadService          = $uploadService;
        $this->currencyService        = $currencyService;
    }

    public function create(int $userId, array $data, ?string $receiptPath): array
    {
        $amount = isset($data['amount']) ? (string)$data['amount'] : '0';
        if (bccomp($amount, '0', 4) <= 0) {
            if (!empty($receiptPath)) {
                try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
            }
            return ['success' => false, 'message' => 'مبلغ واریز دستی باید بزرگتر از صفر باشد'];
        }

        $tracking = trim((string)($data['tracking_code'] ?? ''));
        if (empty($tracking)) {
            if (!empty($receiptPath)) {
                try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
            }
            return ['success' => false, 'message' => 'شماره پیگیری پرداخت الزامی است'];
        }

        $receiptHash = null;
        if (!empty($receiptPath) && \file_exists($receiptPath)) {
            $receiptHash = \hash_file('sha256', $receiptPath);
        }

        $cardId = (int)($data['card_id'] ?? $data['bank_card_id'] ?? 0);
        $card = null;
        if ($cardId > 0) {
            $card = $this->bankCardModel->findByIdAndUser($cardId, $userId);
            if (!$card || $card->status !== 'verified') {
                if (!empty($receiptPath)) {
                    try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
                }
                return ['success' => false, 'message' => 'کارت معتبر یافت نشد'];
            }
        }

        // ممانعت از ثبت واریز ریالی در صورت فعال بودن حالت تتر-تنها
        if (!$this->currencyService->isIRT()) {
            if (!empty($receiptPath)) {
                try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
            }
            return ['success' => false, 'message' => 'واریز دستی ریالی در وضعیت فعلی ارز مسدود است'];
        }

        $user = $this->userModel->find($userId);
        if (!$user || $user->kyc_status !== 'verified') {
            if (!empty($receiptPath)) {
                try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
            }
            return ['success' => false, 'message' => 'برای واریز دستی باید احراز هویت شما تأیید شده باشد'];
        }

        $this->db->beginTransaction();
        try {
            // بررسی مجدد وضعیت احراز هویت کاربر داخل تراکنش با قفل FOR SHARE جهت جلوگیری از Race Condition
            $userLock = $this->db->query(
                "SELECT id, kyc_status FROM users WHERE id = ? FOR SHARE",
                [$userId]
            )->fetch(\PDO::FETCH_OBJ);

            if (!$userLock || $userLock->kyc_status !== 'verified') {
                $this->db->rollBack();
                if (!empty($receiptPath)) {
                    try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
                }
                return ['success' => false, 'message' => 'برای واریز دستی باید احراز هویت شما تأیید شده باشد'];
            }

            // ۱. بررسی عدم وجود درخواست معلق فعلی با قفل تراکنشی
            $pending = $this->db->query(
                "SELECT id FROM manual_deposits WHERE user_id = ? AND status IN ('pending', 'under_review') LIMIT 1 FOR UPDATE",
                [$userId]
            )->fetch();
            
            if ($pending) {
                $this->db->rollBack();
                if (!empty($receiptPath)) {
                    try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
                }
                return ['success' => false, 'message' => 'شما یک درخواست واریز در انتظار دارید'];
            }

            // ۲. بررسی تکراری نبودن شماره پیگیری با قفل تراکنشی
            $existsTracking = $this->db->query(
                "SELECT id FROM manual_deposits WHERE tracking_code = ? LIMIT 1 FOR UPDATE",
                [$tracking]
            )->fetch();
            
            if ($existsTracking) {
                $this->db->rollBack();
                if (!empty($receiptPath)) {
                    try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
                }
                return ['success' => false, 'message' => 'این شماره پیگیری قبلاً ثبت شده است'];
            }

            // ۳. بررسی تکراری نبودن فیش بانکی با قفل تراکنشی
            if ($receiptHash !== null) {
                $duplicateReceipt = $this->db->query(
                    "SELECT id FROM manual_deposits WHERE receipt_hash = ? LIMIT 1 FOR UPDATE",
                    [$receiptHash]
                )->fetch();
                
                if ($duplicateReceipt) {
                    $this->db->rollBack();
                    if (!empty($receiptPath)) {
                        try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
                    }
                    return ['success' => false, 'message' => 'این فیش بانکی قبلاً در سیستم آپلود و ثبت شده است. لطفاً تصویر معتبر و جدیدی ارسال کنید'];
                }
            }

            $id = $this->model->create([
                'user_id'       => $userId,
                'card_id'       => $card ? $card->id : null,
                'amount'        => $amount,
                'currency'      => 'irt',
                'receipt_image' => $receiptPath,
                'receipt_hash'  => $receiptHash,
                'tracking_code' => $tracking,
                'bank_name'     => $card ? $card->bank_name : 'نامشخص',
                'description'   => $data['user_description'] ?? null,
                'status'        => 'pending',
            ]);

            if (!$id) {
                $this->db->rollBack();
                if (!empty($receiptPath)) {
                    try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
                }
                return ['success' => false, 'message' => 'خطا در ایجاد درخواست واریز دستی'];
            }

            $this->db->commit();
            
            $depositId = (int)($id->id ?? 0);
            $this->logger->info('manual_deposit.created', ['user_id' => $userId, 'id' => $depositId, 'amount' => $amount]);

            return [
                'success'    => true,
                'message'    => 'درخواست واریز ثبت شد و در انتظار بررسی است',
                'deposit_id' => $depositId,
            ];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (!empty($receiptPath)) {
                try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
            }
            $this->logger->error('manual_deposit.create.failed', [
                'user_id' => $userId,
                'amount'  => $amount,
                'error'   => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی در ثبت درخواست واریز'];
        }
    }

    /**
     * تأیید واریز دستی
     */
    public function approve(int $adminId, int $depositId, ?string $note): array
    {
        try {
            $this->db->beginTransaction();

            $temp = $this->db->query("SELECT user_id FROM manual_deposits WHERE id = ?", [$depositId])->fetch(\PDO::FETCH_OBJ);
            if (!$temp) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست یافت نشد'];
            }

            // 1. Lock Wallet row to establish consistent lock order hierarchy (Wallet -> ManualDeposit)
            $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [$temp->user_id])->fetch();

            // 2. Lock manual deposit row
            $d = $this->db->query("SELECT * FROM manual_deposits WHERE id = ? FOR UPDATE", [$depositId])->fetch(\PDO::FETCH_OBJ);

            if (!$d) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست یافت نشد'];
            }

            if (!in_array($d->status, ['pending', 'under_review'], true)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'این درخواست قبلاً بررسی شده است'];
            }

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
                $this->db->rollBack();
                return ['success' => false, 'message' => $ok['message'] ?? 'خطا در شارژ کیف پول'];
            }

            $this->model->updateStatus(
                $depositId,
                'approved',
                null,
                $adminId,
                $ok['transaction_id'],
                $note,
                ['processing']
            );

            $this->db->commit();

            // Run reconciliation service call outside the main atomic database transaction block
            try {
                $reconciliation = $this->reconciliationService->reconcilePayment([
                    'transaction_id' => (string)$ok['transaction_id'],
                    'reference_id'   => 'manual_deposit_' . $depositId,
                    'user_id'        => (int)$d->user_id,
                    'amount'         => (float)$amountStr,
                    'currency'       => 'irt',
                    'status'         => 'success',
                    'gateway'        => 'manual_bank',
                ]);

                if (!$reconciliation['success']) {
                    $this->logger->warning('manual_deposit.reconcile_failed', [
                        'deposit_id' => $depositId,
                        'error' => $reconciliation['message'] ?? 'Unknown'
                    ]);
                }
            } catch (\Throwable $reconEx) {
                $this->logger->error('manual_deposit.reconcile_exception', [
                    'deposit_id' => $depositId,
                    'error' => $reconEx->getMessage()
                ]);
            }

            // حذف فیزیکی فایل فیش از هاست پس از تایید ادمین جهت حفظ فضا و حریم خصوصی
            if (!empty($d->receipt_image)) {
                try {
                    $this->uploadService->delete($d->receipt_image);
                } catch (\Throwable $fileEx) {
                    $this->logger->warning('manual_deposit.file_deletion_failed', ['file' => $d->receipt_image, 'error' => $fileEx->getMessage()]);
                }
            }

            $this->auditTrail->record('deposit.approved', (int)$d->user_id, [
                'deposit_id'     => $depositId,
                'amount'         => $amountStr,
                'tracking_code'  => $d->tracking_code,
                'admin_id'       => $adminId,
                'transaction_id' => $ok['transaction_id'],
                'balance_before' => $ok['balance_before'] ?? null,
                'balance_after'  => $ok['balance_after'] ?? null,
            ], $adminId);

            $this->notifier->depositSuccess((int)$d->user_id, $amountStr, 'IRT');

            return ['success' => true, 'message' => 'واریز تأیید شد و کیف پول شارژ گردید'];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('manual_deposit.approve.failed', ['id' => $depositId, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در تأیید واریز'];
        }
    }

    public function reject(int $adminId, int $depositId, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $temp = $this->db->query("SELECT user_id FROM manual_deposits WHERE id = ?", [$depositId])->fetch(\PDO::FETCH_OBJ);
            if (!$temp) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست یافت نشد'];
            }

            // 1. Lock Wallet row to establish consistent lock order hierarchy (Wallet -> ManualDeposit)
            $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [$temp->user_id])->fetch();

            // 2. Lock manual deposit row
            $d = $this->db->query("SELECT * FROM manual_deposits WHERE id = ? FOR UPDATE", [$depositId])->fetch(\PDO::FETCH_OBJ);

            if (!$d) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست یافت نشد'];
            }

            if (!in_array($d->status, ['pending', 'under_review'], true)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'این درخواست قبلاً بررسی شده است'];
            }

            $amountStr = (string)$d->amount;

            $this->model->updateStatus(
                $depositId,
                'rejected',
                $reason,
                $adminId,
                null,
                $reason,
                ['pending', 'under_review']
            );

            $this->auditTrail->record('deposit.rejected', (int)$d->user_id, [
                'deposit_id' => $depositId,
                'amount'     => $amountStr,
                'reason'     => $reason,
                'admin_id'   => $adminId,
            ], $adminId);

            $this->db->commit();

            $this->notifier->send(
                (int)$d->user_id,
                \App\Models\Notification::TYPE_DEPOSIT,
                'واریز دستی رد شد',
                'درخواست واریز دستی شما رد شد. دلیل: ' . $reason,
                ['deposit_id' => $depositId],
                url('/wallet/manual-deposit/history'),
                'مشاهده',
                'high'
            );

            return ['success' => true, 'message' => 'رد شد'];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('manual_deposit.reject.failed', ['id' => $depositId, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در رد درخواست واریز'];
        }
    }

    /**
     * جستجوی سریع واریزهای دستی برای سیستم سرچ مرکزی
     */
    public function quickSearchManualDeposits(string $term, int $limit = 5): array
    {
        $query = $this->model->query()
            ->selectRaw("manual_deposits.id, manual_deposits.amount, 'manual' as type, manual_deposits.status, manual_deposits.created_at, u.full_name, u.email")
            ->leftJoin('users as u', 'u.id', '=', 'manual_deposits.user_id');

        $this->model->applySearch($query, $term);

        if (!empty($term)) {
            $escaped = addcslashes(trim($term), '%_');
            $like = "%{$escaped}%";
            $query->where(function($sub) use ($like) {
                $sub->orWhere('u.email', 'LIKE', $like);
            });
        }

        return $query->orderBy('manual_deposits.created_at', 'DESC')
                     ->limit($limit)
                     ->get() ?? [];
    }
}

