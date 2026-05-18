<?php

namespace App\Adapters;

use App\Services\SettingService;
use Core\Database;
use App\Contracts\LoggerInterface;
use Core\Cache;

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
        $cache = Cache::getInstance();
        $cacheKey = "crypto_verify_tx:" . strtolower($network) . ":" . strtolower($txHash);
        $cached = $cache->get($cacheKey);
        if ($cached) {
            $cachedDecoded = \json_decode($cached, true);
            if (is_array($cachedDecoded)) {
                return $cachedDecoded;
            }
        }

        switch ($network) {
            case 'TRC20':
                $result = $this->verifyTronTransaction($txHash, $toWallet, $expectedAmount);
                break;
            case 'BNB20':
                $result = $this->verifyBscTransaction($txHash, $toWallet, $expectedAmount);
                break;
            case 'ERC20':
                $result = $this->verifyEthereumTransaction($txHash, $toWallet, $expectedAmount);
                break;
            case 'TON':
                $result = $this->verifyTonTransaction($txHash, $toWallet, $expectedAmount);
                break;
            case 'SOL':
                $result = $this->verifySolanaTransaction($txHash, $toWallet, $expectedAmount);
                break;
            default:
                $result = ['status' => 'error', 'reason' => 'شبکه پشتیبانی نمی‌شود'];
        }

        if (isset($result['status']) && $result['status'] === 'verified') {
            $cache->put($cacheKey, \json_encode($result), 300); // Cache verified results for 5 minutes
        }

        return $result;
    }

    /**
     * Execute a network GET request with exponential backoff and circuit breaker
     */
    private function executeWithRetry(string $url): ?string
    {
        $cache = Cache::getInstance();
        
        // Circuit Breaker check
        $disabledUntil = $cache->get('crypto_circuit_breaker_disabled_until');
        if ($disabledUntil && (int)$disabledUntil > \time()) {
            $this->logger->warning('crypto.circuit_breaker.active', ['url' => $url]);
            return null;
        }

        $attempts = 3;
        $delays = [2, 4, 8];
        $timeout = (int)$this->settingService->get('crypto_api_timeout', 15);

        for ($i = 0; $i < $attempts; $i++) {
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: ChortkeSecureApp/1.0 (+https://chortke.com)',
                'Accept: application/json'
            ]);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = \curl_error($ch);
            \curl_close($ch);

            if ($httpCode === 200 && $response) {
                // Success: reset failures count
                $cache->forget('crypto_circuit_breaker_failures');
                return $response;
            }

            $this->logger->warning('crypto.api.attempt_failed', [
                'url' => $url,
                'attempt' => $i + 1,
                'http_code' => $httpCode,
                'error' => $curlError ?: 'HTTP Status ' . $httpCode
            ]);

            if ($i < $attempts - 1) {
                \sleep($delays[$i]);
            }
        }

        // Tripped Circuit Breaker: increment failure count
        $failures = (int)$cache->get('crypto_circuit_breaker_failures', 0) + 1;
        $cache->put('crypto_circuit_breaker_failures', $failures, 10); // keep history for 10 mins
        
        if ($failures >= 5) {
            $cache->put('crypto_circuit_breaker_disabled_until', \time() + 300, 5); // disable for 5 mins
            $this->logger->error('crypto.circuit_breaker.tripped', [
                'failures' => $failures,
                'last_url' => $url
            ]);
        }

        return null;
    }

    /**
     * Normalize Tron/BSC addresses to lower-case / hexadecimal representation for safe matches
     */
    private function normalizeAddress(string $address, string $network): string
    {
        $address = trim($address);
        if (strtolower($network) === 'tron') {
            $base58Contract = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
            $hexContract = '41a614f803b6c4804147c4e8e89f8113730e11a252';
            $addrLower = strtolower($address);
            if ($addrLower === strtolower($base58Contract) || $addrLower === strtolower($hexContract) || $addrLower === 'tr7nhqjekqxgwtci8q8zy4pl8otszgjlj6t') {
                return 'tr7nhqjekqxgwtci8q8zy4pl8otszgjlj6t';
            }
        }
        return strtolower($address);
    }

    /**
     * Verify TRON transaction
     */
    private function verifyTronTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        try {
            $url = "https://apilist.tronscan.org/api/transaction-info?hash=" . urlencode($txHash);
            $response = $this->executeWithRetry($url);

            if (!$response) {
                return ['status' => 'error', 'reason' => 'خطا در اتصال به TronScan API یا فعال بودن مدار قطع‌کننده (Circuit Breaker)'];
            }

            $data = json_decode($response, true);
            if (!$data || !isset($data['contractData'])) {
                return ['status' => 'error', 'reason' => 'پاسخ نامعتبر از API'];
            }

            // Issue 1: Check status and confirmations
            if (!isset($data['confirmed']) || $data['confirmed'] !== true || !isset($data['contractRet']) || $data['contractRet'] !== 'SUCCESS') {
                return ['status' => 'pending', 'reason' => 'تراکنش هنوز تایید نهایی نشده است'];
            }

            // Get dynamic block confirmations count (C-05)
            $currentBlockUrl = "https://apilist.tronscan.org/api/system/status";
            $blockResponse = $this->executeWithRetry($currentBlockUrl);
            $currentBlock = 0;
            if ($blockResponse) {
                $blockData = json_decode($blockResponse, true);
                $currentBlock = (int)($blockData['database']['block'] ?? 0);
            }

            $txBlock = (int)($data['block'] ?? 0);
            $confirmations = ($currentBlock > 0 && $txBlock > 0) ? ($currentBlock - $txBlock) : (isset($data['confirmations']) ? (int)$data['confirmations'] : 0);
            $minConfirmations = (int) $this->settingService->get('crypto_min_confirmations_trc20', 19);

            if ($confirmations < $minConfirmations) {
                return ['status' => 'pending', 'reason' => "تعداد تاییدهای تراکنش TRON کافی نیست (نیاز به حداقل $minConfirmations تایید دارد، فعلی: $confirmations)"];
            }

            // Issue 2: Poisoning check (Fake Token Transfer) with config support and normalization
            $validContract = $this->settingService->get('crypto_contract_trc20_usdt', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
            $receivedContract = $data['contractData']['contract_address'] ?? '';

            if ($this->normalizeAddress($receivedContract, 'tron') !== $this->normalizeAddress($validContract, 'tron')) {
                return ['status' => 'mismatch', 'reason' => 'توکن ارسالی USDT نیست'];
            }

            // Check if transaction is to our wallet
            $to = $data['contractData']['to_address'] ?? '';
            if (strtolower($to) !== strtolower($toWallet)) {
                return ['status' => 'mismatch', 'reason' => 'آدرس گیرنده مطابقت ندارد'];
            }

            // Check amount using integer comparisons to avoid float precision bugs (H-02)
            $amountRaw = isset($data['contractData']['amount']) ? (int)$data['contractData']['amount'] : 0;
            $expectedRaw = (int)round($expectedAmount * 1000000);
            $toleranceRaw = 10000; // 0.01 USDT tolerance in SUN units

            if (abs($amountRaw - $expectedRaw) > $toleranceRaw) {
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
            
            $response = $this->executeWithRetry($url);

            if (!$response) {
                return ['status' => 'error', 'reason' => 'خطا در اتصال به BscScan API یا فعال بودن مدار قطع‌کننده (Circuit Breaker)'];
            }

            $data = json_decode($response, true);
            $tx = null;
            if (isset($data['result']) && is_array($data['result']) && count($data['result']) > 0) {
                $tx = $data['result'][0];
            }

            if (!$tx) {
                return ['status' => 'error', 'reason' => 'تراکنش یافت نشد یا توکن منتقل نشده است'];
            }

            // Issue 1: Confirmation check (C-05)
            if (!isset($tx['blockNumber']) || empty($tx['blockNumber'])) {
                return ['status' => 'pending', 'reason' => 'تراکنش هنوز در بلاک قرار نگرفته است'];
            }

            $confirmations = isset($tx['confirmations']) ? (int)$tx['confirmations'] : 0;
            $minConfirmations = (int) $this->settingService->get('crypto_min_confirmations_bnb20', 15);
            if ($confirmations < $minConfirmations) {
                return ['status' => 'pending', 'reason' => "تعداد تاییدهای تراکنش BSC کافی نیست (حداقل $minConfirmations تایید نیاز است، فعلی: $confirmations)"];
            }

            // Issue 2: Poisoning check (USDT BEP20) from Settings/Config
            $validContract = $this->settingService->get('crypto_contract_bnb20_usdt', '0x55d398326f99059ff775485246999027b3197955');
            if (strtolower($tx['contractAddress'] ?? '') !== strtolower($validContract)) {
                return ['status' => 'mismatch', 'reason' => 'توکن ارسالی USDT (BEP20) نیست'];
            }

            // Check receiver
            if (strtolower($tx['to'] ?? '') !== strtolower($toWallet)) {
                return ['status' => 'mismatch', 'reason' => 'آدرس گیرنده مطابقت ندارد'];
            }

            // Check amount using integer raw comparisons (H-02)
            $decimals = (int)($tx['tokenDecimal'] ?? 18);
            $amountRaw = isset($tx['value']) ? (float)$tx['value'] : 0.0;
            $expectedRaw = $expectedAmount * pow(10, $decimals);
            $toleranceRaw = 0.01 * pow(10, $decimals);

            if (abs($amountRaw - $expectedRaw) > $toleranceRaw) {
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
