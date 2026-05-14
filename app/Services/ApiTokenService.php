<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiToken;
use App\Models\User;
use App\Contracts\LoggerInterface;
use Core\RateLimiter;

class ApiTokenService extends \App\Services\BaseService
{
    private ApiToken $apiTokenModel;
    private User $userModel;
    private RateLimiter $rateLimiter;
    private static string $dummyHash = '';

    public function __construct(LoggerInterface $logger, ApiToken $apiTokenModel, User $userModel, RateLimiter $rateLimiter)
    {
        parent::__construct($logger);
        $this->apiTokenModel = $apiTokenModel;
        $this->userModel = $userModel;
        $this->rateLimiter = $rateLimiter;
    }

    public function getTokensForAdmin(
        int $page = 1,
        int $perPage = 30,
        ?string $search = null,
        ?string $statusFilter = null
    ): array {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);
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
        $this->logger->info('api_token.revoked_by_id', ['token_id' => $tokenId]);
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

        $this->logger->info('api_token.revoked_by_hash', [
            'token_id' => $record['id'] ?? null,
            'user_id' => $record['user_id'] ?? null
        ]);

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

    public function createTokenForUser(int $userId, string $name, int $expiresIn, string $scope = 'read'): array
    {
        if ($name === '') {
            return ['success' => false, 'message' => 'نام توکن الزامی است'];
        }

        $activeCount = $this->getActiveTokenCountForUser($userId);
        if ($activeCount >= 10) {
            return [
                'success' => false,
                'message' => 'حداکثر تعداد توکن‌های فعال (10) به حد خود رسیده است',
                'code' => 'TOKEN_LIMIT_REACHED'
            ];
        }

        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);
        $expiresAt = $expiresIn > 0
            ? date('Y-m-d H:i:s', strtotime("+{$expiresIn} days"))
            : null;

        $name = trim($name);
        $name = $name === '' ? 'api-token-' . date('Ymd') : mb_substr($name, 0, 80);

        $validScopes = ['read', 'write', 'admin'];
        if (!in_array($scope, $validScopes, true)) {
            $scope = 'read';
        }

        $this->apiTokenModel->createToken($userId, $hashedToken, $name, $scope, $expiresAt);

        $this->logger->info('api_token.created_for_user', [
            'user_id' => $userId,
            'name' => $name,
            'scope' => $scope
        ]);

        return [
            'success' => true,
            'payload' => [
                'token' => $token,
                'name' => $name,
                'scopes' => $scope,
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

        $this->logger->info('api_token.revoked_by_user', [
            'user_id' => $userId,
            'token_id' => $tokenId
        ]);

        return ['success' => true];
    }

    private function getDummyHash(): string
    {
        if (empty(self::$dummyHash)) {
            self::$dummyHash = password_hash('dummy_password_not_used', PASSWORD_BCRYPT);
        }
        return self::$dummyHash;
    }

    public function issueToken(string $email, string $password, string $name, string $scopes): array
    {
        // MED-11: Rate limiting check (10 attempts per 60 seconds per IP)
        $ipKey = 'token_issue:' . ($this->clientIp() ?? 'unknown');
        if ($this->rateLimiter->tooMany($ipKey, maxAttempts: 10, decaySeconds: 60)) {
            return [
                'success' => false,
                'message' => 'تعداد تلاش‌های زیادی برای صدور توکن. لطفاً بعداً تلاش کنید',
                'status' => 429,
                'code' => 'RATE_LIMITED',
            ];
        }

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
        $passwordValid = password_verify($password, $user ? $user->password : $this->getDummyHash());

        if (!$user || !$passwordValid) {
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

        $requestedScopes = explode(',', preg_replace('/[^a-z0-9,_-]/i', '', trim($scopes)));
        $validScopesList = ['read', 'write', 'admin'];
        $finalScopes = [];
        foreach ($requestedScopes as $s) {
            $s = trim($s);
            if (in_array($s, $validScopesList, true)) {
                $finalScopes[] = $s;
            }
        }
        $scopes = !empty($finalScopes) ? implode(',', array_unique($finalScopes)) : 'read';

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

    public function searchTokens(string $q, array $filters, int $limit, int $offset): array
    {
        $query = $this->apiTokenModel->query()
            ->select('api_tokens.*', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'api_tokens.user_id');

        if (!empty($q)) {
            // HIGH-01 Fix: فرآیند پاکسازی کاراکترهای ویژه مانند % و _ جهت ممانعت از اسکن سنگین جداول (DoS)
            $escapedQ = addcslashes(trim($q), '%_');
            $like = "%{$escapedQ}%";
            
            $query->where(function($sub) use ($like, $q) {
                $sub->where('api_tokens.name', 'LIKE', $like)
                    ->orWhere('api_tokens.token', '=', $q)
                    ->orWhere('u.email', 'LIKE', $like);
            });
        }

        // HIGH-01 & HIGH-02: اعتبارسنجی status و escape امن
        $allowedStatuses = ['active', 'revoked', 'expired'];
        if (!empty($filters['status']) && in_array($filters['status'], $allowedStatuses, true)) {
            $query->where('api_tokens.status', '=', $filters['status']);
        }

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy('api_tokens.created_at', 'DESC')
                                     ->limit($limit)->offset($offset)->get() ?? []
        ];
    }
}
