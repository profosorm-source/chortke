<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * VitrineListing — مدل آگهی‌های ویترین
 *
 * سیستم خرید و فروش متنی (بدون تصویر) پیج، کانال، گروه، VPS، فیلترشکن، سایت
 * همه آگهی‌ها صرفاً متن‌محور هستند — هیچ تصویری پذیرفته نمی‌شود
 */
class VitrineListing extends Model
{
    protected static string $table = 'vitrine_listings';

    // ─── ثابت‌های وضعیت ──────────────────────────────────────────────────────

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_IN_ESCROW = 'in_escrow';
    public const STATUS_DISPUTED  = 'disputed';
    public const STATUS_SOLD      = 'sold';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REJECTED  = 'rejected';

    // ─── ثابت‌های نوع آگهی ──────────────────────────────────────────────────

    public const TYPE_SELL = 'sell';
    public const TYPE_BUY  = 'buy';

    // ─────────────────────────────────────────────────────────────────────────
    // دریافت
    // ─────────────────────────────────────────────────────────────────────────

    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare(
            "SELECT vl.*,
                    s.full_name  AS seller_name,  s.kyc_status AS seller_kyc,
                    s.tier       AS seller_tier,   s.fraud_score AS seller_fraud,
                    b.full_name  AS buyer_name,   b.kyc_status  AS buyer_kyc,
                    b.tier       AS buyer_tier
             FROM vitrine_listings vl
             LEFT JOIN users s ON s.id = vl.seller_id
             LEFT JOIN users b ON b.id = vl.buyer_id
             WHERE vl.id = ? AND vl.deleted_at IS NULL"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    /**
     * لیست آگهی‌های فعال (فروشنده) با جستجوی پیشرفته
     */
    public function getActive(array $f = [], int $limit = 20, int $offset = 0): array
    {
        $where  = ["vl.status = 'active'", "vl.listing_type = 'sell'", "vl.deleted_at IS NULL"];
        $params = [];

        if (!empty($f['category'])) {
            $where[]  = 'vl.category = ?';
            $params[] = $f['category'];
        }
        if (!empty($f['platform'])) {
            $where[]  = 'vl.platform = ?';
            $params[] = $f['platform'];
        }
        if (!empty($f['search'])) {
            $where[]  = '(vl.title LIKE ? OR vl.description LIKE ? OR vl.username LIKE ?)';
            $s        = '%' . $f['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }
        if (!empty($f['min_price'])) {
            $where[]  = 'vl.price_usdt >= ?';
            $params[] = (float) $f['min_price'];
        }
        if (!empty($f['max_price'])) {
            $where[]  = 'vl.price_usdt <= ?';
            $params[] = (float) $f['max_price'];
        }
        if (!empty($f['min_members'])) {
            $where[]  = 'vl.member_count >= ?';
            $params[] = (int) $f['min_members'];
        }

        $sort = match ($f['sort'] ?? '') {
            'price_asc'   => 'vl.price_usdt ASC',
            'price_desc'  => 'vl.price_usdt DESC',
            'members'     => 'vl.member_count DESC',
            default       => 'vl.created_at DESC',
        };

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare(
            "SELECT vl.*, s.full_name AS seller_name, s.tier AS seller_tier, s.kyc_status AS seller_kyc
             FROM vitrine_listings vl
             LEFT JOIN users s ON s.id = vl.seller_id
             WHERE " . implode(' AND ', $where) .
            " ORDER BY {$sort} LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function countActive(array $f = []): int
    {
        $where  = ["vl.status = 'active'", "vl.listing_type = 'sell'", "vl.deleted_at IS NULL"];
        $params = [];

        if (!empty($f['category'])) { $where[] = 'vl.category = ?'; $params[] = $f['category']; }
        if (!empty($f['platform']))  { $where[] = 'vl.platform = ?'; $params[] = $f['platform']; }
        if (!empty($f['search'])) {
            $where[] = '(vl.title LIKE ? OR vl.description LIKE ? OR vl.username LIKE ?)';
            $s = '%' . $f['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s;
        }
        if (!empty($f['min_price'])) { $where[] = 'vl.price_usdt >= ?'; $params[] = (float)$f['min_price']; }
        if (!empty($f['max_price'])) { $where[] = 'vl.price_usdt <= ?'; $params[] = (float)$f['max_price']; }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM vitrine_listings vl WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * لیست درخواست‌های خریداران (کسانی که دنبال چیزی می‌گردند)
     */
    public function getWantedListings(array $f = [], int $limit = 20, int $offset = 0): array
    {
        $where  = ["vl.status = 'active'", "vl.listing_type = 'buy'", "vl.deleted_at IS NULL"];
        $params = [];

        if (!empty($f['category'])) { $where[] = 'vl.category = ?'; $params[] = $f['category']; }
        if (!empty($f['platform']))  { $where[] = 'vl.platform = ?'; $params[] = $f['platform']; }
        if (!empty($f['search'])) {
            $where[] = '(vl.title LIKE ? OR vl.description LIKE ?)';
            $s = '%' . $f['search'] . '%';
            $params[] = $s; $params[] = $s;
        }

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare(
            "SELECT vl.*, s.full_name AS seller_name, s.tier AS seller_tier, s.kyc_status AS seller_kyc
             FROM vitrine_listings vl
             LEFT JOIN users s ON s.id = vl.seller_id
             WHERE " . implode(' AND ', $where) .
            " ORDER BY vl.created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function getBySeller(int $userId, int $limit = 30, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT vl.*, b.full_name AS buyer_name
             FROM vitrine_listings vl
             LEFT JOIN users b ON b.id = vl.buyer_id
             WHERE vl.seller_id = ? AND vl.deleted_at IS NULL
             ORDER BY vl.created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function getByBuyer(int $userId, int $limit = 30, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT vl.*, s.full_name AS seller_name
             FROM vitrine_listings vl
             LEFT JOIN users s ON s.id = vl.seller_id
             WHERE vl.buyer_id = ? AND vl.deleted_at IS NULL
             ORDER BY vl.updated_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ایجاد و به‌روزرسانی
    // ─────────────────────────────────────────────────────────────────────────

    public function createListing(array $d): ?object
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "INSERT INTO vitrine_listings
                (seller_id, listing_type, category, platform, title, description,
                 specs, username, member_count, creation_date,
                 price_usdt, min_price_usdt, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,?)"
        );
        $ok = $stmt->execute([
            $d['seller_id'],
            $d['listing_type'] ?? self::TYPE_SELL,
            $d['category'],
            $d['platform']      ?? '',
            $d['title'],
            $d['description'],
            $d['specs']         ?? null,
            $d['username']      ?? null,
            $d['member_count']  ?? 0,
            $d['creation_date'] ?? null,
            $d['price_usdt'],
            $d['min_price_usdt'] ?? null,
            $now, $now,
        ]);
        return $ok ? $this->find((int) $this->db->lastInsertId()) : null;
    }

    public function updateStatus(int $id, string $status, array $extra = []): bool
    {
        $set    = ['status = ?', 'updated_at = NOW()'];
        $params = [$status];

        $allowed = [
            'buyer_id', 'admin_note', 'rejection_reason',
            'escrow_locked_at', 'escrow_deadline',
            'seller_info_sent', 'auto_confirmed', 'offer_price_usdt',
        ];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $extra)) {
                $set[]    = "{$f} = ?";
                $params[] = $extra[$f];
            }
        }
        $params[] = $id;
        return $this->db->prepare(
            "UPDATE vitrine_listings SET " . implode(', ', $set) . " WHERE id = ?"
        )->execute($params);
    }

    public function countActiveByUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM vitrine_listings
             WHERE seller_id = ? AND status IN ('pending','active') AND deleted_at IS NULL"
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cron: اسکروهای منقضی
    // ─────────────────────────────────────────────────────────────────────────

    public function getExpiredEscrows(): array
    {
        $stmt = $this->db->prepare(
            "SELECT vl.*, s.full_name AS seller_name, b.full_name AS buyer_name
             FROM vitrine_listings vl
             LEFT JOIN users s ON s.id = vl.seller_id
             LEFT JOIN users b ON b.id = vl.buyer_id
             WHERE vl.status = 'in_escrow'
               AND vl.escrow_deadline IS NOT NULL
               AND vl.escrow_deadline < NOW()
               AND vl.auto_confirmed = 0
               AND vl.deleted_at IS NULL"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin
    // ─────────────────────────────────────────────────────────────────────────

    public function adminList(array $f = [], int $limit = 30, int $offset = 0): array
    {
        $where  = ['vl.deleted_at IS NULL'];
        $params = [];

        if (!empty($f['status']))   { $where[] = 'vl.status = ?';       $params[] = $f['status']; }
        if (!empty($f['category'])) { $where[] = 'vl.category = ?';     $params[] = $f['category']; }
        if (!empty($f['type']))     { $where[] = 'vl.listing_type = ?'; $params[] = $f['type']; }
        if (!empty($f['search'])) {
            $where[] = '(vl.title LIKE ? OR s.full_name LIKE ? OR vl.username LIKE ?)';
            $s = '%' . $f['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s;
        }

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare(
            "SELECT vl.*, s.full_name AS seller_name, b.full_name AS buyer_name,
                    s.kyc_status AS seller_kyc, s.tier AS seller_tier
             FROM vitrine_listings vl
             LEFT JOIN users s ON s.id = vl.seller_id
             LEFT JOIN users b ON b.id = vl.buyer_id
             WHERE " . implode(' AND ', $where) .
            " ORDER BY vl.created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function adminCount(array $f = []): int
    {
        $where  = ['vl.deleted_at IS NULL'];
        $params = [];

        if (!empty($f['status']))   { $where[] = 'vl.status = ?';       $params[] = $f['status']; }
        if (!empty($f['category'])) { $where[] = 'vl.category = ?';     $params[] = $f['category']; }
        if (!empty($f['type']))     { $where[] = 'vl.listing_type = ?'; $params[] = $f['type']; }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM vitrine_listings vl
             LEFT JOIN users s ON s.id = vl.seller_id
             WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function adminStats(): object
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'pending')   AS pending,
                SUM(status = 'active')    AS active,
                SUM(status = 'in_escrow') AS in_escrow,
                SUM(status = 'disputed')  AS disputed,
                SUM(status = 'sold')      AS sold
             FROM vitrine_listings WHERE deleted_at IS NULL"
        );
        $stmt->execute();
        return $stmt->fetch(\PDO::FETCH_OBJ);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Watchlist
    // ─────────────────────────────────────────────────────────────────────────

    public function isWatched(int $userId, int $listingId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM vitrine_watchlist WHERE user_id = ? AND listing_id = ? LIMIT 1"
        );
        $stmt->execute([$userId, $listingId]);
        return (bool) $stmt->fetchColumn();
    }

    public function addWatch(int $userId, int $listingId): bool
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO vitrine_watchlist (user_id, listing_id, created_at) VALUES (?, ?, NOW())"
            );
            return $stmt->execute([$userId, $listingId]);
        } catch (\Throwable) {
            return false;
        }
    }

    public function removeWatch(int $userId, int $listingId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM vitrine_watchlist WHERE user_id = ? AND listing_id = ?"
        );
        return $stmt->execute([$userId, $listingId]);
    }

    public function getWatcherIds(int $listingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT user_id FROM vitrine_watchlist WHERE listing_id = ?"
        );
        $stmt->execute([$listingId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function watchCount(int $listingId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM vitrine_watchlist WHERE listing_id = ?"
        );
        $stmt->execute([$listingId]);
        return (int) $stmt->fetchColumn();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // اعلان دسته (category alert)
    // ─────────────────────────────────────────────────────────────────────────

    public function getCategoryAlertUsers(string $category, string $platform = ''): array
    {
        $where  = ['category = ?'];
        $params = [$category];
        if ($platform) {
            $where[]  = '(platform IS NULL OR platform = ? OR platform = "")';
            $params[] = $platform;
        }
        $stmt = $this->db->prepare(
            "SELECT DISTINCT user_id FROM vitrine_category_alerts WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // لیست‌های ثابت
    // ─────────────────────────────────────────────────────────────────────────

    public function categories(): array
    {
        return [
            'page'    => 'پیج',
            'channel' => 'کانال',
            'group'   => 'گروه',
            'vps'     => 'سرور مجازی (VPS)',
            'vpn'     => 'فیلترشکن / VPN',
            'website' => 'سایت',
            'other'   => 'سایر',
        ];
    }

    public function platforms(): array
    {
        return [
            ''          => 'همه پلتفرم‌ها',
            'telegram'  => 'تلگرام',
            'instagram' => 'اینستاگرام',
            'twitter'   => 'توییتر / X',
            'youtube'   => 'یوتیوب',
            'tiktok'    => 'تیک‌تاک',
            'rubika'    => 'روبیکا',
            'bale'      => 'بله',
            'eitaa'     => 'ایتا',
            'other'     => 'سایر',
        ];
    }

    public function statuses(): array
    {
        return [
            self::STATUS_PENDING   => 'در انتظار تایید',
            self::STATUS_ACTIVE    => 'فعال',
            self::STATUS_IN_ESCROW => 'در حال انتقال',
            self::STATUS_DISPUTED  => 'اختلاف',
            self::STATUS_SOLD      => 'معامله تکمیل شد',
            self::STATUS_CANCELLED => 'لغو شده',
            self::STATUS_REJECTED  => 'رد شده',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // State Machine Validation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Valid status transitions
     */
    private const TRANSITIONS = [
        self::STATUS_PENDING   => [self::STATUS_ACTIVE, self::STATUS_REJECTED],
        self::STATUS_ACTIVE    => [self::STATUS_IN_ESCROW, self::STATUS_CANCELLED],
        self::STATUS_IN_ESCROW => [self::STATUS_SOLD, self::STATUS_DISPUTED, self::STATUS_CANCELLED],
        self::STATUS_DISPUTED  => [self::STATUS_SOLD, self::STATUS_CANCELLED],
        self::STATUS_SOLD      => [], // Terminal
        self::STATUS_CANCELLED => [], // Terminal
        self::STATUS_REJECTED  => [], // Terminal
    ];

    /**
     * Check if transition is valid
     */
    public function canTransitionTo(string $currentStatus, string $targetStatus): bool
    {
        if (!isset(self::TRANSITIONS[$currentStatus])) {
            return false;
        }
        return \in_array($targetStatus, self::TRANSITIONS[$currentStatus], true);
    }

    /**
     * Get allowed next states
     */
    public function getAllowedTransitions(string $currentStatus): array
    {
        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    /**
     * Check if status is terminal
     */
    public function isTerminalStatus(string $status): bool
    {
        return empty(self::TRANSITIONS[$status] ?? []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Null Safety Methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get listing with null safety
     */
    public function getSafe(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }
        return $this->find($id);
    }

    /**
     * Check if listing exists
     */
    public function exists(int $listingId): bool
    {
        $result = $this->db->prepare(
            "SELECT 1 FROM vitrine_listings WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $result->execute([$listingId]);
        return (bool) $result->fetchColumn();
    }

    /**
     * Check if seller owns listing
     */
    public function belongsToSeller(int $listingId, int $sellerId): bool
    {
        $listing = $this->getSafe($listingId);
        if (!$listing) {
            return false;
        }
        return $listing->seller_id === $sellerId;
    }

    /**
     * Check if buyer owns/has this listing
     */
    public function belongsToBuyer(int $listingId, int $buyerId): bool
    {
        $listing = $this->getSafe($listingId);
        if (!$listing) {
            return false;
        }
        return $listing->buyer_id === $buyerId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Business Logic Methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Can transition to escrow (ready for payment)
     */
    public function canMoveToEscrow(int $listingId): bool
    {
        $listing = $this->getSafe($listingId);
        if (!$listing) {
            return false;
        }
        return $listing->status === self::STATUS_ACTIVE 
            && $listing->buyer_id !== null
            && $listing->buyer_id > 0;
    }

    /**
     * Can move to sold (final state)
     */
    public function canMoveSold(int $listingId): bool
    {
        $listing = $this->getSafe($listingId);
        if (!$listing) {
            return false;
        }
        return \in_array($listing->status, [self::STATUS_IN_ESCROW, self::STATUS_DISPUTED], true);
    }

    /**
     * Can cancel (not yet sold/disputed)
     */
    public function canCancel(int $listingId): bool
    {
        $listing = $this->getSafe($listingId);
        if (!$listing) {
            return false;
        }
        return \in_array($listing->status, 
            [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_IN_ESCROW], 
            true
        );
    }

    /**
     * Prevent self-approval
     */
    public function isSelfApproval(int $listingId, int $adminId): bool
    {
        $listing = $this->getSafe($listingId);
        if (!$listing) {
            return false;
        }
        return $listing->seller_id === $adminId;
    }

    /**
     * Get user disputes count for listing
     */
    public function getUserDisputesCount(int $listingId, int $userId): int
    {
        $result = $this->db->prepare(
            "SELECT COUNT(*) FROM vitrine_disputes 
             WHERE listing_id = ? AND (buyer_id = ? OR seller_id = ?)
             AND status != 'resolved' LIMIT 1"
        );
        $result->execute([$listingId, $userId, $userId]);
        return (int)$result->fetchColumn();
    }
}

