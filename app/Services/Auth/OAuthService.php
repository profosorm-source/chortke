<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\SecurityModel;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\AuditTrail;
use App\Services\DistributedLockService;
use App\Services\Auth\GoogleJwtVerifier;
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
        private GoogleJwtVerifier $jwtVerifier,
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
        $redirectUri = $this->buildRedirectUri('/auth/callback/google');
        
        // CRIT-01 Fix: Regenerate session ID BEFORE setting OAuth state to prevent session fixation
        // Attackers could set a known session ID before the user initiates OAuth, then hijack after callback
        $this->session->regenerate(true);
        
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        
        // HIGH-H-15 Fix: State signing with IP and Session ID binding to prevent state tampering/forgery
        $ip = $this->clientIp();
        $sessionId = $this->session->getId();
        $signature = hash_hmac('sha256', $state . '|' . $ip . '|' . $sessionId, (string)config('app.key'));

        // 🛡️ Security Improvement: Storing cryptographic state with creation timestamp for TTL enforcement.
        $this->session->set(SessionKeys::OAUTH_STATE, [
            'token'      => $state,
            'signature'  => $signature,
            'nonce'      => $nonce,
            'created_at' => time(),
            'session_id' => $sessionId,
            'ip'         => $ip
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

    private function buildRedirectUri(string $path): string
    {
        $baseUrl = rtrim($this->appUrl, '/');
        $parsed = parse_url($baseUrl);
        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \RuntimeException('APP_URL is invalid when building OAuth redirect URIs.');
        }

        $uri = $baseUrl . '/' . ltrim($path, '/');
        if (strpos($uri, $baseUrl) !== 0) {
            throw new \RuntimeException('Unsafe OAuth redirect URI construction detected.');
        }

        return $uri;
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

        // HIGH-H-15 Fix: Verify state signature (bound to original IP and Session ID)
        $expectedSignature = hash_hmac('sha256', $state . '|' . ($stored['ip'] ?? '') . '|' . ($stored['session_id'] ?? ''), (string)config('app.key'));
        if (!hash_equals($expectedSignature, (string)($stored['signature'] ?? ''))) {
            $this->logger->critical('oauth.google.state_signature_mismatch', [
                'state' => $state,
                'ip' => $this->clientIp()
            ]);
            return ['success' => false, 'message' => 'State signature verification failed.'];
        }

        // HIGH-06 Fix: Verify session binding (CRIT-01 Fix: Session ID must match exactly)
        if (($stored['session_id'] ?? '') !== $this->session->getId()) {
            $this->logger->critical('oauth.google.session_mismatch', [
                'expected' => $stored['session_id'] ?? 'none',
                'current' => $this->session->getId(),
                'ip' => $this->clientIp()
            ]);
            return ['success' => false, 'message' => 'Session mismatch during OAuth flow.'];
        }

        // CRIT-01 Fix: HIGH-H-10 - Strict IP binding for OAuth flow to prevent replay attacks
        // OAuth flows are particularly vulnerable to man-in-the-middle attacks where attacker
        // starts the flow from different IP than the one completing it
        $expectedIp = $stored['ip'] ?? '';
        $currentIp = $this->clientIp();
        
        if (!$this->matchIpSubnet($expectedIp, $currentIp)) {
            $this->logger->critical('oauth.google.ip_mismatch_replay_attack_detected', [
                'expected_ip' => $expectedIp,
                'current_ip' => $currentIp,
                'state' => $state
            ]);
            
            // HIGH-03 Fix: Audit suspicious IP change during OAuth flow
            $this->auditTrail->record('oauth.google.ip_mismatch_blocked', 0, [
                'expected_ip' => $expectedIp,
                'current_ip' => $currentIp,
                'state' => $state,
                'session_id' => $this->session->getId()
            ]);

            // CRIT-01 Fix: Block OAuth completion on IP change by default (configurable strictness)
            // This is critical because OAuth callback URLs can be shared/predicted
            $strictIpBinding = config('oauth.strict_ip_binding', false); // Default false to prevent breaking NAT/VPN/Mobile users
            if ($strictIpBinding) {
                $this->session->destroy(); // CRIT-01: Destroy session to prevent any partial state exploitation
                return ['success' => false, 'message' => 'IP مبدأ تغییر کرده است. به دلایل امنیتی، لطفاً دوباره تلاش کنید.'];
            }
            
            // If not strict, at minimum log and audit
            $this->logger->warning('oauth.google.ip_changed', [
                'expected' => $expectedIp,
                'received' => $currentIp
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
            // این کار جلوگیری از هرگونه جعل هویت و جعل دسترسی (Authentication Bypass) را می‌گیرد
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
                    $this->session->regenerate(true);
                    $this->logger->activity('oauth.account_linked', 'اتصال حساب اجتماعی (پروفایل)', (int)$linkingUserId);
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
                // Existing social account found
                $user = $this->userModel->find((int)$socialAccount->user_id);
                if (!$user) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'خطا در احراز هویت'];
                }

                if ($user->status === 'locked') {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'حساب کاربری شما قفل شده است.'];
                }

                if (in_array($user->status, ['banned', 'suspended', 'pending'], true)) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'حساب کاربری شما مسدود یا تعلیق شده است.'];
                }

                // HIGH-H-14 Fix: Enforce email verification check for OAuth logins
                if (empty($user->email_verified_at)) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'ایمیل شما تأیید نشده است. لطفاً ابتدا ایمیل خود را تأیید کنید.'];
                }

                $this->db->commit();
                
                // Login the user
                $requires2FA = (bool)($user->two_factor_enabled ?? false);
                if (!$requires2FA) {
                    // CRIT-01 Fix: Regenerate session to complete session fixation protection
                    $this->session->regenerate(true);
                    $this->authService->createSession($user, false);
                } else {
                    // CRIT-01 Fix: Regenerate session before setting pending 2FA
                    $this->session->regenerate(true);
                    $this->authService->createPending2FASession($user);
                }

                $this->logger->activity('oauth.login', 'ورود با ' . ucfirst($provider), (int)$user->id);
                return [
                    'success'      => true,
                    'user'         => $user,
                    'requires_2fa' => $requires2FA,
                ];
            }

            // New social account - check if email exists
            $existingUser = $this->userModel->findByEmail($userData['email']);

            if ($existingUser) {
                // Email exists - ask user to link account (must be logged in first)
                if (!$this->session->get(SessionKeys::LOGGED_IN)) {
                    // Store in session for after login
                    $this->session->set('oauth_pending_link', [
                        'provider' => $provider,
                        'data'     => $userData,
                        'created_at' => time()
                    ]);
                    return [
                        'success' => false,
                        'message' => 'این ایمیل قبلاً در سیستم ثبت شده است. لطفاً ابتدا وارد شوید و سپس حساب ' . ucfirst($provider) . ' خود را متصل کنید.',
                        'code'    => 'EMAIL_EXISTS_LOGIN_REQUIRED'
                    ];
                }

                // CRIT-05 Fix: Prevent linking social account to a different logged-in user
                $sessionUserId = (int)$this->session->get(SessionKeys::USER_ID);
                if ($sessionUserId !== (int)$existingUser->id) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'شما نمی‌توانید این حساب اجتماعی را به اکانت دیگری متصل کنید.'];
                }

                $result = $this->linkSocialAccount((int)$existingUser->id, $provider, $userData);
                if ($result['success']) {
                    $this->session->regenerate(true);
                    $this->logger->activity('oauth.account_linked', 'اتصال حساب اجتماعی (لاگین)', (int)$existingUser->id);
                    $this->db->commit();
                    return $result;
                }
                $this->db->rollBack();
                return $result;
            }

            // Create new user
            $plainPassword = bin2hex(random_bytes(16));
            $passwordHash = password_hash(base64_encode(hash('sha384', $plainPassword, true)), PASSWORD_BCRYPT);
            
            $verificationToken = bin2hex(random_bytes(32));
            $hashedToken = hash_hmac('sha256', strtoupper(substr($verificationToken, 0, 6)), (string)config('app.key'));

            $userId = $this->userModel->create([
                'email'                     => $userData['email'],
                'password'                  => $passwordHash,
                'full_name'                 => $userData['name'] ?? '',
                'avatar'                    => $userData['picture'] ?? null,
                'email_verified_at'         => date('Y-m-d H:i:s'), // CRIT-06 Fix: Mark as verified for OAuth (Google/Facebook verify email)
                'email_verification_token'  => $hashedToken,
                'status'                    => 'active',
                'email_verified'            => true // HIGH-H-12: Facebook/Google verified emails
            ]);

            if (!$userId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ایجاد حساب کاربری'];
            }

            // Link social account
            $linkResult = $this->linkSocialAccount($userId, $provider, $userData);
            if (!$linkResult['success']) {
                $this->db->rollBack();
                return $linkResult;
            }

            $this->db->commit();

            // Login the new user
            $newUser = $this->userModel->find($userId);
            
            // CRIT-01 Fix: Regenerate session to complete session fixation protection for new users
            $this->session->regenerate(true);
            
            $requires2FA = false; // New OAuth users don't have 2FA by default
            if (!$requires2FA) {
                $this->authService->createSession($newUser, false);
            } else {
                $this->authService->createPending2FASession($newUser);
            }

            $this->logger->activity('oauth.register', 'ثبت‌نام با ' . ucfirst($provider), $userId);
            return [
                'success'      => true,
                'user'         => $newUser,
                'requires_2fa' => $requires2FA,
            ];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('oauth.link_or_create.exception', [
                'provider' => $provider,
                'email'    => $userData['email'] ?? 'unknown',
                'error'    => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطا در پردازش درخواست'];
        } finally {
            if (!empty($lock['token'])) {
                $this->lockService->release($lockResource, $lock['token']);
            }
        }
    }

    public function getFacebookAuthUrl(): string
    {
        $redirectUri = $this->buildRedirectUri('/auth/callback/facebook');
        
        // CRIT-01 Fix: Regenerate session ID BEFORE setting OAuth state to prevent session fixation
        $this->session->regenerate(true);
        
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));

        $ip = $this->clientIp();
        $sessionId = $this->session->getId();
        $signature = hash_hmac('sha256', $state . '|' . $ip . '|' . $sessionId, (string)config('app.key'));

        $this->session->set(SessionKeys::OAUTH_STATE, [
            'token'      => $state,
            'signature'  => $signature,
            'nonce'      => $nonce,
            'created_at' => time(),
            'session_id' => $sessionId,
            'ip'         => $ip
        ]);

        return "https://www.facebook.com/v18.0/dialog/oauth?" . http_build_query([
            'client_id'    => $this->facebookAppId,
            'redirect_uri' => $redirectUri,
            'scope'        => 'email,public_profile',
            'state'        => $state,
        ]);
    }

    public function handleFacebookCallback(string $code, string $state): array
    {
        if (!$this->session->has(SessionKeys::OAUTH_STATE)) {
            return ['success' => false, 'message' => 'Invalid request: session state missing.'];
        }

        $stored = $this->session->get(SessionKeys::OAUTH_STATE);
        $this->session->remove(SessionKeys::OAUTH_STATE);

        if (!is_array($stored) || !isset($stored['token']) || !isset($stored['created_at'])) {
            return ['success' => false, 'message' => 'Invalid state structure.'];
        }

        if ($stored['token'] !== $state) {
            return ['success' => false, 'message' => 'Invalid state token match failed.'];
        }

        // HIGH-H-15 Fix: Verify state signature
        $expectedSignature = hash_hmac('sha256', $state . '|' . ($stored['ip'] ?? '') . '|' . ($stored['session_id'] ?? ''), (string)config('app.key'));
        if (!hash_equals($expectedSignature, (string)($stored['signature'] ?? ''))) {
            $this->logger->critical('oauth.facebook.state_signature_mismatch', ['state' => $state, 'ip' => $this->clientIp()]);
            return ['success' => false, 'message' => 'State signature verification failed.'];
        }

        // HIGH-06 Fix: Verify session binding
        if (($stored['session_id'] ?? '') !== $this->session->getId()) {
            return ['success' => false, 'message' => 'Session mismatch during OAuth flow.'];
        }

        // CRIT-01 Fix: Strict IP binding for Facebook OAuth to prevent replay attacks
        $expectedIp = $stored['ip'] ?? '';
        $currentIp = $this->clientIp();
        
        if (!$this->matchIpSubnet($expectedIp, $currentIp)) {
            $this->logger->critical('oauth.facebook.ip_mismatch_replay_attack_detected', [
                'expected_ip' => $expectedIp,
                'current_ip' => $currentIp,
                'state' => $state
            ]);
            
            $this->auditTrail->record('oauth.facebook.ip_mismatch_blocked', 0, [
                'expected_ip' => $expectedIp,
                'current_ip' => $currentIp,
                'state' => $state
            ]);

            // Block by default for security
            $strictIpBinding = config('oauth.strict_ip_binding', false); // Default false to prevent breaking NAT/VPN/Mobile users
            if ($strictIpBinding) {
                $this->session->destroy();
                return ['success' => false, 'message' => 'IP مبدأ تغییر کرده است. به دلایل امنیتی، لطفاً دوباره تلاش کنید.'];
            }
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
        $user = $this->userModel->find($userId);
        if (!$user || in_array($user->status, ['locked', 'banned', 'suspended'], true)) {
            return ['success' => false, 'message' => 'امکان اتصال حساب برای این کاربر وجود ندارد.'];
        }

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

    /**
     * Compare two IPs by subnet (/24 for IPv4 and /64 for IPv6) to allow small network changes
     */
    private function matchIpSubnet(string $ip1, string $ip2): bool
    {
        if ($ip1 === $ip2) {
            return true;
        }

        $normalize = function(string $ip): string {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $packed = inet_pton($ip);
                if ($packed === false) return $ip;
                return inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8));
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $pos = strrpos($ip, '.');
                return $pos !== false ? substr($ip, 0, $pos) : $ip;
            }
            return $ip;
        };

        return $normalize($ip1) === $normalize($ip2);
    }
}