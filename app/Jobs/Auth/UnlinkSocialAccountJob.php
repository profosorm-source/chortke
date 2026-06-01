<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class UnlinkSocialAccountJob
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
    private \Core\Database $db;
    private array $oAuthConfig;
    public function __construct(
        \Core\Database $db,
        array $oAuthConfig = []
    ) {        $this->db = $db;
        $this->oAuthConfig = $oAuthConfig;

        $this->googleClientId = (string)($this->oAuthConfig['google_client_id'] ?? '');
        $this->googleClientSecret = (string)($this->oAuthConfig['google_client_secret'] ?? '');
        $this->googleRedirectUri = (string)($this->oAuthConfig['google_redirect_uri'] ?? '');
        $this->facebookClientId = (string)($this->oAuthConfig['facebook_client_id'] ?? '');
        $this->facebookClientSecret = (string)($this->oAuthConfig['facebook_client_secret'] ?? '');
        $this->facebookRedirectUri = (string)($this->oAuthConfig['facebook_redirect_uri'] ?? '');
    }

public function handle(int $userId, string $provider): array
    {
        $ok = $this->db->table('social_accounts')
            ->where('user_id', '=', $userId)
            ->where('provider', '=', $provider)
            ->delete();
        return ['success' => $ok, 'message' => $ok ? 'اتصال حساب با موفقیت جدا شد.' : 'خطا در جدا کردن اتصال حساب.'];
    }
}
