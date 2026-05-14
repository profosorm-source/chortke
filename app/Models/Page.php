<?php

namespace App\Models;

use Core\Model;

class Page extends Model
{
    /**
     * دریافت صفحه با Slug
     */
    public function findBySlug(string $slug): ?object
    {
        $sql = "SELECT * FROM pages WHERE slug = ? AND is_active = TRUE";
        return $this->db->fetch($sql, [$slug]);
    }
    
    /**
     * دریافت با ID
     */
    public function findById(int $id): ?object
    {
        $sql = "SELECT * FROM pages WHERE id = ?";
        return $this->db->fetch($sql, [$id]);
    }
    
    /**
     * دریافت همه صفحات
     */
    public function getAll(): array
    {
        $sql = "SELECT * FROM pages ORDER BY display_order ASC";
        return $this->db->fetchAll($sql);
    }
    
    /**
     * دریافت صفحات فوتر
     */
    public function getFooterPages(): array
    {
        $sql = "SELECT slug, title FROM pages 
                WHERE is_active = TRUE AND show_in_footer = TRUE 
                ORDER BY display_order ASC";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * ایجاد صفحه جدید
     */
    public function create(array $data): ?int
    {
        $data['slug'] = \strtolower(\preg_replace('/[^a-z0-9\-]/', '-', $data['slug']));

        // Check uniqueness of the slug
        $stmt = $this->db->prepare("SELECT id FROM pages WHERE slug = ? LIMIT 1");
        $stmt->execute([$data['slug']]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException("Duplicate page slug: " . $data['slug']);
        }

        $sql = "INSERT INTO pages 
                (slug, title, content, meta_description, meta_keywords, is_active, show_in_footer, display_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $this->db->query($sql, [
            $data['slug'],
            $data['title'],
            $data['content'],
            $data['meta_description'] ?? null,
            $data['meta_keywords'] ?? null,
            $data['is_active'] ?? true,
            $data['show_in_footer'] ?? true,
            $data['display_order'] ?? 0
        ]);
        
        $insertId = (int)$this->db->lastInsertId();
        if ($insertId > 0) {
            $this->invalidateSitemapCache();
        }
        return $insertId;
    }
    
    /**
     * بروزرسانی
     */
    public function update(int $id, array $data): bool
    {
        $allowed = [
            'slug', 'title', 'content', 'meta_description', 'meta_keywords',
            'is_active', 'show_in_footer', 'display_order'
        ];

        $fields = [];
        $params = [];
        
        foreach ($allowed as $key) {
            if (\array_key_exists($key, $data)) {
                if ($key === 'slug') {
                    $data[$key] = \strtolower(\preg_replace('/[^a-z0-9\-]/', '-', $data[$key]));
                    
                    // Verify uniqueness of slug excluding the current ID
                    $stmt = $this->db->prepare("SELECT id FROM pages WHERE slug = ? AND id != ? LIMIT 1");
                    $stmt->execute([$data['slug'], $id]);
                    if ($stmt->fetch()) {
                        throw new \InvalidArgumentException("Duplicate page slug: " . $data['slug']);
                    }
                }
                $fields[] = "`{$key}` = ?";
                $params[] = $data[$key];
            }
        }
        
        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        
        $sql = "UPDATE pages SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $result = $this->db->query($sql, $params);
        if ($result) {
            $this->invalidateSitemapCache();
        }
        return $result;
    }
    
    /**
     * حذف
     */
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM pages WHERE id = ?";
        $result = $this->db->query($sql, [$id]);
        if ($result) {
            $this->invalidateSitemapCache();
        }
        return $result;
    }

    /**
     * پاکسازی کش نقشه سایت جهت به روزرسانی فوری
     */
    private function invalidateSitemapCache(): void
    {
        try {
            if (\class_exists('\Core\Cache')) {
                \Core\Cache::getInstance()->forget('sitemap_xml_content');
            }
        } catch (\Throwable $e) {
            // خروج امن در صورت اختلال در کش
        }
    }
}