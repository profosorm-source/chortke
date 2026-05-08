<?php
namespace App\Models;
use Core\Model;
use Core\Database;

/**
 * SeoAd — تبلیغ SEO ثبت‌شده توسط کاربر
 * جدول: seo_ads (مجزا از seo_keywords که متعلق به مدیر است)
 */
class SeoAd extends Model
{
    public int     $id;
    public int     $user_id;
    public string  $site_url;
    public string  $title;
    public string  $keyword;
    public ?string $description;
    public float   $budget;
    public float   $remaining_budget;
    public float   $price_per_click;
    public int     $clicks_count;
    public string  $status;          // pending|active|paused|rejected|exhausted
    public ?string $rejection_reason;
    public ?string $deadline;
    public string  $created_at;
    public ?string $updated_at;

    // --------------------------------------------------------
    // READ
    // --------------------------------------------------------

    public function find(int $id): ?self
    {
        $stmt = $this->db->prepare("SELECT * FROM seo_ads WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** آگهی‌های یک کاربر (تبلیغ‌دهنده) */
    public function getByUser(int $userId, int $limit = 30, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM seo_ads WHERE user_id = ?
             ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $limit, $offset]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** پیدا کردن آگهی با تأیید مالکیت */
    public function findByUser(int $id, int $userId): ?self
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM seo_ads WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** آگهی‌های فعال برای نمایش در بخش کسب درآمد (SEO Search) */
    public function getActiveForSearch(string $keyword, int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM seo_ads
             WHERE status = 'active'
               AND remaining_budget > 0
               AND (deadline IS NULL OR deadline > NOW())
               AND LOWER(keyword) LIKE LOWER(?)
             ORDER BY price_per_click DESC, created_at ASC
             LIMIT ?"
        );
        $stmt->execute(['%' . $keyword . '%', $limit]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** لیست ادمین با فیلتر */
    public function adminList(string $status = '', int $limit = 30, int $offset = 0): array
    {
        $where  = [];
        $params = [];

        if ($status !== '') {
            $where[]  = 'a.status = ?';
            $params[] = $status;
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT a.*, u.full_name AS user_name, u.email AS user_email
             FROM seo_ads a
             LEFT JOIN users u ON u.id = a.user_id
             {$whereStr}
             ORDER BY a.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    // --------------------------------------------------------
    // WRITE
    // --------------------------------------------------------

    public function create(array $d): ?self
    {
        $stmt = $this->db->prepare(
            "INSERT INTO seo_ads
             (user_id, site_url, title, keyword, description,
              budget, remaining_budget, price_per_click, status, deadline)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
        );
        $ok = $stmt->execute([
            (int)   $d['user_id'],
                    $d['site_url'],
                    $d['title'],
                    $d['keyword'],
                    $d['description'] ?? null,
            (float) $d['budget'],
            (float) $d['budget'],          // remaining = full budget
            (float) $d['price_per_click'],
                    $d['deadline'] ?? null,
        ]);
        return $ok ? $this->find((int)$this->db->lastInsertId()) : null;
    }

    /** تغییر وضعیت (admin: active/rejected | user: paused/active) */
    public function setStatus(int $id, string $status, ?string $reason = null): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE seo_ads
             SET status = ?, rejection_reason = ?, updated_at = NOW()
             WHERE id = ?"
        );
        return $stmt->execute([$status, $reason, $id]);
    }

    /** تغییر وضعیت با تأیید مالکیت کاربر */
    public function setStatusByUser(int $id, int $userId, string $status): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE seo_ads SET status = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?"
        );
        return $stmt->execute([$status, $id, $userId]);
    }

    /** کسر هزینه هر کلیک از بودجه */
    public function deductClick(int $id, float $amount): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE seo_ads
             SET clicks_count      = clicks_count + 1,
                 remaining_budget  = GREATEST(0, remaining_budget - ?),
                 status            = CASE
                                       WHEN remaining_budget - ? <= 0 THEN 'exhausted'
                                       ELSE status
                                     END,
                 updated_at        = NOW()
             WHERE id = ? AND status = 'active'"
        );
        return $stmt->execute([$amount, $amount, $id]);
    }

    // --------------------------------------------------------
    // PRIVATE
    // --------------------------------------------------------

    private function hydrate(array $row): self
    {
        $o = new self();
        $o->id               = (int)   $row['id'];
        $o->user_id          = (int)   $row['user_id'];
        $o->site_url         =         $row['site_url'];
        $o->title            =         $row['title'];
        $o->keyword          =         $row['keyword'];
        $o->description      =         $row['description']       ?? null;
        $o->budget           = (float) $row['budget'];
        $o->remaining_budget = (float) $row['remaining_budget'];
        $o->price_per_click  = (float) $row['price_per_click'];
        $o->clicks_count     = (int)   $row['clicks_count']      ?? 0;
        $o->status           =         $row['status']            ?? 'pending';
        $o->rejection_reason =         $row['rejection_reason']  ?? null;
        $o->deadline         =         $row['deadline']          ?? null;
        $o->created_at       =         $row['created_at'];
        $o->updated_at       =         $row['updated_at']        ?? null;
        return $o;
    }
}
