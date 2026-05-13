<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\SecurityModel;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\AuditTrail;
use App\Services\DistributedLockService;
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
        private AuditTrail $auditTrail,
        private \Core\Session $session,
        private \Core\Database $db,
        private DistributedLockService $lockService,
        private array $oAuthConfig = []
    ) {
        parent::__construct($logger);
        $this->googleClientId = (string)($this->oAuthConfig['google_client_id'] ?? '');
        $this->googleClientSecret = (string)($this->oAuthConfig['google_client_secret'] ?? '');
        $this->facebookAppId = (string)($this->oAuthConfig['facebook_app_id'] ?? '');
        $this->facebookAppSecret = (string)($this->oAuthConfig['facebook_app_secret'] ?? '');
        $this->appUrl = (string)($this->oAuthConfig['app_url'] ?? 'http://localhost');
    }

    public function getGoogleAuthUrl(): string
    {
        $redirectUri = urlencode("{$this->appUrl}/auth/callback/google");
        $state = bin2hex(random_bytes(16));
        $this->session->set('oauth_state', $state);

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
        if (!$this->session->has('oauth_state') || $this->session->get('oauth_state') !== $state) {
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
        // Input validation
        if (empty($provider) || strlen($provider) > 50) {
            throw new \InvalidArgumentException('Invalid provider: must be non-empty and max 50 chars');
        }

        if (empty($userData) || !is_array($userData)) {
            throw new \InvalidArgumentException('User data must be a non-empty array');
        }

        if (empty($userData['id']) || empty($userData['email'])) {
            throw new \InvalidArgumentException('User data must contain id and email');
        }

        if (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }

        // 🔒 DISTRIBUTED LOCK: Prevent race conditions across multiple servers
        $lockResource = "oauth:{$provider}:" . md5($userData['email']);
        $lock = $this->lockService->acquire($lockResource, ttl: 30, waitTimeout: 5);

        if (!$lock['acquired']) {
            $this->logger->warning('oauth.link_or_create.lock_failed', [
                'provider' => $provider,
                'email' => $userData['email']
            ]);
            return ['success' => false, 'message' => 'سیستم مشغول است، لطفا دوباره تلاش کنید'];
        }

        try {
            $this->db->beginTransaction();

            // 🔒 PESSIMISTIC LOCKING: Lock social_accounts row to prevent race condition
            $socialAccount = $this->db->selectOne(
                "SELECT * FROM social_accounts WHERE provider = ? AND provider_id = ? FOR UPDATE",
                [$provider, (string)$userData['id']]
            );

            if ($socialAccount) {
                $user = $this->userModel->find((int)$socialAccount->user_id);
                if ($user) {
                    $this->db->commit();
                    $this->logger->info('oauth.link_or_create.existing_social_account', [
                        'user_id' => $user->id,
                        'provider' => $provider
                    ]);
                    return ['success' => true, 'user_id' => $user->id, 'is_new' => false];
                }
            }

            // 🔒 PESSIMISTIC LOCKING: Lock users row by email to prevent duplicate user creation
            $existingUser = $this->db->selectOne(
                "SELECT * FROM users WHERE email = ? FOR UPDATE",
                [(string)$userData['email']]
            );

            if ($existingUser) {
                // Link existing user to OAuth provider
                $this->model->createSocialAccount([
                    'user_id' => (int)$existingUser->id,
                    'provider' => $provider,
                    'provider_id' => (string)$userData['id'],
                    'avatar' => $userData['picture'] ?? null
                ]);
                $this->db->commit();
                $this->logger->info('oauth.link_or_create.linked_existing_user', [
                    'user_id' => $existingUser->id,
                    'provider' => $provider
                ]);
                return ['success' => true, 'user_id' => $existingUser->id, 'is_new' => false];
            }

            // 🔒 No rows locked yet for new user creation - proceed safely
            $username = $this->generateUniqueUsername((string)$userData['email']);
            
            $newUser = $this->userModel->create([
                'email' => $userData['email'],
                'username' => $username,
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
                $this->db->commit();
                $this->logger->info('oauth.link_or_create.created_new_user', [
                    'user_id' => $newUser,
                    'provider' => $provider
                ]);
                return ['success' => true, 'user_id' => $newUser, 'is_new' => true];
            }

            $this->db->rollBack();
            $this->logger->error('oauth.link_or_create.user_creation_failed', ['provider' => $provider]);
            return ['success' => false, 'message' => 'خطا در ایجاد حساب کاربری'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('oauth.link_or_create.failed', [
                'error' => $e->getMessage(),
                'provider' => $provider
            ]);
            return ['success' => false, 'message' => 'خطا در پردازش اطلاعات'];
        } finally {
            // 🔒 Always release distributed lock
            $this->lockService->release($lockResource, $lock['token']);
        }
    }

    private function getGoogleToken(string $code): array
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        if ($ch === false) {
            return ['success' => false, 'message' => 'Failed to initialize curl'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'code' => $code,
            'client_id' => $this->googleClientId,
            'client_secret' => $this->googleClientSecret,
            'redirect_uri' => "{$this->appUrl}/auth/callback/google",
            'grant_type' => 'authorization_code',
        ]));

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error('oauth.google.token_curl_error', ['error' => $error]);
            return ['success' => false, 'message' => 'خطا در ارتباط با سرور گوگل'];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = json_decode((string)$rawResponse, true);
        if ($httpCode !== 200 || !isset($response['access_token'])) {
            $this->logger->error('oauth.google.token_invalid_response', [
                'http_code' => $httpCode,
                'response'  => $response
            ]);
            return ['success' => false, 'message' => 'خطا در دریافت توکن گوگل'];
        }

        return ['success' => true, 'access_token' => $response['access_token']];
    }

    private function getGoogleUserInfo(string $accessToken): array
    {
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        if ($ch === false) {
            return ['success' => false, 'message' => 'Failed to initialize curl'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error('oauth.google.userinfo_curl_error', ['error' => $error]);
            return ['success' => false, 'message' => 'خطا در ارتباط با سرور گوگل'];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = json_decode((string)$rawResponse, true);
        if ($httpCode !== 200 || !isset($response['id'])) {
            $this->logger->error('oauth.google.userinfo_invalid_response', [
                'http_code' => $httpCode,
                'response'  => $response
            ]);
            return ['success' => false, 'message' => 'خطا در دریافت اطلاعات کاربری گوگل'];
        }

        return ['success' => true, 'data' => $response];
    }

    private function generateUniqueUsername(string $email): string
    {
        $parts = explode('@', $email);
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $parts[0]));
        
        if (strlen($base) < 4) {
            $base .= 'u' . rand(100, 999);
        }
        
        $username = $base;
        $counter = 1;
        
        while ($this->userModel->where('username', '=', $username)->first()) {
            $username = $base . $counter;
            $counter++;
            
            if ($counter > 50) {
                $username = $base . bin2hex(random_bytes(3));
                break;
            }
        }
        
        return $username;
    }
}

