<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class CreateCryptoDepositIntentJob
{
    public function __construct(
        private \App\Contracts\LoggerInterface $logger,
        private \App\Services\Settings\AppSettings $appSettings,
        private \App\Models\CryptoDepositIntent $intentModel
    ) {}

    public function handle(
        int $userId,
        string $network,
        float $requestedAmount,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $network = strtoupper(trim($network));
        $intentValidation = $this->validateCryptoIntentInput($userId, $network, $requestedAmount);
        if ($intentValidation !== null) {
            return $intentValidation;
        }

        // LOW-09: Defensively sanitize raw user-supplied IP addresses to safeguard system telemetry
        $cleanIp = null;
        if ($ipAddress !== null) {
            $cleanIp = \filter_var($ipAddress, \FILTER_VALIDATE_IP) ?: null;
        }

        $this->logger->info('crypto.intent.create.started', [
            'user_id' => $userId,
            'network' => $network,
            'requested_amount' => $requestedAmount
        ]);

        // 🛡️ گیت ضدتقلب تراکنش کریپتو (Velocity check & Global policies)
        $risk = $this->fraudGuard->checkAction($userId, 'crypto.deposit', [
            'amount'      => $requestedAmount,
            'currency'    => 'usdt',
            'network'     => $network,
            'ip'          => $cleanIp,
            'user_agent'  => $userAgent
        ]);

        if (!$risk['allowed']) {
            $this->logger->warning('crypto.intent_blocked_by_fraud_guard', [
                'user_id' => $userId,
                'amount'  => $requestedAmount,
                'reason'  => $risk['reason']
            ]);
            return ['success' => false, 'message' => 'امکان ثبت درخواست شارژ رمزارز به دلایل امنیتی مسدود شد. دلیل: ' . ($risk['reason'] === 'velocity_limit' ? 'تجاوز از سقف مجاز واریز کریپتو' : $risk['reason'])];
        }

        $expireMinutes = (int) $this->appSettings->get('crypto_intent_expire_minutes', \App\Constants\CryptoConstants::DEFAULT_INTENT_EXPIRE_MINUTES);

        $open = $this->intentModel->getOpenIntentForUser($userId);
        if ($open && \strtotime($open->expires_at) < \time()) {
            // Auto-expire it right here to avoid blocking new intent creations (H-01)
            $this->intentModel->expireIfPassed((int)$open->id);
            $open = null;
        }

        if ($open) {
            $this->logger->info('crypto.intent.existing', [
                'user_id' => $userId,
                'intent_id' => $open->id ?? null
            ]);
            return [
                'success' => true,
                'message' => 'شما یک درخواست فعال دارید',
                'intent' => $open,
            ];
        }

        $toWallet = ($network === 'bnb20' ? $this->appSettings->get('site_usdt_bnb20_address') : $this->appSettings->get('site_usdt_trc20_address'));
        if (!$toWallet) {
            $this->logger->error('crypto.intent.no_wallet', [
                'user_id' => $userId,
                'network' => $network
            ]);
            return ['success' => false, 'message' => 'ولت این شبکه تنظیم نشده است'];
        }

        // H-01: Auto-cleanup expired intents before creating a new one to prevent memory leak / stale claims
        try {
            $this->cleanupExpiredIntents();
        } catch (\Throwable $cleanupErr) {
            $this->logger->warning('crypto.intent.cleanup.failed', ['error' => $cleanupErr->getMessage()]);
        }

        $maxRetryIntents = 5;
        $attempt = 0;
        $id = null;
        $expected = null;
        $expiresAt = null;

        try {
            while ($attempt < $maxRetryIntents) {

                try {
                    // Generate a unique expected amount candidate
                    $expected = \Core\Container::getInstance()->make(\App\Services\CryptoDeposit\CryptoDepositService::class)->generateUniqueAmount($network, $requestedAmount);
                    $expiresAt = \date('Y-m-d H:i:s', \time() + ($expireMinutes * 60));

                    $id = $this->intentModel->create([
                        'user_id' => $userId,
                        'network' => $network,
                        'requested_amount' => $requestedAmount,
                        'expected_amount' => $expected,
                        'to_wallet' => $toWallet,
                        'expires_at' => $expiresAt,
                        'status' => 'open',
                        'ip_address' => $cleanIp,
                        'user_agent' => $userAgent,
                        'created_at' => \date('Y-m-d H:i:s'),
                        'updated_at' => \date('Y-m-d H:i:s'),
                    ]);

                    break; // Success! Exit retry loop
                } catch (\Exception $e) {

                    // If it is a duplicate entry exception (23000), let's retry
                    if ($e instanceof \PDOException && ($e->getCode() === '23000' || \str_contains($e->getMessage(), 'Duplicate entry'))) {
                        $attempt++;
                        if ($attempt >= $maxRetryIntents) {
                            throw new \RuntimeException("امکان تولید درخواست واریز منحصر به فرد به دلیل ترافیک بالا در این لحظه وجود ندارد. لطفا مجددا تلاش کنید.");
                        }
                        continue; // Retry with next attempt
                    }
                    throw $e; // Rethrow other exceptions
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('crypto.intent.create.failed', [
                'channel' => 'crypto',
                'user_id' => $userId,
                'network' => $network,
                'requested_amount' => $requestedAmount,
                'error' => $e->getMessage(),
                'exception' => \get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['success' => false, 'message' => ($e instanceof \RuntimeException) ? $e->getMessage() : 'خطای سیستمی در ساخت درخواست'];
        }

        $this->logger->info('crypto.intent.created', [
            'user_id' => $userId,
            'intent_id' => $id,
            'network' => $network,
            'requested_amount' => $requestedAmount,
            'expected_amount' => $expected,
            'expires_at' => $expiresAt
        ]);

        return [
            'success' => true,
            'message' => 'Intent ساخته شد',
            'intent_id' => (int) $id,
            'network' => $network,
            'requested_amount' => $requestedAmount,
            'expected_amount' => $expected,
            'to_wallet' => $toWallet,
            'expires_at' => $expiresAt,
        ];
    }
}
