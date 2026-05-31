<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class CreateCryptoDepositJob
{
    public function __construct(
        private \App\Models\CryptoDeposit $depositModel,
        private \App\Services\Settings\AppSettings $appSettings,
        private \App\Contracts\LoggerInterface $logger,
        private \Core\TransactionWrapper $transactionWrapper
    ) {}

    public function handle(int $userId, array $data): array
    {
        try {
            return $this->transactionWrapper->runWithRetry(function() use ($userId, $data) {
                // Pessimistic lock check on tx_hash and network to prevent race condition and cross-network bypass (C-01 & C-06, M-02)
                $existingDeposit = $this->depositModel->findByHashAndNetworkForUpdate($data['tx_hash'], $data['network']);
                if ($existingDeposit) {
                    throw new \RuntimeException('این هش تراکنش قبلاً ثبت شده است');
                }

                // دریافت آدرس کیف پول مقصد
                $walletAddress = $data['network'] === 'bnb20' 
                    ? $this->appSettings->get('site_usdt_bnb20_address')
                    : $this->appSettings->get('site_usdt_trc20_address');

                if (!$walletAddress) {
                    throw new \RuntimeException('آدرس کیف پول این شبکه تنظیم نشده است');
                }

                $data['user_id'] = $userId;
                $data['wallet_address'] = $walletAddress;
                $data['verification_status'] = 'pending';
                
                // Set auto_check_deadline (30 mins from now in default timezone)
                $minutes = (int) ($this->appSettings->get('crypto_intent_expire_minutes') ?: \App\Constants\CryptoConstants::DEFAULT_INTENT_EXPIRE_MINUTES);
                $data['auto_check_deadline'] = (new \DateTime())
                    ->modify("+{$minutes} minutes")
                    ->format('Y-m-d H:i:s');
                $data['auto_check_attempts'] = 0;
                $data['created_at'] = \date('Y-m-d H:i:s');
                $data['updated_at'] = \date('Y-m-d H:i:s');

                $deposit = $this->depositModel->create($data);

                if (!$deposit) {
                    throw new \RuntimeException('خطا در ثبت درخواست');
                }
                
                $this->logger->activity('crypto_deposit_requested', "درخواست واریز {$data['amount']} USDT ({$data['network']})", $userId, ['deposit_id' => $deposit->id] ?? []);

                return [
                    'success' => true,
                    'message' => 'درخواست واریز شما ثبت شد و در حال بررسی خودکار است',
                    'deposit_id' => $deposit->id
                ];
            });
        } catch (\Exception $e) {
            // If it's a PDOException with code 23000 (Integrity constraint violation) or duplicate entry
            if ($e instanceof \PDOException && ($e->getCode() === '23000' || \str_contains($e->getMessage(), 'Duplicate entry'))) {
                return ['success' => false, 'message' => 'این هش تراکنش در همین لحظه ثبت شد و امکان ثبت مجدد وجود ندارد.'];
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
