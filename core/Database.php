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
    private $queryBuilder;
	private static int $queryDepth = 0;
    private static bool $fallbackLogging = false;
	private static ?array $lastSqlErrorContext = null;
    private ?\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor $sentryMonitor = null; // M3 Fix: کش کلاینت مانیتورینگ جهت افزایش پرفورمنس کوئری‌ها

    /**
     * Constructor (Private)
     * M4 Fix: رفع منقضی شدن PHP 8.1+ با تبدیل به تایپ نال‌پذیر
     */
    private function __construct(?array $dbConfig = null)
    {
        $config = $dbConfig ?? config('database');
        
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset={$config['charset']}";
        
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ, // ✅ Object به جای Array
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['charset']} COLLATE utf8mb4_unicode_ci"
        ];
        
        try {
            $this->pdo = new \PDO($dsn, $config['user'], $config['pass'], $options);
        } catch (\PDOException $e) {
            // M5 Fix: استفاده از RuntimeException به جای کلاس والد اکسپشن جهت رعایت تمیزی در سلسله مراتب خطاها
            throw new \RuntimeException("Database connection failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
        
        $this->queryBuilder = new QueryBuilder($this->pdo);
    }

    public function setSentryMonitor(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor $monitor): void
    {
        $this->sentryMonitor = $monitor;
    }


private function normalizeSql(string $sql): string
{
    $sql = str_replace(["\\n", "\\r", "\\t"], ' ', $sql); // literal escapes
    $sql = str_replace(["\n", "\r", "\t"], ' ', $sql);    // real whitespace
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

    return [
        'error' => $e->getMessage(),
        'sql' => mb_substr($sql, 0, 1500),
        'params_count' => count($params),
        'file' => $originFile,
        'line' => $originLine,
        'stack' => array_slice($stack, 0, 10),
        'tables' => array_values(array_unique($tables)),
        'unknown_column' => $unknownColumn,
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_id' => function_exists('user_id') ? (user_id() ?: null) : null,
    ];
}

private static function fallbackLog(string $event, array $context = []): void
{
    if (self::$fallbackLogging) {
        return;
    }

    self::$fallbackLogging = true;
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
        self::$fallbackLogging = false;
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
        }
        
        return self::$instance;
    }
	
public function prepare(string $sql): \PDOStatement
{
    try {
        return $this->pdo->prepare($sql);
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
        return $this->pdo;
    }

    /**
     * دریافت Query Builder
     */
    public function table($table)
    {
        return $this->queryBuilder->table($table);
    }
	
	
	
public function fetch(string $sql, array $params = []): ?object
{
    $stmt = $this->executeStatement($sql, $params, 'database.fetch.failed');
    $row = $stmt->fetch(\PDO::FETCH_OBJ);
    return $row ?: null;
}

public function fetchAll(string $sql, array $params = []): array
{
    $stmt = $this->executeStatement($sql, $params, 'database.fetchAll.failed');
    return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
}

public function fetchColumn(string $sql, array $params = [], int $column = 0)
{
    $stmt = $this->executeStatement($sql, $params, 'database.fetchColumn.failed');
    return $stmt->fetchColumn($column);
}

    /**
     * اجرای مرکزی دستورات دیتابیس با مدیریت هوشمند ریکرژن، لاگ و استثناها
     */
    private function executeStatement(string $sql, array $params, string $failureEvent): \PDOStatement
    {
        self::$queryDepth++;
        if (self::$queryDepth > 100) {
            self::$queryDepth--;
            throw new \RuntimeException('Database recursion guard triggered');
        }

        $sql = $this->normalizeSql($sql);
        $startTime = microtime(true);

        try {
            $stmt = $this->pdo->prepare($sql);

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
            $ctx = $this->buildSqlErrorContext($sql, $params, $e);
            self::$lastSqlErrorContext = $ctx;
            self::fallbackLog($failureEvent, $ctx);

            // ارسال خودکار تمام خطاهای دیتابیسی (از کوئری، فچ و غیره) به سیستم مانیتورینگ
            $this->logQueryErrorToSentry($sql, $params, $e);

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
        return $this->executeStatement($sql, $params, 'database.query.failed');
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

    $isPositional = array_keys($params) === range(0, count($params) - 1);

    if ($isPositional) {
        foreach ($params as $value) {
            $sql = preg_replace('/\?/', $this->formatParamValue($value), $sql, 1);
        }
        return $sql;
    }

    foreach ($params as $key => $value) {
        $name = ltrim((string)$key, ':');
        $sql = preg_replace('/:' . preg_quote($name, '/') . '\b/', $this->formatParamValue($value), $sql);
    }

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
    return (int) $this->pdo->lastInsertId();
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
     */
    public function beginTransaction()
    {
        if ($this->pdo->inTransaction()) {
            return true;
        }
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit
     */
    public function commit()
    {
        if (!$this->pdo->inTransaction()) {
            return true;
        }
        try {
            return $this->pdo->commit();
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Rollback
     */
    public function rollback()
    {
        if (!$this->pdo->inTransaction()) {
            return true;
        }
        try {
            return $this->pdo->rollBack();
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * بررسی فعال بودن تراکنش
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
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