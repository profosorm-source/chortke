<?php

namespace App\Adapters;

use App\Contracts\LoggerInterface;
use Core\CircuitBreaker;

class CryptoExplorerAdapter implements CryptoVerificationAdapter
{
    private LoggerInterface $logger;
    private CircuitBreaker $circuitBreaker;

    public function __construct(LoggerInterface $logger, CircuitBreaker $circuitBreaker)
    {
        $this->logger = $logger;
        $this->circuitBreaker = $circuitBreaker;
    }

    /**
     * Verify a crypto transaction using blockchain explorers
     * This is a best-effort verification that may return 'unavailable' for complex cases
     */
    public function verify(string $network, string $txHash, string $fromWallet, string $toWallet, float $expectedAmount): array
    {
        $url = $this->getExplorerUrl($network, $txHash);
        if ($url === '#') {
            return ['status' => 'unavailable', 'reason' => 'Explorer ناشناخته'];
        }

        try {
            $runner = function () use ($url) {
                $html = $this->fetchPage($url);
                if ($html === null) {
                    throw new \Core\Exceptions\TransientException('Explorer unavailable or timed out.');
                }
                return $html;
            };
            $html = $this->circuitBreaker->call('crypto_explorer_' . strtolower($network), $runner);
        } catch (\Throwable $e) {
            $this->logger->warning('crypto.explorer.unavailable', [
                'network' => $network,
                'error' => $e->getMessage(),
            ]);
            return ['status' => 'unavailable', 'reason' => 'عدم دسترسی/تحریم/کلادفلر'];
        }

        if (stripos($html, 'Just a moment') !== false || stripos($html, 'cloudflare') !== false) {
            return ['status' => 'unavailable', 'reason' => 'محافظ ضدربات'];
        }

        // NOTE: در این نسخه چون استخراج دقیق از TronScan/BscScan بدون API تضمینی نیست،
        // اگر parser قابل اتکا نداشتیم => unavailable و مستقیم manual_review.
        // اگر در آینده امکان parse قابل اتکا فراهم شد، همینجا verified/mismatch می‌دهیم.
        return ['status' => 'unavailable', 'reason' => 'داده قابل استخراج نیست (SPA/JS)'];
    }

    /**
     * Get the blockchain explorer URL for a transaction
     */
    private function getExplorerUrl(string $network, string $txHash): string
    {
        $map = [
            'TRC20' => 'https://tronscan.org/#/transaction/',
            'BNB20' => 'https://bscscan.com/tx/',
            'ERC20' => 'https://etherscan.io/tx/',
            'TON'   => 'https://tonscan.org/tx/',
            'SOL'   => 'https://explorer.solana.com/tx/',
        ];
        return ($map[$network] ?? '#') . $txHash;
    }

    /**
     * Fetch HTML content from a URL
     */
    private function fetchPage(string $url): ?string
    {
        $ch = \curl_init($url);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Accept-Encoding: gzip, deflate',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
        ], trace_headers()));

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return null;
        }

        return $response;
    }
}

