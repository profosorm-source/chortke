<?php

/**
 * Global Helper Hub - چرتکه
 * 
 * این فایل شامل توابع هسته (Core) است. 
 * سایر توابع تخصصی در فایل‌های هلوپر مجزا (view, url, auth, ...) تعریف شده‌اند
 * و توسط Composer بارگذاری می‌شوند.
 */

if (!function_exists('app')) {
    /**
     * دریافت Application Instance یا حل وابستگی از Container
     */
    function app(?string $abstract = null)
    {
        $instance = \Core\Application::getInstance();
        if ($abstract === null) {
            return $instance;
        }
        return $instance->container->make($abstract);
    }
}

if (!function_exists('db')) {
    /**
     * دریافت Database Instance
     */
    function db(): \Core\Database
    {
        return app(\Core\Database::class);
    }
}

if (!function_exists('cache')) {
    /**
     * دسترسی سریع به Cache singleton
     */
    function cache(): \Core\Cache
    {
        return \Core\Cache::getInstance();
    }
}

if (!function_exists('session')) {
    /**
     * دسترسی سریع به Session singleton
     */
    function session(): \Core\Session
    {
        return \Core\Session::getInstance();
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        global $env;

        if (!isset($env)) {
            $env = [];
        }

        if (isset($env[$key])) {
            $value = $env[$key];

            if (is_string($value)) {
                $value = trim($value);
                // Unquote values
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                $value = trim($value);

                $lower = strtolower($value);
                if ($lower === 'true') return true;
                if ($lower === 'false') return false;
                if ($lower === 'null') return null;
                if (is_numeric($value)) {
                    return str_contains($value, '.') ? (float)$value : (int)$value;
                }
            }

            return $value;
        }

        return $default;
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        static $config = null;

        if ($config === null) {
            $configFile = __DIR__ . '/../config/config.php';
            $config = file_exists($configFile) ? require $configFile : [];
            $config = is_array($config) ? $config : [];

            // لود هوشمند و داینامیک تمامی فایل‌های پیکربندی در فولدر config
            $configDir = __DIR__ . '/../config/';
            if (is_dir($configDir)) {
                foreach (glob($configDir . '*.php') as $file) {
                    $name = basename($file, '.php');
                    if ($name !== 'config') {
                        $content = require $file;
                        if (is_array($content)) {
                            $config[$name] = $content;
                        }
                    }
                }
            }
        }

        if ($key === null) {
            return $config;
        }

        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }
}

if (!function_exists('settings')) {
    function settings(bool $forceReload = false): array
    {
        static $settings = null;

        if ($forceReload) {
            $settings = null;
        }

        if ($settings !== null) {
            return $settings;
        }

        $settings = app(\App\Services\SettingService::class)->load();
        return is_array($settings) ? $settings : [];
    }
}

if (!function_exists('base_path')) {
    /**
     * مسیر ریشه پروژه
     */
    function base_path(string $path = ''): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        return rtrim($basePath, '/\\') . '/' . ltrim($path, '/\\');
    }
}

if (!function_exists('view_path')) {
    /**
     * مسیر فایل ویو
     */
    function view_path(string $viewName): string
    {
        $viewPath = defined('VIEW_PATH') ? VIEW_PATH : base_path('views');
        return rtrim($viewPath, '/\\') . '/' . str_replace('.', '/', trim($viewName, '/')) . '.php';
    }
}

if (!function_exists('setting')) {
    /**
     * دریافت یک تنظیم خاص
     */
    function setting(string $key, mixed $default = null): mixed
    {
        $settings = settings();
        return $settings[$key] ?? $default;
    }
}

if (!function_exists('logger')) {
    /**
     * Logger Helper
     */
    function logger(): \App\Contracts\LoggerInterface
    {
        return app(\App\Contracts\LoggerInterface::class);
    }
}

if (!function_exists('dd')) {
    /**
     * Dump and Die
     */
    function dd(...$vars)
    {
        if (!config('app.debug')) {
            try {
                logger()->error('dd() called in production environment', [
                    'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
                ]);
            } catch (\Throwable $e) {
                // Fallback if logger is unavailable
            }
            die('An error occurred. Please contact administrator.');
        }

        echo '<pre style="background: #1e1e1e; color: #ddd; padding: 20px; direction: ltr; text-align: left;">';
        foreach ($vars as $var) {
            var_dump($var);
        }
        echo '</pre>';
        die(1);
    }
}

if (!function_exists('format_amount')) {
    /**
     * فرمت‌دهی مبالغ مالی به صورت فارسی یا عددی خوانا
     */
    function format_amount(mixed $amount): string
    {
        return number_format((float)$amount, 0, '.', ',');
    }
}
