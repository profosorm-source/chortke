<?php

/**
 * cron.php - نقطه ورود زمانبندی وظایف (Dispatcher)
 *
 * در این معماری جدید، cron.php هیچ جابی را همزمان اجرا نمی‌کند.
 * بلکه فقط فواصل زمانی را چک کرده و تسک‌ها را به صف (Queue) ارسال می‌کند.
 */
if (php_sapi_name() !== 'cli' && !defined('INTERNAL_APP_CRON_TRIGGER')) {
    die("Access Denied: Cron jobs can only be run via CLI.\n");
}

if (!defined('CRON_MODE')) {
    define('CRON_MODE', true);
}
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

use Core\Container;

try {
    // بارگذاری bootstrap
    require_once __DIR__ . '/bootstrap/app.php';

    $container = Container::getInstance();

    // ==========================================
    //  Distributed Lock — جلوگیری از اجرای همزمان cron در multi-node
    // ==========================================
    $redis = null;
    $distributedLockKey = 'cron:distributed_lock';
    $distributedLockToken = bin2hex(random_bytes(12));
    $lockAcquired = false;

    try {
        $redis = $container->make(\Core\Redis::class);
    } catch (\Throwable $e) {
        $redis = null;
    }

    if ($redis instanceof \Core\Redis && $redis->isAvailable()) {
        try {
            $lockAcquired = $redis->getClient()->set($distributedLockKey, $distributedLockToken, ['nx', 'ex' => 60]);
            if (!$lockAcquired) {
                echo '[' . date('Y-m-d H:i:s') . "] [SKIP] cron.php already running on another node — exiting.\n";
                if (defined('INTERNAL_APP_CRON_TRIGGER')) return;
                exit(0);
            }
        } catch (\Throwable $e) {
            $lockAcquired = false;
        }
    }

    // Fallback to local file lock if Redis is unavailable
    $lockHandle = null;
    $lockFile = null;
    if (!$lockAcquired) {
        $lockDir = BASE_PATH . '/storage/logs';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }

        $lockFile = $lockDir . '/cron.lock';
        $lockHandle = @fopen($lockFile, 'c');

        if ($lockHandle === false) {
            echo '[' . date('Y-m-d H:i:s') . "] [ERROR] Unable to open/create lock file: {$lockFile}.\n";
            if (defined('INTERNAL_APP_CRON_TRIGGER')) return;
            exit(1);
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            echo '[' . date('Y-m-d H:i:s') . "] [SKIP] cron.php already running — exiting.\n";
            if (defined('INTERNAL_APP_CRON_TRIGGER')) return;
            exit(0);
        }
    }

    $onlyJob = null;
    $dryRun  = false;

    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--job=')) {
            $onlyJob = substr($arg, 6);
        }
        if ($arg === '--dry-run') {
            $dryRun = true;
        }
    }

    if ($dryRun) {
        echo "[DRY-RUN] فقط نمایش وظایف - توزیع نمی‌شوند\n";
    }

    // آزادسازی قفل پس از پایان اسکریپت
    register_shutdown_function(function () use ($redis, $distributedLockKey, $distributedLockToken, $lockHandle, $lockFile) {
        if ($redis instanceof \Core\Redis && $redis->isAvailable()) {
            try {
                $currentValue = $redis->getClient()->get($distributedLockKey);
                if ($currentValue === $distributedLockToken) {
                    $redis->getClient()->del($distributedLockKey);
                }
            } catch (\Throwable $e) {}
        }

        if (isset($lockHandle) && $lockHandle !== null) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            @unlink($lockFile);
        }
    });

    // ==========================================
    //  بارگذاری تسک‌ها از Kernel و ارسال به صف
    // ==========================================
    $scheduler = $container->make(\Core\Scheduler::class);
    if ($onlyJob !== null) {
        $scheduler->forceRegisterJobs(true);
    }

    // لود کردن تمامی Closures
    if (class_exists(\App\Console\Kernel::class)) {
        \App\Console\Kernel::schedule($scheduler);
    } else {
        throw new \Exception("App\Console\Kernel class not found!");
    }

    if ($dryRun) {
        echo "وظایف ثبت‌شده - در صف قرار نگرفتند (dry-run mode)\n";
        if (defined('INTERNAL_APP_CRON_TRIGGER')) return;
        exit(0);
    }

    echo '[' . date('Y-m-d H:i:s') . '] شروع توزیع cron jobs به Queue' . PHP_EOL;

    // ارسال همه تسک‌های مجاز به Queue به جای اجرای همزمان
    $results = $scheduler->dispatchAllAsync($onlyJob);

    // نمایش نتایج Dispatch
    foreach ($results as $name => $result) {
        $status = $result['status'];
        $icon   = match($status) {
            'queued'  => '✓',
            'error'   => '✗',
            'skipped' => '⟳',
            default   => '?',
        };

        echo "[{$icon}] {$name}: {$status}";
        if ($status === 'error') {
            echo ' - ' . ($result['message'] ?? '');
        } elseif ($status === 'skipped') {
            echo ' - ' . ($result['reason'] ?? '');
        }
        echo PHP_EOL;
    }

    echo '[' . date('Y-m-d H:i:s') . '] پایان توزیع' . PHP_EOL;

} catch (\Throwable $e) {
    $errorMsg = "CRON FATAL ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
    if (config('app.debug')) {
        echo substr($e->getTraceAsString(), 0, 2000) . "\n";
    } else {
        echo "See application logs for details. Error ID: " . uniqid('cron_', true) . "\n";
    }

    try {
        if (function_exists('logger')) {
            logger()->critical('cron_dispatch_failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 2048)
            ]);
        }
    } catch (\Throwable $loggerException) {}

    error_log($errorMsg);
    if (defined('INTERNAL_APP_CRON_TRIGGER')) throw $e;
    exit(1);
}
