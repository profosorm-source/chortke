<?php

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\BankCard;
use App\Models\User;

class BankCardService extends \App\Services\BaseService
{
    private \App\Models\User $userModel;
    private BankCard $model;
    private \App\Adapters\BankInquiryAdapter $inquiryAdapter;
    private \Core\Encryption $encryption;

    public function __construct(
        \App\Models\BankCard $model,
        \App\Models\User $userModel,
        \App\Adapters\BankInquiryAdapter $inquiryAdapter,
        \Core\Encryption $encryption,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->model          = $model;
        $this->userModel      = $userModel;
        $this->inquiryAdapter = $inquiryAdapter;
        $this->encryption     = $encryption;
    }

    public function create(int $userId, array $data): array
    {
        $count = (int)$this->model->countUserCards($userId);
        if ($count >= 4) {
            return ['success' => false, 'message' => 'حداکثر ۴ کارت بانکی مجاز است'];
        }

        $cardNumber = preg_replace('/\D/', '', (string)($data['card_number'] ?? ''));
        if (!$this->validateLuhn($cardNumber)) {
            return ['success' => false, 'message' => 'شماره کارت وارد شده نامعتبر است'];
        }

        $exists = $this->model->where('card_number', $this->encryption->encrypt($cardNumber))->where('deleted_at', null)->first();
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
            'card_number' => $this->encryption->encrypt($cardNumber),
            'owner_name' => $this->encryption->encrypt($holder),
            'bank_name' => $bankName,
            'shaba' => $iban ?: null,
            'status' => 'pending',
            'is_default' => $count === 0,
        ]);

        if (!$id) {
             return ['success' => false, 'message' => 'خطا در ایجاد کارت'];
        }

        $this->logger->info('bankcard.created', ['user_id' => $userId, 'card_id' => $id->id ?? 0]);

        $message = 'کارت ثبت شد و در انتظار تأیید است';
        return ['success' => true, 'message' => $message, 'card_id' => (int)($id->id ?? 0)];
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
            'owner_name' => \Core\Encryption::encrypt($holder),
            'shaba' => $iban ?: null,
            'status' => 'pending',
            'rejection_reason' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$ok) return ['success' => false, 'message' => 'خطا در ویرایش کارت'];

        $this->logger->info('bankcard.updated', ['user_id' => $userId, 'card_id' => $cardId]);

        return ['success' => true, 'message' => 'کارت ویرایش شد و در انتظار تأیید مجدد است'];
    }

    public function softDeleteByUser(int $userId, int $cardId): array
    {
        $deleted = $this->model->deleteForUser($cardId, $userId);
        if (!$deleted) return ['success' => false, 'message' => 'خطا در حذف کارت (شاید در تراکنش‌ها استفاده شده)'];

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

        $ok = $this->model->setDefault($cardId, $userId);
        return ['success' => (bool)$ok, 'message' => $ok ? 'کارت اصلی تنظیم شد' : 'خطا در تنظیم کارت اصلی'];
    }

    public function adminVerify(int $adminId, int $cardId, bool $approve, ?string $reason = null): array
    {
        $this->db->beginTransaction();
        try {
            $card = $this->db->query(
                "SELECT * FROM bank_cards WHERE id = :id FOR UPDATE",
                ['id' => $cardId]
            )->fetch(\PDO::FETCH_OBJ);

            if (!$card) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'کارت یافت نشد'];
            }

            if (isset($card->deleted_at) && $card->deleted_at !== null) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'کارت حذف شده است و قابل تأیید نیست'];
            }

            if (($card->status ?? '') !== 'pending') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'این کارت در وضعیت معلق قرار ندارد'];
            }

            $status = $approve ? 'verified' : 'rejected';
            $ok = $this->model->updateStatus($cardId, $status, $reason, $adminId);
            
            if (!$ok) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در بروزرسانی وضعیت'];
            }

            $this->db->commit();
            return ['success' => true, 'message' => $approve ? 'کارت تأیید شد' : 'کارت رد شد'];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('bankcard.admin_verify.failed', ['card_id' => $cardId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در فرآیند تأیید کارت بانکی'];
        }
    }

    private function validateLuhn(string $cardNumber): bool
    {
        if (strlen($cardNumber) !== 16 || !ctype_digit($cardNumber)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 16; $i++) {
            $digit = (int)$cardNumber[$i];
            if ($i % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) $digit -= 9;
            }
            $sum += $digit;
        }
        return $sum % 10 === 0;
    }

    private function matchName(string $a, string $b): bool
    {
        $a = \mb_strtolower(trim(preg_replace('/\s+/', ' ', $a)), 'UTF-8');
        $b = \mb_strtolower(trim(preg_replace('/\s+/', ' ', $b)), 'UTF-8');
        if ($a === '' || $b === '') return true;
        if ($a === $b) return true;

        $prefixes = ['سید ', 'سیده ', 'میر ', 'آقا ', 'خانم '];
        $aClean = str_replace($prefixes, '', $a);
        $bClean = str_replace($prefixes, '', $b);

        if (str_replace(' ', '', $aClean) === str_replace(' ', '', $bClean)) return true;

        $sim = 0;
        similar_text($aClean, $bClean, $sim);
        return $sim >= 75;
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
        $card = $this->model->find($cardId);
        if ($card) {
            $card->card_number = $this->encryption->decrypt((string)$card->card_number);
            $card->owner_name = $this->encryption->decrypt((string)$card->owner_name);
        }
        return $card;
    }

    /**
     * دریافت کارت‌های بانکی کاربر (اتصال به جدول اصلی bank_cards)
     */
    public function getUserCards(int $userId, ?string $status = null): array
    {
        $cards = $this->model->getUserCards($userId, $status);
        foreach ($cards as $card) {
            $card->card_number = $this->encryption->decrypt((string)$card->card_number);
            $card->owner_name = $this->encryption->decrypt((string)$card->owner_name);
        }
        return $cards;
    }

    /**
     * یافتن یک کارت تأیید شده برای کاربر خاص
     */
    public function findVerifiedCardForUser(int $userId, int $cardId): ?object
    {
        $card = $this->findById($cardId);
        if ($card && (int)$card->user_id === $userId && $card->status === 'verified') {
            return $card;
        }
        return null;
    }

    /**
     * دریافت کارت‌های در انتظار بررسی (برای پنل مدیریت)
     */
    public function getPendingCards(int $limit = 50, int $offset = 0): array
    {
        $cards = $this->model->getPendingCards($limit, $offset);
        foreach ($cards as $card) {
            $card->card_number = $this->encryption->decrypt((string)$card->card_number);
            $card->owner_name = $this->encryption->decrypt((string)$card->owner_name);
        }
        return $cards;
    }
}



