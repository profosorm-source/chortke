<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PerformanceLog;
use App\Models\SecurityLog;
use App\Models\SystemLog;
use App\Services\AuditTrail;
use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * LogService — مغز سیستم لاگینگ
 */
class LogService
{
    private ActivityLog $activityLog;
    private SystemLog $systemLog;
    private SecurityLog $securityLog;
    private PerformanceLog $performanceLog;
    private Database $db;
    private ?\Core\Redis $redis;
    private \Core\Session $session;
    private ?AuditTrail $auditTrail;
    private string $requestId;
    private array $logBuffer = [];
    private const MAX_BUFFER_SIZE = 100;

    public const TYPE_ACTIVITY = 'activity';
    public const TYPE_SYSTEM = 'system';
    public const TYPE_SECURITY = 'security';
    public const TYPE_PERFORMANCE = 'performance';
    
    private const LEVEL_MAP = [
        'emergency' => 'EMERGENCY',
        'alert'     => 'ALERT',
        'critical'  => 'CRITICAL',
        'error'     => 'ERROR',
        'warning'   => 'WARNING',
        'notice'    => 'NOTICE',
        'info'      => 'INFO',
        'debug'     => 'DEBUG',
    ];

    private string $logDir;
    private string $rotationMarkerFile;
    private bool $rotationChecked = false;
    private array $localThrottleCache = [];
    private int $rotationIntervalSeconds = 3600;
    private int $maxContextSize = 5000;
    private int $retentionDays  = 90;

    public function __construct(
        Database $db,
        ActivityLog $activityLog,
        SystemLog $systemLog,
        SecurityLog $securityLog,
        PerformanceLog $performanceLog,
        ?\Core\Session $session = null,
        ?\Core\Redis $redis = null,
        ?AuditTrail $auditTrail = null
    ) {
        $this->db = $db;
        $this->activityLog = $activityLog;
        $this->systemLog = $systemLog;
        $this->securityLog = $securityLog;
        $this->performanceLog = $performanceLog;
        $this->session = $session ?? \Core\Session::getInstance();
        $this->redis = $redis;
        $this->auditTrail = $auditTrail;
        
        $this->logDir = trim((string)config('logging.log_dir', dirname(__DIR__, 2) . '/storage/logs/'), '/\\') . '/';
        $this->rotationMarkerFile = $this->logDir . '.rotation_check';
        $this->requestId = $_SERVER['REQUEST_ID'] ?? bin2hex(random_bytes(16));

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }

        register_shutdown_function([$this, 'flush']);
    }

    public function query(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $type = $filters['type'] ?? self::TYPE_ACTIVITY;

        switch ($type) {
            case self::TYPE_SYSTEM:
                return $this->systemLog->getPaginated(
                    $page,
                    $perPage,
                    $filters['level'] ?? null,
                    $filters['user_id'] ?? null,
                    $filters['search'] ?? null,
                    $filters['date_from'] ?? null,
                    $filters['date_to'] ?? null
                );
            case self::TYPE_SECURITY:
                return $this->securityLog->getPaginated(
                    $filters,
                    $page,
                    $perPage
                );
            case self::TYPE_PERFORMANCE:
                return $this->performanceLog->getPaginated(
                    $page,
                    $perPage,
                    $filters['metric'] ?? null,
                    $filters['date_from'] ?? null,
                    $filters['date_to'] ?? null
                );
            case self::TYPE_ACTIVITY:
            default:
                return $this->activityLog->getPaginated(
                    $page,
                    $perPage,
                    $filters['user_id'] ?? null,
                    $filters['action'] ?? null,
                    $filters['search'] ?? null,
                    $filters['date_from'] ?? null,
                    $filters['date_to'] ?? null,
                    $filters['channel'] ?? null
                );
        }
    }

    public function findById(int $id, string $type = self::TYPE_ACTIVITY): array|object|null
    {
        return match ($type) {
            self::TYPE_SYSTEM => $this->systemLog->findById($id),
            self::TYPE_SECURITY => $this->securityLog->findById($id),
            self::TYPE_PERFORMANCE => $this->performanceLog->findById($id),
            self::TYPE_ACTIVITY => $this->activityLog->findById($id),
            default => $this->activityLog->findById($id),
        };
    }

    private function sanitizeContext(array $context): array
    {
        $sensitive = ['password', 'token', 'secret', 'api_key', 'credit_card', 
                      'cvv', 'ssn', 'pin', 'otp', 'authorization', 'cookie', 'password_confirmation'];
        
        array_walk_recursive($context, function(&$value, $key) use ($sensitive) {
            if ((is_string($key) || is_numeric($key)) && in_array(strtolower((string)$key), $sensitive, true)) {
                $value = '[REDACTED]';
            }
        });
        
        return $context;
    }

    private function getRealIp(): ?string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 
                    'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];
                if (filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return trim($ip);
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    private function getTraceContext(): array
    {
        return [
            'trace_id' => $_SERVER['HTTP_X_TRACE_ID'] ?? $this->requestId,
            'span_id' => $_SERVER['HTTP_X_SPAN_ID'] ?? bin2hex(random_bytes(8)),
            'parent_span_id' => $_SERVER['HTTP_X_PARENT_SPAN_ID'] ?? null,
        ];
    }

    private function prepareContext(array $context = []): string
    {
        return json_encode(
            array_merge($this->sanitizeContext($context), $this->getTraceContext()),
            JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    private function shouldLog(string $level, string $message, array $context = []): bool
    {
        $env = config('app.env', 'production');
        if (!config("logging.enabled.{$env}", true)) {
            return false;
        }

        $level = strtolower($level);
        $minLevel = strtolower(config('logging.min_level', 'info'));
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        $currentIndex = array_search($level, $levels, true);
        $minIndex = array_search($minLevel, $levels, true);
        if ($currentIndex === false || $minIndex === false || $currentIndex < $minIndex) {
            return false;
        }

        if ($env !== 'production') {
            return true;
        }

        if (in_array($level, ['debug', 'info'], true)) {
            $sampleRate = (int)config("logging.database_sample_rate", $level === 'debug' ? 5 : 25);
            if ($sampleRate > 0 && $sampleRate < 100) {
                $hash = crc32($this->requestId . '|' . $message . '|' . json_encode($context));
                if (($hash % 100) >= $sampleRate) {
                    return false;
                }
            }
        }

        return $this->throttleLog($level, $message, $context);
    }

    private function throttleLog(string $level, string $message, array $context = []): bool
    {
        $limit = (int)config("logging.throttle.{$level}_per_minute", in_array($level, ['debug', 'info'], true) ? 30 : 300);
        if ($limit <= 0) {
            return true;
        }

        $key = 'log:throttle:' . $level . ':' . substr(md5($level . '|' . $message . '|' . json_encode($context)), 0, 16);

        try {
            if ($this->redis && $this->redis->isAvailable()) {
                $count = $this->redis->incr($key);
                if ($count === 1) {
                    $this->redis->expire($key, 60);
                }
                return $count <= $limit;
            }
        } catch (\Throwable) {
            // fallback to in-memory sampling
        }

        $now = time();
        if (!isset($this->localThrottleCache[$key]) || $this->localThrottleCache[$key]['expires_at'] <= $now) {
            $this->localThrottleCache[$key] = [
                'count' => 1,
                'expires_at' => $now + 60,
            ];
            return true;
        }

        if ($this->localThrottleCache[$key]['count'] < $limit) {
            $this->localThrottleCache[$key]['count']++;
            return true;
        }

        return false;
    }

    private function maybeRotateLogFiles(): void
    {
        if ($this->rotationChecked) {
            return;
        }

        $this->rotationChecked = true;
        if (file_exists($this->rotationMarkerFile) && filemtime($this->rotationMarkerFile) > (time() - $this->rotationIntervalSeconds)) {
            return;
        }

        $this->rotateLogFiles();
        @touch($this->rotationMarkerFile);
    }

    public function logActivity(string $action, string $description, ?int $userId = null, array $context = [], string $channel = 'default'): void
    {
        if (!$this->shouldLog('info', "Activity: {$action}", $context)) {
            return;
        }

        $data = [
            'request_id' => $this->requestId,
            'user_id' => $userId ?? $this->session->get('user_id'),
            'channel' => $channel,
            'action' => $action,
            'description' => $description,
            'metadata' => $this->prepareContext($context),
            'ip_address' => $this->getRealIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->addToBuffer('activity', $data);

        // Record to AuditTrail — injected via constructor to avoid Service Locator anti-pattern
        if ($this->auditTrail) {
            try {
                $this->auditTrail->record($action, $userId, $this->sanitizeContext($context));
            } catch (\Throwable $e) {
                $this->robustFallbackLog('audit_trail_evasion', [['action' => $action, 'user' => $userId]], $e->getMessage());
            }
        }
    }

    public function logSystem(string $level, string $message, array $context = []): void
    {
        if (!$this->shouldLog($level, $message, $context)) {
            return;
        }

        $data = [
            'request_id' => $this->requestId,
            'level' => self::LEVEL_MAP[strtolower($level)] ?? 'INFO',
            'type' => $context['type'] ?? 'system',
            'message' => $message,
            'context' => $this->prepareContext($context),
            'user_id' => $this->session->get('user_id'),
            'ip_address' => $this->getRealIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->addToBuffer('system', $data);
    }

    public function logSecurity(string $event, string $message, string $level = 'WARNING', array $context = []): void
    {
        if (!$this->shouldLog($level, $message, $context)) {
            return;
        }

        $data = [
            'request_id' => $this->requestId,
            'level' => $level,
            'type' => $event,
            'message' => $message,
            'context' => $this->prepareContext($context),
            'user_id' => $this->session->get('user_id'),
            'ip_address' => $this->getRealIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->addToBuffer('security', $data);
    }

    public function logPerformance(string $metric, $value, array $context = []): void
    {
        if (!$this->shouldLog('debug', "Performance: {$metric}", $context)) {
            return;
        }

        $data = [
            'request_id' => $this->requestId,
            'metric' => $metric,
            'value' => (float)$value,
            'context' => $this->prepareContext($context),
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->addToBuffer('performance', $data);
    }

    private function addToBuffer(string $type, array $data): void
    {
        if (count($this->logBuffer) >= self::MAX_BUFFER_SIZE) {
            $this->flush();
        }

        $this->logBuffer[] = ['type' => $type, 'data' => $data];
    }

    public function flush(): void
    {
        if (empty($this->logBuffer)) {
            return;
        }

        $grouped = [
            'activity_logs'    => [],
            'performance_logs' => [],
            'security_logs'    => [],
            'system_logs'      => []
        ];

        foreach ($this->logBuffer as $item) {
            $tbl = match($item['type']) {
                'activity'    => 'activity_logs',
                'performance' => 'performance_logs',
                'security'    => 'security_logs',
                default       => 'system_logs'
            };
            $grouped[$tbl][] = $item['data'];
        }

        $this->logBuffer = [];

        foreach ($grouped as $table => $rows) {
            if (!empty($rows)) {
                $this->insertMany($table, $rows);
            }
        }
    }

    private function insertMany(string $table, array $rows): void
    {
        if (empty($rows)) return;

        if (!$this->isDatabaseLoggingEnabled()) {
            $this->writeLogFile($table, $rows);
            return;
        }

        $columns = array_keys($rows[0]);
        $escapedColumns = array_map(fn($col) => "`" . str_replace("`", "", $col) . "`", $columns);
        $colList = implode(', ', $escapedColumns);

        $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ";
        $valuesSql = [];
        $params = [];

        foreach ($rows as $row) {
            $placeholders = array_fill(0, count($columns), '?');
            $valuesSql[] = "(" . implode(', ', $placeholders) . ")";
            
            foreach ($columns as $col) {
                $params[] = $row[$col] ?? null;
            }
        }

        $sql .= implode(', ', $valuesSql);

        try {
            $this->db->query($sql, $params);
        } catch (\Throwable $e) {
            // HIGH-09 Fix: Fallback to Redis or local file system if DB insert fails to prevent audit log evasion
            $this->robustFallbackLog($table, $rows, $e->getMessage());
        }
    }

    private function isDatabaseLoggingEnabled(): bool
    {
        return (bool) config('logging.log_to_database', false);
    }

    private function writeLogFile(string $table, array $rows): void
    {
        if (!config('logging.log_to_file', true)) {
            return;
        }

        $file = $this->logDir . $table . '.log';
        $entries = [];

        foreach ($rows as $row) {
            $entries[] = json_encode([
                'table' => $table,
                'entry' => $row,
                'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        @file_put_contents($file, implode("\n", $entries) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function robustFallbackLog(string $table, array $rows, string $errorMessage): void
    {
        $payload = json_encode([
            'table' => $table,
            'error' => $errorMessage,
            'timestamp' => time(),
            'rows' => $rows
        ], JSON_UNESCAPED_UNICODE);

        $saved = false;
        
        // 1. Try Redis first for fast, persistent, and centralized fallback
        try {
            $redis = $this->redis;
            if ($redis && $redis->isAvailable()) {
                $redis->lpush("audit_fallback_{$table}", $payload);
                $saved = true;
            }
        } catch (\Throwable $e) {}

        // 2. Try File System if Redis is unavailable
        if (!$saved) {
            $file = $this->logDir . 'audit_fallback_' . date('Y-m-d') . '.log';
            $entry = sprintf("[%s] DB_FAILURE: %s\n", date('Y-m-d H:i:s'), $payload);
            @file_put_contents($file, $entry, FILE_APPEND);
        }
    }

    public function cleanup(int $days = 90): array
    {
        $this->rotateLogFiles();

        return [
            'activity' => $this->activityLog->deleteOlderThanChunked($days),
            'system' => $this->systemLog->deleteOlderThanChunked($days),
            'security' => $this->securityLog->deleteOlderThanChunked($days),
            'performance' => $this->performanceLog->deleteOlderThanChunked($days),
        ];
    }

    private function rotateLogFiles(): void
    {
        $maxSize = 10 * 1024 * 1024; // 10MB
        $files = glob($this->logDir . '*.log');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file) && filesize($file) > $maxSize) {
                    $info = pathinfo($file);
                    $newName = $info['dirname'] . '/' . $info['filename'] . '_' . date('YmdHis') . '.' . $info['extension'];
                    rename($file, $newName);
                    
                    // Compress in background (Windows compatible)
                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                        pclose(popen('start /B gzip ' . escapeshellarg($newName), 'r'));
                    } else {
                        exec('gzip ' . escapeshellarg($newName) . ' > /dev/null 2>&1 &');
                    }
                }
            }
        }

        // Delete old archives
        $gzFiles = glob($this->logDir . '*.gz');
        $cutoff = time() - ($this->retentionDays * 86400);
        if ($gzFiles) {
            foreach ($gzFiles as $gz) {
                if (is_file($gz) && filemtime($gz) < $cutoff) {
                    @unlink($gz);
                }
            }
        }
    }
}

