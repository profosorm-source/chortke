<?php

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\BankCard;
use App\Models\User;

class BankCardService
{
    private \App\Models\User $userModel;
    private BankCard $model;
    private \App\Adapters\BankInquiryAdapter $inquiryAdapter;
    private \Core\Encryption $encryption;
private \App\Contracts\ValidatorFactoryInterface $validatorFactory;
private \App\Services\Shared\IdempotencyService $idempotencyService;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \App\Models\BankCard $model,
        \App\Models\User $userModel,
        \App\Adapters\BankInquiryAdapter $inquiryAdapter,
        \Core\Encryption $encryption,
        \App\Contracts\ValidatorFactoryInterface $validatorFactory,
        ?\App\Services\Shared\IdempotencyService $idempotencyService = null
    ) {        $this->db = $db;
        $this->logger = $logger;

        
        $this->model          = $model;
        $this->userModel      = $userModel;
        $this->inquiryAdapter = $inquiryAdapter;
        $this->encryption     = $encryption;
        $this->validatorFactory = $validatorFactory;
        $this->idempotencyService = $idempotencyService ?? \Core\Container::getInstance()->make(\App\Services\Shared\IdempotencyService::class);
    }

    public function create(int $userId, array $data): array
    {
        $data['card_number'] = preg_replace('/\D/', '', $this->normalizeDigits((string)($data['card_number'] ?? '')));
        $data['card_holder'] = trim((string)($data['card_holder'] ?? ''));
        $data['iban'] = trim((string)($data['iban'] ?? ''));

        $validator = $this->validatorFactory->make($data, [
            'card_number' => 'required',
            'card_holder' => 'required|min:3',
            'iban' => 'nullable'
        ]);

        $validator->custom('card_number', function($val) {
            return $this->validateLuhn($val);
        }, 'شماره کارت وارد شده نامعتبر است');

        $validator->custom('iban', function($val) {
            return $val === '' || $this->validateIban($val);
        }, 'شماره شبا نامعتبر است');

        $result = $validator->result();
        if (!$result['valid']) {
            return ['success' => false, 'message' => current(current($result['errors']))];
        }

        $cardNumber = $data['card_number'];
        $holder = $data['card_holder'];
        $iban = $data['iban'];

        $user = ($this->userModel)->find($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد'];
        }

        if (!$this->matchName($holder, (string)$user->full_name)) {
            return ['success' => false, 'message' => 'نام دارنده کارت با نام کاربری شما مطابقت ندارد'];
        }

        $explicitKey = $data['idempotency_key'] ?? null;

        return $this->idempotencyService->executeWithTransaction('bank_card.create', $userId, $data, function() use ($userId, $cardNumber, $holder, $iban) {
            $startedTransaction = !$this->db->inTransaction();
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }
            try {
                $count = (int)$this->model->countUserCards($userId);
                if ($count >= 4) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return ['success' => false, 'message' => 'حداکثر ۴ کارت بانکی مجاز است'];
                }

                $encryptedCardNumber = $this->encryption->encrypt($cardNumber);
                $cardHash = hash_hmac('sha256', $cardNumber, secure_key());
                $stmt = $this->db->prepare("SELECT id FROM bank_cards WHERE card_hash = ? AND deleted_at IS NULL FOR UPDATE");
                $stmt->execute([$cardHash]);
                if ($stmt->fetch()) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return ['success' => false, 'message' => 'این شماره کارت قبلاً ثبت شده است'];
                }

                $bankName = $this->detectBankName($cardNumber);

                $id = $this->model->create([
                    'user_id' => $userId,
                    'card_number' => $encryptedCardNumber,
                    'card_hash' => $cardHash,
                    'owner_name' => $this->encryption->encrypt($holder),
                    'bank_name' => $bankName,
                    'shaba' => $iban ?: null,
                    'status' => 'pending',
                    'is_default' => $count === 0,
                ]);

                if (!$id) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return ['success' => false, 'message' => 'خطا در ایجاد کارت'];
                }

                if ($startedTransaction) {
                    $this->db->commit();
                }
                $this->logger->info('bankcard.created', ['user_id' => $userId, 'card_id' => $id->id ?? 0]);

                $message = 'کارت ثبت شد و در انتظار تأیید است';
                return ['success' => true, 'message' => $message, 'card_id' => (int)($id->id ?? 0)];

            } catch (\PDOException $e) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                // خطای کلید یکتا (کارت تکراری)
                if ((string)$e->getCode() === '23000' || $e->errorInfo[1] === 1062) {
                    return ['success' => false, 'message' => 'این شماره کارت قبلاً ثبت شده است'];
                }
                throw $e;
            } catch (\Throwable $e) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        }, $explicitKey);
    }

    public function updateByUser(int $userId, int $cardId, array $data): array
    {
        $card = $this->model
            ->where('id', $cardId)
            ->where('user_id', $userId)
            ->where('deleted_at', null)
            ->first();

        if (!$card) return ['success' => false, 'message' => 'کارت یافت نشد'];

        $data['card_holder'] = trim((string)($data['card_holder'] ?? ''));
        $data['iban'] = trim((string)($data['iban'] ?? ''));

        $validator = $this->validatorFactory->make($data, [
            'card_holder' => 'required|min:3',
            'iban' => 'nullable'
        ]);

        $validator->custom('iban', function($val) {
            return $val === '' || $this->validateIban($val);
        }, 'شماره شبا نامعتبر است');

        $result = $validator->result();
        if (!$result['valid']) {
            return ['success' => false, 'message' => current(current($result['errors']))];
        }

        $holder = $data['card_holder'];
        $iban = $data['iban'];

        $user = ($this->userModel)->find($userId);
        if (!$user) return ['success' => false, 'message' => 'کاربر یافت نشد'];

        if (!$this->matchName($holder, (string)$user->full_name)) {
            return ['success' => false, 'message' => 'نام دارنده کارت با نام کاربری شما مطابقت ندارد'];
        }

        $ok = $this->model->update($cardId, [
            'owner_name' => $this->encryption->encrypt($holder),
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
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $card = $this->db->query(
                "SELECT * FROM bank_cards WHERE id = :id FOR UPDATE",
                ['id' => $cardId]
            )->fetch(\PDO::FETCH_OBJ);

            if (!$card) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return ['success' => false, 'message' => 'کارت یافت نشد'];
            }

            if (isset($card->deleted_at) && $card->deleted_at !== null) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return ['success' => false, 'message' => 'کارت حذف شده است و قابل تأیید نیست'];
            }

            if (($card->status ?? '') !== 'pending') {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return ['success' => false, 'message' => 'این کارت در وضعیت معلق قرار ندارد'];
            }

            if ($approve) {
                // Fetch user to strictly comply with the KYC / DB Integrity Rule
                $user = $this->userModel->find((int)$card->user_id);

                if (!$user) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return ['success' => false, 'message' => 'کاربر یافت نشد'];
                }

                if (($user->kyc_status ?? '') !== 'verified') {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return ['success' => false, 'message' => 'کاربر احراز هویت نشده است'];
                }

                $decryptedOwnerName = $this->encryption->decrypt((string)$card->owner_name);
                if (!$this->matchName($decryptedOwnerName, (string)$user->full_name)) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return ['success' => false, 'message' => 'نام دارنده کارت با نام احراز هویت شده کاربر مطابقت ندارد'];
                }
            }

            $status = $approve ? 'verified' : 'rejected';
            $ok = $this->model->updateStatus($cardId, $status, $reason, $adminId);
            
            if (!$ok) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return ['success' => false, 'message' => 'خطا در بروزرسانی وضعیت'];
            }

            if ($startedTransaction) {
                $this->db->commit();
            }
            return ['success' => true, 'message' => $approve ? 'کارت تأیید شد' : 'کارت رد شد'];

        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('bankcard.admin_verify.failed', ['card_id' => $cardId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در فرآیند تأیید کارت بانکی'];
        }
    }

    private function validateIban(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        if (strlen($iban) !== 26) {
            return false;
        }
        if (substr($iban, 0, 2) !== 'IR') {
            return false;
        }
        
        $check = substr($iban, 4) . substr($iban, 0, 4);
        $check = str_replace(['I', 'R'], ['18', '27'], $check);
        
        return bcmod($check, '97') === '1';
    }

    private function normalizeDigits(string $str): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $num     = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $str = str_replace($persian, $num, $str);
        return str_replace($arabic, $num, $str);
    }

    private function validateLuhn(string $cardNumber): bool
    {
        $length = strlen($cardNumber);
        if ($length < 15 || $length > 19 || !ctype_digit($cardNumber)) {
            return false;
        }
        $sum = 0;
        $shouldDouble = false;
        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int)$cardNumber[$i];
            if ($shouldDouble) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $shouldDouble = !$shouldDouble;
        }
        return $sum % 10 === 0;
    }

    private function matchName(string $a, string $b): bool
    {
        $a = \mb_strtolower(trim(preg_replace('/\s+/', ' ', $a)), 'UTF-8');
        $b = \mb_strtolower(trim(preg_replace('/\s+/', ' ', $b)), 'UTF-8');
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;

        $titles = ['سید ', 'سیده ', 'میر ', 'آقا ', 'خانم '];
        $aClean = str_replace($titles, '', $a);
        $bClean = str_replace($titles, '', $b);

        if (str_replace(' ', '', $aClean) === str_replace(' ', '', $bClean)) return true;

        $sim = 0;
        similar_text($aClean, $bClean, $sim);
        
        $lev = levenshtein($aClean, $bClean);
        $maxLen = max(strlen($aClean), strlen($bClean));
        $levSim = $maxLen > 0 ? (1 - $lev / $maxLen) * 100 : 0;

        return $sim >= 90 && $levSim >= 90;
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




