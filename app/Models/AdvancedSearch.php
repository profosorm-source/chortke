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

        $params = [];

        // Prefer FULLTEXT when the deployment has the recommended index; otherwise fallback to LIKE.
        if ($this->hasFullTextIndex(['title', 'description', 'content'])) {
            $sql = "SELECT *, MATCH(title, description, content) AGAINST (:keyword_score IN BOOLEAN MODE) AS relevance
                    FROM " . static::$table . " WHERE 1=1
                    AND MATCH(title, description, content) AGAINST (:keyword_match IN BOOLEAN MODE)";
            $booleanKeyword = $this->toBooleanFulltextQuery($keyword);
            $params['keyword_score'] = $booleanKeyword;
            $params['keyword_match'] = $booleanKeyword;
        } else {
            $sql = "SELECT * FROM " . static::$table . " WHERE 1=1";
            $escaped = $this->escapeLikeValue($keyword);
            $sql .= " AND (title LIKE :keyword_title ESCAPE '\\' OR description LIKE :keyword_description ESCAPE '\\' OR content LIKE :keyword_content ESCAPE '\\')";
            $params['keyword_title'] = "%{$escaped}%";
            $params['keyword_description'] = "%{$escaped}%";
            $params['keyword_content'] = "%{$escaped}%";
        }

        // Apply filters if provided
        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = (int)$filters['user_id'];
        }

        if (!empty($filters['category'])) {
            $sql .= " AND category = :category";
            $params['category'] = $filters['category'];
        }

        $sql .= str_contains($sql, 'MATCH(')
            ? " ORDER BY relevance DESC, created_at DESC LIMIT :limit OFFSET :offset"
            : " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }


    private function hasFullTextIndex(array $columns): bool
    {
        try {
            $rows = $this->db->fetchAll("SHOW INDEX FROM " . static::$table . " WHERE Index_type = 'FULLTEXT'");
            $indexed = [];
            foreach ($rows as $row) {
                $indexed[] = (string)($row->Column_name ?? '');
            }
            return count(array_intersect($columns, $indexed)) === count($columns);
        } catch (\Throwable) {
            return false;
        }
    }

    private function toBooleanFulltextQuery(string $keyword): string
    {
        $terms = preg_split('/\s+/u', trim($keyword)) ?: [];
        $terms = array_values(array_filter(array_map(
            fn($term) => preg_replace('/[^\pL\pN_\-]/u', '', (string)$term),
            $terms
        )));

        if (empty($terms)) {
            return $keyword;
        }

        return implode(' ', array_map(fn($term) => '+' . $term . '*', $terms));
    }

    public function getSearchIndexRecommendations(): array
    {
        return [
            'ALTER TABLE searches ADD FULLTEXT ft_searches_text (title, description, content)',
            'ALTER TABLE searches ADD INDEX idx_searches_user_created (user_id, created_at)',
            'ALTER TABLE searches ADD INDEX idx_searches_category_created (category, created_at)',
        ];
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
