<?php

namespace App\Services;

use App\Contracts\LoggerInterface;

use App\Models\BankCard;
use App\Models\User;

class BankCardService extends \App\Services\BaseService
{
    private \App\Models\User $userModel;

    private BankCard $model;
    public function __construct(
        \App\Models\BankCard $model,
        \App\Models\User $userModel,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->model     = $model;
        $this->userModel = $userModel;
    }

    public function create(int $userId, array $data): array
    {
        $count = (int)$this->model->where('user_id', $userId)->where('deleted_at', null)->count();
        if ($count >= 4) {
            return ['success' => false, 'message' => 'حداکثر ۴ کارت بانکی مجاز است'];
        }

        $cardNumber = preg_replace('/\D/', '', (string)($data['card_number'] ?? ''));
        if (\strlen($cardNumber) !== 16) {
            return ['success' => false, 'message' => 'شماره کارت باید ۱۶ رقم باشد'];
        }

        $exists = $this->model->where('card_number', $cardNumber)->where('deleted_at', null)->first();
        if ($exists) {
            return ['success' => false, 'message' => 'این شماره کارت قبلاً ثبت شده است'];
        }

        $holder = trim((string)($data['card_holder'] ?? ''));
        if ($holder === '' || \mb_strlen($holder, 'UTF-8') < 3) {
            return ['success' => false, 'message' => 'نام دارنده کارت نامعتبر است'];
        }

        $iban = trim((string)($data['iban'] ?? ''));
        if ($iban !== '' && (!str_starts_with($iban, 'IR') || \strlen($iban) !== 26)) {
            return ['success' => false, 'message' => 'شماره شبا نامعتبر است'];
        }

        $user = ($this->userModel)->find($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد'];
        }

        if (!$this->matchName($holder, (string)$user->full_name)) {
            return ['success' => false, 'message' => 'نام دارنده کارت با نام کاربری شما مطابقت ندارد'];
        }

        $bankName = $this->detectBankName($cardNumber);

        $id = $this->model->create([
            'user_id' => $userId,
            'card_number' => $cardNumber,
            'card_holder_name' => $holder,
            'bank_name' => $bankName,
            'iban' => $iban ?: null,
            'status' => 'pending',
            'is_default' => $count === 0,
        ]);

        $this->logger->info('bankcard.created', ['user_id' => $userId, 'card_id' => $id]);

        return ['success' => true, 'message' => 'کارت ثبت شد و در انتظار تأیید است', 'card_id' => (int)$id];
    }

    public function updateByUser(int $userId, int $cardId, array $data): array
    {
        $card = $this->model
            ->where('id', $cardId)
            ->where('user_id', $userId)
            ->where('deleted_at', null)
            ->first();

        if (!$card) return ['success' => false, 'message' => 'کارت یافت نشد'];

        $holder = trim((string)($data['card_holder'] ?? ''));
        $iban = trim((string)($data['iban'] ?? ''));

        if ($holder === '' || \mb_strlen($holder, 'UTF-8') < 3) {
            return ['success' => false, 'message' => 'نام دارنده کارت نامعتبر است'];
        }
        if ($iban !== '' && (!str_starts_with($iban, 'IR') || \strlen($iban) !== 26)) {
            return ['success' => false, 'message' => 'شماره شبا نامعتبر است'];
        }

        $user = ($this->userModel)->find($userId);
        if (!$user) return ['success' => false, 'message' => 'کاربر یافت نشد'];

        if (!$this->matchName($holder, (string)$user->full_name)) {
            return ['success' => false, 'message' => 'نام دارنده کارت با نام کاربری شما مطابقت ندارد'];
        }

        $ok = $this->model->update($cardId, [
            'card_holder' => $holder,
            'iban' => $iban ?: null,
            'status' => 'pending',
            'rejection_reason' => null,
            'verified_at' => null,
            'verified_by' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$ok) return ['success' => false, 'message' => 'خطا در ویرایش کارت'];

        $this->logger->info('bankcard.updated', ['user_id' => $userId, 'card_id' => $cardId]);

        return ['success' => true, 'message' => 'کارت ویرایش شد و در انتظار تأیید مجدد است'];
    }

    public function softDeleteByUser(int $userId, int $cardId): array
    {
        $card = $this->model->where('id', $cardId)->where('user_id', $userId)->where('deleted_at', null)->first();
        if (!$card) return ['success' => false, 'message' => 'کارت یافت نشد'];

        $ok = $this->model->update($cardId, ['deleted_at' => date('Y-m-d H:i:s')]);
        if (!$ok) return ['success' => false, 'message' => 'خطا در حذف کارت'];

        // اگر primary حذف شد، primary جدید انتخاب شود (از verified)
        if ((int)$card->is_primary === 1) {
            $next = $this->model
                ->where('user_id', $userId)
                ->where('deleted_at', null)
                ->where('status', 'verified')
                ->orderBy('created_at', 'DESC')
                ->first();

            if ($next) {
                $this->setPrimary($userId, (int)$next->id);
            }
        }

        return ['success' => true, 'message' => 'کارت حذف شد'];
    }

    public function setPrimary(int $userId, int $cardId): array
    {
        $card = $this->model
            ->where('id', $cardId)
            ->where('user_id', $userId)
            ->where('deleted_at', null)
            ->where('status', 'verified')
            ->first();

        if (!$card) return ['success' => false, 'message' => 'کارت یافت نشد یا تأیید نشده است'];

        // تنظیم کارت پیش‌فرض از طریق Model (که همه کارت‌های دیگر را غیرفعال می‌کند)
        $ok = $this->model->setPrimary($cardId, $userId);

        return ['success' => (bool)$ok, 'message' => $ok ? 'کارت اصلی تنظیم شد' : 'خطا در تنظیم کارت اصلی'];
    }

    public function adminVerify(int $adminId, int $cardId, bool $approve, ?string $reason = null): array
    {
        $card = $this->model->find($cardId);
        if (!$card) return ['success' => false, 'message' => 'کارت یافت نشد'];

        if ($approve) {
            $this->model->update($cardId, [
                'status' => 'verified',
                'verified_at' => date('Y-m-d H:i:s'),
                'verified_by' => $adminId,
                'rejection_reason' => null,
            ]);
            return ['success' => true, 'message' => 'کارت تأیید شد'];
        }

        $reason = trim((string)$reason);
        if ($reason === '') $reason = 'رد شد';

        $this->model->update($cardId, [
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'verified_by' => $adminId,
        ]);

        return ['success' => true, 'message' => 'کارت رد شد'];
    }

    private function matchName(string $a, string $b): bool
    {
        $a = \mb_strtolower(trim(preg_replace('/\s+/', ' ', $a)), 'UTF-8');
        $b = \mb_strtolower(trim(preg_replace('/\s+/', ' ', $b)), 'UTF-8');

        if ($a === $b) return true;
        if (str_replace(' ', '', $a) === str_replace(' ', '', $b)) return true;

        $sim = 0;
        similar_text($a, $b, $sim);
        return $sim >= 80;
    }

    private function detectBankName(string $cardNumber): string
    {
        $bin = substr($cardNumber, 0, 6);
        $banks = [
            '603799' => 'بانک ملی',
            '589210' => 'بانک سپه',
            '627961' => 'بانک صنعت و معدن',
            '603770' => 'بانک کشاورزی',
            '628023' => 'بانک مسکن',
            '627760' => 'پست بانک',
            '502908' => 'بانک توسعه تعاون',
            '627412' => 'بانک اقتصاد نوین',
            '622106' => 'بانک پارسیان',
            '502229' => 'بانک پاسارگاد',
            '639607' => 'بانک صادرات',
            '627488' => 'بانک کارآفرین',
            '621986' => 'بانک سامان',
            '639346' => 'بانک سینا',
            '504706' => 'بانک شهر',
            '636214' => 'بانک آینده',
            '505785' => 'بانک تجارت',
        ];
        return $banks[$bin] ?? 'نامشخص';
    }

    public function findById(int $cardId): ?object
    {
        return $this->model->find($cardId);
    }
}

