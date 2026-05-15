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
        // ممانعت از ثبت واریز ریالی در صورت فعال بودن حالت تتر-تنها
        if (!$this->currencyService->isIRT()) {
            return ['success' => false, 'message' => 'واریز دستی ریالی در وضعیت فعلی ارز مسدود است'];
        }

        $user = $this->userModel->find($userId);
        if (!$user || $user->kyc_status !== 'verified') {
            return ['success' => false, 'message' => 'برای واریز دستی باید احراز هویت شما تأیید شده باشد'];
        }

        $pending = $this->model->where('user_id', $userId)->whereIn('status', ['pending', 'under_review'])->first();
        if ($pending) {
            return ['success' => false, 'message' => 'شما یک درخواست واریز در انتظار دارید'];
        }

        $bankCardId = (int)($data['bank_card_id'] ?? 0);
        $card = $this->bankCardModel
            ->where('id', $bankCardId)
            ->where('user_id', $userId)
            ->where('status', 'verified')
            ->where('deleted_at', null)
            ->first();

        if (!$card) {
            return ['success' => false, 'message' => 'کارت بانکی نامعتبر یا تأیید نشده است'];
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount < 10000) return ['success' => false, 'message' => 'حداقل مبلغ واریز دستی ۱۰,۰۰۰ تومان است'];

        $tracking = trim((string)($data['tracking_code'] ?? ''));
        if ($tracking === '') return ['success' => false, 'message' => 'شماره پیگیری الزامی است'];

        $existsTracking = $this->model->where('tracking_code', $tracking)->where('user_id', $userId)->first();
        if ($existsTracking) return ['success' => false, 'message' => 'این شماره پیگیری قبلاً ثبت شده است'];

        // H15 Fix: مانیتور و هشینگ تصویر فیش آپلود شده جهت پیشگیری قطعی از ارسال فیش‌های تکراری
        $receiptHash = null;
        if (!empty($receiptPath)) {
            try {
                $absPath = $this->uploadService->getPath($receiptPath);
                if ($absPath && file_exists($absPath)) {
                    $receiptHash = hash_file('sha256', $absPath);

                    // بررسی وجود فیش تکراری در کل سیستم
                    $duplicateReceipt = $this->model->where('receipt_hash', $receiptHash)->first();
                    if ($duplicateReceipt) {
                        // پاکسازی فایل تازه آپلود شده جهت جلوگیری از انباشت فایل هرز روی دیسک
                        try { $this->uploadService->delete($receiptPath); } catch (\Throwable $t) {}
                        
                        return ['success' => false, 'message' => 'این فیش بانکی قبلاً در سیستم آپلود و ثبت شده است. لطفاً تصویر معتبر و جدیدی ارسال کنید'];
                    }
                }
            } catch (\Throwable $ex) {
                $this->logger->error('manual_deposit.hash_calculation_failed', ['error' => $ex->getMessage()]);
            }
        }

        $id = $this->model->create([
            'user_id'       => $userId,
            'amount'        => $amount,
            'currency'      => 'irt',
            'receipt_image' => $receiptPath,
            'receipt_hash'  => $receiptHash,
            'tracking_code' => $tracking,
            'bank_name'     => $card ? $card->bank_name : 'نامشخص',
            'description'   => $data['user_description'] ?? null,
            'status'        => 'pending',
        ]);

        $depositId = $id ? (int)($id->id ?? 0) : 0;
        $this->logger->info('manual_deposit.created', ['user_id' => $userId, 'id' => $depositId, 'amount' => $amount]);

        return [
            'success'    => true,
            'message'    => 'درخواست واریز ثبت شد و در انتظار بررسی است',
            'deposit_id' => $depositId,
        ];
    }

    /**
     * تأیید واریز دستی
     */
    public function approve(int $adminId, int $depositId, ?string $note): array
    {
        try {
            $this->db->beginTransaction();

            // H14 Fix: اعمال قفل ردیفی جهت جلوگیری از Race Condition و شارژ مضاعف
            $d = $this->db->query("SELECT * FROM manual_deposits WHERE id = ? FOR UPDATE", [$depositId])->fetch(\PDO::FETCH_OBJ);

            if (!$d) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست یافت نشد'];
            }

            if (!in_array($d->status, ['pending', 'under_review'], true)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'این درخواست قبلاً بررسی شده است'];
            }

            $ok = $this->wallet->depositInTransaction(
                (int)$d->user_id,
                (float)$d->amount,
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

            // ✅ ۳. ثبت و تطبیق نهایی تراکنش جهت امنیت حداکثری و چک کردن Consistency
            $reconciliation = $this->reconciliationService->reconcilePayment([
                'transaction_id' => (string)$ok['transaction_id'],
                'reference_id'   => 'manual_deposit_' . $depositId,
                'user_id'        => (int)$d->user_id,
                'amount'         => (float)$d->amount,
                'currency'       => 'irt',
                'status'         => 'success',
                'gateway'        => 'manual_bank',
            ]);

            if (!$reconciliation['success']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'تراکنش با موفقیت انجام شد ولی سیستم تطبیق خطا داد: ' . ($reconciliation['message'] ?? 'ناشناخته')];
            }

            $this->model->update($depositId, [
                'status'         => 'approved',
                'admin_note'     => $note,
                'reviewed_by'    => $adminId,
                'reviewed_at'    => date('Y-m-d H:i:s'),
                'transaction_id' => $ok['transaction_id'],
            ]);

            $this->db->commit();

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
                'amount'         => (float)$d->amount,
                'tracking_code'  => $d->tracking_code,
                'admin_id'       => $adminId,
                'transaction_id' => $ok['transaction_id'],
            ], $adminId);

            $this->notifier->depositSuccess((int)$d->user_id, (float)$d->amount, 'IRT');

            return ['success' => true, 'message' => 'واریز تأیید شد و کیف پول شارژ گردید'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('manual_deposit.approve.failed', ['id' => $depositId, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در تأیید واریز'];
        }
    }

    public function reject(int $adminId, int $depositId, string $reason): array
    {
        $d = $this->model->find($depositId);
        if (!$d) return ['success' => false, 'message' => 'درخواست یافت نشد'];
        if (!in_array($d->status, ['pending', 'under_review'], true)) {
            return ['success' => false, 'message' => 'این درخواست قبلاً بررسی شده است'];
        }

        $this->model->update($depositId, [
            'status'      => 'rejected',
            'admin_note'  => $reason,
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auditTrail->record('deposit.rejected', (int)$d->user_id, [
            'deposit_id' => $depositId,
            'amount'     => (float)$d->amount,
            'reason'     => $reason,
            'admin_id'   => $adminId,
        ], $adminId);

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

