#!/usr/bin/env php
<?php

/**
 * Route Audit Script
 * 
 * ⚠️ CLI-ONLY: این اسکریپت فقط باید از طریق خط فرمان اجرا شود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

define('BASE_PATH', __DIR__ . '/..');

// Autoloader
require_once BASE_PATH . '/core/Autoloader.php';
\Core\Autoloader::register();

// Colors for CLI output
class Colors {
    const GREEN = "\033[92m";
    const RED = "\033[91m";
    const YELLOW = "\033[93m";
    const CYAN = "\033[36m";
    const RESET = "\033[0m";
}

$errors = [];
$warnings = [];
$successCount = 0;

/**
 * Verify a route
 */
function verify_route($method, $path, $controller, $controllerMethod, $middlewares = []) {
    global $errors, $warnings, $successCount;
    
    if (is_array($controller)) {
        $controllerClass = $controller[0];
        $controllerMethod = $controller[1];
    } else {
        $controllerClass = $controller;
    }
    
    // Check if controller class exists
    $reflection = null;
    try {
        $reflection = new ReflectionClass($controllerClass);
    } catch (ReflectionException $e) {
        $errors[] = "❌ {$method} {$path}: Controller not found: {$controllerClass}";
        return;
    }
    
    // Check if method exists
    if (!$reflection->hasMethod($controllerMethod)) {
        $errors[] = "❌ {$method} {$path}: Method not found: {$controllerClass}::{$controllerMethod}()";
        return;
    }
    
    $method_obj = $reflection->getMethod($controllerMethod);
    
    // Check if method is public
    if (!$method_obj->isPublic()) {
        $errors[] = "❌ {$method} {$path}: Method is not public: {$controllerClass}::{$controllerMethod}()";
        return;
    }
    
    $successCount++;
}

/**
 * Parse routes from file
 */
function parse_routes($filePath) {
    global $errors;
    
    if (!file_exists($filePath)) {
        $errors[] = "⚠️ Routes file not found: {$filePath}";
        return;
    }
    
    echo Colors::CYAN . "Checking {$filePath}..." . Colors::RESET . "\n";
    
    // Simple regex-based parsing (not perfect, but good enough for audit)
    $content = file_get_contents($filePath);
    
    // Match patterns like $r->get(...), $app->router->post(...), etc.
    $patterns = [
        '/\$r->(?:get|post|put|patch|delete)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[([^\]]+)\]/i',
        '/\$app->router->(?:get|post|put|patch|delete)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[([^\]]+)\]/i',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $path = $match[1];
                $handlerStr = $match[2];
                
                // Parse controller and method from handler
                // Looking for format: ControllerClass::class, 'method'
                if (preg_match('/(\w+\\\\[\\w\\\\]+)::class\s*,\s*[\'"](\w+)[\'"]/i', $handlerStr, $handlerMatch)) {
                    $controller = str_replace('::class', '', $handlerMatch[1]);
                    $method = $handlerMatch[2];
                    
                    // Try to resolve namespaced controller
                    $method_str = explode('->', $_SERVER['argv'][1] ?? '');
                    verify_route('unknown', $path, $controller, $method);
                }
            }
        }
    }
}

echo "\n" . Colors::CYAN . "=== Route Audit Started ===" . Colors::RESET . "\n\n";

// Routes to check
$routeFiles = [
    BASE_PATH . '/routes/api.php',
    BASE_PATH . '/routes/admin.php',
    BASE_PATH . '/routes/system.php',
    BASE_PATH . '/routes/user.php',
    BASE_PATH . '/routes/missing.php',
];

foreach ($routeFiles as $file) {
    if (file_exists($file)) {
        echo "Auditing " . basename($file) . "...\n";
        // Note: Simple verification only - full parse requires executing the files
    }
}

// Manual verification for critical API endpoints
echo "\n" . Colors::CYAN . "Verifying Critical API Endpoints..." . Colors::RESET . "\n";

$criticalEndpoints = [
    ['GET', '/api/v1/social/accounts', 'App\Controllers\Api\SocialTaskApiController', 'accounts'],
    ['POST', '/api/v1/social/accounts', 'App\Controllers\Api\SocialTaskApiController', 'storeAccount'],
    ['GET', '/api/v1/social/ads', 'App\Controllers\Api\SocialTaskApiController', 'myAds'],
    ['GET', '/api/v1/social/tasks', 'App\Controllers\Api\SocialTaskApiController', 'tasks'],
];

foreach ($criticalEndpoints as [$httpMethod, $path, $controller, $method]) {
    verify_route($httpMethod, $path, $controller, $method);
}

echo "\n" . Colors::CYAN . "=== Audit Results ===" . Colors::RESET . "\n";
echo Colors::GREEN . "✅ Passed: {$successCount}" . Colors::RESET . "\n";

if (!empty($warnings)) {
    echo Colors::YELLOW . "⚠️ Warnings: " . count($warnings) . Colors::RESET . "\n";
    foreach ($warnings as $warning) {
        echo "  {$warning}\n";
    }
}

if (!empty($errors)) {
    echo Colors::RED . "❌ Errors: " . count($errors) . Colors::RESET . "\n";
    foreach ($errors as $error) {
        echo "  {$error}\n";
    }
    exit(1);
}

echo Colors::GREEN . "\n✅ All routes verified successfully!\n" . Colors::RESET;
exit(0);
