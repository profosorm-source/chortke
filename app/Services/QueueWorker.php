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

    public function work(?string $queueName = null, int $limit = 10, ?array $allowedJobs = null): array
    {
        $processed = 0;
        $failed = 0;
        $skipped = 0;
        $limit = max(1, min(500, $limit));
        $allowedJobs ??= $this->defaultAllowedJobs();

        for ($i = 0; $i < $limit; $i++) {
            $job = $this->queue->pop($queueName);
            if (!$job) {
                break;
            }

            try {
                $this->handleJob($job, $allowedJobs);
                $this->queue->delete((int) $job['id']);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->handleFailure($job, $e);
            } finally {
                if (method_exists(Container::getInstance(), 'flushScoped')) {
                    Container::getInstance()->flushScoped();
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

            $this->queue->release((int) $job['id']);
            $this->logger->warning('queue_job_released_retry', [
                'job_id' => $job['id'] ?? null,
                'job' => $jobClass,
                'attempts' => $attempts,
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
}
