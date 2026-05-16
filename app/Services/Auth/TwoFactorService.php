<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Models\SecurityModel;
use Core\Session;
use App\Contracts\LoggerInterface;
/**
 * TwoFactorService
 *
 * مدیریت احراز هویت دو مرحله‌ای.
 *
 * 🛡️ Security Advisory — برنامه‌ریزان آتی لطفاً توجه کنند:
 * 
 * ⚠️ CRITICAL: تمام مقادیر Secret و Recovery Codes بایستی در دیتابیس به صورت:
 * - Hashed (مثل bcrypt یا argon2)
 * - Encrypted (بر اساس Master Key سیستم)
 * ذخیره شوند. برنامه‌نویسی plain-text secret‌ها به معنی افشای کامل 2FA است.
 * 
 * ⚠️ IMPORTANT: تغییرات روی الگوریتم TOTP یا وضعیت کاربر 2FA باید:
 * - درون یک تراکنش دیتابیس انجام شوند
 * - توسط رویداد (Event) ثبت شوند
 * - تاریخچه تغییرات ایمنی (AuditTrail) را تکمیل کنند
 * 
 * ⚠️ CAUTION: فرآیند Enable/Disable 2FA باید نیاز به بازتأیید رمز عبور کاربر داشته باشد
 * تا جلوی Account Takeover Attacks جریان یافته از طریق Session Hijacking را بگیرد.
 */
class TwoFactorService extends \App\Services\BaseService
{
    private User $userModel;
    private SecurityModel $securityModel;
    private Session $session;

    public function __construct(
        User $userModel,
        SecurityModel $securityModel,
        Session $session,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->userModel = $userModel;
        $this->securityModel = $securityModel;
        $this->session = $session;
    }

    public function generateSecret(): string
    {
        $secret = '';
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        
        // 🔐 Cryptographic Hardening: Using standard secure random_bytes generation
        $bytes = random_bytes(32);
        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[ord($bytes[$i]) % 32];
        }
        return $secret;
    }

    public function getQRCodeUrl(string $username, string $secret): string
    {
        $secret = $this->decryptSecret($secret);
        $appName = config('app.name', 'Chortke');
        return "otpauth://totp/" . rawurlencode($appName) . ":" . rawurlencode($username) . "?secret=" . rawurlencode($secret) . "&issuer=" . rawurlencode($appName);
    }

    public function verifyCode(string $secret, string $code, ?int $userId = null): bool
    {
        $secret = $this->decryptSecret($secret);
        $timeSlice = (int)floor(time() / 30);
        
        // بازیابی آخرین تایم اسلایس استفاده شده جهت جلوگیری از Replay Attack
        $lastTimeslice = null;
        if ($userId) {
            $user = $this->userModel->find($userId);
            if ($user && isset($user->last_2fa_timeslice)) {
                $lastTimeslice = (int)$user->last_2fa_timeslice;
            }
        }

        // M31 Fix: کاهش محدوده تحمل به ±1 تایم اسلایس (±۳۰ ثانیه) جهت انطباق کامل با استاندارد امنیت جهانی RFC 6238
        for ($i = -1; $i <= 1; $i++) {
            $sliceToCheck = $timeSlice + $i;

            // 🛡️ CRITICAL ANTI-REPLAY GUARD: به کارگیری مجدد کدی که یک بار در بازه‌ی زمانی فعلی یا قبلی مصرف شده است ممنوع است.
            if ($lastTimeslice !== null && $sliceToCheck <= $lastTimeslice) {
                continue;
            }

            if ($this->timingSafeEquals($this->generateTOTP($secret, $sliceToCheck), $code)) {
                // ذخیره تایم اسلایس موفق جهت فریز کردن آن
                if ($userId) {
                    $this->userModel->update($userId, ['last_2fa_timeslice' => $sliceToCheck]);
                }
                return true;
            }
        }

        if ($userId) {
            return $this->verifyRecoveryCode($userId, $code);
        }
        return false;
    }

    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        return $codes;
    }

    public function enable(int $userId, string $code): array
    {
        $user = $this->userModel->find($userId);
        if (!$user || empty($user->two_factor_secret)) {
            return ['success' => false, 'message' => 'Secret key یافت نشد.'];
        }

        // 🛡️ Domain Invariant Guard: Prevent repeated or corrupted 2FA activation states.
        if (!empty($user->two_factor_enabled)) {
            return ['success' => false, 'message' => 'احراز هویت دو مرحله‌ای قبلاً فعال شده است.'];
        }

        if (!$this->verifyCode($user->two_factor_secret, $code, $userId)) {
            return ['success' => false, 'message' => 'کد وارد شده نامعتبر است.'];
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $this->saveRecoveryCodes($userId, $recoveryCodes);
        $this->userModel->update($userId, ['two_factor_enabled' => 1]);

        return [
            'success' => true,
            'message' => 'احراز هویت دو مرحله‌ای فعال شد.',
            'recovery_codes' => $recoveryCodes,
        ];
    }

    public function disable(int $userId, string $password): array
    {
        $user = $this->userModel->find($userId);
        
        // 🔐 Critical Security Fix: Explicitly migrated custom validation to native password_verify()
        if (!$user || !password_verify($password, $user->password)) {
            return ['success' => false, 'message' => 'رمز عبور اشتباه است.'];
        }

        $this->userModel->update($userId, [
            'two_factor_enabled' => 0,
            'two_factor_secret' => null
        ]);
        $this->securityModel->deleteTwoFactorCodes($userId);

        return ['success' => true, 'message' => 'احراز هویت دو مرحله‌ای غیرفعال شد.'];
    }

    private function saveRecoveryCodes(int $userId, array $codes): void
    {
        $this->securityModel->deleteTwoFactorCodes($userId);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
        foreach ($codes as $code) {
            // Upgraded to Bcrypt hash for high resistance against pre-computed rainbow attacks
            $bcryptHash = password_hash(strtoupper((string)$code), PASSWORD_BCRYPT);
            $this->securityModel->insertTwoFactorCode($userId, $bcryptHash, $expiresAt);
        }
    }

    private function verifyRecoveryCode(int $userId, string $code): bool
    {
        $code = strtoupper(trim($code));
        $records = $this->securityModel->getValidRecoveryCodes($userId);

        foreach ($records as $record) {
            // 1. Attempt industry-standard Bcrypt verification
            if (password_verify($code, $record->code)) {
                $this->securityModel->markTwoFactorCodeAsUsed((int)$record->id);
                $this->logger->info('2FA recovery code used (bcrypt)', ['user_id' => $userId, 'code_id' => $record->id]);
                return true;
            }

            // 2. MED-01 Fix: Graceful migration fallback for older SHA256-hashed codes
            // Use hash_equals for timing-safe comparison
            if (hash_equals(hash('sha256', $code), $record->code)) {
                $this->securityModel->markTwoFactorCodeAsUsed((int)$record->id);
                $this->logger->warning('2FA recovery code used (LEGACY SHA256 - UPGRADE REQUIRED)', [
                    'user_id' => $userId, 
                    'code_id' => $record->id
                ]);
                
                // Notify user to regenerate codes for better security
                $this->session->setFlash('warning', 'شما از یک کد بازیابی قدیمی استفاده کردید. لطفاً برای امنیت بیشتر، کدهای بازیابی جدید دریافت کنید.');
                
                return true;
            }
        }
        return false;
    }

    private function generateTOTP(string $secret, int $timeSlice): string
    {
        $secret = $this->base32Decode($secret);
        $time   = pack('N*', 0) . pack('N*', $timeSlice);
        $hash   = hash_hmac('sha1', $time, $secret, true);
        $offset = ord($hash[19]) & 0xf;

        $code = (
            ((ord($hash[$offset + 0]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        // H-SRV-04 Fix: تبدیل ورودی به حروف بزرگ جهت رفع حساسیت به حروف کوچک
        $secret = strtoupper(trim($secret));
        
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsFlipped = array_flip(str_split($base32chars));
        
        // اضافه کردن پدینگ در صورت عدم تطبیق طول (استاندارد Base32)
        $paddedSecret = str_pad($secret, strlen($secret) + (8 - strlen($secret) % 8) % 8, '=');

        $bits = '';
        for ($i = 0; $i < strlen($paddedSecret); $i++) {
            if ($paddedSecret[$i] === '=') continue;
            
            // H-SRV-04 Fix: اعتبارسنجی دقیق وجود کاراکتر در الفبای Base32 قبل از دستیابی به آرایه جهت جلوگیری از خطای دسترسی و خروجی ناپایدار
            if (!isset($base32charsFlipped[$paddedSecret[$i]])) {
                throw new \InvalidArgumentException("Invalid base32 character '{$paddedSecret[$i]}' encountered in 2FA secret.");
            }
            
            $bits .= sprintf('%05b', $base32charsFlipped[$paddedSecret[$i]]);
        }

        $bytes = '';
        for ($i = 0; $i < strlen($bits); $i += 8) {
            // تکه آخر ممکن است کوتاه باشد
            $chunk = substr($bits, $i, 8);
            if (strlen($chunk) === 8) {
                $bytes .= chr((int)bindec($chunk));
            }
        }
        return $bytes;
    }

    private function timingSafeEquals(string $safe, string $user): bool
    {
        return hash_equals($safe, $user);
    }

    /**
     * Encrypts 2FA secret using AES-256-CBC with application key.
     */
    public function encryptSecret(string $secret): string
    {
        $key = (string)config('app.key');
        $iv = substr($key, 0, 16);
        $encrypted = openssl_encrypt($secret, 'aes-256-cbc', $key, 0, $iv);
        if ($encrypted === false) {
            throw new \RuntimeException('Failed to encrypt 2FA secret.');
        }
        return $encrypted;
    }

    /**
     * Decrypts 2FA secret, falling back gracefully to raw format if legacy.
     */
    public function decryptSecret(string $encryptedSecret): string
    {
        // If length is 32 and base32 compliant, it might be legacy unencrypted
        if (strlen($encryptedSecret) == 32 && preg_match('/^[A-Z2-7]+$/', $encryptedSecret)) {
            return $encryptedSecret;
        }

        $key = (string)config('app.key');
        $iv = substr($key, 0, 16);
        $decrypted = openssl_decrypt($encryptedSecret, 'aes-256-cbc', $key, 0, $iv);
        
        if ($decrypted === false || $decrypted === '') {
            return $encryptedSecret; // Fallback for extreme safety
        }
        return $decrypted;
    }
}

