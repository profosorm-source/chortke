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
    private \App\Services\Auth\TwoFactorService $twoFactorService;
    private readonly string $dummyHash;

    public function __construct(
        LoggerInterface $logger, 
        ApiToken $apiTokenModel, 
        User $userModel, 
        RateLimiter $rateLimiter,
        \App\Services\Auth\TwoFactorService $twoFactorService
    ) {
        parent::__construct($logger);
        $this->apiTokenModel = $apiTokenModel;
        $this->userModel = $userModel;
        $this->rateLimiter = $rateLimiter;
        $this->twoFactorService = $twoFactorService;
        $this->dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
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
        // CRIT-01 Fix: Pass plain token to the model which handles HMAC-SHA256
        $record = $this->apiTokenModel->findByHash($token);

        if (!$record || (int)$record['revoked'] === 1) {
            return ['success' => false, 'message' => 'توکن یافت نشد یا قبلاً باطل شده', 'status' => 404, 'code' => 'TOKEN_NOT_FOUND'];
        }

        $this->apiTokenModel->revokeByHash($token);

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
        $expiresAt = $expiresIn > 0
            ? date('Y-m-d H:i:s', strtotime("+{$expiresIn} days"))
            : null;

        $name = trim($name);
        $name = $name === '' ? 'api-token-' . date('Ymd') : mb_substr($name, 0, 80);

        // MEDIUM-M7 Fix: Robust scope validation for multiple scopes
        $requestedScopes = explode(',', (string)$scope);
        $finalScopes = [];
        foreach ($requestedScopes as $s) {
            $s = trim($s);
            if (in_array($s, ApiToken::ALLOWED_SCOPES, true)) {
                $finalScopes[] = $s;
            }
        }
        $finalScopes = array_unique($finalScopes);
        if (empty($finalScopes)) {
            $finalScopes = ['read'];
        }

        // فقط ادمین میتواند توکن با اسکوپ admin بسازد
        $user = $this->userModel->findById($userId);
        $isAdmin = $user && in_array($user->role, ['admin', 'super_admin'], true);
        
        if (in_array('admin', $finalScopes, true) && !$isAdmin) {
            $finalScopes = array_diff($finalScopes, ['admin']);
            if (empty($finalScopes)) {
                $finalScopes = ['read'];
            }
        }

        if (in_array('*', $finalScopes, true) && !$isAdmin) {
            return [
                'success' => false,
                'message' => 'تنها ادمین می‌تواند توکن با دسترسی کامل بسازد',
                'status' => 403,
                'code' => 'FORBIDDEN_SCOPE'
            ];
        }

        $scope = implode(',', $finalScopes);

        $this->apiTokenModel->createToken($userId, $token, $name, $scope, $expiresAt);

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
        return $this->dummyHash;
    }

    public function issueToken(string $email, string $password, string $name, string $scopes, string $otp = ''): array
    {
        // MED-11: Rate limiting check (10 attempts per 60 seconds per IP)
        $ip = $this->clientIp() ?? 'unknown';
        $ipKey = 'token_issue:' . $ip;
        if ($this->rateLimiter->tooMany($ipKey, maxAttempts: 10, decaySeconds: 60)) {
            return [
                'success' => false,
                'message' => 'تعداد تلاش‌های زیادی برای صدور توکن. لطفاً بعداً تلاش کنید',
                'status' => 429,
                'code' => 'RATE_LIMITED',
            ];
        }

        // HIGH-05 Fix: Per-identifier rate limiting to prevent password spray
        $identifierKey = 'token_issue_id:' . hash('sha256', mb_strtolower($email));
        if ($this->rateLimiter->tooMany($identifierKey, maxAttempts: 5, decaySeconds: 300)) {
            $this->logger->warning('api_token.issue.throttled_by_id', ['email' => $email, 'ip' => $ip]);
            return [
                'success' => false,
                'message' => 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً ۵ دقیقه دیگر تلاش کنید.',
                'status' => 429,
                'code' => 'RATE_LIMITED_IDENTIFIER',
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
        $passwordValid = verify_user_password($password, $user ? $user->password : $this->getDummyHash(), $user ? (int)$user->id : null);

        if (!$user || !$passwordValid) {
            return [
                'success' => false,
                'message' => 'ایمیل یا رمز عبور اشتباه است',
                'status' => 401,
                'code' => 'INVALID_CREDENTIALS',
            ];
        }

        // HIGH-H-05 Fix: Enforce 2FA check for API token issuance
        if (!empty($user->two_factor_enabled)) {
            if (empty($otp)) {
                return [
                    'success' => false,
                    'message' => 'کد 2FA الزامی است',
                    'code' => 'REQUIRES_2FA',
                    'status' => 403
                ];
            }
            if (!$this->twoFactorService->verifyTOTPCode($user->two_factor_secret, $otp, (int)$user->id)) {
                return [
                    'success' => false,
                    'message' => 'کد 2FA نامعتبر است',
                    'code' => 'INVALID_2FA',
                    'status' => 403
                ];
            }
        }

        // HIGH-H-07 Fix: Enforce account status check (locked, banned, suspended)
        if (in_array($user->status, ['locked', 'banned', 'suspended'], true)) {
            return [
                'success' => false,
                'message' => 'حساب کاربری شما غیرفعال یا مسدود شده است',
                'status' => 403,
                'code' => 'ACCOUNT_DISABLED',
            ];
        }

        if ((string)$user->status !== 'active') {
            return [
                'success' => false,
                'message' => 'حساب کاربری فعال نیست',
                'status' => 403,
                'code' => 'ACCOUNT_INACTIVE',
            ];
        }

        // MED-07 Fix: Ensure email is verified before issuing tokens
        if (empty($user->email_verified_at)) {
            return [
                'success' => false,
                'message' => 'ایمیل شما تایید نشده است. لطفاً ابتدا ایمیل خود را تایید کنید.',
                'status' => 403,
                'code' => 'EMAIL_UNVERIFIED',
            ];
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $name = trim($name);
        if ($name === '') {
            $name = 'api-token-' . date('Ymd');
        }
        $name = mb_substr($name, 0, 80);

        $requestedScopes = explode(',', preg_replace('/[^a-z0-9,:_-]/i', '', trim($scopes)));
        $finalScopes = [];
        foreach ($requestedScopes as $s) {
            $s = trim($s);
            if (in_array($s, ApiToken::ALLOWED_SCOPES, true)) {
                $finalScopes[] = $s;
            }
        }
        $finalScopes = array_unique($finalScopes);

        // H20 Fix: فقط ادمین یا سوپرادمین مجاز به دریافت توکن با اسکوپ admin هستند
        $isAdmin = in_array($user->role, ['admin', 'super_admin'], true);
        if (in_array('admin', $finalScopes, true) && !$isAdmin) {
            $finalScopes = array_diff($finalScopes, ['admin']);
        }

        $scopes = !empty($finalScopes) ? implode(',', array_unique($finalScopes)) : 'read';

        $this->apiTokenModel->createToken($user->id, $token, $name, $scopes, $expiresAt);

        // Clear rate limit on success
        $this->rateLimiter->clear($identifierKey);

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
