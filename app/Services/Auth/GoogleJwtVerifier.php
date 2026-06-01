<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Core\Cache;
use App\Contracts\LoggerInterface;

class GoogleJwtVerifier
{
    private const JWKS_CACHE_KEY = 'google_oauth_jwks';
    private const JWKS_CACHE_MINUTES = 60;
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private Cache $cache;
    private LoggerInterface $logger;

    public function __construct(Cache $cache, LoggerInterface $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function verifyIdToken(string $idToken, string $expectedAudience, array $validIssuers): array
    {
        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            return ['success' => false, 'message' => 'Invalid ID Token format'];
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;
        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);
        $signature = $this->base64UrlDecode($encodedSignature);

        if (!is_array($header) || !is_array($payload) || $signature === false) {
            return ['success' => false, 'message' => 'Malformed ID Token'];
        }

        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return ['success' => false, 'message' => 'Unsupported ID Token algorithm or missing key ID'];
        }

        $jwks = $this->getJwks();
        if (!isset($jwks['keys']) || !is_array($jwks['keys'])) {
            return ['success' => false, 'message' => 'Unable to retrieve Google public keys'];
        }

        $jwk = null;
        foreach ($jwks['keys'] as $key) {
            if (isset($key['kid']) && $key['kid'] === $header['kid']) {
                $jwk = $key;
                break;
            }
        }

        if ($jwk === null) {
            $jwks = $this->fetchJwks(true);
            if (!isset($jwks['keys']) || !is_array($jwks['keys'])) {
                return ['success' => false, 'message' => 'Google public keys are unavailable'];
            }
            foreach ($jwks['keys'] as $key) {
                if (isset($key['kid']) && $key['kid'] === $header['kid']) {
                    $jwk = $key;
                    break;
                }
            }
        }

        if ($jwk === null) {
            return ['success' => false, 'message' => 'ID Token key ID not found'];
        }

        try {
            $publicKey = $this->buildPemFromJwk($jwk);
        } catch (\Throwable $e) {
            $this->logger->error('oauth.google.jwt_key_build_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Unable to build JWT verification key'];
        }

        $signedInput = $encodedHeader . '.' . $encodedPayload;
        $verification = openssl_verify($signedInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verification !== 1) {
            return ['success' => false, 'message' => 'ID Token signature verification failed'];
        }

        if (!in_array($payload['iss'] ?? '', $validIssuers, true)) {
            return ['success' => false, 'message' => 'Issuer mismatch in ID Token'];
        }

        if (($payload['aud'] ?? '') !== $expectedAudience) {
            return ['success' => false, 'message' => 'Audience mismatch in ID Token'];
        }

        if (!isset($payload['exp']) || (int)$payload['exp'] < time()) {
            return ['success' => false, 'message' => 'ID Token has expired'];
        }

        if (isset($payload['iat'])) {
            $iat = (int)$payload['iat'];
            if ($iat > time() + 60 || $iat < time() - 86400) {
                return ['success' => false, 'message' => 'ID Token issued at invalid time'];
            }
        }

        return ['success' => true, 'payload' => $payload];
    }

    private function getJwks(): array
    {
        $cached = $this->cache->get(self::JWKS_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        return $this->fetchJwks();
    }

    private function fetchJwks(bool $force = false): array
    {
        if (!$force) {
            $cached = $this->cache->get(self::JWKS_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $ch = curl_init(self::JWKS_URL);
        if ($ch === false) {
            return [];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $this->logger->error('oauth.google.jwks_fetch_failed', ['error' => curl_error($ch)]);
            curl_close($ch);
            return [];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $jwks = json_decode((string)$raw, true);
        if ($httpCode !== 200 || !is_array($jwks) || !isset($jwks['keys'])) {
            $this->logger->error('oauth.google.jwks_invalid_response', ['http_code' => $httpCode, 'response' => $jwks]);
            return [];
        }

        $this->cache->set(self::JWKS_CACHE_KEY, $jwks, self::JWKS_CACHE_MINUTES);
        return $jwks;
    }

    private function base64UrlDecode(string $value): string|false
    {
        $value = str_replace(['-', '_'], ['+', '/'], $value);
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return base64_decode($value, true);
    }

    private function buildPemFromJwk(array $jwk): string
    {
        if (empty($jwk['n']) || empty($jwk['e'])) {
            throw new \InvalidArgumentException('JWK is missing modulus or exponent');
        }

        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);
        if ($modulus === false || $exponent === false) {
            throw new \InvalidArgumentException('Invalid JWK encoding');
        }

        $modulus = ltrim($modulus, "\x00");
        if (ord($modulus[0]) > 0x7f) {
            $modulus = "\x00" . $modulus;
        }

        $components = $this->encodeSequence([
            $this->encodeInteger($modulus),
            $this->encodeInteger($exponent),
        ]);

        $rsaOid = hex2bin('300d06092a864886f70d0101010500');
        if ($rsaOid === false) {
            throw new \RuntimeException('Unable to build RSA public key OID');
        }

        $bitString = "\x03" . $this->encodeLength(strlen($components) + 1) . "\x00" . $components;
        $der = $this->encodeSequence([$rsaOid, $bitString]);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function encodeInteger(string $data): string
    {
        return "\x02" . $this->encodeLength(strlen($data)) . $data;
    }

    private function encodeSequence(array $elements): string
    {
        $payload = implode('', $elements);
        return "\x30" . $this->encodeLength(strlen($payload)) . $payload;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $hexLength = dechex($length);
        if (strlen($hexLength) % 2 !== 0) {
            $hexLength = '0' . $hexLength;
        }

        $lengthBytes = hex2bin($hexLength);
        return chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
    }
}
