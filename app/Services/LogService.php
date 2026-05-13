<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PerformanceLog;
use App\Models\SecurityLog;
use App\Models\SystemLog;
use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * LogService — مغز سیستم لاگینگ
 * 
 * وظایف:
 * - Validation و Sanitization
 * - Sensitive Data Masking
 * - Context Enrichment (IP, User Agent, Request ID)
 * - Write/Read Orchestration
 * - Retention و Query Management
 * 
 * این سرویس هیچ‌وقت مستقیم صدا زده نمی‌شود
 * همه کال‌ها از طریق Core\Logger می‌آیند
 */
class LogService extends BaseService
{
    private Database $db;
    private ActivityLog $activityLog;
    private SystemLog $systemLog;
    private SecurityLog $securityLog;
    private PerformanceLog $performanceLog;
    private array $logBuffer = []; // M24 Fix: بافر مرکزی موقت جهت ذخیره‌سازی انباشته لاگ‌ها
    
    // PSR-3 Level Mapping
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

    // Sensitive Fields - Full Masking
    private const SENSITIVE_FULL = [
        'password', 'pass', 'secret', 'token', 'api_key', 'api_secret',
        'private_key', 'auth_token', 'access_token', 'refresh_token',
        'remember_token', 'csrf_token', 'two_factor_code', 'otp',
        'verification_code', 'reset_token', 'session_id',
    ];

    // Sensitive Fields - Partial Masking
    private const SENSITIVE_PARTIAL = [
        'card_number', 'bank_card', 'account_number', 'sheba', 'iban',
        'national_id', 'national_code', 'phone', 'mobile', 'email',
        'wallet_address', 'crypto_address',
    ];

    // Log Types
    public const TYPE_SYSTEM      = 'system';
    public const TYPE_ACTIVITY    = 'activity';
    public const TYPE_SECURITY    = 'security';
    public const TYPE_PERFORMANCE = 'performance';

    private string $logDir;
    private int $maxContextSize = 5000;
    private int $retentionDays  = 90;

    public function __construct(
        Database $db,
        ActivityLog $activityLog,
        SystemLog $systemLog,
        SecurityLog $securityLog,
        PerformanceLog $performanceLog,
        ?LoggerInterface $logger = null
    ) {
        $realLogger = $logger ?? new class implements LoggerInterface {
            public function emergency(string $message, array $context = []): void {}
            public function alert(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
            public function log(string $level, string $message, array $context = []): void {}
        };
        parent::__construct($realLogger);
        $this->db = $db;
        $this->activityLog = $activityLog;
        $this->systemLog = $systemLog;
        $this->securityLog = $securityLog;
        $this->performanceLog = $performanceLog;
        $this->logDir = dirname(__DIR__, 2) . '/storage/logs/';

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
        
        // M24 Fix: اتصال هوشمند رویداد Shutdown جهت تخلیه خودکار لاگ‌ها به دیتابیس در انتهای ریکوئست
        register_shutdown_function([$this, 'flush']);
    }

    /**
     * ثبت لاگ سیستمی (file + database)
     */
    public function system(string $level, string $message, array $context = [], ?int $userId = null): void
    {
        $this->write(self::TYPE_SYSTEM, $level, $message, $context, $userId);
    }

    public function activity(string $action, string $description, ?int $userId = null, array $metadata = []): void
    {
        $enriched = $this->enrichContext($metadata, $userId);
        $masked = $this->maskSensitiveData($enriched);
        
        // Limit metadata size to prevent memory exhaustion
        $metadataJson = json_encode($masked, JSON_UNESCAPED_UNICODE);
        if (strlen($metadataJson) > $this->maxContextSize) {
            $metadataJson = json_encode(['truncated' => true, 'original_size' => strlen($metadataJson)], JSON_UNESCAPED_UNICODE);
        }
        
        // M24 Fix: بافر کردن اطلاعات فعالیت بجای درج مستقیم سنگین دیتابیس
        $this->bufferLog('activity', [
            'user_id'     => $userId,
            'action'      => $this->sanitizeString($action, 100),
            'description' => $this->sanitizeString($description, 500),
            'metadata'    => $metadataJson,
            'ip_address'  => $enriched['ip'] ?? null,
            'user_agent'  => $enriched['user_agent'] ?? null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * ثبت Security Event
     */
    public function security(string $level, string $message, array $context = [], ?int $userId = null): void
    {
        $this->write(self::TYPE_SECURITY, $level, $message, $context, $userId, true);
    }

    public function performance(string $metric, float $value, array $context = []): void
    {
        $enriched = $this->enrichContext($context);
        $enriched['metric'] = $metric;
        $enriched['value'] = $value;

        try {
            // Limit context size to prevent memory exhaustion
            $contextJson = json_encode($enriched, JSON_UNESCAPED_UNICODE);
            if (strlen($contextJson) > $this->maxContextSize) {
                $contextJson = json_encode(['truncated' => true, 'original_size' => strlen($contextJson)], JSON_UNESCAPED_UNICODE);
            }

            // M24 Fix: بافر کردن مقادیر Performance
            $this->bufferLog('performance', [
                'metric'     => $this->sanitizeString($metric, 100),
                'value'      => $value,
                'context'    => $contextJson,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
    $this->writeToFile(self::TYPE_SYSTEM, 'ERROR', 'log_service.performance.failed', [
        'channel' => 'performance',
        'metric' => $metric,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}
    }

    /**
     * کوئری لاگ‌ها با فیلتر پیشرفته
     */
    public function query(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $type = $filters['type'] ?? self::TYPE_ACTIVITY;
        
        switch ($type) {
            case self::TYPE_ACTIVITY:
                return $this->queryActivityLogs($filters, $page, $perPage);
            case self::TYPE_SECURITY:
                return $this->querySecurityLogs($filters, $page, $perPage);
            default:
                return ['rows' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage];
        }
    }

    /**
     * پاک‌سازی لاگ‌های قدیمی
     */
 public function cleanup(int $days = null): array
{
    $days = $days ?? $this->retentionDays;
    $results = [
    'activity_logs' => 0,
    'system_logs' => 0,
    'security_logs' => 0,
    'performance_logs' => 0,
    'log_files' => 'cleaned',
];

    try {
		// Performance logs - chunked delete
		$results['performance_logs'] = $this->performanceLog->deleteOlderThanChunked($days);

        // Activity logs - chunked delete
        $results['activity_logs'] = $this->activityLog->deleteOlderThanChunked($days);

        // System logs - chunked delete
        $results['system_logs'] = $this->systemLog->deleteOlderThanChunked($days);

        // Security logs - chunked delete
        $results['security_logs'] = $this->securityLog->deleteOlderThanChunked($days);

        // پاکسازی فایل‌های لاگ روی دیسک
        $this->cleanupLogFiles($days);

    } catch (\Throwable $e) {
        $this->fallbackLog('log_service.cleanup.failed', [
            'channel' => 'system',
            'days' => $days,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }

    return $results;
}

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * نوشتن لاگ (orchestration)
     */
    private function write(
        string $type,
        string $level,
        string $message,
        array $context,
        ?int $userId,
        bool $forceDb = false
    ): void {
        $level = strtolower($level);
        $normalizedLevel = self::LEVEL_MAP[$level] ?? 'INFO';
        
        $enriched = $this->enrichContext($context, $userId);
        $sanitized = $this->maskSensitiveData($enriched);
        
        // File Log (همیشه)
        $this->writeToFile($type, $normalizedLevel, $message, $sanitized);
        
        // Database Log (فقط مهم‌ها یا force)
        if ($forceDb || in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning'])) {
            $this->writeToDatabase($type, $normalizedLevel, $message, $sanitized, $userId);
        }
    }

    /**
     * نوشتن به فایل
     */
    private function writeToFile(string $type, string $level, string $message, array $context): void
    {
        try {
            $file = $this->logDir . $type . '_' . date('Y-m-d') . '.log';
            $timestamp = date('Y-m-d H:i:s');
            
            // Limit message size to prevent disk exhaustion
            $message = $this->sanitizeString($message, 2000);
            
            // Limit context size to prevent memory exhaustion
            $contextStr = '';
            if (!empty($context)) {
                $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (strlen($contextJson) > $this->maxContextSize) {
                    $contextJson = json_encode(['truncated' => true, 'original_size' => strlen($contextJson)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $contextStr = ' | ' . $contextJson;
            }
            
            $line = "[{$timestamp}] [{$level}] [{$type}] {$message}{$contextStr}" . PHP_EOL;
            file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            $this->fallbackLog('log_service.write_to_file.failed', ['error' => $e->getMessage()]);
        }
    }

    private function writeToDatabase(string $type, string $level, string $message, array $context, ?int $userId): void
    {
        try {
            // Limit context size to prevent memory exhaustion
            $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE);
            if (strlen($contextJson) > $this->maxContextSize) {
                $contextJson = json_encode(['truncated' => true, 'original_size' => strlen($contextJson)], JSON_UNESCAPED_UNICODE);
            }

            $data = [
                'level' => $level,
                'type' => $type,
                'message' => $this->sanitizeString($message, 1000),
                'context' => $contextJson,
                'user_id' => $userId,
                'ip_address' => $context['ip'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
            ];

            // M24 Fix: هدایت لاگ امنیتی/سیستمی به صف فله‌ای
            $data['created_at'] = date('Y-m-d H:i:s');
            $this->bufferLog($type === self::TYPE_SECURITY ? 'security' : 'system', $data);
        } catch (\Throwable $e) {
            $this->fallbackLog('log_service.write_to_database.failed', [
    'error' => $e->getMessage(),
    'exception' => get_class($e),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
]);
		}
    }

    /**
     * غنی‌سازی Context
     */
    private function enrichContext(array $context, ?int $userId = null): array
    {
        $enriched = $context;
        
        // User ID
        if ($userId !== null) {
            $enriched['user_id'] = $userId;
        } elseif (!isset($enriched['user_id'])) {
            $enriched['user_id'] = $this->getCurrentUserId();
        }
        
        // IP Address
        if (!isset($enriched['ip'])) {
            $enriched['ip'] = $this->getClientIp();
        }
        
        // User Agent
        if (!isset($enriched['user_agent'])) {
            $enriched['user_agent'] = $this->sanitizeString($_SERVER['HTTP_USER_AGENT'] ?? '', 500);
        }
        
        // Request ID (for tracing)
        if (!isset($enriched['request_id'])) {
            $enriched['request_id'] = $this->getRequestId();
        }
        
        // Timestamp
        if (!isset($enriched['timestamp'])) {
            $enriched['timestamp'] = date('Y-m-d H:i:s');
        }
        
        return $enriched;
    }

    /**
     * Mask کردن داده‌های حساس
     */
    private function maskSensitiveData(array $data, int $depth = 0): array
    {
        if ($depth > 3) {
            return ['truncated' => true];
        }

        $masked = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            
            if (is_array($value)) {
                $masked[$key] = $this->maskSensitiveData($value, $depth + 1);
                continue;
            }
            
            // Full Masking
            foreach (self::SENSITIVE_FULL as $field) {
                if (str_contains($lowerKey, $field)) {
                    $masked[$key] = '[REDACTED]';
                    continue 2;
                }
            }
            
            // Partial Masking
            foreach (self::SENSITIVE_PARTIAL as $field) {
                if (str_contains($lowerKey, $field) && is_string($value) && strlen($value) > 4) {
                    $masked[$key] = str_repeat('*', max(0, strlen($value) - 4)) . substr($value, -4);
                    continue 2;
                }
            }
            
            $masked[$key] = $value;
        }
        
        return $masked;
    }

    /**
     * Sanitize String
     */
    private function sanitizeString(string $str, int $maxLength): string
    {
        return mb_substr(trim($str), 0, $maxLength);
    }

    /**
     * دریافت User ID جاری
     */
    private function getCurrentUserId(): ?int
    {
        try {
            $session = \Core\Session::getInstance();
            return $session->get('user_id') ? (int) $session->get('user_id') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * دریافت IP کلاینت
     */
    private function getClientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        
        // Check for proxy
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    /**
     * دریافت/ایجاد Request ID
     */
    private function getRequestId(): string
    {
        static $requestId = null;
        
        if ($requestId === null) {
            $requestId = $_SERVER['HTTP_X_REQUEST_ID'] 
                ?? ($_SERVER['REQUEST_ID'] ?? bin2hex(random_bytes(8)));
        }
        
        return $requestId;
    }

    /**
     * پاک‌سازی فایل‌های لاگ قدیمی
     */
    private function cleanupLogFiles(int $days): void
    {
        try {
            // 1. Rotate single large log files that aren't dated automatically
            $staticLogs = ['_exception_fallback.log', '_fallback.log'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            foreach ($staticLogs as $staticLog) {
                $filePath = $this->logDir . $staticLog;
                if (file_exists($filePath) && filesize($filePath) > $maxSize) {
                    // Rename to include date so glob picks it up and rotates it
                    $rotatedName = $this->logDir . pathinfo($staticLog, PATHINFO_FILENAME) . '_' . date('Y-m-d_His') . '.log';
                    @rename($filePath, $rotatedName);
                }
            }

            // 2. General cleanup of older log files
            $cutoff = strtotime("-{$days} days");
            $files = glob($this->logDir . '*.log');
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoff) {
                    @unlink($file);
                }
            }
        } catch (\Throwable $e) {
            $this->fallbackLog('log_service.cleanup_log_files.failed', ['error' => $e->getMessage()]);
        }
    }
	
	private function fallbackLog(string $event, array $context = [], string $level = 'ERROR'): void
{
    try {
        if (!isset($this->logDir) || !is_dir($this->logDir)) {
            $this->logDir = dirname(__DIR__, 2) . '/storage/logs/';
            @mkdir($this->logDir, 0755, true);
        }

        $line = '[' . date('Y-m-d H:i:s') . ']'
            . ' [' . strtoupper($level) . '] '
            . $event . ' '
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . PHP_EOL;

        @file_put_contents($this->logDir . '_fallback.log', $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
        // جلوگیری از recursion در fallback
    }
}

    /**
     * کوئری Activity Logs
     */
    private function queryActivityLogs(array $filters, int $page, int $perPage): array
    {
        return $this->activityLog->getPaginated(
            $page,
            $perPage,
            $filters['user_id'] ?? null,
            $filters['action'] ?? null,
            $filters['search'] ?? null,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null
        );
    }

    
    /**
     * کوئری Security Logs
     */
    private function querySecurityLogs(array $filters, int $page, int $perPage): array
    {
        try {
            return $this->securityLog->getPaginated($filters, $page, $perPage);
        } catch (\Throwable $e) {
            $this->fallbackLog('log_service.query_security_logs.failed', ['error' => $e->getMessage()]);
            return ['rows' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage, 'totalPages' => 0];
        }
    }

    /**
     * M24 Fix: درج اطلاعات در بافر موقت حافظه
     */
    private function bufferLog(string $type, array $data): void
    {
        $this->logBuffer[] = ['type' => $type, 'data' => $data];
        
        // فلاش کردن زودهنگام جهت مسدود کردن انباشت رم در سرویس‌های دائمی (CLI Daemons)
        if (count($this->logBuffer) >= 25) {
            $this->flush();
        }
    }

    /**
     * M24 Fix: ذخیره‌سازی هوشمند و اتمیک گروهی لاگ‌ها
     */
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

        // پاکسازی بافر بلافاصله برای مهار هرگونه خطای چرخه‌ای
        $this->logBuffer = [];

        foreach ($grouped as $table => $rows) {
            if (!empty($rows)) {
                $this->insertMany($table, $rows);
            }
        }
    }

    /**
     * M24 Fix: درج چندسطری فله‌ای (Bulk Multi-row Insert) در دیتابیس با یک کوئری خام و پرسرعت
     */
    private function insertMany(string $table, array $rows): void
    {
        if (empty($rows)) return;

        // استخراج تمام نام ستون‌ها بر مبنای رکورد نخست
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
            $this->fallbackLog('log_service.bulk_insert_many.failed', [
                'table' => $table,
                'count' => count($rows),
                'error' => $e->getMessage()
            ]);
        }
    }
}
