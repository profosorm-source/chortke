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

    public function searchTasks(string $q, int $userId, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
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
                    LIMIT {$limit} OFFSET {$offset}";

            return $this->db->fetchAll($sql, $params);
        } catch (\Throwable $e) {
            $this->logger->warning('search.user_tasks_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function searchVitrines(string $q, int $userId, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $params = ['user_id' => $userId];
        $where = ['vl.deleted_at IS NULL', 'vl.user_id = :user_id'];

        if ($q !== '') {
            $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim(mb_substr($q, 0, 100))) . '%';
            $where[] = "(vl.title LIKE :q1 ESCAPE '\\\\' OR vl.description LIKE :q2 ESCAPE '\\\\' OR vl.username LIKE :q3 ESCAPE '\\\\')";
            $params['q1'] = $needle;
            $params['q2'] = $needle;
            $params['q3'] = $needle;
        }

        $whereSql = implode(' AND ', $where);
        try {
            if (!$this->tableExists('vitrine_listings')) {
                return [];
            }
            return $this->db->fetchAll("SELECT vl.* FROM vitrine_listings vl WHERE {$whereSql} ORDER BY vl.created_at DESC LIMIT {$limit} OFFSET {$offset}", $params);
        } catch (\Throwable $e) {
            $this->logger->warning('search.user_vitrines_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function searchContents(string $q, int $userId, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $params = ['user_id' => $userId];
        $where = ['cs.is_deleted = 0', 'cs.user_id = :user_id'];

        if ($q !== '') {
            $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim(mb_substr($q, 0, 100))) . '%';
            $where[] = "(cs.title LIKE :q1 ESCAPE '\\\\' OR cs.description LIKE :q2 ESCAPE '\\\\' OR cs.video_url LIKE :q3 ESCAPE '\\\\' OR cs.category LIKE :q4 ESCAPE '\\\\' OR cs.platform LIKE :q5 ESCAPE '\\\\')";
            $params['q1'] = $needle;
            $params['q2'] = $needle;
            $params['q3'] = $needle;
            $params['q4'] = $needle;
            $params['q5'] = $needle;
        }

        $whereSql = implode(' AND ', $where);
        try {
            if (!$this->tableExists('content_submissions')) {
                return [];
            }
            return $this->db->fetchAll("SELECT cs.* FROM content_submissions cs WHERE {$whereSql} ORDER BY cs.created_at DESC LIMIT {$limit} OFFSET {$offset}", $params);
        } catch (\Throwable $e) {
            $this->logger->warning('search.user_contents_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function searchDirectMessages(string $q, int $userId, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $params = ['user_id' => $userId];
        $where = ["(dm.sender_id = :user_id OR dm.receiver_id = :user_id)"];

        if ($q !== '') {
            $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim(mb_substr($q, 0, 100))) . '%';
            $where[] = "(dm.message LIKE :q1 ESCAPE '\\\\' OR dm.subject LIKE :q2 ESCAPE '\\\\')";
            $params['q1'] = $needle;
            $params['q2'] = $needle;
        }

        $whereSql = implode(' AND ', $where);
        try {
            if (!$this->tableExists('direct_messages')) {
                return [];
            }
            return $this->db->fetchAll("SELECT dm.* FROM direct_messages dm WHERE {$whereSql} ORDER BY dm.created_at DESC LIMIT {$limit} OFFSET {$offset}", $params);
        } catch (\Throwable $e) {
            $this->logger->warning('search.user_dms_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
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
