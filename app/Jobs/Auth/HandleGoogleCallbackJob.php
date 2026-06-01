<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class HandleGoogleCallbackJob
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

            // HIGH-H-15 Fix: Verify state signature (bound to original IP and Session ID)
            $expectedSignature = hash_hmac('sha256', $state . '|' . ($stored['ip'] ?? '') . '|' . ($stored['session_id'] ?? ''), secure_key());
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

                // CRIT-01 Fix: Block OAuth completion on IP change when strict binding is enabled.
                // Default false to prevent breaking NAT/VPN/Mobile users.
                $strictIpBinding = config('oauth.strict_ip_binding', false) || feature_enabled('oauth_strict_ip_binding');
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
        } finally {
            $this->session->remove(SessionKeys::OAUTH_STATE);
            $this->session->remove(SessionKeys::OAUTH_STATE . '_used');
        }
    }
}
