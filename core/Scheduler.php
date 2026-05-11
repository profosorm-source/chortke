<?php

namespace Core;

use App\Models\ActivityLog;

/**
 * Scheduler - سیستم زمانبندی وظایف
 *
 * نحوه استفاده:
 *   $scheduler = new Scheduler();
 *   $scheduler->everyMinute(fn() => ...);
 *   $scheduler->hourly(fn() => ...);
 *   $scheduler->daily('02:00', fn() => ...);
 *   $scheduler->weekly('Monday', '03:00', fn() => ...);
 *   $scheduler->run();
 *
 * در crontab:
 *   * * * * * php /var/www/html/cron.php >> /var/log/chortke-cron.log 2>&1
 */
class Scheduler
{
    /** @var array لیست وظایف ثبت‌شده */
    private array $jobs = [];

    /** @var string مسیر فایل lock */
    private string $lockDir;

    /** @var Logger */
    private Logger $logger;

    public function __construct()
{
    $this->lockDir = __DIR__ . '/../storage/cron/';
    if (!is_dir($this->lockDir)) {
        mkdir($this->lockDir, 0755, true);
    }
    $this->logger = logger();
}

    // ==========================================
    //  ثبت وظایف با زمانبندی مختلف
    // ==========================================

    /** هر دقیقه */
    public function everyMinute(callable $callback, string $name = ''): self
    {
        return $this->addJob('every_minute', $callback, $name, 60);
    }

    /** هر N دقیقه */
    public function everyMinutes(int $minutes, callable $callback, string $name = ''): self
    {
        return $this->addJob("every_{$minutes}_minutes", $callback, $name, $minutes * 60);
    }

    /** هر ساعت (دقیقه ۰) */
    public function hourly(callable $callback, string $name = ''): self
    {
        return $this->addJob('hourly', $callback, $name, 3600);
    }

    /** هر ساعت در دقیقه مشخص */
    public function hourlyAt(int $minute, callable $callback, string $name = ''): self
    {
        $now = (int)date('i');
        if ($now !== $minute) {
            return $this;
        }
        return $this->addJob("hourly_at_{$minute}", $callback, $name, 3600);
    }

    /** روزانه در ساعت مشخص (مثلاً '02:30') */
    public function daily(string $time, callable $callback, string $name = ''): self
    {
        [$h, $m] = explode(':', $time);
        $nowH = (int)date('H');
        $nowM = (int)date('i');
        if ($nowH !== (int)$h || $nowM !== (int)$m) {
            return $this;
        }
        return $this->addJob("daily_{$time}", $callback, $name, 86400);
    }

    /** هفتگی در روز و ساعت مشخص */
    public function weekly(string $day, string $time, callable $callback, string $name = ''): self
    {
        [$h, $m] = explode(':', $time);
        $nowDay = date('l');   // Monday, Tuesday, ...
        $nowH   = (int)date('H');
        $nowM   = (int)date('i');
        if (strtolower($nowDay) !== strtolower($day) || $nowH !== (int)$h || $nowM !== (int)$m) {
            return $this;
        }
        return $this->addJob("weekly_{$day}_{$time}", $callback, $name, 604800);
    }

    /** ماهانه در روز و ساعت مشخص */
    public function monthly(int $dayOfMonth, string $time, callable $callback, string $name = ''): self
    {
        [$h, $m] = explode(':', $time);
        $nowDay = (int)date('j');
        $nowH   = (int)date('H');
        $nowM   = (int)date('i');
        if ($nowDay !== $dayOfMonth || $nowH !== (int)$h || $nowM !== (int)$m) {
            return $this;
        }
        return $this->addJob("monthly_{$dayOfMonth}_{$time}", $callback, $name, 2592000);
    }

    // ==========================================
    //  اجرا
    // ==========================================

    /**
     * اجرای همه وظایف واجد شرایط
     */
    public function run(?string $onlyJobName = null): array
    {
        $results = [];

        foreach ($this->jobs as $job) {
            // اگر فیلتر اعمال شده باشد، نام جاب باید مطابقت داشته باشد
            if ($onlyJobName !== null && $job['name'] !== $onlyJobName) {
                continue;
            }

            $lockKey = 'cron:' . md5($job['key']);

            // بررسی lock - جلوگیری از اجرای موازی با استفاده از سیستم کش (Redis/File)
            if (!Cache::getInstance()->lock($lockKey, $job['interval'])) {
                $results[$job['name']] = ['status' => 'skipped', 'reason' => 'lock'];
                continue;
            }

            $start = microtime(true);
            try {
                $output = ($job['callback'])();
                $duration = round((microtime(true) - $start) * 1000, 2);

                $results[$job['name']] = [
                    'status'   => 'ok',
                    'duration' => $duration . 'ms',
                    'output'   => $output,
                ];

                $this->logger->info("Cron [{$job['name']}] OK in {$duration}ms", $output ?? []);

                // ثبت لاگ در activity_logs برای نمایش در پنل مدیریت
                try {
                    $activityLog = Container::getInstance()->make(ActivityLog::class);
                    $activityLog->log(
                        'cron',
                        $job['name'] . ' [' . $job['key'] . ']',
                        null,
                        array_merge(
                            is_array($output) ? $output : [],
                            [
                                'job_key'        => $job['key'],
                                'execution_time' => $duration . 'ms',
                            ]
                        )
                    );
                } catch (\Throwable $logEx) {
                    // لاگ نشدن نباید اجرای cron رو متوقف کنه
                }

            } catch (\Throwable $e) {
                $duration = round((microtime(true) - $start) * 1000, 2);
                $results[$job['name']] = [
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile() . ':' . $e->getLine(),
                ];

                $this->logger->error("Cron [{$job['name']}] FAILED: " . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                // release lock در صورت خطا هم
            } finally {
                // release lock after execution
                Cache::getInstance()->unlock('cron:' . md5($job['key']));
            }
        }

        return $results;
    }

    // ==========================================
    //  private helpers
    // ==========================================

    private function addJob(string $key, callable $callback, string $name, int $intervalSeconds): self
    {
        $this->jobs[] = [
            'key'      => $key,
            'name'     => $name ?: $key,
            'callback' => $callback,
            'interval' => $intervalSeconds,
        ];
        return $this;
    }
}