<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/** Read-only adapter for user-scoped search operations. */
final class UserSearchGateway
{
    public function __construct(
        private Database $db,
        private LoggerInterface $logger,
        private AdminSearchGateway $reader
    ) {}

    public function searchTransactions(string $q, int $userId, int $limit): array
    {
        return $this->reader->quickSearchTransactions($q, $userId, $limit);
    }

    public function searchTickets(string $q, int $userId, int $limit): array
    {
        return $this->reader->quickSearchTickets($q, $userId, $limit);
    }

    public function searchAds(string $q, int $userId, int $limit): array
    {
        return $this->reader->quickSearchAds($q, $userId, $limit);
    }

    public function searchTasks(string $q, int $userId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim(mb_substr($q, 0, 100))) . '%';

        try {
            if (!$this->tableExists('custom_task_submissions')) {
                return [];
            }

            $params = ['user_id' => $userId, 'q1' => $term, 'q2' => $term];
            $sql = "SELECT s.*, a.title as task_title, a.description as task_description
                    FROM custom_task_submissions s
                    LEFT JOIN ads a ON a.id = s.task_id
                    WHERE s.user_id = :user_id
                      AND (a.title LIKE :q1 ESCAPE '\\\\' OR a.description LIKE :q2 ESCAPE '\\\\')
                    ORDER BY s.created_at DESC
                    LIMIT {$limit}";

            return $this->db->fetchAll($sql, $params);
        } catch (\Throwable $e) {
            $this->logger->warning('search.user_tasks_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool) $this->db->fetchColumn('SHOW TABLES LIKE ?', [$table]);
        } catch (\Throwable) {
            return false;
        }
    }
}
