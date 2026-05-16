<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\SecurityModel;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\AuditTrail;
use App\Services\DistributedLockService;
use App\Contracts\LoggerInterface;
use App\Constants\SessionKeys;
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
        $redirectUri = "{$this->appUrl}/auth/callback/google";
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        
        // HIGH-H-15 Fix: State signing with application key to prevent state tampering/forgery
        $signature = hash_hmac('sha256', $state, (string)config('app.key'));

        // 🛡️ Security Improvement: Storing cryptographic state with creation timestamp for TTL enforcement.
        $this->session->set(SessionKeys::OAUTH_STATE, [
            'token'      => $state,
            'signature'  => $signature,
            'nonce'      => $nonce,
            'created_at' => time(),
            'session_id' => $this->session->getId(),
            'ip'         => $this->clientIp()
        ]);

        return "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
            'client_id' => $this->googleClientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
        ]);
    }

    public function handleGoogleCallback(string $code, string $state): array
    {
        if (!$this->session->has(SessionKeys::OAUTH_STATE)) {
            return ['success' => false, 'message' => 'Invalid request: session state missing.'];
        }

        $stored = $this->session->get(SessionKeys::OAUTH_STATE);
        
        // Atomic Cleanup: Clear state instantly to block replay attacks
        $this->session->remove(SessionKeys::OAUTH_STATE);

        if (!is_array($stored) || !isset($stored['token']) || !isset($stored['created_at'])) {
            return ['success' => false, 'message' => 'Invalid state structure.'];
        }

        if ($stored['token'] !== $state) {
            return ['success' => false, 'message' => 'Invalid state token match failed.'];
        }

        // HIGH-H-15 Fix: Verify state signature
        $expectedSignature = hash_hmac('sha256', $state, (string)config('app.key'));
        if (!hash_equals($expectedSignature, (string)($stored['signature'] ?? ''))) {
            return ['success' => false, 'message' => 'State signature verification failed.'];
        }

        // HIGH-06 Fix: Verify session binding
        if (($stored['session_id'] ?? '') !== $this->session->getId()) {
            return ['success' => false, 'message' => 'Session mismatch during OAuth flow.'];
        }

        // HIGH-H-01 Fix: Relaxed IP binding - Log as warning but don't block (UX for mobile/proxy users)
        if (($stored['ip'] ?? '') !== $this->clientIp()) {
            $this->logger->warning('oauth.google.ip_changed_during_flow', [
                'expected' => $stored['ip'],
                'received' => $this->clientIp()
            ]);
        }


        // 🛡️ Hardened Expiration: Bound security state validity to maximum 5 minutes
        if ((time() - (int)$stored['created_at']) > 300) {
            return ['success' => false, 'message' => 'The sign-in state has expired. Please try again.'];
        }

        try {
            $token = $this->getGoogleToken($code);
            if (!$token['success']) return $token;

            if (empty($token['id_token'])) {
                $this->logger->error('oauth.google.id_token_missing', ['token_resp' => $token]);
                return ['success' => false, 'message' => 'توکن هویتی (ID Token) از طرف گوگل صادر نشده است'];
            }

            // 🛡️ Security Upgrade: استفاده از ID Token و راستی‌آزمایی رمزنگاری شده به جای access_token
            // این کار جلوی هرگونه جعل هویت و جعل دسترسی (Authentication Bypass) را می‌گیرد
            $userInfo = $this->verifyGoogleIdToken($token['id_token']);
            if (!$userInfo['success']) return $userInfo;

            // MED-M-04 Fix: Validate nonce in ID Token to prevent replay attacks
            if (empty($userInfo['data']['nonce']) || !hash_equals((string)$stored['nonce'], (string)$userInfo['data']['nonce'])) {
                $this->logger->critical('oauth.google.nonce_mismatch', [
                    'expected' => $stored['nonce'] ?? 'none',
                    'received' => $userInfo['data']['nonce'] ?? 'none'
                ]);
                return ['success' => false, 'message' => 'Nonce validation failed.'];
            }

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

            // CRIT-05: Check if we are in a linking flow (user already logged in)
            $linkingUserId = $this->session->get(SessionKeys::OAUTH_LINKING_USER_ID);
            $this->session->remove(SessionKeys::OAUTH_LINKING_USER_ID);

            if ($linkingUserId) {
                $result = $this->linkSocialAccount((int)$linkingUserId, $provider, $userData);
                if ($result['success']) {
                    $this->db->commit();
                    return $result;
                }
                $this->db->rollBack();
                return $result;
            }

            // 🔒 Safe Pessimistic Lock: Swapped RAW SQL query for native, database-agnostic lockForUpdate()
            $socialAccount = $this->db->table('social_accounts')
                ->where('provider', '=', $provider)
                ->where('provider_id', '=', (string)$userData['id'])
                ->lockForUpdate()
                ->first();

            if ($socialAccount) {
                $user = $this->userModel->find((int)$socialAccount->user_id);
                if ($user) {
                    $this->db->commit();
                    $this->logger->info('oauth.link_or_create.existing_social_account', [
                        'user_id' => $user->id,
                        'provider' => $provider
                    ]);
                    $login = $this->authService->loginDirectly($user);
                    return array_merge($login, ['is_new' => false, 'user_id' => $user->id]);
                }
            }

            // 🔒 Safe Pessimistic Lock: Prevent account duplication safely via atomic Query Builder locking
            $existingUser = $this->db->table('users')
                ->where('email', '=', (string)$userData['email'])
                ->lockForUpdate()
                ->first();

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
                $user = $this->userModel->find((int)$existingUser->id);
                $login = $this->authService->loginDirectly($user);
                return array_merge($login, ['is_new' => false, 'user_id' => $user->id]);
            }

            // 🔒 No rows locked yet for new user creation - proceed safely
            $username = $this->generateUniqueUsername((string)$userData['email']);
            
            $newUser = $this->userModel->create([
                'email' => $userData['email'],
                'username' => $username,
                'full_name' => $userData['name'] ?? '',
                'password' => hash_password(bin2hex(random_bytes(16))),
                'email_verified_at' => ($userData['email_verified'] ?? true) ? date('Y-m-d H:i:s') : null,
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
                $user = $this->userModel->find((int)$newUser);
                $login = $this->authService->loginDirectly($user);
                return array_merge($login, ['is_new' => true, 'user_id' => $user->id]);
            }

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('oauth.link_or_create.user_creation_failed', ['provider' => $provider]);
            return ['success' => false, 'message' => 'خطا در ایجاد حساب کاربری'];
        } catch (\Exception $e) {
            // 🛡️ H01 Fix: Safe transaction rollback — check if transaction is active before rolling back
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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

        return [
            'success'      => true, 
            'access_token' => $response['access_token'],
            'id_token'     => $response['id_token'] ?? null // OIDC ID Token
        ];
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

    /**
     * 🛡️ متد حیاتی جهت بررسی اعتبار توکن هویتی و اعتبارسنجی Audience گوگل
     */
    private function verifyGoogleIdToken(string $idToken): array
    {
        $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?' . http_build_query(['id_token' => $idToken]));
        if ($ch === false) {
            return ['success' => false, 'message' => 'Failed to initialize curl'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error('oauth.google.id_token_curl_error', ['error' => $error]);
            return ['success' => false, 'message' => 'خطا در راستی‌آزمایی توکن گوگل'];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = json_decode((string)$rawResponse, true);
        
        if ($httpCode !== 200 || !isset($response['aud']) || !isset($response['sub'])) {
            $this->logger->error('oauth.google.id_token_invalid', ['response' => $response]);
            return ['success' => false, 'message' => 'امضای توکن هویتی گوگل نامعتبر است'];
        }

        // CRITICAL-C-02 Fix: Validating Issuer (iss), Expiration (exp), and Issued-At (iat) claims
        $validIssuers = ['accounts.google.com', 'https://accounts.google.com'];
        if (!in_array($response['iss'] ?? '', $validIssuers, true)) {
            $this->logger->critical('oauth.google.iss_mismatch', ['received' => $response['iss'] ?? '']);
            return ['success' => false, 'message' => 'Issuer mismatch in ID Token'];
        }

        if (isset($response['exp']) && (int)$response['exp'] < time()) {
            return ['success' => false, 'message' => 'ID Token has expired'];
        }

        if (isset($response['iat'])) {
            $iat = (int)$response['iat'];
            if ($iat > time() + 60 || $iat < time() - 86400) {
                return ['success' => false, 'message' => 'ID Token issued at invalid time'];
            }
        }
        
        // 🛡️ گارد حیاتی رمزنگاری: بررسی مطابقت کامل با کلاینت آیدی خود برنامه جهت جلوگیری از حملات Confused Deputy
        if ($response['aud'] !== $this->googleClientId) {
            $this->logger->critical('oauth.google.aud_mismatch_detected', [
                'expected' => $this->googleClientId,
                'received' => $response['aud']
            ]);
            return ['success' => false, 'message' => 'نقص امنیتی شناسایی شد: کلاینت آیدی نامعتبر'];
        }

        return [
            'success' => true,
            'data' => [
                'id'      => $response['sub'],
                'email'   => $response['email'] ?? null,
                'email_verified' => ($response['email_verified'] ?? false) === true,
                'name'    => $response['name'] ?? '',
                'picture' => $response['picture'] ?? null,
                'nonce'   => $response['nonce'] ?? null
            ]
        ];
    }

    private function generateUniqueUsername(string $email): string
    {
        $parts = explode('@', $email);
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $parts[0]));
        
        if (strlen($base) < 4) {
            $base .= 'u' . random_int(100, 999);
        }
        
        $username = $base;
        $counter = 1;
        
        while ($this->db->table('users')->where('username', '=', $username)->lockForUpdate()->first()) {
            $username = $base . $counter;
            $counter++;
            
            if ($counter > 50) {
                $username = $base . bin2hex(random_bytes(3));
                break;
            }
        }
        
        return $username;
    }

    /**
     * هدایت کاربر به درگاه ورود امن فیس‌بوک
     */
    public function getFacebookAuthUrl(): string
    {
        $redirectUri = "{$this->appUrl}/auth/callback/facebook";
        $state = bin2hex(random_bytes(16));
        
        // HIGH-H-15 Fix: State signing for Facebook
        $signature = hash_hmac('sha256', $state, (string)config('app.key'));

        $this->session->set(SessionKeys::OAUTH_STATE . '_facebook', [
            'token'      => $state,
            'signature'  => $signature,
            'created_at' => time(),
            'session_id' => $this->session->getId(),
            'ip'         => $this->clientIp()
        ]);

        return "https://www.facebook.com/v18.0/dialog/oauth?" . http_build_query([
            'client_id' => $this->facebookAppId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'email,public_profile',
            'response_type' => 'code'
        ]);
    }

    /**
     * پردازش درخواست بازگشت فیس‌بوک و احراز اصالت کاربر
     */
    public function handleFacebookCallback(string $code, string $state): array
    {
        $stateKey = SessionKeys::OAUTH_STATE . '_facebook';
        if (!$this->session->has($stateKey)) {
            return ['success' => false, 'message' => 'Invalid request: session state missing.'];
        }

        $stored = $this->session->get($stateKey);
        $this->session->remove($stateKey);

        if (!is_array($stored) || !isset($stored['token']) || !isset($stored['created_at'])) {
            return ['success' => false, 'message' => 'Invalid state structure.'];
        }

        if ($stored['token'] !== $state) {
            return ['success' => false, 'message' => 'Invalid state token match failed.'];
        }

        // HIGH-H-15 Fix: Verify state signature for Facebook
        $expectedSignature = hash_hmac('sha256', $state, (string)config('app.key'));
        if (!hash_equals($expectedSignature, (string)($stored['signature'] ?? ''))) {
            return ['success' => false, 'message' => 'State signature verification failed.'];
        }

        // HIGH-06 Fix: Verify session binding
        if (($stored['session_id'] ?? '') !== $this->session->getId()) {
            return ['success' => false, 'message' => 'Session mismatch during OAuth flow.'];
        }

        // HIGH-H-01 Fix: Relaxed IP binding - Log as warning but don't block
        if (($stored['ip'] ?? '') !== $this->clientIp()) {
            $this->logger->warning('oauth.facebook.ip_changed_during_flow', [
                'expected' => $stored['ip'],
                'received' => $this->clientIp()
            ]);
        }

        if ((time() - (int)$stored['created_at']) > 300) {
            return ['success' => false, 'message' => 'The sign-in state has expired. Please try again.'];
        }

        try {
            $tokenResp = $this->getFacebookToken($code);
            if (!$tokenResp['success']) return $tokenResp;
            
            $accessToken = $tokenResp['access_token'];

            // 🛡️ CRITICAL SECURITY UPGRADE: اعتبارسنجی عمیق با debug_token جهت پیشگیری کامل از نشت احراز هویت و Confused Deputy Attack
            $debugResp = $this->verifyFacebookAccessToken($accessToken);
            if (!$debugResp['success']) return $debugResp;

            $userInfo = $this->getFacebookUserInfo($accessToken);
            if (!$userInfo['success']) return $userInfo;

            return $this->linkOrCreateUser('facebook', $userInfo['data']);
        } catch (\Exception $e) {
            $this->logger->error('oauth.facebook.callback_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ورود با فیس‌بوک'];
        }
    }

    private function getFacebookToken(string $code): array
    {
        $ch = curl_init('https://graph.facebook.com/v18.0/oauth/access_token');
        if ($ch === false) {
            return ['success' => false, 'message' => 'Failed to initialize curl'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $this->facebookAppId,
            'client_secret' => $this->facebookAppSecret,
            'redirect_uri' => "{$this->appUrl}/auth/callback/facebook",
            'code' => $code,
        ]));

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error('oauth.facebook.token_curl_error', ['error' => $error]);
            return ['success' => false, 'message' => 'خطا در ارتباط با سرور فیس‌بوک'];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = json_decode((string)$rawResponse, true);
        if ($httpCode !== 200 || !isset($response['access_token'])) {
            $this->logger->error('oauth.facebook.token_invalid_response', [
                'http_code' => $httpCode,
                'response'  => $response
            ]);
            return ['success' => false, 'message' => 'خطا در دریافت توکن فیس‌بوک'];
        }

        return [
            'success'      => true, 
            'access_token' => $response['access_token']
        ];
    }

    /**
     * 🛡️ گیت حیاتی امنیتی فیس‌بوک: تصدیق تعلق مستقیم توکن به شناسه اختصاصی اپلیکیشن جاری
     */
    private function verifyFacebookAccessToken(string $inputToken): array
    {
        $appAccessToken = "{$this->facebookAppId}|{$this->facebookAppSecret}";
        $url = 'https://graph.facebook.com/debug_token?' . http_build_query([
            'input_token' => $inputToken,
            'access_token' => $appAccessToken
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'message' => 'Failed to initialize curl'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error('oauth.facebook.debug_token_curl_error', ['error' => $error]);
            return ['success' => false, 'message' => 'خطا در راستی‌آزمایی توکن فیس‌بوک'];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = json_decode((string)$rawResponse, true);
        if ($httpCode !== 200 || !isset($response['data']['app_id']) || !isset($response['data']['is_valid']) || !$response['data']['is_valid']) {
            $this->logger->error('oauth.facebook.token_invalid', ['response' => $response]);
            return ['success' => false, 'message' => 'توکن فیس‌بوک نامعتبر یا منقضی شده است'];
        }

        // 🛡️ Critical Cryptographic Binding: گارد امنیتی حیاتی جهت ممانعت از تزریق توکن‌های صادر شده برای اپلیکیشن‌های متفرقه (Auth Bypass)
        if ($response['data']['app_id'] !== $this->facebookAppId) {
            $this->logger->critical('oauth.facebook.app_id_mismatch_detected', [
                'expected' => $this->facebookAppId,
                'received' => $response['data']['app_id']
            ]);
            return ['success' => false, 'message' => 'نقص امنیتی شناسایی شد: اپلیکیشن آیدی نامعتبر'];
        }

        return ['success' => true];
    }

    private function getFacebookUserInfo(string $accessToken): array
    {
        $ch = curl_init('https://graph.facebook.com/v18.0/me?' . http_build_query([
            'fields' => 'id,name,email,picture.type(large)',
            'access_token' => $accessToken
        ]));
        if ($ch === false) {
            return ['success' => false, 'message' => 'Failed to initialize curl'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error('oauth.facebook.userinfo_curl_error', ['error' => $error]);
            return ['success' => false, 'message' => 'خطا در ارتباط با فیس‌بوک'];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = json_decode((string)$rawResponse, true);
        if ($httpCode !== 200 || !isset($response['id'])) {
            $this->logger->error('oauth.facebook.userinfo_invalid_response', [
                'http_code' => $httpCode,
                'response'  => $response
            ]);
            return ['success' => false, 'message' => 'خطا در دریافت اطلاعات کاربری فیس‌بوک'];
        }

        return [
            'success' => true, 
            'data' => [
                'id'      => $response['id'],
                'email'   => $response['email'] ?? null,
                'email_verified' => isset($response['email']), // HIGH-H-12: Facebook doesn't always guarantee verified emails
                'name'    => $response['name'] ?? '',
                'picture' => $response['picture']['data']['url'] ?? null
            ]
        ];
    }

    public function getLinkedAccounts(int $userId): array
    {
        return $this->db->table('social_accounts')->where('user_id', '=', $userId)->get() ?? [];
    }

    public function getAuthUrlForLinking(string $provider, int $userId): string
    {
        // Store the fact that we are linking to an existing account
        $this->session->set(SessionKeys::OAUTH_LINKING_USER_ID, $userId);
        
        if ($provider === 'google') {
            return $this->getGoogleAuthUrl();
        } elseif ($provider === 'facebook') {
            return $this->getFacebookAuthUrl();
        }
        
        throw new \InvalidArgumentException("Unsupported provider: {$provider}");
    }

    public function linkSocialAccount(int $userId, string $provider, array $userData): array
    {
        // Check if this social account is already linked to ANOTHER user
        $existing = $this->db->table('social_accounts')
            ->where('provider', '=', $provider)
            ->where('provider_id', '=', (string)$userData['id'])
            ->first();
            
        if ($existing) {
            if ((int)$existing->user_id === $userId) {
                return ['success' => true, 'message' => 'این حساب قبلاً به اکانت شما متصل شده است.'];
            }
            return ['success' => false, 'message' => 'این حساب اجتماعی قبلاً به اکانت دیگری متصل شده است.'];
        }

        $ok = $this->db->table('social_accounts')->insert([
            'user_id'     => $userId,
            'provider'    => $provider,
            'provider_id' => (string)$userData['id'],
            'avatar'      => $userData['picture'] ?? null,
            'created_at'  => date('Y-m-d H:i:s')
        ]);

        return ['success' => $ok, 'message' => $ok ? 'حساب با موفقیت متصل شد.' : 'خطا در اتصال حساب.'];
    }

    public function unlinkSocialAccount(int $userId, string $provider): array
    {
        $ok = $this->db->table('social_accounts')
            ->where('user_id', '=', $userId)
            ->where('provider', '=', $provider)
            ->delete();
        return ['success' => $ok, 'message' => $ok ? 'اتصال حساب با موفقیت جدا شد.' : 'خطا در جدا کردن اتصال حساب.'];
    }
}

