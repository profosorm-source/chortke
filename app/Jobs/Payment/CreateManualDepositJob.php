<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class CreateManualDepositJob
{
    public function __construct(
        private \App\Services\Shared\IdempotencyKey $idempotency,
        private \Core\RateLimiter $rateLimiter,
        private \App\Contracts\LoggerInterface $logger,
        private \App\Services\UploadService $uploadService,
        private \App\Models\BankCard $bankCardModel,
        private \App\Services\Financial\CurrencyService $currencyService,
        private \App\Models\User $userModel,
        private \Core\Database $db,
        private \App\Models\ManualDeposit $model,
        private \Core\EventDispatcher $eventDispatcher
    ) {}

    public function handle(int $userId, array $data, ?string $receiptPath): array
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
    private function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }

}
