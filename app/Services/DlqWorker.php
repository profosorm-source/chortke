<?php

declare(strict_types=1);

namespace App\Services;

use Core\Container;
use Core\Queue;
use App\Contracts\LoggerInterface;

class DlqWorker
{
    private bool $shouldQuit = false;

    private \App\Contracts\LoggerInterface $logger;
    private Queue $queue;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        Queue $queue
    )
    {        $this->logger = $logger;
        $this->queue = $queue;

                $this->registerGracefulShutdownHandler();
    }

    private function registerGracefulShutdownHandler(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            $handler = function ($signo) {
                $this->logger->info('dlq_worker.graceful_shutdown_initiated', ['signal' => $signo]);
                $this->shouldQuit = true;
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
            pcntl_signal(SIGQUIT, $handler);
        }
    }

    /**
     * اجرای ورکر DLQ برای پردازش پیام‌های مرده
     */
    public function work(int $limit = 10): array
    {
        $processed = 0;
        $compensated = 0;
        $archived = 0;
        $limit = max(1, min(500, $limit));

        for ($i = 0; $i < $limit; $i++) {
            if ($this->shouldQuit) {
                $this->logger->info('dlq_worker.stopped_gracefully', ['processed' => $processed]);
                break;
            }

            // ۱. برداشتن جاب از جدول failed_jobs
            $job = $this->queue->popDlq();
            if (!$job) {
                break; // دیگر جابی در DLQ نیست
            }

            try {
                $this->processDlqJob($job);
                $processed++;
            } catch (\Throwable $e) {
                $this->logger->critical('dlq_worker.unhandled_exception', [
                    'dlq_job_id' => $job['id'] ?? null,
                    'error' => $e->getMessage()
                ]);
            } finally {
                $this->performMemoryCleanup();
            }
        }

        return [
            'processed' => $processed,
            'compensated' => $compensated,
            'archived' => $archived
        ];
    }

    private function processDlqJob(array $job): void
    {
        $jobClass = (string) ($job['job'] ?? '');
        $data = (array) ($job['data'] ?? []);
        $exceptionStr = (string) ($job['exception'] ?? '');

        // تشخیص اینکه آیا خطا سینتکس یا ولی‌دیشن است (Poison Message قطعی)
        if (str_contains($exceptionStr, 'ValidationException') || 
            str_contains($exceptionStr, 'TypeError') || 
            str_contains($exceptionStr, 'ParseError')) {
            
            // پیام سمی غیرقابل بازیابی
            $this->queue->archiveDlqJob($job, 'unrecoverable_poison_message');
            return;
        }

        // تلاش مجدد با Exponential Backoff
        $attempts = (int)($job['attempts'] ?? 1);
        if ($attempts < 5) {
            $delay = (int)pow(2, $attempts) * 60; // 2, 4, 8, 16 دقیقه
            $data['attempts'] = $attempts + 1; // آپدیت تعداد تلاش‌ها در دیتای جاب
            
            // بازگردانی به صف اصلی با تاخیر
            $this->queue->push($jobClass, $data, null, $delay);
            
            $this->logger->info('dlq_worker.requeued_with_backoff', [
                'job' => $jobClass,
                'delay_seconds' => $delay,
                'next_attempt' => $data['attempts']
            ]);
            
            $this->queue->archiveDlqJob($job, 'requeued_to_main_with_backoff');
            return;
        }

        if (class_exists($jobClass)) {
            try {
                $handler = Container::getInstance()->make($jobClass);
                
                // ۲. تلاش برای اجرای جبران (Compensation)
                if (method_exists($handler, 'compensate')) {
                    $this->logger->info('dlq_worker.running_compensation', ['job' => $jobClass]);
                    $handler->compensate($data, new \RuntimeException($exceptionStr));
                    
                    $this->queue->archiveDlqJob($job, 'compensated_successfully');
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->error('dlq_worker.compensation_failed', [
                    'job' => $jobClass,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // اگر نه Poison Message قطعی بود و نه متد جبرانی داشت،
        // فقط آرشیو می‌کنیم و برای مدیر نوتیفیکیشن می‌فرستیم.
        $this->notifyAdmin($job);
        $this->queue->archiveDlqJob($job, 'archived_pending_manual_review');
    }

    private function notifyAdmin(array $job): void
    {
        // در صورت نیاز ارسال ایمیل یا پیامک به ادمین برای بررسی دستی
        $this->logger->alert('dlq_worker.admin_action_required', [
            'dlq_job_id' => $job['id'],
            'job_class' => $job['job'],
            'failed_at' => $job['failed_at']
        ]);
    }

    /**
     * پاکسازی کامل حافظه پس از اجرای هر جاب در پروسه‌های طولانی (Long-running Queue Workers)
     */
    private function performMemoryCleanup(): void
    {
        $container = Container::getInstance();

        if (method_exists($container, 'flushScoped')) {
            $container->flushScoped();
        }

        if (method_exists($container, 'flushSingletonInstances')) {
            $container->flushSingletonInstances();
        }

        if (method_exists($container, 'cleanupReflectionCache')) {
            $container->cleanupReflectionCache();
        }

        if ($container->has(\Core\Application::class)) {
            try {
                $app = $container->make(\Core\Application::class);
                if (method_exists($app, 'forgetUser')) {
                    $app->forgetUser();
                }
            } catch (\Throwable $ignored) {}
        }

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
}
