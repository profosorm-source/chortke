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
 */
class TwoFactorService extends \App\Services\BaseService
{
    public function __construct(
        private User $userModel,
        private SecurityModel $securityModel,
        private Session $session,
        protected LoggerInterface $logger
    ) {}

    public function generateSecret(): string
    {
        $secret = '';
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    public function getQRCodeUrl(string $username, string $secret): string
    {
        $appName = urlencode(config('app.name', 'Chortke'));
        $username = urlencode($username);
        $otpauthUrl = "otpauth://totp/{$appName}:{$username}?secret={$secret}&issuer={$appName}";
        return "https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=" . urlencode($otpauthUrl);
    }

    public function verifyCode(string $secret, string $code, ?int $userId = null): bool
    {
        $timeSlice = (int)floor(time() / 30);
        for ($i = -1; $i <= 1; $i++) {
            if ($this->timingSafeEquals($this->generateTOTP($secret, $timeSlice + $i), $code)) {
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

        if (!$this->verifyCode($user->two_factor_secret, $code)) {
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
        if (!$user || !verify_password($password, $user->password)) {
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
            $this->securityModel->insertTwoFactorCode($userId, hash('sha256', $code), $expiresAt);
        }
    }

    private function verifyRecoveryCode(int $userId, string $code): bool
    {
        $hashedCode = hash('sha256', strtoupper($code));
        $record = $this->securityModel->findValidTwoFactorCode($userId, $hashedCode);

        if ($record) {
            $this->securityModel->markTwoFactorCodeAsUsed((int)$record->id);
            $this->logger->info('2FA recovery code used', ['user_id' => $userId, 'code_id' => $record->id]);
            return true;
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
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsFlipped = array_flip(str_split($base32chars));
        $paddedSecret = str_pad($secret, strlen($secret) + (8 - strlen($secret) % 8) % 8, '=');

        $bits = '';
        for ($i = 0; $i < strlen($paddedSecret); $i++) {
            if ($paddedSecret[$i] === '=') continue;
            $bits .= sprintf('%05b', $base32charsFlipped[$paddedSecret[$i]]);
        }

        $bytes = '';
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $bytes .= chr((int)bindec(substr($bits, $i, 8)));
        }
        return $bytes;
    }

    private function timingSafeEquals(string $safe, string $user): bool
    {
        return hash_equals($safe, $user);
    }
}

