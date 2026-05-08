<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Contracts\LoggerInterface;
/**
 * UserService
 *
 * مدیریت موجودیت کاربر (ثبت‌نام، وضعیت، هویت).
 */
class UserService extends \App\Services\BaseService
{
    public function __construct(
        private User $model,
        protected LoggerInterface $logger
    ) {}

    public function register(array $data): int|false
    {
        $this->logger->info('user.registration.attempt', ['email' => $data['email'] ?? 'unknown']);

        $data['password'] = hash_password($data['password'] ?? bin2hex(random_bytes(8)));
        $data['referral_code'] = $this->generateUniqueReferralCode();
        $data['email_verification_token'] = bin2hex(random_bytes(32));
        $data['status'] = $data['status'] ?? 'active';
        $data['role'] = $data['role'] ?? 'user';
        $data['created_at'] = date('Y-m-d H:i:s');

        $userId = $this->model->create($data);

        if ($userId) {
            $this->logger->info('user.registration.success', ['user_id' => $userId]);
        }

        return $userId;
    }

    public function generateUniqueReferralCode(): string
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while ($this->model->findByReferralCode($code));

        return $code;
    }

    public function verifyEmail(int $userId): bool
    {
        return $this->model->verifyEmail($userId);
    }

    public function changePassword(int $userId, string $newPassword): bool
    {
        $success = $this->model->update($userId, [
            'password' => hash_password($newPassword),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($success) {
            $this->logger->info('user.password_changed', ['user_id' => $userId]);
        }

        return $success;
    }

    public function banUser(int $userId, ?string $reason = null): bool
    {
        return $this->model->update($userId, [
            'status' => 'banned',
            'ban_reason' => $reason,
            'banned_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function unbanUser(int $userId): bool
    {
        return $this->model->update($userId, [
            'status' => 'active',
            'ban_reason' => null,
            'banned_at' => null,
        ]);
    }

    public function recordLogin(int $userId): bool
    {
        return $this->model->updateLastLogin(
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        );
    }

    public function find(int $id): ?object
    {
        return $this->model->find($id);
    }

    public function findById(int $id): ?object
    {
        return $this->find($id);
    }

    public function findByEmail(string $email): ?object
    {
        return $this->model->findByEmail($email);
    }

    public function emailExists(string $email): bool
    {
        return $this->model->findByEmail($email) !== null;
    }

    public function mobileExists(string $mobile): bool
    {
        return $this->model->findByMobile($mobile) !== null;
    }

    public function findByCredentials(string $identifier): ?object
    {
        return $this->model->findByCredentials($identifier);
    }

    public function isBlacklisted(int $userId): bool
    {
        return $this->model->isBlacklisted($userId);
    }

    public function isKycVerified(int $userId): bool
    {
        $user = $this->find($userId);
        return $user && isset($user->kyc_status) && $user->kyc_status === 'verified';
    }
}

