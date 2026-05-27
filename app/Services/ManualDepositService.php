<?php

namespace App\Services;

use Core\Database;
use App\Models\ManualDeposit;
use App\Models\BankCard;
use App\Models\User;
use App\Services\UploadService;
use App\Services\CurrencyService;
use App\Validators\Requests\CreateManualDepositRequest;
use Core\EventDispatcher;
use Core\RateLimiter;
use Core\IdempotencyKey;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;

use App\Contracts\LoggerInterface;
class ManualDepositService extends \App\Services\BaseService
{
    private \App\Models\User        $userModel;
    private \App\Models\BankCard    $bankCardModel;
    private ManualDeposit           $model;
    private WalletService           $wallet;
    private ReconciliationService   $reconciliationService;
    private UploadService           $uploadService;
    private CurrencyService         $currencyService;

    public function __construct(
        WalletService           $walletService,
        \App\Models\ManualDeposit $model,
        \App\Models\BankCard      $bankCardModel,
        \App\Models\User          $userModel,
        Database                $db,
        LoggerInterface         $logger,
        EventDispatcher         $eventDispatcher,
        private RateLimiter     $rateLimiter,
        private IdempotencyKey  $idempotency,
        ReconciliationService   $reconciliationService,
        UploadService           $uploadService,
        CurrencyService         $currencyService
    ) {
        parent::__construct($logger, null, $db, null, null, null, null, $eventDispatcher);
        $this->model                  = $model;
        $this->wallet                 = $walletService;
        $this->bankCardModel          = $bankCardModel;
        $this->userModel              = $userModel;
        $this->reconciliationService  = $reconciliationService;
        $this->uploadService          = $uploadService;
        $this->currencyService        = $currencyService;
    }

    public function create(int $userId, array $data, ?string $receiptPath): array
    {
        // 🛡️ Idempotency Check: Prevent duplicate form submissions
        $ikey = $data['idempotency_key'] ?? null;
        if ($ikey) {
            $cachedResponse = $this->idempotency->check($ikey, "manual_deposit:{$userId}");
            if ($cachedResponse) {
                return $cachedResponse;
            }
        }

        // 🛡️ Service-Layer Rate Limiting: Prevent rapid-fire manual deposit requests
        if (!$this->rateLimiter->attempt("manual_deposit_create:{$userId}", 3, 600)) {
            $this->logger->warning('manual_deposit.rate_limit_exceeded', ['user_id' => $userId]);
            return $this->idempotency->save($ikey, ['success' => false, 'message' => 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً ۱۰ دقیقه دیگر تلاش کنید.'], 600);
        }

        $request = new CreateManualDepositRequest($data);
        if (!$request->validate()) {
            if (!empty($receiptPath)) {
                try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
            }

            return [
                'success' => false,
                'message' => 'اطلاعات واریز نامعتبر است',
                'errors' => $request->errors(),
            ];
        }

        $validated = $request->validated();
        $amount = (string)$validated['amount'];
        $tracking = trim((string)$validated['tracking_code']);
        $dateStr = $validated['deposit_date'];
        $timeStr = $validated['deposit_time'];
        $receiptHash = null;
        if (!empty($receiptPath) && \file_exists($receiptPath)) {
            $receiptHash = \hash_file('sha256', $receiptPath);
        }

        try {
            $date = new \DateTime($dateStr);
            $now = new \DateTime();
            $diff = $date->diff($now);
            if ($diff->days > 7 || $date > $now) {
                if (!empty($receiptPath)) {
                    try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
                }
                return ['success' => false, 'message' => 'تاریخ فیش واریزی باید نهایتاً مربوط به ۷ روز گذشته باشد و در آینده نباشد.'];
            }
        } catch (\Throwable $e) {
            if (!empty($receiptPath)) {
                try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
            }
            return ['success' => false, 'message' => 'تاریخ واریز نامعتبر است'];
        }

        $cardId = (int)($validated['card_id'] ?? $validated['bank_card_id'] ?? 0);
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
            // بررسی مجدد وضعیت احراز هویت کاربر داخل تراکنش جهت جلوگیری از Race Condition
            $userLock = $this->userModel->find($userId);

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
                'deposit_date'  => $dateStr,
                'deposit_time'  => $timeStr,
                'description'   => $validated['user_description'] ?? null,
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

            $this->eventDispatcher->dispatchAsync('deposit.manual_created', [
                'user_id' => $userId,
                'deposit_id' => $depositId,
                'amount' => $amount
            ]);

            $this->logger->info('manual_deposit.created', [
                'channel' => 'deposit',
                'user_id' => $userId, 
                'id' => $depositId, 
                'amount' => $amount
            ]);

            return $this->idempotency->save($ikey, [
                'success'    => true,
                'message'    => 'درخواست واریز ثبت شد و در انتظار بررسی است',
                'deposit_id' => $depositId,
            ]);

        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (!empty($receiptPath)) {
                try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
            }
            if ($e->getCode() === '23000' || strpos($e->getMessage(), '1062') !== false) {
                if (strpos($e->getMessage(), 'receipt_hash') !== false || strpos($e->getMessage(), 'uq_receipt_hash') !== false) {
                    return ['success' => false, 'message' => 'این فیش بانکی قبلاً در سیستم آپلود و ثبت شده است. لطفاً تصویر معتبر و جدیدی ارسال کنید'];
                }
                return ['success' => false, 'message' => 'این شماره پیگیری قبلاً ثبت شده است'];
            }
            $this->logger->error('manual_deposit.create.failed', [
                'channel' => 'deposit',
                'user_id' => $userId,
                'amount'  => $amount,
                'error'   => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی در ثبت درخواست واریز'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (!empty($receiptPath)) {
                try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
            }
            $this->logger->error('manual_deposit.create.failed', [
                'channel' => 'deposit',
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
                    // 🚀 اعلام شکست در تطبیق برای Alerting در Listener
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
                'amount' => $amountStr,
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

            $this->db->commit();

            $this->eventDispatcher->dispatchAsync('deposit.manual_rejected', [
                'user_id' => (int)$d->user_id,
                'deposit_id' => $depositId,
                'amount' => $amountStr,
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

    /**
     * جستجوی استاندارد واریزهای دستی
     */
    public function searchManualDeposits(SearchQuery $query): SearchResult
    {
        $dbQuery = $this->model->query()
            ->selectRaw("manual_deposits.*, u.full_name as user_name, u.email as user_email")
            ->leftJoin('users as u', 'u.id', '=', 'manual_deposits.user_id');

        if ($query->getTerm()) {
            $this->model->applySearch($dbQuery, $query->getTerm());
        }

        // اعمال فیلترهای استاندارد
        foreach ($query->getFilters() as $col => $val) {
            if ($val !== null && $val !== '') {
                $dbQuery->where("manual_deposits.{$col}", '=', $val);
            }
        }

        $total = (int)$dbQuery->count();
        $items = $dbQuery->orderByRaw($query->getSort())
                       ->limit($query->getLimit())
                       ->offset($query->getOffset())
                       ->get() ?? [];

        return new SearchResult($items, $total);
    }
}