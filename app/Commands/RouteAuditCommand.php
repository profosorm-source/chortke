<?php

declare(strict_types=1);

namespace App\Commands;

use ReflectionClass;
use ReflectionException;

/**
 * RouteAuditCommand - سیستم یکپارچه ممیزی و صحت‌سنجی روت‌ها
 * 
 * استفاده: php cli.php route:audit
 */
class RouteAuditCommand
{
    private array $errors = [];
    private array $warnings = [];
    private int $successCount = 0;

    public function run(array $argv): void
    {
        echo "\n\033[1;36m=== Route Audit Started ===\033[0m\n\n";

        // 1. بررسی فایل‌های روت
        $routeFiles = [
            BASE_PATH . '/routes/api.php',
            BASE_PATH . '/routes/admin.php',
            BASE_PATH . '/routes/system.php',
            BASE_PATH . '/routes/user.php',
            BASE_PATH . '/routes/missing.php',
        ];

        foreach ($routeFiles as $file) {
            if (file_exists($file)) {
                echo "Checking " . basename($file) . " structural presence...\n";
            }
        }

        $this->verifyRouteFiles($routeFiles);

        // 2. اعتبارسنجی دستی اندپوینت‌های کلیدی سیستم
        echo "\n\033[1;36mVerifying Critical API Endpoints...\033[0m\n";

        $criticalEndpoints = [
            ['GET',  '/api/v1/social/accounts', 'App\Controllers\Api\SocialTaskApiController', 'accounts'],
            ['POST', '/api/v1/social/accounts', 'App\Controllers\Api\SocialTaskApiController', 'storeAccount'],
            ['GET',  '/api/v1/social/ads',      'App\Controllers\Api\SocialTaskApiController', 'myAds'],
            ['GET',  '/api/v1/social/tasks',    'App\Controllers\Api\SocialTaskApiController', 'tasks'],
        ];

        foreach ($criticalEndpoints as [$httpMethod, $path, $controller, $method]) {
            $this->verifyRoute($httpMethod, $path, $controller, $method);
        }

        // 3. نمایش نتایج نهایی
        echo "\n\033[1;36m=== Audit Results ===\033[0m\n";
        echo "\033[1;32m✅ Passed: {$this->successCount}\033[0m\n";

        if (!empty($this->warnings)) {
            echo "\033[1;33m⚠️ Warnings: " . count($this->warnings) . "\033[0m\n";
            foreach ($this->warnings as $warning) {
                echo "  {$warning}\n";
            }
        }

        if (!empty($this->errors)) {
            echo "\033[1;31m❌ Errors: " . count($this->errors) . "\033[0m\n";
            foreach ($this->errors as $error) {
                echo "  {$error}\n";
            }
            exit(1);
        }

        echo "\033[1;32m\n✅ All critical routes verified successfully!\033[0m\n";
    }

    private function verifyRouteFiles(array $routeFiles): void
    {
        echo "\n\033[1;36mVerifying route definitions in route files...\033[0m\n";

        foreach ($routeFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                $this->warnings[] = "⚠️ Could not read route file: {$file}";
                continue;
            }

            $aliasMap = $this->parseUseAliases($contents);

            preg_match_all('/\[\s*([\\A-Za-z0-9_]+)::class\s*,\s*["\']([A-Za-z0-9_]+)["\']\s*\]/', $contents, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                [$raw, $className, $method] = $match;
                $resolvedClass = $this->resolveRouteClass($className, $aliasMap);
                if ($resolvedClass === null) {
                    $this->errors[] = "❌ {$file}: Route references unimported controller alias '{$className}::class'";
                    continue;
                }
                $this->verifyRoute('UNKNOWN', $file, $resolvedClass, $method);
            }
        }
    }

    private function parseUseAliases(string $contents): array
    {
        $aliases = [];
        preg_match_all('/^use\s+([^;]+);/m', $contents, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $useStatement = trim($match[1]);
            if (stripos($useStatement, ' as ') !== false) {
                [$fqcn, $alias] = preg_split('/\s+as\s+/i', $useStatement, 2);
            } else {
                $fqcn = $useStatement;
                $parts = explode('\\', $fqcn);
                $alias = end($parts);
            }
            $aliases[$alias] = ltrim($fqcn, '\\');
        }
        return $aliases;
    }

    private function resolveRouteClass(string $className, array $aliasMap): ?string
    {
        if (str_contains($className, '\\')) {
            return ltrim($className, '\\');
        }
        return $aliasMap[$className] ?? null;
    }

    private function verifyRoute(string $method, string $path, string $controllerClass, string $controllerMethod): void
    {
        try {
            // بررسی وجود کلاس کنترولر
            if (!class_exists($controllerClass)) {
                $this->errors[] = "❌ {$method} {$path}: Controller class does not exist: {$controllerClass}";
                return;
            }

            $reflection = new ReflectionClass($controllerClass);

            // بررسی وجود متد کنترولر
            if (!$reflection->hasMethod($controllerMethod)) {
                $this->errors[] = "❌ {$method} {$path}: Method not found: {$controllerClass}::{$controllerMethod}()";
                return;
            }

            $methodObj = $reflection->getMethod($controllerMethod);

            // بررسی پابلیک بودن متد
            if (!$methodObj->isPublic()) {
                $this->errors[] = "❌ {$method} {$path}: Method is not public: {$controllerClass}::{$controllerMethod}()";
                return;
            }

            $this->successCount++;
        } catch (ReflectionException $e) {
            $this->errors[] = "❌ {$method} {$path}: Reflection failure for {$controllerClass} -> " . $e->getMessage();
        }
    }
}
