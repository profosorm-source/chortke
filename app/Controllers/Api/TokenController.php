<?php

namespace App\Controllers\Api;

use App\Services\ApiTokenService;
use Core\RateLimiter;

/**
 * API\TokenController - مدیریت API Token
 *
 * POST /api/v1/auth/token    → دریافت token با credentials
 * POST /api/v1/auth/revoke   → باطل کردن token
 * GET  /api/v1/auth/tokens   → لیست tokenهای فعال (نیاز به auth)
 */
class TokenController extends BaseApiController
{
    private ApiTokenService $service;
    private \Core\RateLimiter $rateLimiter;

    public function __construct(ApiTokenService $service, \Core\RateLimiter $rateLimiter, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->service = $service;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * دریافت API Token با email/password
     * این endpoint نیاز به middleware auth ندارد
     */
    public function issue(): void
    {
        $data = $this->request->body();

        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->validationError([
                'email'    => $email === '' ? 'ایمیل الزامی است' : null,
                'password' => $password === '' ? 'رمز الزامی است' : null,
            ]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('فرمت ایمیل نامعتبر است', 422, 'INVALID_EMAIL');
        }

        $key = $this->issueRateLimitKey($email);
        if (!$this->rateLimiter->attempt($key, 8, 10, true)) {
            $this->error('تعداد تلاش بیش از حد مجاز است. چند دقیقه دیگر تلاش کنید', 429, 'RATE_LIMITED');
            return;
        }

        $result = $this->service->issueToken(
            $email,
            $password,
            trim((string)($data['token_name'] ?? '')),
            trim((string)($data['scopes'] ?? 'read')),
            trim((string)($data['otp'] ?? ''))
        );

        if (!$result['success']) {

            if (!empty($result['validation'])) {
                $this->validationError($result['validation']);
                return;
            }

            $this->error($result['message'], $result['status'] ?? 400, $result['code'] ?? 'TOKEN_ERROR');
            return;
        }

        $this->clearIssueRateLimit($email);
        $this->success($result['payload'], 'توکن با موفقیت صادر شد', 201);
    }

    /**
     * باطل کردن token جاری
     */
    public function revoke(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->error('احراز هویت نشده', 401);
            return;
        }

        $authHeader = $this->request->header('Authorization') ?? '';
        $token      = str_starts_with($authHeader, 'Bearer ') ? trim(substr($authHeader, 7)) : null;

        if (!$token) {
            $this->error('توکن یافت نشد', 400);
            return;
        }

        $result = $this->service->revokeTokenByHashForUser($token, $userId);

        if (!$result['success']) {
            $this->error($result['message'], $result['status'] ?? 404, $result['code'] ?? 'TOKEN_NOT_FOUND');
            return;
        }

        $this->success(null, 'توکن با موفقیت باطل شد');
    }

    /**
     * لیست tokenهای فعال کاربر
     */
    public function list(): void
    {
        $tokens = $this->service->listTokensForUser($this->userId());
        $this->success($tokens);
    }

    /**
     * باطل کردن یک token خاص
     */
    public function revokeById(): void
    {
        $userId  = $this->userId();
        $tokenId = (int)($this->request->get('id') ?? 0);

        if (!$tokenId) {
            $this->error('ID توکن الزامی است', 400);
        }

        $result = $this->service->revokeTokenById($userId, $tokenId);

        if (!$result['success']) {
            $this->error($result['message'], $result['status'] ?? 404, $result['code'] ?? 'TOKEN_NOT_FOUND');
            return;
        }

        $this->success(null, 'توکن باطل شد');
    }
	
    private function issueRateLimitKey(string $email): string
    {
        $ip = $this->request->ip() ?? 'unknown';
        return 'api_token_issue_rl_' . hash_hmac('sha256', $ip . '|' . mb_strtolower($email), (string)config('app.key'));
    }

    private function clearIssueRateLimit(string $email): void
    {
        $this->rateLimiter->clear($this->issueRateLimitKey($email));
    }

}
