<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * AdvancedSearch Model - Secured against LIKE Injections
 */
class AdvancedSearch extends Model
{
    protected static string $table = 'searches';

    /**
     * Escape and validate search query
     */
    private function sanitizeSearchQuery(string $q, int $maxLength = 100): string
    {
        return $this->escapeLikeValue($q, $maxLength);
    }

    /**
     * جستجوی پیشرفته
     */
    public function search(string $keyword, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $keyword = trim($keyword);
        if (empty($keyword)) {
            return [];
        }

        $limit = max(1, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT * FROM " . static::$table . " WHERE 1=1";
        $params = [];

        $escaped = $this->escapeLikeValue($keyword);
        $sql .= " AND (title LIKE :keyword OR description LIKE :keyword OR content LIKE :keyword)";
        $params['keyword'] = "%{$escaped}%";

        // Apply filters if provided
        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = (int)$filters['user_id'];
        }

        if (!empty($filters['category'])) {
            $sql .= " AND category = :category";
            $params['category'] = $filters['category'];
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * ذخیره یک جستجوی کاربر
     */
    public function saveSearchTerm(int $userId, string $term): bool
    {
        $term = trim($term);
        if (empty($term)) {
            return false;
        }

        // H-02 Fix: Sanitize term before saving to prevent potential issues in trending queries
        $term = htmlspecialchars(strip_tags($term), ENT_QUOTES, 'UTF-8');
        if (strlen($term) > 100) $term = substr($term, 0, 100);

        $sql = "INSERT INTO " . static::$table . " (user_id, term, created_at)
                VALUES (:user_id, :term, NOW())
                ON DUPLICATE KEY UPDATE created_at = NOW()";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'user_id' => $userId,
            'term' => $term,
        ]);
    }

    /**
     * دریافت جستجوهای محبوب
     */
    public function getTrending(int $limit = 10): array
    {
        $limit = max(1, (int)$limit);
        $sql = "SELECT term, COUNT(*) as count
                FROM " . static::$table . "
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY term
                ORDER BY count DESC
                LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }



}
