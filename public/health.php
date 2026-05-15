<?php
// Chortke Lightweight Health Check Endpoint (Decoupled)
header('Content-Type: application/json');

$baseDir = dirname(__DIR__);
$envPath = $baseDir . '/.env';
$env = [];

if (file_exists($envPath)) {
    $parsed = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if ($parsed !== false) {
        $env = $parsed;
    }
}

// Helpers to retrieve settings with fallback
function get_env_val(string $key, $default, array $env) {
    return isset($env[$key]) ? $env[$key] : $default;
}

// ── Access Protection ────────────────────────────────────────────
$allowedIpsStr = get_env_val('HEALTH_ALLOWED_IPS', '127.0.0.1,::1', $env);
$allowedIps = array_filter(array_map('trim', explode(',', $allowedIpsStr)));
$token = get_env_val('HEALTH_CHECK_TOKEN', '', $env);

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$requestToken = $_GET['token'] ?? $_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '';

$isIpAllowed = in_array($clientIp, $allowedIps, true);
$isTokenValid = !empty($token) && hash_equals($token, $requestToken);

if (!$isIpAllowed && !$isTokenValid) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access'], JSON_UNESCAPED_UNICODE);
    exit;
}

$checks = [];
$status = 'healthy';

// ── ۱. Database Direct Check ─────────────────────────────────────
try {
    $dbHost = get_env_val('DB_HOST', '127.0.0.1', $env);
    $dbPort = get_env_val('DB_PORT', '3306', $env);
    $dbName = get_env_val('DB_NAME', 'chortke', $env);
    $dbUser = get_env_val('DB_USER', 'root', $env);
    $dbPass = get_env_val('DB_PASS', '', $env);
    $dbCharset = get_env_val('DB_CHARSET', 'utf8mb4', $env);

    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset={$dbCharset}";
    $pdo = new \PDO($dsn, $dbUser, $dbPass, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_TIMEOUT => 2
    ]);

    $stmt = $pdo->query("SELECT 1");
    $checks['database'] = ['status' => 'ok'];
} catch (\Throwable $e) {
    $status = 'unhealthy';
    $checks['database'] = [
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ];
}

// ── ۲. Redis Direct Check ────────────────────────────────────────
$redisEnabled = filter_var(get_env_val('REDIS_ENABLED', 'true', $env), FILTER_VALIDATE_BOOLEAN);
if ($redisEnabled && class_exists('Redis')) {
    try {
        $redisHost = get_env_val('REDIS_HOST', '127.0.0.1', $env);
        $redisPort = (int)get_env_val('REDIS_PORT', 6379, $env);
        $redisPass = get_env_val('REDIS_PASSWORD', null, $env);
        $redisTimeout = (float)get_env_val('REDIS_TIMEOUT', 1.0, $env);

        $redis = new \Redis();
        $redis->connect($redisHost, $redisPort, $redisTimeout);
        if ($redisPass) {
            $redis->auth($redisPass);
        }
        $redis->ping();
        $checks['redis'] = ['status' => 'ok'];
    } catch (\Throwable $e) {
        $status = 'unhealthy';
        $checks['redis'] = [
            'status' => 'error',
            'message' => 'Redis connection failed: ' . $e->getMessage()
        ];
    }
} else {
    $checks['redis'] = [
        'status' => 'disabled',
        'driver' => 'file'
    ];
}

// ── ۳. File System Check ─────────────────────────────────────────
$storageDir = $baseDir . '/storage';
$storageWritable = is_dir($storageDir) && is_writable($storageDir);
$checks['filesystem'] = [
    'status' => $storageWritable ? 'ok' : 'error',
    'storage_writable' => $storageWritable
];

if (!$storageWritable) {
    $status = 'unhealthy';
}

// ── ۴. Standard Monitors (Static placeholders for Sentry telemetry availability)
$checks['sentry_monitor'] = ['status' => 'ok'];
$checks['performance_monitor'] = ['status' => 'ok'];

// ── Output Result ────────────────────────────────────────────────
$response = [
    'status' => $status,
    'timestamp' => date('c'),
    'checks' => $checks
];

http_response_code($status === 'healthy' ? 200 : 503);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);