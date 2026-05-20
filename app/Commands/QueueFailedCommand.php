<?php

declare(strict_types=1);

namespace App\Commands;

use Core\Database;
use Core\Queue;

class QueueFailedCommand
{
    public function __construct(
        private Database $db,
        private Queue $queue
    ) {}

    public function run(array $argv): void
    {
        $command = $argv[1] ?? 'queue:failed:list';
        $id = isset($argv[2]) ? (int)$argv[2] : 0;

        switch ($command) {
            case 'queue:failed:list':
                $this->list();
                return;
            case 'queue:failed:retry':
                $this->retry($id);
                return;
            case 'queue:failed:forget':
                $this->forget($id);
                return;
            default:
                throw new \InvalidArgumentException('Unsupported queue failed command.');
        }
    }

    private function list(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT id, queue, LEFT(payload, 180) AS payload_preview, LEFT(exception, 160) AS error_preview, failed_at
             FROM failed_jobs
             ORDER BY failed_at DESC
             LIMIT 50"
        );

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

    private function forget(int $id): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Failed job id is required.');
        }

        $deleted = $this->db->execute('DELETE FROM failed_jobs WHERE id = ?', [$id]);
        echo $deleted > 0 ? "Forgot failed job #{$id}.\n" : "Failed job #{$id} not found.\n";
    }
}
