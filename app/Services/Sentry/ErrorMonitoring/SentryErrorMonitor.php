<?php

declare(strict_types=1);

namespace App\Services\Sentry\ErrorMonitoring;

use App\Models\SentryModel;
use Core\Logger;
use App\Services\AuditTrail;
use App\Utils\Sentry\StackTraceAnalyzer;
use App\Utils\Sentry\BreadcrumbCollector;
use App\Utils\Sentry\ContextEnricher;
use App\Services\Sentry\Alerting\AlertDispatcher;
use App\Contracts\CacheInterface;

/**
 * 🔥 SentryErrorMonitor - سیستم مانیتورینگ خطا مشابه Sentry
 */
class SentryErrorMonitor
{
    private StackTraceAnalyzer $stackAnalyzer;
    private BreadcrumbCollector $breadcrumbs;
    private ContextEnricher $contextEnricher;
    
    private array $config = [
        'enabled' => true,
        'environment' => 'production',
        'release' => null,
        'sample_rate' => 1.0,
        'ignore_exceptions' => [],
        'before_send' => null,
    ];

    private SentryModel $model;
    private Logger $logger;
    private AlertDispatcher $alertDispatcher;
    private AuditTrail $auditTrail;
    private CacheInterface $cache;
    public function __construct(
        SentryModel $model,
        Logger $logger,
        AlertDispatcher $alertDispatcher,
        AuditTrail $auditTrail,
        CacheInterface $cache,
        array $config = []
    ) {        $this->model = $model;
        $this->logger = $logger;
        $this->alertDispatcher = $alertDispatcher;
        $this->auditTrail = $auditTrail;
        $this->cache = $cache;

        $this->config = array_merge($this->config, $config);

        $this->stackAnalyzer = new StackTraceAnalyzer();
        $this->breadcrumbs = new BreadcrumbCollector();
        $this->contextEnricher = new ContextEnricher();

        if (!$this->config['release']) {
            $this->config['release'] = $this->detectRelease();
        }
    }

    /**
     * 🎯 Capture Exception - ورودی اصلی برای ثبت خطا
     */
    public function captureException(
        \Throwable $exception,
        ?int $userId = null,
        array $extraContext = [],
        string $level = 'error'
    ): ?string {
        try {
            if (!$this->config['enabled'] || !$this->shouldCapture() || $this->shouldIgnore($exception)) {
                return null;
            }

            $event = $this->buildEvent($exception, $userId, $extraContext, $level);

            if (is_callable($this->config['before_send'])) {
                $event = call_user_func($this->config['before_send'], $event);
                if ($event === null) {
                    return null;
                }
            }

            $eventId = $this->storeEvent($event);

            $this->handleAlerting($event, $eventId);
            $this->breadcrumbs->clear();

            return $eventId;

        } catch (\Throwable $e) {
            $this->logger->critical('sentry.error_monitor.failed', [
                'channel' => 'sentry',
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 📝 Capture Message - برای لاگ پیام‌های manual
     */
    public function captureMessage(
        string $message,
        string $level = 'info',
        ?int $userId = null,
        array $context = []
    ): ?string {
        try {
            if (!$this->config['enabled'] || !$this->shouldCapture()) {
                return null;
            }

            $event = [
                'event_id' => $this->generateEventId(),
                'timestamp' => microtime(true),
                'level' => $level,
                'message' => $message,
                'logger' => 'php',
                'platform' => 'php',
                'environment' => $this->config['environment'],
                'release' => $this->config['release'],
                'user' => $this->getUserContext($userId),
                'request' => $this->getRequestContext(),
                'tags' => $this->getTags(),
                'extra' => $context,
                'breadcrumbs' => $this->breadcrumbs->getAll(),
            ];

            return $this->storeEvent($event);

        } catch (\Throwable $e) {
            $this->logger->error('captureMessage failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 🍞 Add Breadcrumb
     */
    public function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
        $this->breadcrumbs->add($message, $category, $level, $data);
    }

    private function buildEvent(\Throwable $exception, ?int $userId, array $extraContext, string $level): array
    {
        $stackTrace = $this->stackAnalyzer->analyze($exception);
        $fingerprint = $this->generateFingerprint($exception, $stackTrace);
        $eventId = $this->generateEventId();

        return [
            'event_id' => $eventId,
            'timestamp' => microtime(true),
            'level' => $level,
            'logger' => 'php',
            'platform' => 'php',
            'sdk' => [
                'name' => 'chortke-sentry',
                'version' => '1.0.0',
            ],
            'exception' => [
                'type' => get_class($exception),
                'value' => $exception->getMessage(),
                'stacktrace' => $stackTrace,
                'module' => $this->getModuleFromException($exception),
            ],
            'fingerprint' => $fingerprint,
            'environment' => $this->config['environment'],
            'release' => $this->config['release'],
            'server_name' => gethostname(),
            'user' => $this->getUserContext($userId),
            'request' => $this->getRequestContext(),
            'contexts' => $this->contextEnricher->enrich(),
            'tags' => $this->getTags(),
            'breadcrumbs' => $this->breadcrumbs->getAll(),
            'extra' => array_merge($extraContext, [
                'php_version' => PHP_VERSION,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
            ]),
        ];
    }

    private function storeEvent(array $event): string
    {
        try {
            // EM2: Filter PII and mask secrets recursively prior to storage to secure persistence logs
            $event = $this->sanitizeData($event);

            $fingerprint = $event['fingerprint'] ?? $this->generateSimpleFingerprint($event);

            // EM1: Throttle and consolidate database writes under high-volume bursts
            if ($this->isRateLimited($fingerprint)) {
                return $event['event_id'] ?? 'rate_limited';
            }

            $existingIssue = $this->model->findExistingIssue($fingerprint, $this->config['environment']);

            if ($existingIssue) {
                $this->model->updateIssueStats((int)$existingIssue->id, $event['level']);
                $issueId = (int)$existingIssue->id;
            } else {
                $issueId = $this->model->createIssue([
                    'fingerprint' => $fingerprint,
                    'level' => $event['level'],
                    'title' => $this->getIssueTitle($event),
                    'culprit' => $this->getCulprit($event),
                    'environment' => $this->config['environment'],
                    'release' => $this->config['release'],
                    'metadata' => [
                        'exception_type' => $event['exception']['type'] ?? null,
                        'platform' => $event['platform'] ?? 'php',
                    ]
                ]);
            }

            $this->model->storeEventRecord([
                'event_id' => $event['event_id'],
                'issue_id' => $issueId,
                'level' => $event['level'],
                'message' => $event['message'] ?? $event['exception']['value'] ?? '',
                'exception_type' => $event['exception']['type'] ?? null,
                'stack_trace' => json_encode($event['exception']['stacktrace'] ?? []),
                'breadcrumbs' => json_encode($event['breadcrumbs'] ?? []),
                'user_context' => json_encode($event['user'] ?? []),
                'request_context' => json_encode($event['request'] ?? []),
                'device_context' => json_encode($event['contexts'] ?? []),
                'tags' => json_encode($event['tags'] ?? []),
                'extra' => json_encode($event['extra'] ?? []),
                'environment' => $this->config['environment'],
                'release_version' => $this->config['release'],
                'user_id' => $event['user']['id'] ?? null,
                // Masked IP from request context mapped securely (EM2 enforced)
                'ip_address' => $event['request']['ip'] ?? null,
                'user_agent' => $event['request']['user_agent'] ?? null,
            ]);

            return $event['event_id'];
        } catch (\Throwable $e) {
            $this->logger->error('storeEvent failed', ['error' => $e->getMessage()]);
            return $event['event_id'] ?? uniqid('error_');
        }
    }

    private function handleAlerting(array $event, string $eventId): void
    {
        if (!in_array($event['level'], ['error', 'critical', 'fatal'])) {
            return;
        }

        $this->alertDispatcher->dispatch([
            'type' => 'error',
            'severity' => $this->mapLevelToSeverity($event['level']),
            'title' => $this->getIssueTitle($event),
            'message' => $event['exception']['value'] ?? $event['message'] ?? '',
            'event_id' => $eventId,
            'environment' => $this->config['environment'],
            'metadata' => [
                'exception_type' => $event['exception']['type'] ?? null,
                'file' => $event['exception']['stacktrace']['frames'][0]['file'] ?? null,
                'line' => $event['exception']['stacktrace']['frames'][0]['line'] ?? null,
            ]
        ]);
    }

    private function generateFingerprint(\Throwable $exception, array $stackTrace): string
    {
        $frame = $stackTrace['frames'][0] ?? [];
        $normalizedMessage = $this->normalizeMessage($exception->getMessage());
        
        $components = [
            get_class($exception),
            $frame['file'] ?? '',
            $frame['line'] ?? '',
            $normalizedMessage,
        ];

        return hash('sha256', implode('|', $components));
    }

    private function normalizeMessage(string $message): string
    {
        $normalized = preg_replace('/\d+/', 'N', $message);
        $normalized = preg_replace('/0x[0-9a-f]+/i', '0xHEX', $normalized);
        $normalized = preg_replace('/\/[\w\/]+\//', '/PATH/', $normalized);
        return substr($normalized, 0, 200);
    }

    private function generateSimpleFingerprint(array $event): string
    {
        $message = $event['message'] ?? $event['exception']['value'] ?? '';
        $type = $event['exception']['type'] ?? 'message';
        return hash('sha256', $type . '|' . $this->normalizeMessage($message));
    }

    private function getIssueTitle(array $event): string
    {
        if (isset($event['exception']['type'])) {
            $shortType = substr(strrchr($event['exception']['type'], '\\') ?: $event['exception']['type'], 1);
            return $shortType . ': ' . substr($event['exception']['value'], 0, 100);
        }
        return substr($event['message'] ?? 'Unknown Error', 0, 150);
    }

    private function getCulprit(array $event): ?string
    {
        $frame = $event['exception']['stacktrace']['frames'][0] ?? null;
        if (!$frame) return null;
        $file = basename($frame['file'] ?? '');
        $function = $frame['function'] ?? '';
        return $file ? "{$file} in {$function}" : null;
    }

    private function getUserContext(?int $userId): array
    {
        $context = ['id' => $userId];
        if ($userId) {
            try {
                $user = $this->model->getUserData($userId);
                if ($user) {
                    $context['email'] = $user->email;
                    $context['username'] = $user->full_name;
                }
            } catch (\Throwable) {}
        }
        return $context;
    }

    private function getRequestContext(): array
    {
        return [
            'url' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'query_string' => $_SERVER['QUERY_STRING'] ?? null,
            'headers' => $this->getHeaders(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
    }

    private function getHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('HTTP_', '', $key);
                $header = str_replace('_', '-', $header);
                $headers[$header] = $value;
            }
        }
        return $headers;
    }

    private function getTags(): array
    {
        return [
            'environment' => $this->config['environment'],
            'release' => $this->config['release'],
            'server_name' => gethostname(),
            'php_version' => PHP_VERSION,
        ];
    }

    private function getModuleFromException(\Throwable $exception): ?string
    {
        $class = get_class($exception);
        $parts = explode('\\', $class);
        return $parts[0] ?? null;
    }

    private function generateEventId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function detectRelease(): ?string
    {
        $gitHead = dirname(__DIR__, 4) . '/.git/HEAD';
        if (file_exists($gitHead)) {
            $head = trim(file_get_contents($gitHead));
            if (preg_match('/ref: (.+)/', $head, $matches)) {
                return basename($matches[1]);
            }
        }
        return config('app.release', 'unknown');
    }

    private function shouldCapture(): bool
    {
        $sampleRate = (float)($this->config['sample_rate'] ?? 1.0);
        if ($sampleRate >= 1.0) return true;
        if ($sampleRate <= 0.0) return false;

        // EM3: Guarantee deterministic reproducibility of probabilistic captures bounded to user/trace contexts
        $traceId = $_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['UNIQUE_ID'] ?? (($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        
        // Derive deterministic 32-bit value from user trace signature
        $hashValue = hexdec(substr(md5($traceId), 0, 8));
        $maxLimit = 0xFFFFFFFF;

        return ($hashValue / $maxLimit) <= $sampleRate;
    }

    private function shouldIgnore(\Throwable $exception): bool
    {
        $class = get_class($exception);
        return in_array($class, $this->config['ignore_exceptions'], true);
    }

    private function mapLevelToSeverity(string $level): string
    {
        return match($level) {
            'critical', 'fatal' => 'critical',
            'error' => 'high',
            'warning' => 'medium',
            default => 'low'
        };
    }

    public function setConfig(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    public function getStatistics(string $period = 'today'): array
    {
        $stats = $this->model->getErrorStats($period, $this->config['environment']);

        return [
            'total_issues' => $stats->total_issues ?? 0,
            'total_events' => $stats->total_events ?? 0,
            'critical_count' => $stats->critical_count ?? 0,
            'error_count' => $stats->error_count ?? 0,
            'warning_count' => $stats->warning_count ?? 0,
        ];
    }

    /**
     * EM1: Throttles event captures under rapid exception bursts to maintain DB responsiveness
     */
    private function isRateLimited(string $fingerprint): bool
    {
        $cacheKey = 'sentry:burst_limit:' . md5($fingerprint);
        
        try {
            // Standard increment on PSR-16 (or simulation)
            $current = (int)$this->cache->get($cacheKey, 0);
            if ($current >= 10) {
                // Block if limit exceeded (10 errors per 60s)
                return true;
            }
            
            $this->cache->set($cacheKey, $current + 1, 60);
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * EM2: Recursive deep filtering of PII identifiers and sensitive secrets to enforce GDPR compliance
     */
    private function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'passwd', 'secret', 'token', 'api_key', 'auth', 'authorization', 'cookie', 'session', 'card', 'credit', 'email'];
        $result = [];

        foreach ($data as $key => $value) {
            $lowKey = strtolower((string)$key);
            
            $isSensitive = false;
            foreach ($sensitiveKeys as $sKey) {
                if (str_contains($lowKey, $sKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $result[$key] = '[FILTERED]';
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitizeData($value);
            } elseif (in_array($lowKey, ['ip', 'ip_address', 'remote_addr'], true)) {
                // Mask the last octet/segments of IP address to defend PII disclosure vectors
                $ip = (string)$value;
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $result[$key] = preg_replace('/\d+$/', 'XXX', $ip);
                } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $result[$key] = preg_replace('/:[0-9a-fA-F]+$/', ':XXXX', $ip);
                } else {
                    $result[$key] = '[MASKED]';
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
