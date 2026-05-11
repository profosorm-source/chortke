<?php

declare(strict_types=1);

/**
 * cli.php
 * نقطه ورود ابزارهای خط فرمان پروژه چرتکه
 */

if (php_sapi_name() !== 'cli') {
    die("Only CLI access allowed.\n");
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

// بارگذاری bootstrap
require_once __DIR__ . '/bootstrap/app.php';

use Core\Container;
use App\Commands\FeatureFlagCommand;

try {
    $dispatcher = Container::getInstance()->make(\Core\Console\CliDispatcher::class);
    $dispatcher->run($argv);
} catch (\Throwable $e) {
    echo "❌ Error executing command: " . $e->getMessage() . "\n";
    exit(1);
}
