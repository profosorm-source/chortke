<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\LoggerInterface;
use App\Services\OutboxPublisher;

/**
 * CLI entry point for the OutboxPublisher (Section 8.1 — transactional outbox).
 *
 * Usage:
 *   php cli.php outbox:publish [--limit=50]
 */
class OutboxPublishCommand
{
    private OutboxPublisher $publisher;
    private LoggerInterface $logger;
    public function __construct(
        OutboxPublisher $publisher,
        LoggerInterface $logger
    ) {        $this->publisher = $publisher;
        $this->logger = $logger;
}

    public function run(array $argv): void
    {
        $limit = 50;
        foreach ($argv as $arg) {
            if (is_string($arg) && str_starts_with($arg, '--limit=')) {
                $limit = (int)substr($arg, 8);
            }
        }
        $limit = max(1, min(500, $limit));

        try {
            $result = $this->publisher->publishPending($limit);
            $this->logger->info('outbox.cli.publish.completed', $result);

            $published = (int)($result['published'] ?? 0);
            $failed    = (int)($result['failed'] ?? 0);
            $skipped   = $result['skipped'] ?? null;

            if ($skipped) {
                echo "[outbox] skipped: {$skipped}\n";
                return;
            }
            echo "[outbox] published={$published} failed={$failed} (limit={$limit})\n";
        } catch (\Throwable $e) {
            $this->logger->error('outbox.cli.publish.failed', ['error' => $e->getMessage()]);
            fwrite(STDERR, "[outbox] error: " . $e->getMessage() . "\n");
            exit(1);
        }
    }
}
