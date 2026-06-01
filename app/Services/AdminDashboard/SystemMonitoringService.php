<?php

declare(strict_types=1);

namespace App\Services\AdminDashboard;

use Core\Database;
use Core\Cache;
use App\Contracts\LoggerInterface;
use App\Constants\SystemConstants;
use App\Constants\TimeConstants;

class SystemMonitoringService
{


    
    private \Core\Cache $cache;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    )
    {        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;

        
        }

    /**
     * دریافت وضعیت زنده سیستم (Uptime, Memory, Disk, CPU) با متدهای ایمن و فیل‌سیف
     */
    public function getSystemStatus(): array
    {
        // MED-26: Encapsulate high-load scans in lightweight TTL caching pools (15s) to protect against high disk I/Opolling storms
        return $this->cache->remember('admin_dashboard_system_status', 15, function() {
            // ۱. وضعیت دیسک
            $diskTotal = @disk_total_space('.') ?: SystemConstants::DEFAULT_DISK_TOTAL;
            $diskFree = @disk_free_space('.') ?: SystemConstants::DEFAULT_DISK_FREE;
            $diskUsed = $diskTotal - $diskFree;
            $diskPercentage = round(($diskUsed / $diskTotal) * 100, 2);

            // ۲. وضعیت حافظه رم
            $memTotal = SystemConstants::DEFAULT_MEMORY_TOTAL;
            $memFree = SystemConstants::DEFAULT_MEMORY_FREE;
            
            if (!stristr(PHP_OS, 'win')) {
                if ($this->isContainerEnvironment()) {
                    // H18 Fix: در کانتینر، خواندن از cgroup به جای افشای اطلاعات هاست فیزیکی سرور
                    $cTotal = $this->readCgroupValue(['/sys/fs/cgroup/memory/memory.limit_in_bytes', '/sys/fs/cgroup/memory.max']);
                    $cUsage = $this->readCgroupValue(['/sys/fs/cgroup/memory/memory.usage_in_bytes', '/sys/fs/cgroup/memory.current']);
                    
                    // بررسی سقف عددی max که در لینوکس برگردانده می‌شود
                    if ($cTotal > 0 && $cTotal < 9223372036854770000 && $cUsage > 0) {
                        $memTotal = $cTotal;
                        $memFree = max(0, $cTotal - $cUsage);
                    }
                } else {
                    $memInfo = @file_get_contents('/proc/meminfo');
                    if ($memInfo) {
                        preg_match('/MemTotal:\s+(\d+)/', $memInfo, $matchesTotal);
                        preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $matchesAvailable);
                        if (isset($matchesTotal[1])) {
                            $memTotal = (int)$matchesTotal[1] * 1024; // ✅ تبدیل کیلوبایت به بایت
                        }
                        if (isset($matchesAvailable[1])) {
                            $memFree = (int)$matchesAvailable[1] * 1024;
                        }
                    }
                }
            }
            
            $memUsed = $memTotal - $memFree;
            $memPercentage = round(($memUsed / $memTotal) * 100, 2);

            // ۳. لود پردازنده (CPU)
            $cpuLoad = 0.0;
            if (function_exists('sys_getloadavg')) {
                $load = @sys_getloadavg();
                if (is_array($load) && isset($load[0])) {
                    $cores = max(1, $this->getCpuCoreCount());
                    // MED-25: Scientifically determine resource load by dividing process load averages by active logical core caps
                    $cpuLoad = \min(100.0, \max(0.0, ((float)$load[0] / $cores) * 100.0));
                }
            }
            if ($cpuLoad <= 0.0) {
                $cpuLoad = 0.0; 
            }

            // ۴. مدت زمان روشن بودن (Uptime)
            // M37 Fix: جایگزینی مقدار هاردکد نادرست با مقدار خنثی و واقعی نامشخص در محیط‌های فاقد دسترسی
            $uptimeStr = 'نامشخص';
            if (!stristr(PHP_OS, 'win')) {
                if ($this->isContainerEnvironment()) {
                    // H18 Fix: عدم خواندن Uptime هاست در محفظه کانتینر جهت حفظ محرمانگی ساختار سرور
                    $uptimeStr = 'فعال (ایمن)';
                } else {
                    $uptimeSec = @file_get_contents('/proc/uptime');
                    if ($uptimeSec) {
                        $parts = explode(' ', $uptimeSec);
                        $seconds = (int)$parts[0];
                        $days = (int)($seconds / TimeConstants::SECONDS_PER_DAY);
                        $hours = (int)(($seconds % TimeConstants::SECONDS_PER_DAY) / TimeConstants::SECONDS_PER_HOUR);
                        // LOW-14: Deliver rich localized outputs utilizing Persian string and numeral mappings
                        $uptimeStr = $this->toPersianNumbers("{$days}") . ' روز و ' . $this->toPersianNumbers("{$hours}") . ' ساعت';
                    }
                }
            }

            // ۵. وضعیت دیتابیس
            $dbStatus = 'فعال';
            try {
                // H20 Fix: استفاده از کوئری استاندارد و کراس‌دیتابیس ANSI SQL جهت تضمین سازگاری با PostgreSQL و SQLite
                $this->db->fetch("SELECT 1");
            } catch (\Throwable) {
                $dbStatus = 'محدودشده';
            }

            // ۶. وضعیت صف‌ها (Queue Status Dashboard)
            $queueStats = [];
            try {
                $queueService = \Core\Container::getInstance()->make(\Core\Queue::class);
                $queueStats = $queueService->getQueueStatusReport();
            } catch (\Throwable) {
                // fail-safe fallback
            }

            return [
                'cpu_usage' => round($cpuLoad, 2),
                'memory' => [
                    'total' => $memTotal,
                    'used' => $memUsed,
                    'free' => $memFree,
                    'percentage' => $memPercentage,
                ],
                'disk' => [
                    'total' => $diskTotal,
                    'used' => $diskUsed,
                    'free' => $diskFree,
                    'percentage' => $diskPercentage,
                ],
                'uptime' => $uptimeStr,
                'database' => [
                    'status' => $dbStatus,
                    'connections' => 'Hidden', // H21 Fix: پنهان‌سازی اطلاعات تکنیکال دیتابیس جهت مقابله با Timing Attack
                ],
                'queues' => $queueStats,
                'php_version' => 'Hidden', // H22 Fix: ممانعت از افشای نسخه مفسر PHP جهت حفظ محرمانگی سرور
                'server_software' => 'Hidden', // محافظت در برابر فاش شدن نوع سرور
            ];
        });
    }

    /**
     * بررسی وضعیت سیستم و ارسال هشدار در صورت عبور از آستانه مجاز
     */
    public function checkAndAlert(): void
    {
        try {
            $status = $this->getSystemStatus();
            
            if (isset($status['disk']['percentage']) && $status['disk']['percentage'] >= 90) {
                if (class_exists(\App\Services\Sentry\SentryExceptionHandler::class)) {
                    \App\Services\Sentry\SentryExceptionHandler::captureMessage(
                        'CRITICAL: Disk usage exceeded 90%', 
                        'critical', 
                        null, 
                        ['usage' => $status['disk']['percentage']]
                    );
                }
            }
            
            if (isset($status['memory']['percentage']) && $status['memory']['percentage'] >= 95) {
                if (class_exists(\App\Services\Sentry\SentryExceptionHandler::class)) {
                    \App\Services\Sentry\SentryExceptionHandler::captureMessage(
                        'WARNING: Memory usage exceeded 95%', 
                        'warning', 
                        null, 
                        ['usage' => $status['memory']['percentage']]
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('monitoring.alert_check_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * محاسبه زمان گذشته
     */
    public function timeAgo(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return 'نامشخص';
        }
        
        $diff = time() - $timestamp;
        if ($diff < 0) {
            return 'هم‌اکنون';
        }
        if ($diff < 60) {
            return 'لحظاتی پیش';
        }
        
        $output = '';
        if ($diff < 3600) {
            $output = (int)($diff / 60) . ' دقیقه پیش';
        } elseif ($diff < 86400) {
            $output = (int)($diff / 3600) . ' ساعت پیش';
        } elseif ($diff < 604800) {
            $output = (int)($diff / 86400) . ' روز پیش';
        } elseif ($diff < 2592000) {
            $output = (int)($diff / 604800) . ' هفته پیش';
        } else {
            $output = (int)($diff / 2592000) . ' ماه پیش';
        }

        // LOW-14: Format final numeric time offsets using native Persian digits
        return $this->toPersianNumbers($output);
    }

    /**
     * شمارش تعداد هسته‌های فعال پردازنده جهت کالیبراسیون لود واقعی سیستم
     */
    private function getCpuCoreCount(): int
    {
        $cores = 1;
        try {
            if (!stristr(PHP_OS, 'win')) {
                if ($this->isContainerEnvironment()) {
                    // H18 Fix: جلوگیری از نشت اطلاعات تعداد هسته‌های هاست
                    $cores = 1;
                } else if (is_readable('/proc/cpuinfo')) {
                    $cpuinfo = @file_get_contents('/proc/cpuinfo');
                    if ($cpuinfo) {
                        preg_match_all('/^processor/m', $cpuinfo, $matches);
                        $cores = count($matches[0]) ?: 1;
                    }
                }
            } else {
                $processCount = getenv('NUMBER_OF_PROCESSORS');
                if ($processCount) {
                    $cores = (int)$processCount ?: 1;
                }
            }
        } catch (\Throwable) {
            // Fail-safe fallback to single-core divisor
            $cores = 1;
        }
        return $cores;
    }

    /**
     * تبدیل اعداد لاتین به معادل‌های بومی فارسی
     */
    private function toPersianNumbers(string $str): string
    {
        $eng = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return str_replace($eng, $fa, $str);
    }

    /**
     * H18 Support: تشخیص محیط Container
     */
    private function isContainerEnvironment(): bool
    {
        return @file_exists('/.dockerenv') || @file_exists('/run/.containerenv');
    }

    /**
     * H18 Support: خواندن امن مقادیر cgroup
     */
    private function readCgroupValue(array $paths): int
    {
        foreach ($paths as $path) {
            if (@is_readable($path)) {
                $val = trim((string)@file_get_contents($path));
                if (ctype_digit($val)) {
                    return (int)$val;
                }
            }
        }
        return 0;
    }
}
