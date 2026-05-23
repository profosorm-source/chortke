<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use Core\Container;
use Core\EventDispatcher;
use Core\Queue;

/**
 * QueueWorker - executes queued jobs and centralizes retry/DLQ handling.
 *
 * Core\Queue فقط storage abstraction باقی می‌ماند؛ اجرای Jobها اینجا انجام می‌شود.
 */
class QueueWorker extends BaseService
{
    public function __construct(
        private Queue $queue,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    public function work(?string $queueName = null, int $limit = 10, ?array $allowedJobs = null, int $maxConcurrency = 4): array
    {
        $processed = 0;
        $failed = 0;
        $skipped = 0;
        $limit = max(1, min(500, $limit));
        $allowedJobs ??= $this->defaultAllowedJobs();

        $canFork = function_exists('pcntl_fork') && !stristr(PHP_OS, 'win');
        $activeChildren = [];

        for ($i = 0; $i < $limit; $i++) {
            if ($canFork) {
                // Reap exited children
                foreach ($activeChildren as $pid => $childJob) {
                    $res = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($res == -1 || $res > 0) {
                        if ($res > 0) {
                            $exitCode = pcntl_wexitstatus($status);
                            if ($exitCode === 0) {
                                $processed++;
                            } else {
                                $failed++;
                            }
                        }
                        unset($activeChildren[$pid]);
                    }
                }

                // If concurrency limit is reached, wait for at least one child to finish
                while (count($activeChildren) >= $maxConcurrency) {
                    $pid = pcntl_waitpid(-1, $status);
                    if ($pid > 0) {
                        $exitCode = pcntl_wexitstatus($status);
                        if ($exitCode === 0) {
                            $processed++;
                        } else {
                            $failed++;
                        }
                        unset($activeChildren[$pid]);
                    } else {
                        break;
                    }
                }
            }

            $job = $this->queue->pop($queueName);
            if (!$job) {
                break;
            }

            if ($canFork) {
                $pid = pcntl_fork();
                if ($pid == -1) {
                    // Fork failed, fallback to synchronous handling
                    $this->logger->error('queue.fork_failed_falling_back', ['job_id' => $job['id']]);
                    try {
                        $this->handleJob($job, $allowedJobs);
                        $this->queue->delete((int) $job['id']);
                        $processed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->handleFailure($job, $e);
                    } finally {
                        $this->performMemoryCleanup();
                    }
                } elseif ($pid === 0) {
                    // Child Process
                    try {
                        $this->handleJob($job, $allowedJobs);
                        $this->queue->delete((int) $job['id']);
                        exit(0);
                    } catch (\Throwable $e) {
                        $this->handleFailure($job, $e);
                        exit(1);
                    }
                } else {
                    // Parent Process: track child
                    $activeChildren[$pid] = $job;
                }
            } else {
                // Synchronous processing fallback (Windows / no pcntl)
                try {
                    $this->handleJob($job, $allowedJobs);
                    $this->queue->delete((int) $job['id']);
                    $processed++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->handleFailure($job, $e);
                } finally {
                    $this->performMemoryCleanup();
                }
            }
        }

        // Parent wait for all remaining children to finish
        if ($canFork && !empty($activeChildren)) {
            while (count($activeChildren) > 0) {
                $pid = pcntl_waitpid(-1, $status);
                if ($pid > 0) {
                    $exitCode = pcntl_wexitstatus($status);
                    if ($exitCode === 0) {
                        $processed++;
                    } else {
                        $failed++;
                    }
                    unset($activeChildren[$pid]);
                } else {
                    break;
                }
            }
        }

        return [
            'processed_jobs' => $processed,
            'failed_jobs' => $failed,
            'skipped_jobs' => $skipped,
        ];
    }

    private function handleJob(array $job, array $allowedJobs): void
    {
        $jobClass = (string) ($job['job'] ?? '');
        $data = (array) ($job['data'] ?? []);

        Container::resetTraceStack();

        $correlationId = $job['meta']['correlation_id'] ?? null;
        if ($correlationId && method_exists($this->logger, 'withContext')) {
            $this->logger->withContext([
                'correlation_id' => $correlationId,
                'queue_job_id' => $job['id'] ?? null,
            ]);
        }

        if ($jobClass === 'dispatch_event') {
            $dispatcher = Container::getInstance()->make(EventDispatcher::class);
            $dispatcher->processQueuedEvent($job);
            return;
        }

        if (!in_array($jobClass, $allowedJobs, true)) {
            throw new \RuntimeException("Queue job not allowed: {$jobClass}");
        }

        if (!class_exists($jobClass)) {
            throw new \RuntimeException("Queue job not found: {$jobClass}");
        }

        $handler = Container::getInstance()->make($jobClass);

        if (!method_exists($handler, 'handle')) {
            throw new \RuntimeException("Queue job has no handle method: {$jobClass}");
        }

        $handler->handle($data);
    }

    private function handleFailure(array $job, \Throwable $e): void
    {
        $attempts = (int) ($job['attempts'] ?? 0);
        $jobClass = (string) ($job['job'] ?? '');

        $this->logger->error('queue_job_failed', [
            'job_id' => $job['id'] ?? null,
            'job' => $jobClass,
            'attempts' => $attempts,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => config('app.debug') ? substr($e->getTraceAsString(), 0, 2048) : null,
        ]);

        try {
            if ($attempts >= $this->queue->getMaxAttempts()) {
                $this->queue->fail((int) $job['id'], $e);
                $this->logger->warning('queue_job_sent_to_dlq', [
                    'job_id' => $job['id'] ?? null,
                    'job' => $jobClass,
                ]);
                return;
            }

            // Calculate customized retry delay if defined on the job handler
            $delay = 0;
            if (class_exists($jobClass)) {
                try {
                    $handler = Container::getInstance()->make($jobClass);
                    if (method_exists($handler, 'retryAfter')) {
                        $delay = (int) $handler->retryAfter($attempts);
                    } elseif (property_exists($handler, 'backoff')) {
                        $delay = (int) $handler->backoff;
                    }
                } catch (\Throwable $inspectErr) {
                    // Fail-safe to default exponential delay if instantiation fails
                }
            }

            $this->queue->release((int) $job['id'], $delay);
            $this->logger->warning('queue_job_released_retry', [
                'job_id' => $job['id'] ?? null,
                'job' => $jobClass,
                'attempts' => $attempts,
                'delay_applied' => $delay,
            ]);
        } catch (\Throwable $failError) {
            $this->logger->critical('queue_failure_handler_failed', [
                'job_id' => $job['id'] ?? null,
                'job' => $jobClass,
                'error' => $failError->getMessage(),
            ]);
        }
    }

    private function defaultAllowedJobs(): array
    {
        return config('queue.allowed_jobs') ?? [
            \App\Jobs\ApplyWeeklyProfitLossJob::class,
            \App\Jobs\LogPerformanceJob::class,
            \App\Jobs\SendBulkNotificationJob::class,
            \App\Jobs\PersistBulkInAppNotificationJob::class,
            \App\Jobs\SendEmailJob::class,
            \App\Jobs\UpdateFraudScoreJob::class,
            \App\Jobs\InvestmentProfitDistributionJob::class,
            \App\Jobs\NotificationCleanupJob::class,
            \App\Jobs\EscrowTimeoutJob::class,
            \App\Jobs\CacheWarmupJob::class,
            \App\Jobs\ScoreRecalculationJob::class,
            \App\Jobs\PredictionGameSettlementJob::class,
            \App\Jobs\VitrineListingExpiryJob::class,
            \App\Jobs\InfluencerOrderTimeoutJob::class,
            \App\Jobs\SocialTaskApprovalReminderJob::class,
            \App\Jobs\AggregateAnalyticsJob::class,
        ];
    }

    /**
     * پاکسازی کامل حافظه پس از اجرای هر جاب در پروسه‌های طولانی (Long-running Queue Workers)
     */
    private function performMemoryCleanup(): void
    {
        $container = Container::getInstance();

        // ۱. پاکسازی آبجکت‌های Scoped
        if (method_exists($container, 'flushScoped')) {
            $container->flushScoped();
        }

        // ۲. پاکسازی نمونه‌های سینگلتون اضافی برای ممانعت از نشت حافظه
        if (method_exists($container, 'flushSingletonInstances')) {
            $container->flushSingletonInstances();
        }

        // ۳. پاکسازی کش رفلکشن کانتینر
        if (method_exists($container, 'cleanupReflectionCache')) {
            $container->cleanupReflectionCache();
        }

        // ۴. بازگردانی وضعیت شنونده‌های رویداد به حالت اولیه بوت‌استرپ
        if ($container->has(EventDispatcher::class)) {
            try {
                $dispatcher = $container->make(EventDispatcher::class);
                if (method_exists($dispatcher, 'restoreBootstrapState')) {
                    $dispatcher->restoreBootstrapState();
                }
            } catch (\Throwable $ignored) {}
        }

        // ۵. اجرای رفتگر برای بازپس‌گیری حافظه‌های چرخه‌ای
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
}
