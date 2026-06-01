<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\LoggerInterface;
use App\Jobs\BackfillSearchProjectionJob;

/**
 * CLI entry point برای Backfill اولیه‌ی Read-Model جستجو (CQRS).
 *
 * Usage:
 *   php cli.php search:backfill                 # همه‌ی منابع
 *   php cli.php search:backfill --source=transactions
 *   php cli.php search:backfill --batch=1000
 */
final class BackfillSearchProjectionCommand
{
    private BackfillSearchProjectionJob $job;
    private LoggerInterface $logger;
    public function __construct(
        BackfillSearchProjectionJob $job,
        LoggerInterface $logger
    ) {        $this->job = $job;
        $this->logger = $logger;

    }

    public function run(array $argv): void
    {
        $source = 'all';
        $batch = 500;

        foreach ($argv as $arg) {
            if (is_string($arg) && str_starts_with($arg, '--source=')) {
                $source = substr($arg, 9);
            } elseif (is_string($arg) && str_starts_with($arg, '--batch=')) {
                $batch = (int)substr($arg, 8);
            }
        }

        echo "[search:backfill] starting (source={$source}, batch={$batch})...\n";

        try {
            $this->job->handle(['source' => $source, 'batch_size' => $batch]);
            echo "[search:backfill] completed. See logs for per-source totals.\n";
        } catch (\Throwable $e) {
            $this->logger->error('search.backfill.cli_failed', ['error' => $e->getMessage()]);
            fwrite(STDERR, "[search:backfill] error: " . $e->getMessage() . "\n");
            exit(1);
        }
    }
}
