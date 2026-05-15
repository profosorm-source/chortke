<?php

if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
}

/**
 * Ensure test environment values for config and OAuth bootstrap.
 */
global $env;
$env = array_merge($env ?? [], [
    'APP_ENV' => 'local',
    'APP_URL' => 'http://localhost',
    'APP_DEBUG' => 'true',
    'TRUSTED_PROXIES' => '127.0.0.1',
]);

require_once __DIR__ . '/../vendor/autoload.php';
