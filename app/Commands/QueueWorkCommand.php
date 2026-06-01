<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\QueueWorker;
use App\Contracts\LoggerInterface;

/**
 * CLI command daemon to run the QueueWorker and process jobs.
 *
 * Usage:
 *   php cli.php queue:work [--queue=default] [--limit=10]
 */
class QueueWorkCommand
{
    private QueueWorker $worker;
    private LoggerInterface $logger;
    public function __construct(
        QueueWorker $worker,
        LoggerInterface $logger
    ) {        $this->worker = $worker;
        $this->logger = $logger;
}

    /**
     * Executes the CLI command.
     */
    public function run(array $argv = []): array
    {
        $queue = null;
        $limit = 10;

        foreach ($argv as $arg) {
            if (is_string($arg)) {
                if (str_starts_with($arg, '--queue=')) {
                    $queue = substr($arg, 8);
                } elseif (str_starts_with($arg, '--limit=')) {
                    $limit = (int)substr($arg, 8);
                }
            }
        }

        // Validate range limits
        $limit = max(1, min(500, $limit));

        $this->logger->info('queue.command.work.starting', [
            'queue' => $queue ?? 'all',
            'limit' => $limit
        ]);

        try {
            $result = $this->worker->work($queue, $limit);
            $processed = (int)($result['processed_jobs'] ?? 0);
            $failed = (int)($result['failed_jobs'] ?? 0);

            $this->logger->info('queue.command.work.completed', [
                'processed' => $processed,
                'failed' => $failed,
                'queue' => $queue ?? 'all'
            ]);

            echo "[queue:work] processed={$processed} failed={$failed} (limit={$limit})\n";

            return [
                'processed_jobs' => $processed,
                'failed_jobs' => $failed
            ];
        } catch (\Throwable $e) {
            $this->logger->error('queue.command.work.failed', [
                'queue' => $queue ?? 'all',
                'error' => $e->getMessage()
            ]);
            fwrite(STDERR, "[queue:work] error: " . $e->getMessage() . "\n");
            return [
                'processed_jobs' => 0,
                'failed_jobs' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
}
