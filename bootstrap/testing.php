<?php
/**
 * Test Bootstrap File
 * Sets up the test environment
 */

// Autoloading
require_once __DIR__ . '/../vendor/autoload.php';

// Define test environment
define('APP_ENV', 'testing');
define('APP_DEBUG', true);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Mock helper functions if not defined in helpers
if (!function_exists('response')) {
    function response($data = [], $status = 200, $headers = []) {
        return ['data' => $data, 'status' => $status, 'headers' => $headers];
    }
}

if (!function_exists('abort')) {
    function abort($code = 404, $message = '') {
        throw new Exception($message, $code);
    }
}

if (!function_exists('config')) {
    function config($key, $default = null) {
        return $default;
    }
}

if (!function_exists('logger')) {
    function logger() {
        return new class {
            public function info($msg) {}
            public function warning($msg) {}
            public function error($msg) {}
            public function debug($msg) {}
        };
    }
}

// Silence strict warnings during testing
error_reporting(E_ERROR | E_WARNING | E_PARSE);
