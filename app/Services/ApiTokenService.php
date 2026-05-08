<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiToken;
use App\Models\User;

use App\Contracts\LoggerInterface;
class ApiTokenServiceextends \App\Services\BaseService
{
    private ApiToken $apiTokenModel;
    private User $userModel;

    public function __construct(ApiToken $apiTokenModel, User $userModel)
    {
        $this->apiTokenModel = $apiTokenModel;
        $this->userModel = $userModel;
    }

    public function getTokensForAdmin(
        int $page = 1,
        int $perPage = 30,
        ?string $search = null,
        ?string $statusFilter = null
    ): array {
        $offset = ($page - 1) * $perPage;

        $tokens = $this->apiTokenModel->findAllPaginated($perPage, $offset, $search, $statusFilter);
        $total = $this->apiTokenModel->countAll($search, $statusFilter);
        $stats = $this->apiTokenModel->getStats();

        return [
            'tokens' => $tokens,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'stats' => $stats,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    public function revokeToken(int $tokenId): bool
    {
        return $this->apiTokenModel->revokeById($tokenId);
    }

    public function revokeTokenByHash(string $token): array
    {
        $hashedToken = hash('sha256', $token);
        $record = $this->apiTokenModel->findByHash($hashedToken);

        if (!$record || (int)$record['revoked'] === 1) {
            return ['success' => false, 'message' => 'توکن یافت نشد یا قبلاً باطل شده', 'status' => 404, 'code' => 'TOKEN_NOT_FOUND'];
        }

        $this->apiTokenModel->revokeByHash($hashedToken);

        return ['success' => true];
    }

    public function listTokensForUser(int $userId): array
    {
        return $this->apiTokenModel->findByUserId($userId);
    }

    public function getActiveTokenCountForUser(int $userId): int
    {
        return $this->apiTokenModel->countActiveByUserId($userId);
    }

    public function createTokenForUser(int $userId, string $name, int $expiresIn): array
    {
        if ($name === '') {
            return ['success' => false, 'message' => 'نام توکن الزامی است'];
        }

        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);
        $expiresAt = $expiresIn > 0
            ? date('Y-m-d H:i:s', strtotime("+{$expiresIn} days"))
            : null;

        $name = trim($name);
        $name = $name === '' ? 'api-token-' . date('Ymd') : mb_substr($name, 0, 80);

        $this->apiTokenModel->createToken($userId, $hashedToken, $name, 'read', $expiresAt);

        return [
            'success' => true,
            'payload' => [
                'token' => $token,
                'name' => $name,
                'scopes' => 'read',
                'expires_at' => $expiresAt,
            ],
        ];
    }

    public function revokeTokenById(int $userId, int $tokenId): array
    {
        $token = $this->apiTokenModel->findById($tokenId);

        if (!$token || (int)$token['user_id'] !== $userId || (int)$token['revoked'] === 1) {
            return ['success' => false, 'message' => 'توکن یافت نشد', 'status' => 404, 'code' => 'TOKEN_NOT_FOUND'];
        }

        $this->apiTokenModel->revokeById($tokenId);

        return ['success' => true];
    }

    public function issueToken(string $email, string $password, string $name, string $scopes): array
    {
        if ($email === '' || $password === '') {
            return [
                'success' => false,
                'validation' => [
                    'email' => $email === '' ? 'ایمیل الزامی است' : null,
                    'password' => $password === '' ? 'رمز الزامی است' : null,
                ],
            ];
        }

        $user = $this->userModel->findByEmail($email);
        if (!$user || !password_verify($password, $user->password)) {
            return [
                'success' => false,
                'message' => 'ایمیل یا رمز عبور اشتباه است',
                'status' => 401,
                'code' => 'INVALID_CREDENTIALS',
            ];
        }

        if ($user->status !== 'active' && (int)$user->status !== 1) {
            return [
                'success' => false,
                'message' => 'حساب کاربری غیرفعال است',
                'status' => 403,
                'code' => 'ACCOUNT_INACTIVE',
            ];
        }

        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $name = trim($name);
        if ($name === '') {
            $name = 'api-token-' . date('Ymd');
        }
        $name = mb_substr($name, 0, 80);

        $scopes = preg_replace('/[^a-z0-9,_-]/i', '', trim($scopes));
        if ($scopes === '') {
            $scopes = 'read';
        }

        $this->apiTokenModel->createToken($user->id, $hashedToken, $name, $scopes, $expiresAt);

        return [
            'success' => true,
            'payload' => [
                'token' => $token,
                'type' => 'Bearer',
                'expires_at' => $expiresAt,
                'name' => $name,
                'scopes' => $scopes,
            ],
        ];
    }

    public function revokeAllExpiredTokens(): int
    {
        return $this->apiTokenModel->revokeAllExpired();
    }
}

