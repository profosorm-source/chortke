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

// جلوگیری از اجرای هم‌زمان دستورات مشابه و انباشت پردازش‌ها (Fork Bomb & Concurrency Protection)
$commandName = isset($argv[1]) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $argv[1]) : 'default';
$lockDir = __DIR__ . '/storage/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFile = $lockDir . '/cli_' . $commandName . '.lock';

$lockFp = @fopen($lockFile, 'c');
if ($lockFp) {
    if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
        echo "⚠️ Command '{$commandName}' is already running. Preventing concurrent execution to avoid resource exhaust.\n";
        @fclose($lockFp);
        exit(0);
    }
}

// بارگذاری bootstrap
require_once __DIR__ . '/bootstrap/app.php';

use Core\Container;

try {
    $dispatcher = Container::getInstance()->make(\Core\Console\CliDispatcher::class);
    $dispatcher->run($argv);
} catch (\Throwable $e) {
    echo "❌ Error executing command: " . $e->getMessage() . "\n";
    if (isset($lockFp)) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
    exit(1);
}

if (isset($lockFp)) {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
}
