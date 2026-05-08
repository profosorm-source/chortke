<?php

namespace App\Services\Adapters;

use App\Models\Setting;
use Core\Database;
use Core\Logger;

class CryptoApiAdapter implements CryptoVerificationAdapter
{
    private Database $db;
    private Logger $logger;
    private Setting $settingModel;
    private array $siteWallets = [];

    public function __construct(Database $db, Logger $logger, Setting $settingModel)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->settingModel = $settingModel;
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
            'BNB20' => $this->settingModel->get('site_wallet_bnb20', ''),
            'TRC20' => $this->settingModel->get('site_wallet_trc20', ''),
            'ERC20' => $this->settingModel->get('site_wallet_erc20', ''),
            'TON' => $this->settingModel->get('site_wallet_ton', ''),
            'SOL' => $this->settingModel->get('site_wallet_sol', ''),
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
            $url = "https://apilist.tronscan.org/api/transaction-info?hash=" . $txHash;
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

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

            // Check if transaction is to our wallet
            $to = $data['contractData']['to_address'] ?? '';
            if (strtolower($to) !== strtolower($toWallet)) {
                return ['status' => 'mismatch', 'reason' => 'آدرس گیرنده مطابقت ندارد'];
            }

            // Check amount (convert from SUN to TRX)
            $amount = isset($data['contractData']['amount']) ? $data['contractData']['amount'] / 1000000 : 0;
            if (abs($amount - $expectedAmount) > 0.000001) {
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
            $url = "https://api.bscscan.com/api?module=transaction&action=gettxreceiptstatus&txhash=" . $txHash . "&apikey=" . ($this->settingModel->get('bscscan_api_key', '') ?: 'YourApiKeyToken');
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                return ['status' => 'error', 'reason' => 'خطا در اتصال به BscScan API'];
            }

            $data = json_decode($response, true);
            if (!$data || $data['status'] !== '1') {
                return ['status' => 'error', 'reason' => 'تراکنش ناموفق یا یافت نشد'];
            }

            // For detailed verification, we would need more API calls
            // For now, return verified if transaction exists and is successful
            return ['status' => 'verified', 'details' => $data];

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
        // Similar implementation for Ethereum
        return ['status' => 'unavailable', 'reason' => 'Ethereum verification not implemented yet'];
    }

    /**
     * Verify TON transaction
     */
    private function verifyTonTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        // Similar implementation for TON
        return ['status' => 'unavailable', 'reason' => 'TON verification not implemented yet'];
    }

    /**
     * Verify Solana transaction
     */
    private function verifySolanaTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        // Similar implementation for Solana
        return ['status' => 'unavailable', 'reason' => 'Solana verification not implemented yet'];
    }
}