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

    private bool $forceRegisterJobs = false;

    /** @var Logger */
    private Logger $logger;

    private ActivityLog $activityLog;

    public function __construct(ActivityLog $activityLog, ?string $lockDir = null)
{
    $this->activityLog = $activityLog;
    $this->lockDir = $lockDir ?? __DIR__ . '/../storage/cron/';
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

    /** هر N ثانیه */
    public function everySeconds(int $seconds, callable $callback, string $name = ''): self
    {
        return $this->addJob("every_{$seconds}_seconds", $callback, $name, $seconds);
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
        if (!$this->forceRegisterJobs && $now !== $minute) {
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
        if (!$this->forceRegisterJobs && ($nowH !== (int)$h || $nowM !== (int)$m)) {
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
        if (!$this->forceRegisterJobs && (strtolower($nowDay) !== strtolower($day) || $nowH !== (int)$h || $nowM !== (int)$m)) {
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
        if (!$this->forceRegisterJobs && ($nowDay !== $dayOfMonth || $nowH !== (int)$h || $nowM !== (int)$m)) {
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

            $lastRunKey = 'cron_last_run:' . md5($job['key']);
            $mutexKey = 'cron_mutex:' . md5($job['key']);

            // ۱. بررسی فاصله زمانی از آخرین اجرای موفق (فقط برای کرون اتوماتیک)
            if ($onlyJobName === null) {
                $lastRun = Cache::getInstance()->get($lastRunKey);
                if ($lastRun && (time() - (int)$lastRun) < ($job['interval'] - 5)) {
                    $results[$job['name']] = ['status' => 'skipped', 'reason' => 'already_run_within_interval'];
                    continue;
                }
            }

            // ۲. دریافت قفل همزمانی کوتاه‌مدت (جلوگیری از تداخل لحظه‌ای)
            try {
                if (!Cache::getInstance()->lock($mutexKey, 300)) { // قفل ۵ دقیقه‌ای
                    $results[$job['name']] = ['status' => 'skipped', 'reason' => 'concurrent_mutex'];
                    continue;
                }
            } catch (\Throwable $e) {
                // CORE-055: Gracefully skip execution if the locking mechanism fails (e.g., Redis down or file lock disabled in prod)
                $this->logger->warning("Cron [{$job['name']}] lock mechanism failure: " . $e->getMessage());
                $results[$job['name']] = ['status' => 'skipped', 'reason' => 'lock_failure_skipped'];
                continue;
            }

            $start = microtime(true);
            try {
                $output = ($job['callback'])();
                $duration = round((microtime(true) - $start) * 1000, 2);

                // ۳. ثبت موفقیت اجرای این بازه
                Cache::getInstance()->forever($lastRunKey, time());

                $results[$job['name']] = [
                    'status'   => 'ok',
                    'duration' => $duration . 'ms',
                    'output'   => $output,
                ];

                // CORE-056: Redact sensitive details from execution output before storing in persistent log files
                $loggedOutput = is_array($output) ? $this->redactSensitiveData($output) : ($output ?? []);

                $this->logger->info("Cron [{$job['name']}] OK in {$duration}ms", is_array($loggedOutput) ? $loggedOutput : []);

                // ثبت لاگ در activity_logs برای نمایش در پنل مدیریت
                try {
                    $this->activityLog->log(
                        'cron',
                        $job['name'] . ' [' . $job['key'] . ']',
                        null,
                        array_merge(
                            is_array($loggedOutput) ? $loggedOutput : [],
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
                Cache::getInstance()->unlock('cron_mutex:' . md5($job['key']));
            }
        }

        return $results;
    }

    /**
     * ارسال تمامی وظایف به Queue به جای اجرای همزمان (Synchronous)
     */
    public function dispatchAllAsync(?string $onlyJobName = null): array
    {
        $results = [];
        $queue = \Core\Container::getInstance()->make(\Core\Queue::class);

        foreach ($this->jobs as $job) {
            if ($onlyJobName !== null && $job['name'] !== $onlyJobName) {
                continue;
            }

            $lastRunKey = 'cron_last_run:' . md5($job['key']);

            if ($onlyJobName === null) {
                $lastRun = Cache::getInstance()->get($lastRunKey);
                if ($lastRun && (time() - (int)$lastRun) < ($job['interval'] - 5)) {
                    $results[$job['name']] = ['status' => 'skipped', 'reason' => 'already_run_within_interval'];
                    continue;
                }
            }

            try {
                // فقط به جای اجرای مستقیم، تسک را به صف Queue ارسال می‌کنیم
                $queue->push(\App\Jobs\RunCronTaskJob::class, ['task_name' => $job['name']]);
                
                Cache::getInstance()->forever($lastRunKey, time());
                $results[$job['name']] = ['status' => 'queued'];
            } catch (\Throwable $e) {
                $results[$job['name']] = ['status' => 'error', 'message' => $e->getMessage()];
                $this->logger->error("Cron Dispatch [{$job['name']}] FAILED: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * اجرای مستقیم یک Job از طریق نام (برای استفاده در Queue Worker)
     */
    public function executeJobByName(string $name): mixed
    {
        foreach ($this->jobs as $job) {
            if ($job['name'] === $name) {
                $mutexKey = 'cron_mutex:' . md5($job['key']);
                
                // قفل کوتاه مدت در سطح اجرای Worker
                if (!Cache::getInstance()->lock($mutexKey, 300)) {
                    $this->logger->warning("Cron Worker [{$job['name']}] skipped due to lock.");
                    return ['status' => 'skipped', 'reason' => 'concurrent_mutex'];
                }

                $start = microtime(true);
                try {
                    $output = ($job['callback'])();
                    $duration = round((microtime(true) - $start) * 1000, 2);

                    $loggedOutput = is_array($output) ? $this->redactSensitiveData($output) : ($output ?? []);
                    $this->logger->info("Cron [{$job['name']}] OK in {$duration}ms", is_array($loggedOutput) ? $loggedOutput : []);

                    try {
                        $this->activityLog->log('cron', $job['name'], null, array_merge(
                            is_array($loggedOutput) ? $loggedOutput : [],
                            ['execution_time' => $duration . 'ms']
                        ));
                    } catch (\Throwable $logEx) {}

                    return $output;
                } finally {
                    Cache::getInstance()->unlock($mutexKey);
                }
            }
        }
        
        throw new \RuntimeException("Cron job not found: {$name}");
    }

    // ==========================================
    //  private helpers
    // ==========================================

    public function forceRegisterJobs(bool $force = true): void
    {
        $this->forceRegisterJobs = $force;
    }

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

    /**
     * CORE-056: Recursively sanitizes and redacts potentially sensitive keys inside telemetry payloads
     */
    private function redactSensitiveData(array $data): array
    {
        static $sensitiveKeywords = ['pass', 'pwd', 'token', 'secret', 'key', 'auth', 'card', 'phone', 'email', 'wallet', 'hash'];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redactSensitiveData($value);
                continue;
            }

            $lowerKey = strtolower((string)$key);
            foreach ($sensitiveKeywords as $word) {
                if (str_contains($lowerKey, $word)) {
                    $data[$key] = '[REDACTED]';
                    break;
                }
            }
        }

        return $data;
    }
}