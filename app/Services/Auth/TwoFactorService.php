<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Models\SecurityModel;
use App\Services\AuditTrail;
use App\Services\Notification\NotificationService;
use Core\Database;
use Core\Session;
use Core\RateLimiter;
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
 * تا جلوگیری از Account Takeover Attacks جریان یافته از طریق Session Hijacking را بگیرد.
 * 
 * ⚠️ MEDIUM-M-14: Recovery codes have separate rate limiting to prevent brute-force attacks.
 */
class TwoFactorService
{
    private User $userModel;
    private SecurityModel $securityModel;
    private Session $session;

    private NotificationService $notificationService;
    private AuditTrail $auditTrail;
    private ?RateLimiter $rateLimiter;

    // MEDIUM-M-14 Fix: Rate limit for recovery code attempts (stricter than TOTP)
    private const RECOVERY_CODE_RATE_LIMIT_MAX = 3;      // 3 attempts
    private const RECOVERY_CODE_RATE_LIMIT_DECAY = 300;  // 5 minutes
    private const RECOVERY_CODE_RATE_LIMIT_WINDOW = 3;   // per 3 attempts

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        User $userModel,
        SecurityModel $securityModel,
        Session $session,
        NotificationService $notificationService,
        AuditTrail $auditTrail,
        ?RateLimiter $rateLimiter = null
    ) {        $this->db = $db;
        $this->logger = $logger;

        
        $this->userModel = $userModel;
        $this->securityModel = $securityModel;
        $this->session = $session;
        $this->notificationService = $notificationService;
        $this->auditTrail = $auditTrail;
        $this->rateLimiter = $rateLimiter;
    }

    public function generateSecret(): string
    {
        $secret = '';
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        
        // 🔐 Cryptographic Hardening: Using standard secure random_bytes generation
        $bytes = random_bytes(32);
        for ($i = 0; $i < 32; $i++) {
            // Note: 256 % 32 === 0, so no modulo bias exists here for a 32-char set
            $secret .= $chars[ord($bytes[$i]) % 32];
        }
        return $secret;
    }

    public function getQRCodeUrl(string $username, string $secret): string
    {
        // MEDIUM-02 Fix: Robust decryption and error handling to prevent plaintext leakage
        try {
            $plainSecret = $this->decryptSecret($secret);
        } catch (\Throwable $e) {
            $this->logger->error('2fa.qr_url.decrypt_failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('امکان تولید QR Code وجود ندارد');
        }

        $appName = config('app.name', 'Chortke');
        return "otpauth://totp/" . rawurlencode($appName) . ":" . rawurlencode($username) 
             . "?secret=" . rawurlencode($plainSecret) . "&issuer=" . rawurlencode($appName);
    }

    public function verifyTOTPCode(string $secret, string $code, ?int $userId = null): bool
    {
        $isLegacy = false;
        $plainSecret = $this->decryptSecret($secret, $isLegacy);
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

            if ($this->timingSafeEquals($this->generateTOTP($plainSecret, $sliceToCheck), $code)) {
                // 🛡️ MIGRATION-01: On-the-fly migration for legacy secrets using derived IV
                if ($userId && $isLegacy) {
                    try {
                        $newEncryptedSecret = $this->encryptSecret($plainSecret);
                        $this->userModel->update($userId, ['two_factor_secret' => $newEncryptedSecret]);
                        $this->logger->info('2fa.secret_migrated', ['user_id' => $userId]);
                    } catch (\Throwable $e) {
                        // Log but don't fail the login if migration fails (already verified)
                        $this->logger->error('2fa.migration_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                    }
                }

                // CRIT-06 Fix: استفاده از آپدیت اتمیک برای جلوگیری از Race Condition در مصرف کد
                if ($userId) {
                    if (!$this->userModel->update2FATimeslice($userId, $sliceToCheck)) {
                        continue;
                    }
                }
                return true;
            }
        }

        return false;
    }

    public function verifyCode(string $secret, string $code, ?int $userId = null): bool
    {
        // HIGH-H-04 Fix: Combined verification for TOTP and Recovery Codes (Standard login flow)
        if ($this->verifyTOTPCode($secret, $code, $userId)) {
            return true;
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
            // CRITICAL-C-04 Fix: Increasing entropy to 96-bit (12 bytes) for high resistance against offline attacks
            $codes[] = strtoupper(bin2hex(random_bytes(12))); // 24 hex chars
        }
        return $codes;
    }

    public function enable(int $userId, string $code): array
    {
        // HIGH-06 Fix: Acquire a distributed lock to prevent concurrent 2FA setup replay race conditions
        $lockResource = "2fa_enable:{$userId}";
        $lock = $this->lockService->acquire($lockResource, ttl: 30, waitTimeout: 5);
        if (!$lock['acquired']) {
            $this->logger->warning('2fa.enable.lock_failed', ['user_id' => $userId]);
            return ['success' => false, 'message' => 'سیستم مشغول است، لطفا دوباره تلاش کنید.'];
        }

        try {
            $user = $this->userModel->find($userId);
            if (!$user || empty($user->two_factor_secret)) {
                return ['success' => false, 'message' => 'Secret key یافت نشد.'];
            }

            // 🛡️ Domain Invariant Guard: Prevent repeated or corrupted 2FA activation states.
            if (!empty($user->two_factor_enabled)) {
                return ['success' => false, 'message' => 'احراز هویت دو مرحله‌ای قبلاً فعال شده است.'];
            }

            // HIGH-H-04 Fix: When enabling 2FA, ONLY accept TOTP codes (Recovery codes are not yet issued)
            if (!$this->verifyTOTPCode($user->two_factor_secret, $code, $userId)) {
                return ['success' => false, 'message' => 'کد وارد شده نامعتبر است.'];
            }

            $this->db->beginTransaction();
            try {
                $recoveryCodes = $this->generateRecoveryCodes();
                $this->saveRecoveryCodes($userId, $recoveryCodes);
                $this->userModel->update($userId, ['two_factor_enabled' => 1]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollback();
                $this->logger->error('2fa.enable.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                return ['success' => false, 'message' => 'خطا در فعال‌سازی احراز هویت دو مرحله‌ای.'];
            }

            return [
                'success' => true,
                'message' => 'احراز هویت دو مرحله‌ای فعال شد.',
                'recovery_codes' => $recoveryCodes,
            ];
        } finally {
            if (!empty($lock['token'])) {
                $this->lockService->release($lockResource, $lock['token']);
            }
        }
    }

    public function disable(int $userId, string $password): array
    {
        $user = $this->userModel->find($userId);
        
        // 🔐 Critical Security Fix: Explicitly migrated custom validation to centralized verify_user_password()
        if (!$user || !verify_user_password($password, $user->password, (int)$userId)) {
            return ['success' => false, 'message' => 'رمز عبور اشتباه است.'];
        }

        $this->userModel->update($userId, [
            'two_factor_enabled' => 0,
            'two_factor_secret' => null
        ]);
        $this->securityModel->deleteTwoFactorCodes($userId);

        // HIGH-H-01 Fix: Record the action in AuditTrail
        try {
            $this->auditTrail->record('2fa.disabled', $userId, [
                'ip' => function_exists('get_client_ip') ? get_client_ip() : 'unknown',
                'user_agent' => function_exists('get_user_agent') ? get_user_agent() : '',
            ], $userId);
        } catch (\Throwable $e) {
            $this->logger->error('2fa.disable.audit_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        return ['success' => true, 'message' => 'احراز هویت دو مرحله‌ای غیرفعال شد.'];
    }

    private function saveRecoveryCodes(int $userId, array $codes): void
    {
        $this->securityModel->deleteTwoFactorCodes($userId);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
        $key = secure_key();
        foreach ($codes as $code) {
            // CRITICAL-C-04 Fix: Double-layer protection — HMAC-SHA256 of the code then Bcrypt hash.
            // This prevents cracking even if the salt/hashes are leaked, as the attacker needs the app key.
            $hashedCode = hash_hmac('sha256', strtoupper((string)$code), $key);
            $bcryptHash = password_hash($hashedCode, PASSWORD_BCRYPT);
            $this->securityModel->insertTwoFactorCode($userId, $bcryptHash, $expiresAt);
        }
    }

    /**
     * MEDIUM-M-14 Fix: Verify recovery code with separate rate limiting
     * 
     * Recovery codes are high-value backup credentials that should have
     * stricter rate limiting than regular TOTP codes. This prevents
     * brute-force attacks on recovery codes.
     * 
     * @param int $userId User ID for rate limiting and code lookup
     * @param string $code Recovery code to verify
     * @return bool True if code is valid and not rate limited
     */
    private function verifyRecoveryCode(int $userId, string $code): bool
    {
        // MEDIUM-M-14 Fix: Check rate limit BEFORE attempting verification
        // This prevents brute-force attacks on recovery codes
        if ($this->rateLimiter !== null) {
            $rateLimitKey = "2fa_recovery:" . $userId;
            
            // Strict rate limiting for recovery codes: only 3 attempts per 5 minutes
            // failClosed=true ensures we deny on rate limiter failure (secure default)
            if (!$this->rateLimiter->attempt($rateLimitKey, self::RECOVERY_CODE_RATE_LIMIT_MAX, self::RECOVERY_CODE_RATE_LIMIT_DECAY, true)) {
                $this->logger->warning('2fa.recovery_code.rate_limited', ['user_id' => $userId]);
                
                // CRITICAL-NEW-01 Fix: Lock account temporarily and send security alert
                $this->userModel->update($userId, ['status' => 'locked_2fa']);
                
                try {
                    $this->notificationService->securityAlert($userId, 'حساب شما به دلیل تلاش‌های مشکوک و بیش از حد مجاز با کدهای بازیابی قفل شد.', $this->clientIp());
                } catch (\Throwable $e) {
                    $this->logger->error('2fa.recovery_code.lockout_notif_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                }
                
                $this->logger->critical('2fa.recovery_code.bruteforce_lockout', [
                    'user_id' => $userId,
                    'ip' => $this->clientIp()
                ]);
                
                return false;
            }
        }
        
        $code = strtoupper(trim($code));
        
        // Validate code format (recovery codes are 24 hex characters)
        if (!preg_match('/^[A-Z0-9]{24}$/', $code)) {
            $this->logger->warning('2fa.recovery_code.invalid_format', ['user_id' => $userId]);
            return false;
        }
        
        $db = $this->securityModel->getDb();
        $db->beginTransaction();
        
        try {
            // HIGH-H-04 Fix: Atomic recovery code invalidation using FOR UPDATE to prevent race conditions
            $records = $db->fetchAll(
                "SELECT id, code FROM two_factor_codes WHERE user_id = ? AND used = 0 AND expires_at > NOW() FOR UPDATE",
                [$userId]
            ) ?: [];

            $key = secure_key();
            $found = false;
            $matchedRecord = null;

            foreach ($records as $record) {
                $hmacCode = hash_hmac('sha256', $code, $key);
                
                $match = false;
                if (password_verify($hmacCode, $record->code)) {
                    $match = true;
                } elseif (password_verify($code, $record->code)) {
                    $match = true;
                }

                if ($match && !$found) {
                    $found = true;
                    $matchedRecord = $record;
                }
            }

            if ($found && $matchedRecord) {
                if (!$this->securityModel->deleteTwoFactorCodeAtomic((int)$matchedRecord->id, $userId)) {
                    $db->rollBack();
                    $this->logger->critical('2fa.recovery_code.race_condition_detected', [
                        'user_id' => $userId,
                        'code_id' => $matchedRecord->id
                    ]);
                    return false;
                }

                $db->commit();
                
                // MEDIUM-M-14 Fix: Clear rate limit on successful verification
                if ($this->rateLimiter !== null) {
                    $this->rateLimiter->clear("2fa_recovery:" . $userId);
                }
                
                $this->logger->info('2FA recovery code used', ['user_id' => $userId, 'code_id' => $matchedRecord->id]);
                
                // Check for legacy formats to trigger migration
                if (!password_verify(hash_hmac('sha256', $code, $key), $matchedRecord->code)) {
                    $this->userModel->update($userId, ['force_2fa_regen' => 1]);
                    $this->session->setFlash('warning', 'شما از یک کد بازیابی قدیمی استفاده کردید. لطفاً کدهای جدید دریافت کنید.');
                }
                
                return true;
            }

            $db->rollBack();
            
            // Log failed attempt for security monitoring
            $this->logger->warning('2fa.recovery_code.invalid', [
                'user_id' => $userId,
                'remaining_attempts' => $this->rateLimiter !== null 
                    ? (self::RECOVERY_CODE_RATE_LIMIT_MAX - $this->rateLimiter->attempts("2fa_recovery:" . $userId))
                    : 'unknown'
            ]);
            
            return false;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->logger->error('2fa.recovery_code.verification_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
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
        $rawKey = secure_key();
        if (strlen($rawKey) < 32) {
            throw new \RuntimeException('Application key is too short for AES-256 encryption.');
        }

        $key = hash('sha256', $rawKey, true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($secret, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        
        if ($encrypted === false) {
            throw new \RuntimeException('Failed to encrypt 2FA secret.');
        }

        // Prepend IV to ciphertext and base64 encode
        return base64_encode($iv . $encrypted);
    }

    public function decryptSecret(string $encryptedSecret, bool &$isLegacy = false): string
    {
        $isLegacy = false;
        // If length is 32 and base32 compliant, it's a legacy plaintext 2FA secret.
        if (strlen($encryptedSecret) === 32 && preg_match('/^[A-Z2-7]+$/', $encryptedSecret)) {
            $isLegacy = true;
            return $encryptedSecret;
        }

        $rawKey = secure_key();
        $key = hash('sha256', $rawKey, true);
        $decoded = base64_decode($encryptedSecret, true);

        // Check if it's the new format (IV + Ciphertext)
        if ($decoded !== false && strlen($decoded) > 16) {
            $iv = substr($decoded, 0, 16);
            $ciphertext = substr($decoded, 16);
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            
            if ($decrypted !== false && $decrypted !== '') {
                return $decrypted;
            }

            // Fallback: try with non-hashed key for backwards compatibility
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $rawKey, OPENSSL_RAW_DATA, $iv);
            if ($decrypted !== false && $decrypted !== '') {
                return $decrypted;
            }
        }

        // Fallback for old format (Derived IV)
        $ivLegacy = substr($key, 0, 16);
        $decryptedLegacy = openssl_decrypt($encryptedSecret, 'aes-256-cbc', $key, 0, $ivLegacy);
        
        if ($decryptedLegacy !== false && $decryptedLegacy !== '') {
            $isLegacy = true;
            return $decryptedLegacy;
        }

        // CRITICAL-05 Fix: Do not return raw input on failure. Log and fail.
        $this->logger->critical('2fa.decrypt_failed', [
            'payload_length' => strlen($encryptedSecret),
            'format' => ($decoded !== false) ? 'new' : 'legacy/unknown'
        ]);
        
        throw new \RuntimeException('امکان رمزگشایی کد تایید وجود ندارد. لطفاً با پشتیبانی تماس بگیرید.');
    }
}