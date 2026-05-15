<?php

namespace App\Adapters;

use App\Services\SettingService;
use Core\Database;
use App\Contracts\LoggerInterface;

class CryptoApiAdapter implements CryptoVerificationAdapter
{
    private Database $db;
    private LoggerInterface $logger;
    private SettingService $settingService;
    private array $siteWallets = [];

    public function __construct(Database $db, LoggerInterface $logger, SettingService $settingService)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->settingService = $settingService;
        $this->loadSiteWallets();
    }

    /**
     * Verify a crypto transaction using API calls to blockchain explorers
     */
    public function verify(string $network, string $txHash, string $fromWallet, string $toWallet, float $expectedAmount): array
    {
        return $this->verifyTransaction($network, $txHash, $toWallet, $expectedAmount);
    }

    /**
     * Load site wallet addresses from settings
     */
    private function loadSiteWallets(): void
    {
        $this->siteWallets = [
            'BNB20' => $this->settingService->get('site_wallet_bnb20', ''),
            'TRC20' => $this->settingService->get('site_wallet_trc20', ''),
            'ERC20' => $this->settingService->get('site_wallet_erc20', ''),
            'TON'   => $this->settingService->get('site_wallet_ton', ''),
            'SOL'   => $this->settingService->get('site_wallet_sol', ''),
        ];
    }

    /**
     * Verify transaction based on network
     */
    private function verifyTransaction(string $network, string $txHash, string $toWallet, float $expectedAmount): array
    {
        switch ($network) {
            case 'TRC20':
                return $this->verifyTronTransaction($txHash, $toWallet, $expectedAmount);
            case 'BNB20':
                return $this->verifyBscTransaction($txHash, $toWallet, $expectedAmount);
            case 'ERC20':
                return $this->verifyEthereumTransaction($txHash, $toWallet, $expectedAmount);
            case 'TON':
                return $this->verifyTonTransaction($txHash, $toWallet, $expectedAmount);
            case 'SOL':
                return $this->verifySolanaTransaction($txHash, $toWallet, $expectedAmount);
            default:
                return ['status' => 'error', 'reason' => 'شبکه پشتیبانی نمی‌شود'];
        }
    }

    /**
     * Verify TRON transaction
     */
    private function verifyTronTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        try {
            $url = "https://apilist.tronscan.org/api/transaction-info?hash=" . urlencode($txHash);
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                return ['status' => 'error', 'reason' => 'خطا در اتصال به TronScan API'];
            }

            $data = json_decode($response, true);
            if (!$data || !isset($data['contractData'])) {
                return ['status' => 'error', 'reason' => 'پاسخ نامعتبر از API'];
            }

            // Issue 1: Check status and confirmations
            if (!isset($data['confirmed']) || $data['confirmed'] !== true || !isset($data['contractRet']) || $data['contractRet'] !== 'SUCCESS') {
                return ['status' => 'pending', 'reason' => 'تراکنش هنوز تایید نهایی نشده است'];
            }

            // Issue 2: Poisoning check (Fake Token Transfer)
            // USDT (TRC20) Contract: TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t
            if (!isset($data['contractData']['contract_address']) || strtolower($data['contractData']['contract_address']) !== 'tr7nhqjeqxgwgcuilmt11mxpcwjrqcqq8d') {
                return ['status' => 'mismatch', 'reason' => 'توکن ارسالی USDT نیست'];
            }

            // Check if transaction is to our wallet
            $to = $data['contractData']['to_address'] ?? '';
            if (strtolower($to) !== strtolower($toWallet)) {
                return ['status' => 'mismatch', 'reason' => 'آدرس گیرنده مطابقت ندارد'];
            }

            // Check amount (convert from 10^6 for USDT)
            $amount = isset($data['contractData']['amount']) ? $data['contractData']['amount'] / 1000000 : 0;
            if (abs($amount - $expectedAmount) > 0.01) {
                return ['status' => 'mismatch', 'reason' => 'مبلغ تراکنش مطابقت ندارد'];
            }

            return ['status' => 'verified', 'details' => $data];

        } catch (\Exception $e) {
            $this->logger->error('crypto.verify.tron.failed', [
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return ['status' => 'error', 'reason' => 'خطا در بررسی تراکنش TRON'];
        }
    }

    /**
     * Verify BSC transaction
     */
    private function verifyBscTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        try {
            $apiKey = $this->settingService->get('bscscan_api_key', '') ?: 'YourApiKeyToken';
            $url = "https://api.bscscan.com/api?module=account&action=tokentx&txhash=" . urlencode($txHash) . "&apikey=" . urlencode($apiKey);
            
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                return ['status' => 'error', 'reason' => 'خطا در اتصال به BscScan API'];
            }

            $data = json_decode($response, true);
            $tx = null;
            if (isset($data['result']) && is_array($data['result']) && count($data['result']) > 0) {
                $tx = $data['result'][0];
            }

            if (!$tx) {
                return ['status' => 'error', 'reason' => 'تراکنش یافت نشد یا توکن منتقل نشده است'];
            }

            // Issue 1: Confirmation check
            if (!isset($tx['blockNumber']) || empty($tx['blockNumber'])) {
                return ['status' => 'pending', 'reason' => 'تراکنش هنوز در بلاک قرار نگرفته است'];
            }

            // Issue 2: Poisoning check (USDT BEP20)
            if (strtolower($tx['contractAddress'] ?? '') !== '0x55d398326f99059ff775485246999027b3197955') {
                return ['status' => 'mismatch', 'reason' => 'توکن ارسالی USDT (BEP20) نیست'];
            }

            // Check receiver
            if (strtolower($tx['to'] ?? '') !== strtolower($toWallet)) {
                return ['status' => 'mismatch', 'reason' => 'آدرس گیرنده مطابقت ندارد'];
            }

            // Check amount
            $decimals = (int)($tx['tokenDecimal'] ?? 18);
            $amount = $tx['value'] / pow(10, $decimals);
            
            if (abs($amount - $expectedAmount) > 0.01) {
                return ['status' => 'mismatch', 'reason' => 'مبلغ تراکنش مطابقت ندارد'];
            }

            return ['status' => 'verified', 'details' => $tx];

        } catch (\Exception $e) {
            $this->logger->error('crypto.verify.bsc.failed', [
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return ['status' => 'error', 'reason' => 'خطا در بررسی تراکنش BSC'];
        }
    }

    /**
     * Verify Ethereum transaction
     */
    private function verifyEthereumTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        return ['status' => 'manual', 'reason' => 'Ethereum verification needs manual review'];
    }

    /**
     * Verify TON transaction
     */
    private function verifyTonTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        return ['status' => 'manual', 'reason' => 'TON verification needs manual review'];
    }

    /**
     * Verify Solana transaction
     */
    private function verifySolanaTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        return ['status' => 'manual', 'reason' => 'Solana verification needs manual review'];
    }
}

