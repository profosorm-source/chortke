<?php

declare(strict_types=1);

namespace Core;

use \PDO;
use \PDOException;
/**
 * Database Connection (Singleton)
 * 
 * مدیریت اتصال به دیتابیس با PDO
 */
class Database
{
    private static $instance = null;
    private $pdo;
    private $pdoRead; // Read Replica Connection
	private static int $queryDepth = 0;
    private static bool $fallbackLogging = false;
	private static ?array $lastSqlErrorContext = null;
    private ?\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor $sentryMonitor = null; // M3 Fix: کش کلاینت مانیتورینگ جهت افزایش پرفورمنس کوئری‌ها
    private int $transactionLevel = 0; // H24 Fix: شمارنده پشته تراکنش‌ها جهت جلوگیری از Partial Commit در معماری تودرتو
    private bool $isRollbackOnly = false; // Prevents silent commit of corrupted nested transactions

    private int $lastPingTime = 0;
    private const PING_INTERVAL = 60; // 60 seconds
    private array $config;

    /**
     * Constructor (Private)
     * M4 Fix: رفع منقضی شدن PHP 8.1+ با تبدیل به تایپ نال‌پذیر
     */
    private function __construct(?array $dbConfig = null)
    {
        $this->config = $dbConfig ?? config('database');
    }

    private function reconnect(): void
    {
        $this->pdo = $this->createPdoConnection($this->config);
        
        // Setup Read Replica if configured (supports array of hosts for load balancing)
        if (!empty($this->config['read'])) {
            $readConfig = array_merge($this->config, $this->config['read']);
            if (is_array($readConfig['host'])) {
                $readConfig['host'] = $readConfig['host'][array_rand($readConfig['host'])];
            }
            $this->pdoRead = $this->createPdoConnection($readConfig);
        } else {
            $this->pdoRead = $this->pdo; // Fallback to master
        }
    }

    private function createPdoConnection(array $config): \PDO
    {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset={$config['charset']};connect_timeout=2";
        
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ, // ✅ Object به جای Array
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 2, // ✅ Strict timeout to protect against Time-Based SQLi DoS
        ];

        if (defined('\PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$config['charset']} COLLATE utf8mb4_unicode_ci";
        }
        if (defined('\PDO::MYSQL_ATTR_READ_TIMEOUT')) {
            $options[\PDO::MYSQL_ATTR_READ_TIMEOUT] = 3; // ✅ Query Timeout Manager (Read)
        }
        if (defined('\PDO::MYSQL_ATTR_WRITE_TIMEOUT')) {
            $options[\PDO::MYSQL_ATTR_WRITE_TIMEOUT] = 3; // ✅ Query Timeout Manager (Write)
        }
        
        return new \PDO($dsn, $config['user'], $config['pass'], $options);
    }

    public function ensureConnected(): void
    {
        if ($this->pdo === null || time() - $this->lastPingTime > self::PING_INTERVAL) {
            try {
                if ($this->pdo) {
                    $this->pdo->query('SELECT 1');
                } else {
                    $this->reconnect();
                }
                $this->lastPingTime = time();
            } catch (\PDOException $e) {
                try {
                    $this->reconnect();
                    $this->lastPingTime = time();
                } catch (\Throwable $ex) {
                    throw new \RuntimeException("Database reconnection failed: " . $ex->getMessage(), (int)$ex->getCode(), $ex);
                }
            }
        }
    }

    public function setSentryMonitor(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor $monitor): void
    {
        $this->sentryMonitor = $monitor;
    }


private function normalizeSql(string $sql): string
{
    $sql = preg_replace('/\s+/', ' ', $sql);
    return trim($sql);
}

private function buildSqlErrorContext(string $sql, array $params, \Throwable $e): array
{
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);

    $originFile = null;
    $originLine = null;
    $stack = [];

    foreach ($trace as $t) {
        $cls = $t['class'] ?? null;
        $fn  = $t['function'] ?? null;
        if ($fn) {
            $stack[] = ($cls ? $cls . '->' : '') . $fn . '()';
        }

        $file = $t['file'] ?? null;
        if ($file) {
            $normalized = str_replace('\\', '/', $file);
            if (!str_contains($normalized, '/core/Database.php') && $originFile === null) {
                $originFile = $file;
                $originLine = $t['line'] ?? null;
            }
        }
    }

    $unknownColumn = null;
    if (preg_match("/Unknown column '([^']+)'/i", $e->getMessage(), $m)) {
        $unknownColumn = $m[1];
    }

    $tables = [];
    $patterns = [
        '/\bfrom\s+([`a-zA-Z0-9_\.]+)/i',
        '/\bjoin\s+([`a-zA-Z0-9_\.]+)/i',
        '/\bupdate\s+([`a-zA-Z0-9_\.]+)/i',
        '/\binsert\s+into\s+([`a-zA-Z0-9_\.]+)/i',
        '/\bdelete\s+from\s+([`a-zA-Z0-9_\.]+)/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match_all($p, $sql, $mm)) {
            foreach ($mm[1] as $t) {
                $tables[] = trim($t, '`');
            }
        }
    }

    $context = [
        'error' => $e->getMessage(),
        'error_type' => get_class($e),
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
    ];

    if (config('app.env') !== 'production') {
        $context['sql'] = mb_substr($sql, 0, 1500);
        $context['params_count'] = count($params);
        $context['file'] = $originFile;
        $context['line'] = $originLine;
        $context['stack'] = array_slice($stack, 0, 10);
        $context['tables'] = array_values(array_unique($tables));
        $context['unknown_column'] = $unknownColumn;
        $context['method'] = $_SERVER['REQUEST_METHOD'] ?? null;
        $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
        $context['user_id'] = function_exists('user_id') ? (user_id() ?: null) : null;
    } else {
        $context['sql_hash'] = hash('sha256', $sql);
        $context['error_code'] = $e->getCode();
    }

    return $context;
}

private static function fallbackLog(string $event, array $context = []): void
{
    static $inProgress = [];
    $key = md5($event . ':' . json_encode($context));
    if (isset($inProgress[$key])) {
        return;
    }

    $inProgress[$key] = true;
    try {
        $payload = [
            'timestamp' => date('c'),
            'event' => $event,
            'context' => $context,
        ];

        // لاگ متنی مخصوص DB (همان قبلی)
        @file_put_contents(
            __DIR__ . '/../storage/logs/_db_fallback.log',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        // ارسال همزمان به logger اصلی پروژه (برای system log)
        try {
            if (function_exists('logger')) {
                $logger = logger();
                if ($logger && method_exists($logger, 'error')) {
                    $logger->error($event, $context);
                }
            }
        } catch (\Throwable $ignore) {
            // no-op
        }
    } finally {
        unset($inProgress[$key]);
    }
}
public static function getLastSqlErrorContext(): ?array
{
    return self::$lastSqlErrorContext;
}

private static function recordSqlFailure(string $event, array $context): void
{
    self::$lastSqlErrorContext = $context;
    self::fallbackLog($event, $context);
}

    /**
     * دریافت Instance (Singleton)
     */
    public static function getInstance(array $dbConfig = null)
    {
        if (self::$instance === null) {
            self::$instance = new self($dbConfig);
        } elseif ($dbConfig !== null && self::$instance->config !== $dbConfig) {
            self::$instance = new self($dbConfig);
        }
        
        return self::$instance;
    }

    /**
     * ریست کردن اتصال پایگاه داده
     * برای استفاده در محیط تست یا چرخه‌های طولانی دمون با پیکربندی‌های مختلف
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
	
public function prepare(string $sql): \PDOStatement
{
    $this->ensureConnected();
    try {
        $isRead = stripos(ltrim($sql), 'SELECT') === 0;
        $useMaster = !$isRead || $this->inTransaction();
        $pdo = $useMaster ? $this->pdo : ($this->pdoRead ?? $this->pdo);
        return $pdo->prepare($sql);
    } catch (\Throwable $e) {
        self::recordSqlFailure('database.prepare.failed', $this->buildSqlErrorContext($sql, [], $e));
        throw $e;
    }
}

    /**
     * دریافت PDO
     */
    public function getPdo()
    {
        $this->ensureConnected();
        return $this->pdo;
    }

    /**
     * دریافت Query Builder
     */
    public function table(string $table): QueryBuilder
    {
        $this->ensureConnected();
        return (new QueryBuilder($this->pdo))->table($table);
    }
	
	
	
public function fetch(string $sql, array $params = []): ?object
{
    $stmt = $this->executeStatement($sql, $params, 'database.fetch.failed', true);
    $row = $stmt->fetch(\PDO::FETCH_OBJ);
    return $row ?: null;
}

public function fetchAll(string $sql, array $params = []): array
{
    $stmt = $this->executeStatement($sql, $params, 'database.fetchAll.failed', true);
    return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
}

public function fetchColumn(string $sql, array $params = [], int $column = 0)
{
    $stmt = $this->executeStatement($sql, $params, 'database.fetchColumn.failed', true);
    return $stmt->fetchColumn($column);
}

    /**
     * اجرای مرکزی دستورات دیتابیس با مدیریت هوشمند ریکرژن، لاگ و استثناها
     */
    private function executeStatement(string $sql, array $params, string $failureEvent, bool $isRead = false): \PDOStatement
    {
        $this->ensureConnected();

        self::$queryDepth++;
        if (self::$queryDepth > 100) {
            self::$queryDepth--;
            throw new \RuntimeException('Database recursion guard triggered');
        }

        $sql = $this->normalizeSql($sql);
        $startTime = microtime(true);

        try {
            $useMaster = !$isRead || $this->inTransaction();
            $pdo = $useMaster ? $this->pdo : ($this->pdoRead ?? $this->pdo);

            $stmt = $pdo->prepare($sql);

            foreach ($params as $key => $value) {
                $param = is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':');

                $type = \PDO::PARAM_STR;
                if (is_int($value))        $type = \PDO::PARAM_INT;
                elseif (is_bool($value))   $type = \PDO::PARAM_BOOL;
                elseif ($value === null)   $type = \PDO::PARAM_NULL;

                $stmt->bindValue($param, $value, $type);
            }

            $stmt->execute();

            // مانیتورینگ کوئری‌های کند
            $duration = microtime(true) - $startTime;
            if ($duration > 0.1) {
                $this->logSlowQuery($sql, $params, $duration);
            }

            return $stmt;
        } catch (\PDOException $e) {
            // Check if connection was lost and queryDepth is low to retry safely
            $message = $e->getMessage();
            $lostConnection = false;
            $lostKeywords = ['gone away', 'lost connection', 'refused', 'timeout', 'deadlock', 'packets out of order'];
            foreach ($lostKeywords as $kw) {
                if (stripos($message, $kw) !== false) {
                    $lostConnection = true;
                    break;
                }
            }

            if ($lostConnection && self::$queryDepth <= 1) {
                try {
                    $this->reconnect();
                    $this->lastPingTime = time();
                    
                    // Retry once
                    $useMaster = !$isRead || $this->inTransaction();
                    $pdo = $useMaster ? $this->pdo : ($this->pdoRead ?? $this->pdo);

                    $stmt = $pdo->prepare($sql);
                    foreach ($params as $key => $value) {
                        $param = is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':');
                        $type = \PDO::PARAM_STR;
                        if (is_int($value))        $type = \PDO::PARAM_INT;
                        elseif (is_bool($value))   $type = \PDO::PARAM_BOOL;
                        elseif ($value === null)   $type = \PDO::PARAM_NULL;
                        $stmt->bindValue($param, $value, $type);
                    }
                    $stmt->execute();
                    return $stmt;
                } catch (\Throwable $retryEx) {
                    // Fall through to regular logging & exception
                }
            }

            $ctx = $this->buildSqlErrorContext($sql, $params, $e);
            self::$lastSqlErrorContext = $ctx;
            self::fallbackLog($failureEvent, $ctx);

            // ارسال خودکار تمام خطاهای دیتابیسی (از کوئری، فچ و غیره) به سیستم مانیتورینگ
            $this->logQueryErrorToSentry($sql, $params, $e);

            // If unique constraint violation occurs during HTTP request handling, translate it to a user-friendly ValidationException
            if (PHP_SAPI !== 'cli' && ((string)$e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), '1062'))) {
                $fieldName = 'record';
                $message = $e->getMessage();
                
                if (preg_match("/key '.*?_([a-zA-Z0-9_]+)_unique'/i", $message, $matches)) {
                    $fieldName = $matches[1];
                } elseif (preg_match("/key '.*?\.(.*?)'/i", $message, $matches)) {
                    $fieldName = $matches[1];
                } elseif (preg_match("/for key '([^']+)'/i", $message, $matches)) {
                    $keyName = $matches[1];
                    $parts = explode('_', $keyName);
                    if (count($parts) > 1) {
                        $fieldName = $parts[count($parts) - 2];
                    } else {
                        $fieldName = $keyName;
                    }
                }
                
                $friendlyFieldNames = [
                    'email' => 'ایمیل',
                    'username' => 'نام کاربری',
                    'mobile' => 'شماره موبایل',
                    'phone' => 'شماره تلفن',
                    'card_number' => 'شماره کارت',
                    'national_code' => 'کد ملی',
                    'slug' => 'شناسه یکتا',
                    'name' => 'نام',
                    'key' => 'کلید همزمانی',
                ];
                
                $friendlyName = $friendlyFieldNames[$fieldName] ?? 'این مقدار';
                $errorMessage = "{$friendlyName} قبلاً در سیستم ثبت شده است و نمی‌تواند تکراری باشد.";
                
                throw new \Core\Exceptions\ValidationException(
                    [$fieldName => [$errorMessage]],
                    "ثبت داده‌های تکراری در سیستم امکان‌پذیر نیست."
                );
            }

            throw $e;
        } finally {
            self::$queryDepth--;
        }
    }

    /**
     * اجرای Query مستقیم
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $isRead = stripos(ltrim($sql), 'SELECT') === 0;
        return $this->executeStatement($sql, $params, 'database.query.failed', $isRead);
    }

private function formatParamValue(mixed $value): string
{
    if ($value === null) return 'NULL';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_int($value) || is_float($value)) return (string)$value;
    return $this->pdo->quote((string)$value);
}

private function interpolateSql(string $sql, array $params): string
{
    if (!$params) {
        return $sql;
    }

    // ── پالایش اطلاعات حساس برای جلوگیری از نشت در لاگ‌ها ──────────────────
    $sensitiveKeys = ['pass', 'password', 'token', 'key', 'card', 'iban', 'national_id', 'secret', 'cvv', 'auth'];
    $redactedParams = [];
    foreach ($params as $key => $val) {
        $isSensitive = false;
        if (!is_numeric($key)) {
            $stringKey = strtolower((string)$key);
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($stringKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }
        }
        $redactedParams[$key] = $isSensitive ? '***[REDACTED]***' : $val;
    }

    $isPositional = array_keys($redactedParams) === range(0, count($redactedParams) - 1);

    if ($isPositional) {
        foreach ($redactedParams as $value) {
            $sql = preg_replace('/\?/', $this->formatParamValue($value), $sql, 1);
        }
    } else {
        foreach ($redactedParams as $key => $value) {
            $name = ltrim((string)$key, ':');
            $sql = preg_replace('/:' . preg_quote($name, '/') . '\b/', $this->formatParamValue($value), $sql);
        }
    }

    // پسا-پالایش: فیلتر جفت‌های کلید/مقدار در عبارات SQL (مانند شرط‌های UPDATE/WHERE)
    $sensitiveKeywords = 'pass|password|token|key|card|iban|national_id|secret|cvv|auth';
    $pattern = '/\b(' . $sensitiveKeywords . ')\b\s*=\s*(\'[^\']*\'|"[^"]*"|\d+)/i';
    $sql = preg_replace($pattern, '$1 = \'***[REDACTED]***\'', $sql);

    return $sql;
}

private function resolveSqlOrigin(array $trace): array
{
    $originFile = null;
    $originLine = null;

    foreach ($trace as $t) {
        $file = $t['file'] ?? null;
        if (!$file) {
            continue;
        }

        $normalized = str_replace('\\', '/', $file);

        // اولین فایل خارج از Core/Database.php که از app/core-idempotency آمده باشد
        if (
            !str_contains($normalized, '/core/Database.php') &&
            (
                str_contains($normalized, '/app/') ||
                str_contains($normalized, '/core/IdempotencyKey.php') ||
                str_contains($normalized, '/cron.php')
            )
        ) {
            $originFile = $file;
            $originLine = $t['line'] ?? null;
            break;
        }
    }

    return [$originFile, $originLine];
}

private function buildAppStack(array $trace, int $limit = 12): array
{
    $stack = [];

    foreach ($trace as $t) {
        $file = $t['file'] ?? '';
        $normalized = str_replace('\\', '/', $file);

        if (
            $file &&
            (
                str_contains($normalized, '/app/') ||
                str_contains($normalized, '/core/IdempotencyKey.php') ||
                str_contains($normalized, '/cron.php')
            )
        ) {
            $cls = $t['class'] ?? '';
            $fn  = $t['function'] ?? '';
            $line = $t['line'] ?? null;
            $stack[] = ($cls ? $cls . '->' : '') . $fn . '()' . ($line ? ':' . $line : '');
        }

        if (count($stack) >= $limit) {
            break;
        }
    }

    return $stack;
}



/**
 * ✅ متد جدید برای دریافت نتایج
 */
public function select(string $sql, array $params = []): array
{
    $stmt = $this->query($sql, $params);
    return $stmt->fetchAll(\PDO::FETCH_OBJ);
}

    /**
     * SELECT یک رکورد
     */
    public function selectOne(string $sql, array $params = [])
{
    $stmt = $this->query($sql, $params);
    $result = $stmt->fetch(\PDO::FETCH_OBJ);
    return $result !== false ? $result : null;
}
/**
 * دریافت آخرین ID درج شده
 */
public function lastInsertId(): int
{
    $this->ensureConnected();
    return (int)$this->pdo->lastInsertId();
}
    /**
     * INSERT
     */
    public function insert($sql, $params = [])
    {
        $this->query($sql, $params);
        return $this->pdo->lastInsertId();
    }

    /**
     * UPDATE/DELETE
     */
    public function execute($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * شروع Transaction
     * H24 Upgrade: پشتیبانی هوشمند از تراکنش‌های تو در تو (Nested Transactions)
     */
    public function beginTransaction(): void
    {
        $this->ensureConnected();
        if ($this->transactionLevel === 0) {
            try {
                $this->pdo->beginTransaction();
                $this->isRollbackOnly = false; // Reset on new root transaction
            } catch (\Throwable $e) {
                $this->transactionLevel = 0;
                throw new \RuntimeException("PDO BeginTransaction failed: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        } else {
            // H24 Fix: Create a database SAVEPOINT for nested transactions
            try {
                $this->pdo->exec("SAVEPOINT trans_" . $this->transactionLevel);
            } catch (\Throwable $e) {
                throw new \RuntimeException("PDO SAVEPOINT creation failed: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }
        $this->transactionLevel++;
    }

    /**
     * Commit
     * H24 Upgrade: فقط زمانی به دیتابیس اعمال می‌شود که بالاترین سطح تراکنش خاتمه یابد
     */
    public function commit(): void
    {
        if ($this->transactionLevel <= 0) {
            $this->transactionLevel = 0;
            throw new \RuntimeException('No active transaction to commit');
        }

        if ($this->isRollbackOnly) {
            // Force a rollback of the entire transaction
            $this->transactionLevel = 0;
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->isRollbackOnly = false;
            throw new \RuntimeException('Cannot commit transaction: nested rollback occurred');
        }

        try {
            if ($this->transactionLevel === 1) {
                if (!$this->pdo->commit()) {
                    throw new \RuntimeException('PDO Commit returned false');
                }
            } else {
                // Nested commit: Release the savepoint
                try {
                    $this->pdo->exec("RELEASE SAVEPOINT trans_" . ($this->transactionLevel - 1));
                } catch (\Throwable $e) {
                    // Fallback for database engines that do not support RELEASE SAVEPOINT (e.g. sqlite/mssql, though MySQL supports it)
                }
            }
        } finally {
            $this->transactionLevel = max(0, $this->transactionLevel - 1);
        }
    }

    /**
     * Rollback
     * H24 Upgrade: هر کجای زنجیره رخ دهد، به سطح تراکنش مربوطه بازنشانی می‌شود
     */
    public function rollback(): void
    {
        if ($this->transactionLevel <= 0) {
            $this->transactionLevel = 0;
            return;
        }

        try {
            if ($this->transactionLevel === 1) {
                $this->isRollbackOnly = false;
                if ($this->pdo->inTransaction()) {
                    if (!$this->pdo->rollBack()) {
                         throw new \RuntimeException('PDO Rollback returned false');
                    }
                }
            } else {
                // Nested rollback: Rollback to the savepoint and mark transaction as rollback-only
                $this->isRollbackOnly = true;
                if ($this->pdo->inTransaction()) {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT trans_" . ($this->transactionLevel - 1));
                }
            }
        } catch (\Throwable $e) {
            $this->transactionLevel = 0;
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new \RuntimeException("PDO Rollback failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        } finally {
            $this->transactionLevel = max(0, $this->transactionLevel - 1);
        }
    }

    /**
     * بررسی فعال بودن تراکنش
     */
    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0 || $this->pdo->inTransaction();
    }

    /**
     * M3 Fix: حل‌کننده هوشمند و دارای کش کلاینت سنتری
     */
    private function getSentryMonitor(): ?\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor
    {
        return $this->sentryMonitor;
    }

    private function logSlowQuery(string $sql, array $params, float $duration): void
    {
        try {
            // M3 Fix: استفاده از کلاینت مانیتور کش شده به جای حل مجدد و سنگین در هر ریکوئست
            $sentry = $this->getSentryMonitor();
            if ($sentry) {
                $interpolatedSql = $this->interpolateSql($sql, $params);
                $sentry->captureMessage(
                    "Slow query detected: " . mb_substr($interpolatedSql, 0, 200),
                    'warning',
                    null,
                    [
                        'sql' => $sql,
                        'params_count' => count($params),
                        'duration_seconds' => $duration,
                        'interpolated_sql' => mb_substr($interpolatedSql, 0, 1000),
                        'backtrace' => array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10), 2)
                    ]
                );
            }
        } catch (\Throwable $ignore) {
            // کاملاً فیل‌سیف
        }
    }

    private function logQueryErrorToSentry(string $sql, array $params, \Throwable $e): void
    {
        try {
            // M3 Fix: بازیابی کلاینت مانیتور از کش داخلی دیتابیس
            $sentry = $this->getSentryMonitor();
            if ($sentry) {
                $interpolatedSql = $this->interpolateSql($sql, $params);
                $sentry->captureException(
                    $e,
                    null,
                    [
                        'sql' => $sql,
                        'params_count' => count($params),
                        'interpolated_sql' => mb_substr($interpolatedSql, 0, 1000),
                    ],
                    'error'
                );
            }
        } catch (\Throwable $ignore) {
            // کاملاً فیل‌سیف
        }
    }

    /**
     * جلوگیری از Clone
     */
    private function __clone() {}

    /**
     * جلوگیری از Unserialize
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}