<?php

declare(strict_types=1);

namespace App\Commands;

use Core\Database;
use Core\Queue;

/**
 * CLI for failed_jobs / DLQ management (Section 8.5 / 8.7).
 *
 * Usage:
 *   php cli.php queue:failed:list                              [--queue=name] [--limit=50]
 *   php cli.php queue:failed:retry        <id>
 *   php cli.php queue:failed:retry-batch                       [--queue=name] [--limit=100]
 *   php cli.php queue:failed:forget       <id>
 *   php cli.php queue:failed:purge                             [--days=14] [--queue=name]
 *   php cli.php queue:failed:stats
 */
class QueueFailedCommand
{
    public function __construct(
        private Database $db,
        private Queue $queue
    ) {}

    public function run(array $argv): void
    {
        $command = $argv[1] ?? 'queue:failed:list';
        $arg2 = $argv[2] ?? null;
        $opts = $this->parseOptions($argv);

        switch ($command) {
            case 'queue:failed:list':
                $this->list($opts);
                return;
            case 'queue:failed:retry':
                $this->retry((int)($arg2 ?? 0));
                return;
            case 'queue:failed:retry-batch':
                $this->retryBatch($opts);
                return;
            case 'queue:failed:forget':
                $this->forget((int)($arg2 ?? 0));
                return;
            case 'queue:failed:purge':
                $this->purge($opts);
                return;
            case 'queue:failed:stats':
                $this->stats();
                return;
            default:
                throw new \InvalidArgumentException('Unsupported queue failed command: ' . $command);
        }
    }

    private function list(array $opts): void
    {
        $limit = max(1, min(500, (int)($opts['limit'] ?? 50)));
        $queue = isset($opts['queue']) ? (string)$opts['queue'] : null;

        $sql = "SELECT id, queue, LEFT(payload, 180) AS payload_preview,
                       LEFT(exception, 160) AS error_preview, failed_at
                FROM failed_jobs";
        $params = [];
        if ($queue !== null && $queue !== '') {
            $sql .= " WHERE queue = ?";
            $params[] = $queue;
        }
        $sql .= " ORDER BY failed_at DESC LIMIT " . (int)$limit;

        $rows = $this->db->fetchAll($sql, $params);

        if (empty($rows)) {
            echo "No failed jobs found.\n";
            return;
        }
        foreach ($rows as $row) {
            echo sprintf(
                "#%d [%s] %s\n  payload: %s\n  error: %s\n\n",
                (int)$row->id,
                (string)$row->queue,
                (string)$row->failed_at,
                (string)$row->payload_preview,
                (string)$row->error_preview
            );
        }
    }

    private function retry(int $id): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Failed job id is required.');
        }
        $row = $this->db->fetch('SELECT * FROM failed_jobs WHERE id = ?', [$id]);
        if (!$row) {
            throw new \RuntimeException("Failed job #{$id} not found.");
        }
        $payload = json_decode((string)$row->payload, true);
        if (!is_array($payload) || empty($payload['job'])) {
            throw new \RuntimeException('Failed job payload is invalid.');
        }

        $this->queue->push(
            (string)$payload['job'],
            (array)($payload['data'] ?? []),
            (string)($row->queue ?? 'default')
        );
        $this->db->execute('DELETE FROM failed_jobs WHERE id = ?', [$id]);
        echo "Retried failed job #{$id}.\n";
    }

    private function retryBatch(array $opts): void
    {
        $queue = isset($opts['queue']) ? (string)$opts['queue'] : null;
        $limit = max(1, min(1000, (int)($opts['limit'] ?? 100)));

        $r = $this->queue->retryFailedJobsBatch($queue, $limit);
        echo sprintf(
            "[retry-batch] requeued=%d skipped=%d errors=%d%s\n",
            $r['requeued'],
            $r['skipped'],
            $r['errors'],
            $queue !== null ? " (queue={$queue})" : ''
        );
    }

    private function forget(int $id): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Failed job id is required.');
        }
        $deleted = (int)$this->db->execute('DELETE FROM failed_jobs WHERE id = ?', [$id]);
        echo $deleted > 0 ? "Forgot failed job #{$id}.\n" : "Failed job #{$id} not found.\n";
    }

    private function purge(array $opts): void
    {
        $days  = max(1, min(3650, (int)($opts['days'] ?? 14)));
        $queue = isset($opts['queue']) ? (string)$opts['queue'] : null;

        $deleted = $this->queue->purgeFailedJobsOlderThan($days, $queue);
        echo sprintf(
            "[purge] deleted=%d (older_than=%dd%s)\n",
            $deleted,
            $days,
            $queue !== null ? ", queue={$queue}" : ''
        );
    }

    private function stats(): void
    {
        $total = $this->queue->countFailedJobs();
        echo "Total failed_jobs: {$total}\n";

        $rows = $this->db->fetchAll(
            "SELECT queue, COUNT(*) AS c,
                    MIN(failed_at) AS oldest, MAX(failed_at) AS newest
             FROM failed_jobs
             GROUP BY queue
             ORDER BY c DESC"
        );
        if (empty($rows)) {
            return;
        }
        echo "By queue:\n";
        foreach ($rows as $r) {
            echo sprintf(
                "  %-30s count=%-6d oldest=%s  newest=%s\n",
                (string)$r->queue,
                (int)$r->c,
                (string)$r->oldest,
                (string)$r->newest
            );
        }
    }

    private function parseOptions(array $argv): array
    {
        $opts = [];
        $count = count($argv);
        for ($i = 2; $i < $count; $i++) {
            $arg = (string)$argv[$i];
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$k, $v] = explode('=', $arg, 2);
                $opts[$k] = trim($v, "\"' ");
            } else {
                $next = $argv[$i + 1] ?? null;
                if ($next !== null && !str_starts_with((string)$next, '--')) {
                    $opts[$arg] = trim((string)$next, "\"' ");
                    $i++;
                } else {
                    $opts[$arg] = true;
                }
            }
        }
        return $opts;
    }
}
