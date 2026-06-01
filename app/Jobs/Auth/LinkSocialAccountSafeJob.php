<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class LinkSocialAccountSafeJob
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
    private \App\Contracts\LoggerInterface $logger;
    private array $oAuthConfig;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        array $oAuthConfig = []
    ) {        $this->db = $db;
        $this->logger = $logger;
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
        $this->db->beginTransaction();
        try {
            $result = $this->linkSocialAccount($userId, $provider, $userData);
            if ($result['success']) {
                $this->db->commit();
            } else {
                $this->db->rollBack();
            }
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('oauth.linkSocialAccountSafe_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی در اتصال حساب'];
        }
    }
}
