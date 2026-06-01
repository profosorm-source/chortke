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
        global $configData, $configLoaded, $configOverrides;

        if (!is_array($configData)) {
            $configData = [];
        }

        if (!is_array($configLoaded)) {
            $configLoaded = [];
        }

        if (!is_array($configOverrides)) {
            $configOverrides = [];
        }

        $loadConfig = function(string $name) use (&$configData, &$configLoaded) {
            if (isset($configLoaded[$name])) {
                return;
            }
            $configLoaded[$name] = true;

            $file = __DIR__ . "/../config/{$name}.php";
            if (file_exists($file)) {
                $content = require $file;
                if (is_array($content)) {
                    $configData[$name] = $content;
                }
            }
        };

        $traverse = function(array $source, array $keys, bool &$found) {
            $value = $source;
            foreach ($keys as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                    continue;
                }
                $found = false;
                return null;
            }
            $found = true;
            return $value;
        };

        if ($key === null) {
            $configDir = __DIR__ . '/../config/';
            if (is_dir($configDir)) {
                foreach (glob($configDir . '*.php') as $file) {
                    $loadConfig(basename($file, '.php'));
                }
            }

            $merged = [];
            foreach ($configData as $name => $content) {
                if ($name === 'config') {
                    $merged = array_merge($merged, $content);
                } else {
                    $merged[$name] = $content;
                }
            }

            return array_replace_recursive($merged, $configOverrides);
        }

        $keys = explode('.', $key);
        $file = $keys[0];

        $loadConfig($file);
        $loadConfig('config');

        if (!empty($configOverrides)) {
            $found = false;
            $override = $traverse($configOverrides, $keys, $found);
            if ($found) {
                return $override;
            }
        }

        if (isset($configData[$file])) {
            $found = false;
            $value = $traverse($configData[$file], array_slice($keys, 1), $found);
            if ($found) {
                return $value;
            }
        }

        if (isset($configData['config'])) {
            $found = false;
            $value = $traverse($configData['config'], $keys, $found);
            if ($found) {
                return $value;
            }
        }

        return $default;
    }
}

if (!function_exists('config_set')) {
    function config_set(string $key, mixed $value): void
    {
        global $configOverrides;

        if (!is_array($configOverrides)) {
            $configOverrides = [];
        }

        $segments = explode('.', $key);
        $target = &$configOverrides;
        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        $target = $value;
    }
}

if (!function_exists('config_reload')) {
    function config_reload(?string $key = null): void
    {
        global $configData, $configLoaded, $configOverrides;

        if (!is_array($configData)) {
            $configData = [];
        }
        if (!is_array($configLoaded)) {
            $configLoaded = [];
        }
        if (!is_array($configOverrides)) {
            $configOverrides = [];
        }

        if ($key === null) {
            $configData = [];
            $configLoaded = [];
            $configOverrides = [];
            return;
        }

        $segments = explode('.', $key);
        unset($configData[$segments[0]], $configLoaded[$segments[0]]);
    }
}

if (!function_exists('settings')) {
    function settings(bool $forceReload = false): array
    {
        $service = app(\App\Services\Settings\AppSettings::class);
        if ($forceReload) {
            $service->clearCache();
        }
        $settings = $service->load();
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

/* -------------------------------------------------------------
 | Lazy Loading Helpers Hub
 | فایل‌های راهنما به صورت هوشمند و تنها در صورت نیاز لود می‌شوند.
 * ------------------------------------------------------------- */

if (!function_exists('csrf_token')) {
    require_once __DIR__ . '/csrf_helper.php';
}
if (!function_exists('secure_key')) {
    require_once __DIR__ . '/security.php';
}
if (!function_exists('view')) {
    require_once __DIR__ . '/view_helper.php';
}
if (!function_exists('url')) {
    require_once __DIR__ . '/url_helper.php';
}
if (!function_exists('auth')) {
    require_once __DIR__ . '/auth_helper.php';
}
if (!function_exists('today')) {
    require_once __DIR__ . '/date_helper.php';
}
if (!function_exists('json_response')) {
    require_once __DIR__ . '/response_helper.php';
}
if (!function_exists('rate_limit')) {
    require_once __DIR__ . '/rate_limit_helper.php';
}
if (!function_exists('captcha')) {
    require_once __DIR__ . '/captcha_helper.php';
}
if (!function_exists('feature_enabled')) {
    require_once __DIR__ . '/feature_flag_helpers.php';
}
if (!function_exists('site_logo')) {
    require_once __DIR__ . '/site_helper.php';
}
