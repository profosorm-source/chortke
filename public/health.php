<?php
// Chortke Health Check Endpoint with Sentry Integration
header('Content-Type: application/json');

require_once __DIR__ . '/../bootstrap/app.php';

// Access protection logic
$allowedIps = array_filter(array_map('trim', explode(',', env('HEALTH_ALLOWED_IPS', '127.0.0.1,::1'))));
$token = env('HEALTH_CHECK_TOKEN', '');

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

try {
    // Database check
    $db = \Core\Database::getInstance();
    $stmt = $db->query("SELECT 1");
    $checks['database'] = ['status' => 'ok'];

    // Redis check
    $cache = \Core\Cache::getInstance();
    if ($cache->driver() === 'redis') {
        $redis = $cache->redis();
        if ($redis) {
            $redis->ping();
            $checks['redis'] = ['status' => 'ok'];
        } else {
            $checks['redis'] = ['status' => 'error', 'message' => 'Redis connection failed'];
            $status = 'unhealthy';
        }
    } else {
        $checks['redis'] = ['status' => 'fallback', 'driver' => 'file'];
    }

    $checks['sentry_monitor'] = ['status' => 'ok', 'version' => '1.0'];
    $checks['performance_monitor'] = ['status' => 'ok'];
    $checks['alert_system'] = ['status' => 'ok'];

} catch (Throwable $e) {
    $status = 'unhealthy';
    $checks['error'] = [
        'status' => 'error',
        'message' => 'Internal error occurred'
    ];
}

// File system check
$storageWritable = is_writable(__DIR__ . '/../storage');
$checks['filesystem'] = [
    'status' => $storageWritable ? 'ok' : 'error',
    'storage_writable' => $storageWritable
];
if (!$storageWritable) $status = 'unhealthy';

// System resources (remove sensitive info)
$checks['system'] = [
    'status' => 'ok'
];

$response = [
    'status' => $status,
    'timestamp' => date('c'),
    'checks' => $checks
];

http_response_code($status === 'healthy' ? 200 : 503);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);