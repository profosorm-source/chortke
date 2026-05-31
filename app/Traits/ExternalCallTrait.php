<?php

declare(strict_types=1);

namespace App\Traits;

use Core\CircuitBreaker;
use Core\Exceptions\PermanentFailure;
use Core\Exceptions\ProviderUnavailable;
use Core\Exceptions\RateLimitedFailure;
use Core\Exceptions\TransientException;

/**
 * Section 8.3 / 8.4 — Unified external-call helpers for adapters.
 *
 * این trait کلاس جدیدی نمی‌سازد — فقط سه چیز پراکنده را در یک‌جا متمرکز می‌کند:
 *
 *   1. callWithBreaker()
 *      اجرای یک callable درون Core\CircuitBreaker، با fallback graceful
 *      اگر CB در دسترس نباشد.
 *
 *   2. classifyHttpFailure()
 *      تبدیل (httpCode, curlErrno) به یکی از ۴ Failure استاندارد
 *      (Section 8.4).
 *
 *   3. retryTransient()
 *      حلقه‌ی retry که فقط روی TransientException و ProviderUnavailable
 *      تلاش مجدد می‌کند، با backoff نمایی و jitter.
 *
 * استفاده در هر adapter:
 *
 *   use ExternalCallTrait;
 *
 *   $result = $this->callWithBreaker('fcm', function () {
 *       return $this->retryTransient(function () {
 *           [$body, $code, $errno] = $this->doCurl($url, $payload);
 *           if ($code < 200 || $code >= 300) {
 *               throw $this->classifyHttpFailure($code, $errno);
 *           }
 *           return $body;
 *       });
 *   });
 */
trait ExternalCallTrait
{
    /**
     * @template T
     * @param string $providerName  نام منطقی provider برای CB state key
     * @param callable():T $operation
     * @param callable(\Core\Exceptions\CircuitBreakerOpenException):T|null $fallback استراتژی جایگزین در صورت باز بودن مدار
     * @return T
     * @throws \Throwable
     */
    protected function callWithBreaker(string $providerName, callable $operation, ?callable $fallback = null): mixed
    {
        $providerName = $this->sanitizeProviderName($providerName);

        $breaker = $this->resolveCircuitBreaker();
        if ($breaker === null) {
            // graceful fallback: CB not bound → call directly
            return $operation();
        }

        try {
            return $breaker->call($providerName, $operation);
        } catch (\Core\Exceptions\CircuitBreakerOpenException $e) {
            if ($fallback !== null) {
                return $fallback($e);
            }
            throw $e;
        } catch (PermanentFailure $e) {
            // PermanentFailure should never have tripped CB — re-throw as-is.
            // (CircuitBreaker::call already counted it; we accept the noise
            //  in exchange for keeping the trait simple. Use rawCall() to
            //  bypass CB entirely for known-permanent paths.)
            throw $e;
        }
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     * @throws \Throwable
     */
    protected function retryTransient(
        callable $operation,
        int $maxAttempts = 3,
        int $initialDelayMs = 200,
        int $maxDelayMs = 3000
    ): mixed {
        $maxAttempts    = max(1, min(10, $maxAttempts));
        $initialDelayMs = max(10, min(5000, $initialDelayMs));
        $maxDelayMs     = max($initialDelayMs, min(30000, $maxDelayMs));

        $attempt = 0;
        $delay   = $initialDelayMs;

        while (true) {
            $attempt++;
            try {
                return $operation();
            } catch (PermanentFailure $e) {
                throw $e;
            } catch (RateLimitedFailure $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                $sleepMs = $e->getRetryAfterSeconds() > 0
                    ? min(30000, $e->getRetryAfterSeconds() * 1000)
                    : $delay;
                usleep($sleepMs * 1000);
                $delay = min($maxDelayMs, $delay * 2);
                continue;
            } catch (TransientException | ProviderUnavailable $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                // jitter ± 25%
                $jitter  = (int) ($delay * (mt_rand(-25, 25) / 100));
                $sleepMs = max(10, $delay + $jitter);
                usleep($sleepMs * 1000);
                $delay = min($maxDelayMs, $delay * 2);
                continue;
            }
            // any other exception → don't retry
        }
    }

    /**
     * تبدیل وضعیت HTTP / خطای curl به یکی از Failure استاندارد.
     * هرگز null برنمی‌گرداند — همیشه یک exception می‌دهد که caller throw کند.
     */
    protected function classifyHttpFailure(
        int $httpCode,
        int $curlErrno = 0,
        string $bodySnippet = '',
        array $context = []
    ): \Throwable {
        // 1) خطاهای شبکه (curl-level) → Transient یا ProviderUnavailable
        // 6  CURLE_COULDNT_RESOLVE_HOST
        // 7  CURLE_COULDNT_CONNECT
        // 28 CURLE_OPERATION_TIMEDOUT
        // 35 CURLE_SSL_CONNECT_ERROR
        // 52 CURLE_GOT_NOTHING
        // 56 CURLE_RECV_ERROR
        if ($curlErrno !== 0) {
            $transient = [6, 7, 28, 35, 52, 55, 56];
            if (in_array($curlErrno, $transient, true)) {
                return new ProviderUnavailable(
                    sprintf('Network/curl failure errno=%d ctx=%s', $curlErrno, json_encode($context, JSON_UNESCAPED_UNICODE)),
                    503
                );
            }
            return new TransientException(
                sprintf('curl failure errno=%d ctx=%s', $curlErrno, json_encode($context, JSON_UNESCAPED_UNICODE)),
                500
            );
        }

        // 2) دسته‌بندی بر اساس HTTP code
        if ($httpCode === 408 || $httpCode === 504 || $httpCode === 524) {
            return new TransientException("Upstream timeout (HTTP {$httpCode})", $httpCode);
        }
        if ($httpCode === 429) {
            $retryAfter = (int)($context['retry_after'] ?? 0);
            return new RateLimitedFailure("Upstream rate-limited (HTTP 429)", $retryAfter, 429);
        }
        if ($httpCode === 502 || $httpCode === 503) {
            return new ProviderUnavailable("Upstream unavailable (HTTP {$httpCode})", $httpCode);
        }
        if ($httpCode >= 500 && $httpCode < 600) {
            return new TransientException("Upstream 5xx (HTTP {$httpCode})", $httpCode);
        }
        if ($httpCode >= 400 && $httpCode < 500) {
            // 4xx به‌جز 408/429 — معمولاً bug در requestِ ما → نباید retry شود.
            return new PermanentFailure(
                sprintf('Upstream 4xx (HTTP %d) body=%s', $httpCode, mb_substr($bodySnippet, 0, 200)),
                $httpCode
            );
        }
        // 1xx/2xx/3xx اینجا نباید برسد. اگر رسید → Transient.
        return new TransientException("Unexpected HTTP {$httpCode}", $httpCode ?: 500);
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    /**
     * สร้าง Stream Context ایمن با زمان‌بندی دقیق برای استفاده در file_get_contents و soap
     */
    protected function getStreamContextWithTimeout(int $timeoutSeconds = 5)
    {
        return stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true // برای دریافت کدهای 4xx/5xx به جای Warning
            ],
            'ssl' => [
                'timeout' => $timeoutSeconds,
            ]
        ]);
    }

    /**
     * پیکربندی زمان‌بندی دقیق و امن برای cURL
     */
    protected function setupCurlTimeout(\CurlHandle $ch, int $timeoutSeconds = 5): void
    {
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(1, $timeoutSeconds - 2));
    }

    private function resolveCircuitBreaker(): ?CircuitBreaker
    {
        // اگر property داخل adapter ست شده (مثل JibitInquiry)، از آن استفاده کن.
        if (property_exists($this, 'circuitBreaker') && $this->circuitBreaker instanceof CircuitBreaker) {
            return $this->circuitBreaker;
        }
        if (property_exists($this, 'circuit') && $this->circuit instanceof CircuitBreaker) {
            return $this->circuit;
        }

        // در غیر این صورت از container بگیر (graceful, optional).
        try {
            $container = \Core\Container::getInstance();
            if (method_exists($container, 'has') && !$container->has(CircuitBreaker::class)) {
                return null;
            }
            $instance = $container->make(CircuitBreaker::class);
            return $instance instanceof CircuitBreaker ? $instance : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sanitizeProviderName(string $name): string
    {
        $name = preg_replace('/[^a-z0-9_:.-]/i', '_', trim($name)) ?? 'external';
        return mb_substr($name, 0, 60);
    }
}
