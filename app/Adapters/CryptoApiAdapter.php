<?php

namespace App\Adapters;

use App\Services\Settings\AppSettings;
use Core\Database;
use App\Contracts\LoggerInterface;
use Core\Cache;
use Core\CircuitBreaker;
use App\Traits\ExternalCallTrait;

class CryptoApiAdapter implements CryptoVerificationAdapter
{
    use ExternalCallTrait;

    private Database $db;
    private LoggerInterface $logger;
    private AppSettings $appSettings;
    private array $siteWallets = [];

    private CircuitBreaker $circuit;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        AppSettings $appSettings,
        CircuitBreaker $circuit
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->appSettings = $appSettings;
        $this->circuit = $circuit;
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
            'BNB20' => $this->appSettings->get('site_wallet_bnb20', ''),
            'TRC20' => $this->appSettings->get('site_wallet_trc20', ''),
            'ERC20' => $this->appSettings->get('site_wallet_erc20', ''),
            'TON'   => $this->appSettings->get('site_wallet_ton', ''),
            'SOL'   => $this->appSettings->get('site_wallet_sol', ''),
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
     * Section 8.3/8.4 — single source of truth for circuit-breaker + retry +
     * failure classification via App\Traits\ExternalCallTrait.
     * Supports multiple URLs for Provider Chain / Fallback Strategy.
     *
     * Returns the response body on success, or null on (Permanent/CB-open) across all URLs.
     */
    private function executeWithRetry(array $urls): ?string
    {
        $timeout = (int)$this->appSettings->get('crypto_api_timeout', 15);
        $lastError = null;

        foreach ($urls as $index => $url) {
            try {
                // Use a separate breaker for each endpoint to isolate failures
                $breakerName = 'crypto_api_' . parse_url($url, PHP_URL_HOST);
                
                $response = $this->callWithBreaker($breakerName, function () use ($url, $timeout): ?string {
                    return $this->retryTransient(function () use ($url, $timeout): string {
                        $ch = \curl_init($url);
                        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(8, max(2, (int)floor($timeout / 2))));
                        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                        \curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
                            'User-Agent: ChortkeSecureApp/1.0 (+https://chortke.com)',
                            'Accept: application/json',
                        ], trace_headers()));
                        $response = \curl_exec($ch);
                        $httpCode = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $errno    = (int) \curl_errno($ch);
                        $error    = \curl_error($ch);
                        \curl_close($ch);

                        if ($httpCode === 200 && is_string($response) && $response !== '') {
                            return $response;
                        }

                        $this->logger->warning('crypto.api.attempt_failed', [
                            'url'       => $url,
                            'http_code' => $httpCode,
                            'errno'     => $errno,
                            'error'     => $error ?: 'HTTP Status ' . $httpCode,
                        ]);
                        throw $this->classifyHttpFailure($httpCode, $errno, (string)$response, ['provider' => 'crypto_api']);
                    }, 3, 500, 4000);
                });

                if ($response !== null) {
                    return $response;
                }
            } catch (\Core\Exceptions\PermanentFailure $e) {
                $lastError = $e->getMessage();
                $this->logger->warning('crypto.api.permanent_failure', ['url' => $url, 'error' => $lastError]);
                // If permanent failure (e.g., 400 Bad Request), it's usually bad input, not a node issue.
                // However, we still try the next node just in case it's a proxy error.
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->logger->error('crypto.api.unavailable', [
                    'url'   => $url,
                    'class' => get_class($e),
                    'error' => $lastError,
                ]);
            }
        }
        
        $this->logger->critical('crypto.api.all_nodes_failed', [
            'urls' => $urls,
            'last_error' => $lastError
        ]);
        
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
            $urls = [
                "https://apilist.tronscan.org/api/transaction-info?hash=" . urlencode($txHash),
                "https://api.trongrid.io/v1/transactions/" . urlencode($txHash) . "/events", // Fallback
            ];
            $response = $this->executeWithRetry($urls);

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
            $currentBlockUrls = [
                "https://apilist.tronscan.org/api/system/status",
                "https://api.trongrid.io/wallet/getnowblock" // Fallback
            ];
            $blockResponse = $this->executeWithRetry($currentBlockUrls);
            $currentBlock = 0;
            if ($blockResponse) {
                $blockData = json_decode($blockResponse, true);
                $currentBlock = (int)($blockData['database']['block'] ?? 0);
            }

            $txBlock = (int)($data['block'] ?? 0);
            $confirmations = ($currentBlock > 0 && $txBlock > 0) ? ($currentBlock - $txBlock) : (isset($data['confirmations']) ? (int)$data['confirmations'] : 0);
            $minConfirmations = (int) $this->appSettings->get('crypto_min_confirmations_trc20', 19);

            if ($confirmations < $minConfirmations) {
                return ['status' => 'pending', 'reason' => "تعداد تاییدهای تراکنش TRON کافی نیست (نیاز به حداقل $minConfirmations تایید دارد، فعلی: $confirmations)"];
            }

            // Issue 2: Poisoning check (Fake Token Transfer) with config support and normalization
            $validContract = $this->appSettings->get('crypto_contract_trc20_usdt', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
            $receivedContract = $data['contractData']['contract_address'] ?? '';

            if ($this->normalizeAddress($receivedContract, 'tron') !== $this->normalizeAddress($validContract, 'tron')) {
                return ['status' => 'mismatch', 'reason' => 'توکن ارسالی USDT نیست'];
            }

            // Check if transaction is to our wallet
            $to = $data['contractData']['to_address'] ?? '';
            if ($this->normalizeAddress($to, 'tron') !== $this->normalizeAddress($toWallet, 'tron')) {
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
            $apiKey = $this->appSettings->get('bscscan_api_key', '');
            if (!$apiKey) {
                return ['status' => 'error', 'reason' => 'BscScan API key not configured'];
            }
            $urls = [
                "https://api.bscscan.com/api?module=account&action=tokentx&txhash=" . urlencode($txHash) . "&apikey=" . urlencode($apiKey),
                "https://api-testnet.bscscan.com/api?module=account&action=tokentx&txhash=" . urlencode($txHash) . "&apikey=" . urlencode($apiKey), // Fallback
            ];
            
            $response = $this->executeWithRetry($urls);

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
            $minConfirmations = (int) $this->appSettings->get('crypto_min_confirmations_bnb20', \App\Constants\CryptoConstants::DEFAULT_MIN_CONFIRMATIONS_BNB20);
            if ($confirmations < $minConfirmations) {
                return ['status' => 'pending', 'reason' => "تعداد تاییدهای تراکنش BSC کافی نیست (حداقل $minConfirmations تایید نیاز است، فعلی: $confirmations)"];
            }

            // Issue 2: Poisoning check (USDT BEP20) from Settings/Config
            $validContract = $this->appSettings->get('crypto_contract_bnb20_usdt', '0x55d398326f99059ff775485246999027b3197955');
            if ($this->normalizeAddress($tx['contractAddress'] ?? '', 'bsc') !== $this->normalizeAddress($validContract, 'bsc')) {
                return ['status' => 'mismatch', 'reason' => 'توکن ارسالی USDT (BEP20) نیست'];
            }

            // Check receiver
            if ($this->normalizeAddress($tx['to'] ?? '', 'bsc') !== $this->normalizeAddress($toWallet, 'bsc')) {
                return ['status' => 'mismatch', 'reason' => 'آدرس گیرنده مطابقت ندارد'];
            }

            // Check amount using integer raw comparisons (H-02, M-05)
            $decimals = (int)($tx['tokenDecimal'] ?? 18);
            $amountRaw = $tx['value'] ?? '0';

            // Convert expected amount to raw token units using BCMath to avoid float issues
            $expectedRaw = bcmul((string)$expectedAmount, bcpow('10', $decimals), 0);
            $toleranceRaw = bcmul('0.01', bcpow('10', $decimals), 0);

            $diff = bcsub((string)$amountRaw, (string)$expectedRaw, 0);
            if (str_starts_with($diff, '-')) {
                $diff = substr($diff, 1);
            }

            if (bccomp($diff, $toleranceRaw, 0) === 1) {
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
     * Verify Ethereum transaction (M-03, M-04, M-05)
     */
    private function verifyEthereumTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        try {
            $apiKey = $this->appSettings->get('etherscan_api_key', '');
            if (!$apiKey) {
                return ['status' => 'error', 'reason' => 'Etherscan API key not configured'];
            }
            $urls = [
                "https://api.etherscan.io/api?module=account&action=tokentx&txhash=" . urlencode($txHash) . "&apikey=" . urlencode($apiKey),
                "https://api-sepolia.etherscan.io/api?module=account&action=tokentx&txhash=" . urlencode($txHash) . "&apikey=" . urlencode($apiKey), // Fallback
            ];
            $response = $this->executeWithRetry($urls);
            if (!$response) {
                return ['status' => 'error', 'reason' => 'خطا در اتصال به Etherscan API یا فعال بودن مدار قطع‌کننده'];
            }

            $data = json_decode($response, true);
            $tx = null;
            if (isset($data['result']) && is_array($data['result']) && count($data['result']) > 0) {
                $tx = $data['result'][0];
            }

            if (!$tx) {
                return ['status' => 'error', 'reason' => 'تراکنش یافت نشد یا توکن منتقل نشده است'];
            }

            if (!isset($tx['blockNumber']) || empty($tx['blockNumber'])) {
                return ['status' => 'pending', 'reason' => 'تراکنش هنوز در بلاک قرار نگرفته است'];
            }

            $confirmations = isset($tx['confirmations']) ? (int)$tx['confirmations'] : 0;
            $minConfirmations = (int) $this->appSettings->get('crypto_min_confirmations_erc20', \App\Constants\CryptoConstants::DEFAULT_MIN_CONFIRMATIONS_ERC20);
            if ($confirmations < $minConfirmations) {
                return ['status' => 'pending', 'reason' => "تعداد تاییدهای تراکنش Ethereum کافی نیست (حداقل $minConfirmations تایید نیاز است، فعلی: $confirmations)"];
            }

            $validContract = $this->appSettings->get('crypto_contract_erc20_usdt', '0xdac17f958d2ee523a2206206994597c13d831ec7');
            if ($this->normalizeAddress($tx['contractAddress'] ?? '', 'ethereum') !== $this->normalizeAddress($validContract, 'ethereum')) {
                return ['status' => 'mismatch', 'reason' => 'توکن ارسالی USDT (ERC20) نیست'];
            }

            if ($this->normalizeAddress($tx['to'] ?? '', 'ethereum') !== $this->normalizeAddress($toWallet, 'ethereum')) {
                return ['status' => 'mismatch', 'reason' => 'آدرس گیرنده مطابقت ندارد'];
            }

            $decimals = (int)($tx['tokenDecimal'] ?? 6);
            $amountRaw = $tx['value'] ?? '0';

            // Convert expected amount to raw token units using BCMath
            $expectedRaw = bcmul((string)$expectedAmount, bcpow('10', $decimals), 0);
            $toleranceRaw = bcmul('0.01', bcpow('10', $decimals), 0);

            $diff = bcsub((string)$amountRaw, (string)$expectedRaw, 0);
            if (str_starts_with($diff, '-')) {
                $diff = substr($diff, 1);
            }

            if (bccomp($diff, $toleranceRaw, 0) === 1) {
                return ['status' => 'mismatch', 'reason' => 'مبلغ تراکنش مطابقت ندارد'];
            }

            return ['status' => 'verified', 'details' => $tx];
        } catch (\Exception $e) {
            $this->logger->error('crypto.verify.ethereum.failed', [
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return ['status' => 'error', 'reason' => 'خطا در بررسی تراکنش Ethereum'];
        }
    }

    /**
     * Verify TON transaction (M-03)
     */
    private function verifyTonTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        try {
            $apiKey = $this->appSettings->get('toncenter_api_key', '');
            $baseUrl = "https://toncenter.com/api/v2/getTransactions?address=" . urlencode($toWallet) . "&limit=20&archival=true";
            if ($apiKey) {
                $baseUrl .= "&api_key=" . urlencode($apiKey);
            }
            $urls = [
                $baseUrl,
                // Fallback example (using testnet or another provider if configured)
                str_replace("toncenter.com", "testnet.toncenter.com", $baseUrl)
            ];
            $response = $this->executeWithRetry($urls);
            if (!$response) {
                return ['status' => 'error', 'reason' => 'خطا در اتصال به Toncenter API'];
            }

            $data = json_decode($response, true);
            if (!isset($data['ok']) || $data['ok'] !== true || !isset($data['result'])) {
                return ['status' => 'error', 'reason' => 'پاسخ نامعتبر از API ترون'];
            }

            $foundTx = null;
            foreach ($data['result'] as $tx) {
                $hash = $tx['transaction_id']['hash'] ?? '';
                if (strtolower($hash) === strtolower($txHash) || (is_string($hash) && @base64_encode(hex2bin($hash)) === $txHash)) {
                    $foundTx = $tx;
                    break;
                }
            }

            if (!$foundTx) {
                return ['status' => 'pending', 'reason' => 'تراکنش یافت نشد یا هنوز تأیید نشده است'];
            }

            $inMsg = $foundTx['in_msg'] ?? [];
            $value = $inMsg['value'] ?? '0';
            
            $expectedRaw = \Core\ValueObjects\Money::fromString((string)((string)$expectedAmount))->multiply((string)('1000000'))->getAmount();
            $toleranceRaw = \Core\ValueObjects\Money::fromString((string)('0.01'))->multiply((string)('1000000'))->getAmount();
            
            $diff = \Core\ValueObjects\Money::fromString((string)($value))->subtract(\Core\ValueObjects\Money::fromString((string)($expectedRaw)))->getAmount();
            if (str_starts_with($diff, '-')) {
                $diff = substr($diff, 1);
            }
            if (\Core\ValueObjects\Money::fromString((string)($diff))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($toleranceRaw)))) {
                return ['status' => 'mismatch', 'reason' => 'مبلغ تراکنش مطابقت ندارد'];
            }

            return ['status' => 'verified', 'details' => $foundTx];
        } catch (\Exception $e) {
            $this->logger->error('crypto.verify.ton.failed', [
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return ['status' => 'error', 'reason' => 'خطا در بررسی تراکنش TON'];
        }
    }

    /**
     * Verify Solana transaction (M-03)
     */
    private function verifySolanaTransaction(string $txHash, string $toWallet, float $expectedAmount): array
    {
        try {
            $rpcUrl = $this->appSettings->get('solana_rpc_url', 'https://api.mainnet-beta.solana.com');
            $payload = json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getTransaction',
                'params' => [
                    $txHash,
                    ['encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0]
                ]
            ]);

            $timeout = (int)$this->appSettings->get('solana_api_timeout', 15);
            $connectTimeout = max(2, (int)floor($timeout / 3));

            $ch = \curl_init($rpcUrl);
            \curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => $timeout,                    // Total timeout
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,      // Connection timeout
                CURLOPT_DNS_CACHE_TIMEOUT => 120,               // Cache DNS
                CURLOPT_FAILONERROR => false,                   // Don't fail silently
            ]);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $response = \curl_exec($ch);
            $curlErr = \curl_errno($ch);
            $curlErrMsg = \curl_error($ch);
            \curl_close($ch);

            if ($curlErr !== 0) {
                return ['status' => 'error', 'reason' => "خطا در اتصال به Solana RPC: {$curlErrMsg}"];
            }

            if (!$response) {
                return ['status' => 'error', 'reason' => 'خطا در اتصال به Solana RPC'];
            }

            $data = json_decode($response, true);
            $result = $data['result'] ?? null;
            if (!$result) {
                return ['status' => 'pending', 'reason' => 'تراکنش در شبکه سولانا یافت نشد'];
            }

            $meta = $result['meta'] ?? [];
            if (isset($meta['err']) && $meta['err'] !== null) {
                return ['status' => 'mismatch', 'reason' => 'تراکنش ناموفق در شبکه سولانا'];
            }

            $postBalances = $meta['postTokenBalances'] ?? [];
            $preBalances = $meta['preTokenBalances'] ?? [];
            
            $usdtMint = 'Es9vMFrzaCERmJfrF4H2FYBnIiXMfYm4bov5BqNW9blI';
            $transferAmount = 0.0;
            $receiverFound = false;

            foreach ($postBalances as $post) {
                if (($post['mint'] ?? '') === $usdtMint) {
                    $owner = $post['owner'] ?? '';
                    if (strtolower($owner) === strtolower($toWallet)) {
                        $receiverFound = true;
                        $preAmount = 0.0;
                        foreach ($preBalances as $pre) {
                            if (($pre['owner'] ?? '') === $owner && ($pre['mint'] ?? '') === $usdtMint) {
                                $preAmount = (float)($pre['uiTokenAmount']['uiAmount'] ?? 0.0);
                                break;
                            }
                        }
                        $postAmount = (float)($post['uiTokenAmount']['uiAmount'] ?? 0.0);
                        $transferAmount = $postAmount - $preAmount;
                        break;
                    }
                }
            }

            if (!$receiverFound) {
                return ['status' => 'mismatch', 'reason' => 'آدرس گیرنده یا توکن USDT یافت نشد'];
            }

            $expected = (float)$expectedAmount;
            if (abs($transferAmount - $expected) > 0.01) {
                return ['status' => 'mismatch', 'reason' => 'مبلغ تراکنش مطابقت ندارد'];
            }

            return ['status' => 'verified', 'details' => $result];
        } catch (\Exception $e) {
            $this->logger->error('crypto.verify.solana.failed', [
                'tx_hash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return ['status' => 'error', 'reason' => 'خطا در بررسی تراکنش Solana'];
        }
    }
}
