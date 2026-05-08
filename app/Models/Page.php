<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class Page extends Model {
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
        
        return (int) $this->db->lastInsertId();
    }
    
    /**
     * بروزرسانی
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $params[] = $value;
        }
        
        $params[] = $id;
        
        $sql = "UPDATE pages SET " . implode(', ', $fields) . " WHERE id = ?";
        
        return $this->db->query($sql, $params);
    }
    
    /**
     * حذف
     */
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM pages WHERE id = ?";
        return $this->db->query($sql, [$id]);
    }
}