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
class QueueWorker
{
    private bool $shouldQuit = false;

    private \App\Contracts\LoggerInterface $logger;
    private Queue $queue;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        Queue $queue
    ) {        $this->logger = $logger;
        $this->queue = $queue;

                $this->registerGracefulShutdownHandler();
    }

    private function registerGracefulShutdownHandler(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            $handler = function ($signo) {
                $this->logger->info('queue_worker.graceful_shutdown_initiated', ['signal' => $signo]);
                $this->shouldQuit = true;
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
            pcntl_signal(SIGQUIT, $handler);
        }
    }

    public function work(?string $queueName = null, int $limit = 10, ?array $allowedJobs = null): array
    {
        $processed = 0;
        $failed = 0;
        $skipped = 0;
        $limit = max(1, min(500, $limit));
        $allowedJobs ??= $this->defaultAllowedJobs();

        for ($i = 0; $i < $limit; $i++) {
            if ($this->shouldQuit) {
                $this->logger->info('queue_worker.stopped_gracefully', ['processed' => $processed]);
                break;
            }

            $job = $this->queue->pop($queueName);
            if (!$job) {
                break; // No more jobs
            }

            try {
                // اجرای خطی و همگام جاب‌ها - مقیاس‌پذیری افقی (Horizontal Scaling) 
                // باید از طریق اجرای چندین Worker به صورت موازی (مثلاً با Supervisor) انجام شود.
                // Record attempt for the system-wide retry budget tracking
                \Core\RetryPolicy::recordAttempt('queue:' . ($queueName ?: 'default'));
                $this->handleJob($job, $allowedJobs);
                $this->queue->delete((int) $job['id']);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->handleFailure($job, $e, $queueName);
            } finally {
                $this->performMemoryCleanup();
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
        $traceId = $job['meta']['trace_id'] ?? null;
        
        if ($traceId) {
            $_SERVER['HTTP_X_TRACE_ID'] = $traceId; // Propagate distributed trace context
        }

        if ($correlationId && method_exists($this->logger, 'withContext')) {
            $this->logger->withContext([
                'correlation_id' => $correlationId,
                'trace_id' => $traceId,
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

    private function handleFailure(array $job, \Throwable $e, ?string $queueName = null): void
    {
        $attempts = (int) ($job['attempts'] ?? 0);
        $jobClass = (string) ($job['job'] ?? '');
        $context = 'queue:' . ($queueName ?: 'default');

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
            $isFatal = $this->isFatalException($e);

            if ($isFatal || $attempts >= $this->queue->getMaxAttempts()) {
                // Poison Message Handler Logic: In-place classification
                $classificationResult = $this->classifyError($e);
                $errorClass = $classificationResult['class'];
                $status = $classificationResult['status'];
                $nextRetryAt = null;

                // Smart retry for transient errors that hit max attempts
                if ($errorClass === 'transient') {
                    // Maximum of 3 extra smart retries inside the DLQ
                    $dlqRetries = (int)($job['data']['_dlq_retry_count'] ?? 0);
                    if ($dlqRetries < 3) {
                        $status = 'retrying';
                        // Exponential backoff: 1h, 4h, 12h
                        $backoffHours = [1, 4, 12];
                        $nextRetryAt = time() + ($backoffHours[$dlqRetries] * 3600);
                    } else {
                        $status = 'dead_letter'; // Give up after DLQ retries exhausted
                    }
                } elseif ($errorClass === 'business') {
                    $this->logger->critical('queue_poison_message_quarantined', [
                        'job_id' => $job['id'] ?? null,
                        'job' => $jobClass,
                        'error' => $e->getMessage()
                    ]);
                }

                $this->queue->fail((int) $job['id'], $e, $errorClass, $status, $nextRetryAt);
                
                $this->logger->warning('queue_job_sent_to_dlq', [
                    'job_id' => $job['id'] ?? null,
                    'job' => $jobClass,
                    'reason' => $isFatal ? 'fatal_error' : 'max_attempts_reached',
                    'classification' => $errorClass,
                    'status' => $status
                ]);

                // Sentry Integration: Capture DLQ failures for monitoring/alerting
                try {
                    \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                        'queue_job_id' => $job['id'] ?? null,
                        'queue_job_class' => $jobClass,
                        'attempts' => $attempts,
                        'dlq_status' => $status
                    ]);
                } catch (\Throwable $sentryError) {
                    // Fail silently for sentry
                }
                return;
            }

            // System-wide Retry Budget check to prevent Queue Retry Storm
            if (!\Core\RetryPolicy::acquireRetryBudget($context)) {
                $this->logger->critical('queue_job_aborted_budget', [
                    'job_id' => $job['id'] ?? null,
                    'job' => $jobClass,
                    'context' => $context
                ]);
                $this->queue->fail((int) $job['id'], new \RuntimeException("Queue Retry Storm protection: system-wide retry budget exhausted for context {$context}. " . $e->getMessage(), 503, $e));
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

    /**
     * Determines if an exception should immediately send the job to DLQ
     * without attempting any further retries.
     */
    private function isFatalException(\Throwable $e): bool
    {
        if ($e instanceof \Core\Exceptions\ValidationException ||
            $e instanceof \App\Exceptions\BusinessException ||
            $e instanceof \InvalidArgumentException ||
            $e instanceof \TypeError) {
            return true;
        }

        if ($e instanceof \PDOException) {
            // Check for SQLSTATE 23000: Integrity constraint violation
            if (isset($e->errorInfo[0]) && $e->errorInfo[0] === '23000') {
                return true;
            }
            // Check for syntax errors which won't fix themselves (42000)
            if (isset($e->errorInfo[0]) && $e->errorInfo[0] === '42000') {
                return true;
            }
        }

        return false;
    }

    /**
     * Poison Message Handler: Classify the error to determine DLQ routing
     */
    private function classifyError(\Throwable $e): array
    {
        $class = get_class($e);
        $message = strtolower($e->getMessage());

        // 1. Business Logic Errors -> Quarantined
        if ($e instanceof \App\Exceptions\BusinessException ||
            $e instanceof \Core\Exceptions\ValidationException) {
            return ['class' => 'business', 'status' => 'quarantined'];
        }

        // 2. Transient Errors (Network, DB Lock, Timeout) -> Retrying
        if ($e instanceof \PDOException) {
            $sqlState = $e->errorInfo[0] ?? '';
            // Deadlock, Connection Timeout, Server Has Gone Away
            if (in_array($sqlState, ['40001', 'HY000', '08S01']) || str_contains($message, 'timeout') || str_contains($message, 'lock')) {
                return ['class' => 'transient', 'status' => 'retrying'];
            }
        }
        if (str_contains($message, 'timeout') || str_contains($message, 'connection') || str_contains($message, 'network')) {
            return ['class' => 'transient', 'status' => 'retrying'];
        }

        // 3. Permanent Errors (Syntax, Type, Not Found) -> Dead Letter
        if ($e instanceof \TypeError || 
            $e instanceof \ParseError || 
            $e instanceof \InvalidArgumentException ||
            str_contains($message, 'not found') || 
            str_contains($message, 'syntax error')) {
            return ['class' => 'permanent', 'status' => 'dead_letter'];
        }

        // Default
        return ['class' => 'unknown', 'status' => 'pending_analysis'];
    }

    private function defaultAllowedJobs(): array
    {
        return config('queue.allowed_jobs') ?? [
            \App\Jobs\ApplyWeeklyProfitLossJob::class,
            \App\Jobs\LogPerformanceJob::class,
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
            \App\Jobs\RunCronTaskJob::class,
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

        if ($container->has(\Core\Application::class)) {
            try {
                $app = $container->make(\Core\Application::class);
                if (method_exists($app, 'forgetUser')) {
                    $app->forgetUser();
                }
            } catch (\Throwable $ignored) {}
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

        // ۵. پاکسازی قفل‌های بجامانده در سیستم کش
        if ($container->has(\Core\Cache::class)) {
            try {
                $cache = $container->make(\Core\Cache::class);
                if (method_exists($cache, 'flushAllLocks')) {
                    $cache->flushAllLocks();
                }
            } catch (\Throwable $ignored) {}
        }

        // ۶. اجرای رفتگر برای بازپس‌گیری حافظه‌های چرخه‌ای
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // ۷. تازه‌سازی پیکربندی‌ها برای 반영 تغییرات feature flag یا config
        if (function_exists('config_reload')) {
            config_reload();
        }
    }
}
