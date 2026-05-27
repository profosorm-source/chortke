<?php

declare(strict_types=1);

namespace App\Jobs;

use Core\Container;
use Core\Scheduler;

/**
 * RunCronTaskJob
 * 
 * این جاب توسط Queue Worker دریافت می‌شود و مسئول اجرای واقعی کلوژرهای کرون‌جاب‌ها در پس‌زمینه است.
 */
class RunCronTaskJob
{
    /**
     * @param array $data باید شامل کلید 'task_name' باشد
     */
    public function handle(array $data): void
    {
        $taskName = $data['task_name'] ?? null;
        if (!$taskName) {
            logger()->error('run_cron_task_job_failed', ['reason' => 'missing_task_name']);
            return;
        }

        try {
            $container = Container::getInstance();
            $scheduler = $container->make(Scheduler::class);
            
            // اطمینان از اینکه Kernel بارگذاری شده و وظایف ثبت شده‌اند
            if (class_exists(\App\Console\Kernel::class)) {
                // فلگ اجبار را روشن می‌کنیم تا تسک‌ها با زمان‌بندی (هرچند زمانشان گذشته باشد) در Scheduler رجیستر شوند
                $scheduler->forceRegisterJobs(true);
                \App\Console\Kernel::schedule($scheduler);
            } else {
                throw new \Exception("App\Console\Kernel not found");
            }

            // اجرای مستقیم متد متناظر با این تسک (بدون بررسی مجدد interval)
            $scheduler->executeJobByName($taskName);

        } catch (\Throwable $e) {
            logger()->error('run_cron_task_job_exception', [
                'task_name' => $taskName,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine()
            ]);
            throw $e; // Throwing it allows the Queue system to handle retries/DLQ
        }
    }
}
