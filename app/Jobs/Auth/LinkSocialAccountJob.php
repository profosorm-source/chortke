<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class LinkSocialAccountJob
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
    private \App\Models\User $userModel;
    private \Core\Database $db;
    private array $oAuthConfig;
    public function __construct(
        \App\Models\User $userModel,
        \Core\Database $db,
        array $oAuthConfig = []
    ) {        $this->userModel = $userModel;
        $this->db = $db;
        $this->oAuthConfig = $oAuthConfig;

        $this->googleClientId = (string)($this->oAuthConfig['google_client_id'] ?? '');
        $this->googleClientSecret = (string)($this->oAuthConfig['google_client_secret'] ?? '');
        $this->googleRedirectUri = (string)($this->oAuthConfig['google_redirect_uri'] ?? '');
        $this->facebookClientId = (string)($this->oAuthConfig['facebook_client_id'] ?? '');
        $this->facebookClientSecret = (string)($this->oAuthConfig['facebook_client_secret'] ?? '');
        $this->facebookRedirectUri = (string)($this->oAuthConfig['facebook_redirect_uri'] ?? '');
    }

public function handle(int $userId, string $provider, array $userData): array
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
}
