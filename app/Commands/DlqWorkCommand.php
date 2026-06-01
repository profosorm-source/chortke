<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\DlqWorker;
use App\Contracts\LoggerInterface;

/**
 * CLI command daemon to run the DlqWorker and process dead letter jobs.
 *
 * Usage:
 *   php cli.php dlq:work [--limit=10]
 */
class DlqWorkCommand
{
    private DlqWorker $worker;
    private LoggerInterface $logger;
    public function __construct(
        DlqWorker $worker,
        LoggerInterface $logger
    ) {        $this->worker = $worker;
        $this->logger = $logger;
}

    /**
     * Executes the CLI command.
     */
    public function run(array $argv = []): array
    {
        $limit = 10;

        foreach ($argv as $arg) {
            if (is_string($arg)) {
                if (str_starts_with($arg, '--limit=')) {
                    $limit = (int)substr($arg, 8);
                }
            }
        }

        // Validate range limits
        $limit = max(1, min(500, $limit));

        $this->logger->info('dlq.command.work.starting', [
            'limit' => $limit
        ]);

        try {
            $result = $this->worker->work($limit);
            $processed = (int)($result['processed'] ?? 0);
            $compensated = (int)($result['compensated'] ?? 0);
            $archived = (int)($result['archived'] ?? 0);

            $this->logger->info('dlq.command.work.completed', [
                'processed' => $processed,
                'compensated' => $compensated,
                'archived' => $archived
            ]);

            echo "[dlq:work] processed={$processed} compensated={$compensated} archived={$archived} (limit={$limit})\n";

            return [
                'processed' => $processed,
                'compensated' => $compensated,
                'archived' => $archived
            ];
        } catch (\Throwable $e) {
            $this->logger->error('dlq.command.work.failed', [
                'error' => $e->getMessage()
            ]);
            fwrite(STDERR, "[dlq:work] error: " . $e->getMessage() . "\n");
            return [
                'processed' => 0,
                'compensated' => 0,
                'archived' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
}
