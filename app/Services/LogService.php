<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PerformanceLog;
use App\Models\SecurityLog;
use App\Models\SystemLog;
use Core\Database;
use App\Contracts\LoggerInterface;
use Core\Container;

/**
 * LogService — مغز سیستم لاگینگ
 */
class LogService extends BaseService
{
    private Database $db;
    private ActivityLog $activityLog;
    private SystemLog $systemLog;
    private SecurityLog $securityLog;
    private PerformanceLog $performanceLog;
    private \Core\Session $session;
    private \Core\Redis $redis;
    private string $requestId;
    private array $logBuffer = [];
    private const MAX_BUFFER_SIZE = 100;
    
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
    private int $maxContextSize = 5000;
    private int $retentionDays  = 90;

    public function __construct(
        Database $db,
        ActivityLog $activityLog,
        SystemLog $systemLog,
        SecurityLog $securityLog,
        PerformanceLog $performanceLog,
        \Core\Session $session,
        \Core\Redis $redis
    ) {
        // LogService dummy logger to prevent recursion
        parent::__construct(new class implements LoggerInterface {
            public function emergency(string $message, array $context = []): void {}
            public function alert(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
            public function log(string $level, string $message, array $context = []): void {}
        });

        $this->db = $db;
        $this->activityLog = $activityLog;
        $this->systemLog = $systemLog;
        $this->securityLog = $securityLog;
        $this->performanceLog = $performanceLog;
        $this->session = $session;
        $this->redis = $redis;
        
        $this->logDir = dirname(__DIR__, 2) . '/storage/logs/';
        $this->requestId = $_SERVER['REQUEST_ID'] ?? bin2hex(random_bytes(16));

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
        
        register_shutdown_function([$this, 'flush']);
    }

    public function logActivity(string $action, string $description, ?int $userId = null, array $context = [], string $channel = 'default'): void
    {
        $data = [
            'request_id' => $this->requestId,
            'user_id' => $userId ?? $this->session->get('user_id'),
            'channel' => $channel,
            'action' => $action,
            'description' => $description,
            'context' => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->addToBuffer('activity', $data);

        // Record to AuditTrail lazily to break circular dependency
        try {
            $auditTrail = Container::getInstance()->make(AuditTrail::class);
            $auditTrail->record($action, $userId, $context);
        } catch (\Throwable $e) {
            // Silently fail to avoid breaking the request
        }
    }

    public function logSystem(string $level, string $message, array $context = []): void
    {
        $data = [
            'request_id' => $this->requestId,
            'level' => self::LEVEL_MAP[strtolower($level)] ?? 'INFO',
            'type' => $context['type'] ?? 'system',
            'message' => $message,
            'context' => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            'user_id' => $this->session->get('user_id'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->addToBuffer('system', $data);
    }

    public function logSecurity(string $event, string $message, string $level = 'WARNING', array $context = []): void
    {
        $data = [
            'request_id' => $this->requestId,
            'level' => $level,
            'type' => $event,
            'message' => $message,
            'context' => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            'user_id' => $this->session->get('user_id'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->addToBuffer('security', $data);
    }

    public function logPerformance(string $metric, $value, array $context = []): void
    {
        $data = [
            'request_id' => $this->requestId,
            'metric' => $metric,
            'value' => (float)$value,
            'context' => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
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
        return [
            'activity' => $this->activityLog->deleteOlderThanChunked($days),
            'system' => $this->systemLog->deleteOlderThanChunked($days),
            'security' => $this->securityLog->deleteOlderThanChunked($days),
            'performance' => $this->performanceLog->deleteOlderThanChunked($days),
        ];
    }
}
