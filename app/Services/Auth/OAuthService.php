<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\SecurityModel;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\AuditTrail;
use App\Contracts\LoggerInterface;
/**
 * OAuthService — Social Login سہولت (Google, Facebook)
 */
class OAuthService extends \App\Services\BaseService
{
    private string $googleClientId;
    private string $googleClientSecret;
    private string $facebookAppId;
    private string $facebookAppSecret;
    private string $appUrl;

    public function __construct(
        private SecurityModel $model,
        protected LoggerInterface $logger,
        private User $userModel,
        private AuthService $authService,
        private NotificationService $notificationService,
        private AuditTrail $auditTrail
    ) {
        parent::__construct($logger);
        $this->googleClientId = (string)config('oauth.google.client_id', '');
        $this->googleClientSecret = (string)config('oauth.google.client_secret', '');
        $this->facebookAppId = (string)config('oauth.facebook.app_id', '');
        $this->facebookAppSecret = (string)config('oauth.facebook.app_secret', '');
        $this->appUrl = (string)config('app.url', 'http://localhost');
    }

    public function getGoogleAuthUrl(): string
    {
        $redirectUri = urlencode("{$this->appUrl}/auth/callback/google");
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        return "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
            'client_id' => $this->googleClientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
        ]);
    }

    public function handleGoogleCallback(string $code, string $state): array
    {
        if (!isset($_SESSION['oauth_state']) || $_SESSION['oauth_state'] !== $state) {
            return ['success' => false, 'message' => 'Invalid state security check failed.'];
        }

        try {
            $token = $this->getGoogleToken($code);
            if (!$token['success']) return $token;

            $userInfo = $this->getGoogleUserInfo($token['access_token']);
            if (!$userInfo['success']) return $userInfo;

            return $this->linkOrCreateUser('google', $userInfo['data']);
        } catch (\Exception $e) {
            $this->logger->error('oauth.google.callback_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ورود با گوگل'];
        }
    }

    private function linkOrCreateUser(string $provider, array $userData): array
    {
        try {
            $socialAccount = $this->model->getSocialAccount($provider, (string)$userData['id']);

            if ($socialAccount) {
                $user = $this->userModel->find((int)$socialAccount->user_id);
                if ($user) {
                    return ['success' => true, 'user_id' => $user->id, 'is_new' => false];
                }
            }

            $existingUser = $this->userModel->findByEmail((string)$userData['email']);
            if ($existingUser) {
                $this->model->createSocialAccount([
                    'user_id' => (int)$existingUser->id,
                    'provider' => $provider,
                    'provider_id' => (string)$userData['id'],
                    'avatar' => $userData['picture'] ?? null
                ]);
                return ['success' => true, 'user_id' => $existingUser->id, 'is_new' => false];
            }

            $newUser = $this->userModel->create([
                'email' => $userData['email'],
                'username' => explode('@', (string)$userData['email'])[0],
                'full_name' => $userData['name'] ?? '',
                'password' => hash_password(bin2hex(random_bytes(16))),
                'email_verified_at' => date('Y-m-d H:i:s'),
                'status' => 'active',
                'role' => 'user'
            ]);

            if ($newUser) {
                $this->model->createSocialAccount([
                    'user_id' => (int)$newUser,
                    'provider' => $provider,
                    'provider_id' => (string)$userData['id'],
                    'avatar' => $userData['picture'] ?? null
                ]);
                return ['success' => true, 'user_id' => $newUser, 'is_new' => true];
            }

            return ['success' => false, 'message' => 'خطا در ایجاد حساب کاربری'];
        } catch (\Exception $e) {
            $this->logger->error('oauth.link_or_create.failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در پردازش اطلاعات'];
        }
    }

    private function getGoogleToken(string $code): array
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'code' => $code,
            'client_id' => $this->googleClientId,
            'client_secret' => $this->googleClientSecret,
            'redirect_uri' => "{$this->appUrl}/auth/callback/google",
            'grant_type' => 'authorization_code',
        ]));
        $response = json_decode((string)curl_exec($ch), true);
        curl_close($ch);

        return isset($response['access_token']) ? ['success' => true, 'access_token' => $response['access_token']] : ['success' => false, 'message' => 'Token retrieval failed'];
    }

    private function getGoogleUserInfo(string $accessToken): array
    {
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
        $response = json_decode((string)curl_exec($ch), true);
        curl_close($ch);

        return isset($response['id']) ? ['success' => true, 'data' => $response] : ['success' => false, 'message' => 'User info retrieval failed'];
    }
}

