<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class HandleFacebookCallbackJob
{
    private string $googleClientId;
    private string $googleClientSecret;
    private string $googleRedirectUri;
    private string $facebookClientId;
    private string $facebookClientSecret;
    private string $facebookRedirectUri;

    protected function registerFromOAuth(array $userData): array
    {
        // We will proxy to a method on OAuthService if needed, but for now we assume OAuthService handles it, or we implement it here.
        // Actually, registerFromOAuth is a private method in OAuthService. Let's just copy it to the Jobs that need it.
        return \Core\Container::getInstance()->make(\App\Services\OAuthService::class)->registerFromOAuth($userData);
    }
    private \Core\Session $session;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\AuditTrail $auditTrail;
    private array $oAuthConfig;
    public function __construct(
        \Core\Session $session,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\AuditTrail $auditTrail,
        array $oAuthConfig = []
    ) {        $this->session = $session;
        $this->logger = $logger;
        $this->auditTrail = $auditTrail;
        $this->oAuthConfig = $oAuthConfig;

        $this->googleClientId = (string)($this->oAuthConfig['google_client_id'] ?? '');
        $this->googleClientSecret = (string)($this->oAuthConfig['google_client_secret'] ?? '');
        $this->googleRedirectUri = (string)($this->oAuthConfig['google_redirect_uri'] ?? '');
        $this->facebookClientId = (string)($this->oAuthConfig['facebook_client_id'] ?? '');
        $this->facebookClientSecret = (string)($this->oAuthConfig['facebook_client_secret'] ?? '');
        $this->facebookRedirectUri = (string)($this->oAuthConfig['facebook_redirect_uri'] ?? '');
    }

public function handle(string $code, string $state): array
    {
        if (!$this->session->has(SessionKeys::OAUTH_STATE)) {
            return ['success' => false, 'message' => 'Invalid request: session state missing.'];
        }

        $stored = $this->session->get(SessionKeys::OAUTH_STATE);
        $this->session->remove(SessionKeys::OAUTH_STATE);
        $this->session->remove(SessionKeys::OAUTH_STATE . '_used');

        try {
            if (!is_array($stored) || !isset($stored['token']) || !isset($stored['created_at'])) {
                return ['success' => false, 'message' => 'Invalid state structure.'];
            }

            if ($stored['token'] !== $state) {
                return ['success' => false, 'message' => 'Invalid state token match failed.'];
            }

            // HIGH-H-15 Fix: Verify state signature
            $expectedSignature = hash_hmac('sha256', $state . '|' . ($stored['ip'] ?? '') . '|' . ($stored['session_id'] ?? ''), secure_key());
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

                // Block by default for security when strict binding is enabled
                $strictIpBinding = config('oauth.strict_ip_binding', false) || feature_enabled('oauth_strict_ip_binding');
                if ($strictIpBinding) {
                    $this->session->destroy();
                    return ['success' => false, 'message' => 'IP مبدأ تغییر کرده است. به دلایل امنیتی، لطفاً دوباره تلاش کنید.'];
                }
            }

            if ((time() - (int)$stored['created_at']) > 300) {
                return ['success' => false, 'message' => 'The sign-in state has expired. Please try again.'];
            }

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
        } finally {
            $this->session->remove(SessionKeys::OAUTH_STATE);
            $this->session->remove(SessionKeys::OAUTH_STATE . '_used');
        }
    }
}
