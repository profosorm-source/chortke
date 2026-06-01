<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\SecurityModel;
use App\Models\User;
use App\Services\AuthService;
use Core\Database;
use Core\SessionKeys;

class OAuthService
{
    private string $googleClientId;
    private string $googleClientSecret;
    private string $googleRedirectUri;
    private string $facebookClientId;
    private string $facebookClientSecret;
    private string $facebookRedirectUri;

    private Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private SecurityModel $model;
    private User $userModel;
    private AuthService $authService;
    private \Core\Session $session;
    private array $oAuthConfig;
    public function __construct(
        Database $db,
        \App\Contracts\LoggerInterface $logger,
        SecurityModel $model,
        User $userModel,
        AuthService $authService,
        \Core\Session $session,
        array $oAuthConfig = []
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->model = $model;
        $this->userModel = $userModel;
        $this->authService = $authService;
        $this->session = $session;
        $this->oAuthConfig = $oAuthConfig;

        $this->googleClientId = (string)($this->oAuthConfig['google_client_id'] ?? '');
        $this->googleClientSecret = (string)($this->oAuthConfig['google_client_secret'] ?? '');
        $this->googleRedirectUri = (string)($this->oAuthConfig['google_redirect_uri'] ?? '');
        $this->facebookClientId = (string)($this->oAuthConfig['facebook_client_id'] ?? '');
        $this->facebookClientSecret = (string)($this->oAuthConfig['facebook_client_secret'] ?? '');
        $this->facebookRedirectUri = (string)($this->oAuthConfig['facebook_redirect_uri'] ?? '');
    }

    public function handleGoogleCallback(string $code, string $state): array
    {
        return \Core\Container::getInstance()->make(\App\Jobs\Auth\HandleGoogleCallbackJob::class)->handle($code, $state);
    }

    public function handleFacebookCallback(string $code, string $state): array
    {
        return \Core\Container::getInstance()->make(\App\Jobs\Auth\HandleFacebookCallbackJob::class)->handle($code, $state);
    }

    public function linkSocialAccount(int $userId, string $provider, array $userData): array
    {
        return \Core\Container::getInstance()->make(\App\Jobs\Auth\LinkSocialAccountJob::class)->handle($userId, $provider, $userData);
    }

    public function linkSocialAccountSafe(int $userId, string $provider, array $userData): array
    {
        return \Core\Container::getInstance()->make(\App\Jobs\Auth\LinkSocialAccountSafeJob::class)->handle($userId, $provider, $userData);
    }

    public function unlinkSocialAccount(int $userId, string $provider): array
    {
        return \Core\Container::getInstance()->make(\App\Jobs\Auth\UnlinkSocialAccountJob::class)->handle($userId, $provider);
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
        $signature = hash_hmac('sha256', $state . '|' . $ip . '|' . $sessionId, secure_key());

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



    public function getFacebookAuthUrl(): string
    {
        $redirectUri = $this->buildRedirectUri('/auth/callback/facebook');
        
        // CRIT-01 Fix: Regenerate session ID BEFORE setting OAuth state to prevent session fixation
        $this->session->regenerate(true);
        
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));

        $ip = $this->clientIp();
        $sessionId = $this->session->getId();
        $signature = hash_hmac('sha256', $state . '|' . $ip . '|' . $sessionId, secure_key());

        $this->session->set(SessionKeys::OAUTH_STATE, [
            'token'      => $state,
            'signature'  => $signature,
            'nonce'      => $nonce,
            'created_at' => time(),
            'session_id' => $sessionId,
            'ip'         => $ip
        ]);

        return "https://www.facebook.com/v18.0/dialog/oauth?" . http_build_query([
            'client_id'    => $this->facebookClientId,
            'redirect_uri' => $redirectUri,
            'scope'        => 'email,public_profile',
            'state'        => $state,
        ]);
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

    private function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }

    private function buildRedirectUri(string $path): string
    {
        if ($path === '/auth/callback/google' && $this->googleRedirectUri !== '') {
            return $this->googleRedirectUri;
        }

        if ($path === '/auth/callback/facebook' && $this->facebookRedirectUri !== '') {
            return $this->facebookRedirectUri;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return sprintf('%s://%s%s', $scheme, $host, $path);
    }









}
