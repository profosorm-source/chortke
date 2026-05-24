<?php

declare(strict_types=1);

namespace App\Commands;

use Core\Command;
use Core\Database;
use Core\Logger;

/**
 * QueueFailedCommand - فاز ۵ (Section 8.5)
 *
 * مدیریت کامل DLQ (Dead Letter Queue)
 */
class QueueFailedCommand extends Command
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function list(): void
    {
        $failed = $this->db->fetchAll("SELECT * FROM failed_jobs ORDER BY failed_at DESC LIMIT 50");
        $this->info("Found " . count($failed) . " failed jobs:");
        foreach ($failed as $job) {
            $this->line("ID: {$job->id} | Queue: {$job->queue} | Failed at: {$job->failed_at}");
        }
    }

    public function retry(int $id): void
    {
        $job = $this->db->fetch("SELECT * FROM failed_jobs WHERE id = ?", [$id]);
        if (!$job) {
            $this->error("Job not found");
            return;
        }

        $this->info("Retrying job #{$id}...");
        
        $queue = app(\Core\Queue::class);
        $payload = json_decode((string)$job->payload, true);
        if (is_array($payload) && !empty($payload['job'])) {
            $ok = $queue->push(
                (string)$payload['job'],
                (array)($payload['data'] ?? []),
                (string)($job->queue ?? 'default')
            );
            if ($ok) {
                $this->db->execute("DELETE FROM failed_jobs WHERE id = ?", [$id]);
                $this->info("Job retried successfully and pushed back to queue.");
            } else {
                $this->error("Failed to push job back to queue.");
            }
        } else {
            $this->error("Invalid job payload.");
        }
    }

    public function forget(int $id): void
    {
        $this->db->execute("DELETE FROM failed_jobs WHERE id = ?", [$id]);
        $this->info("Job #{$id} forgotten");
    }

    public function replayAll(): void
    {
        $queue = app(\Core\Queue::class);
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM failed_jobs");
        if ($count == 0) {
            $this->info("No failed jobs to replay.");
            return;
        }

        $this->info("Replaying {$count} failed jobs...");
        $stats = $queue->retryFailedJobs((int)$count);
        $this->info("Replay complete: {$stats['requeued']} requeued, {$stats['errors']} errors, {$stats['skipped']} skipped.");
    }
}
