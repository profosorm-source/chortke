<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use App\Traits\Filterable;

/**
 * InfluencerModel - مدل اشتراکی مدیریت اینفلوئنسرها
 *
 * تجمیع عملیات پروفایل، احراز هویت و شهرت (امتیازات) اینفلوئنسرها.
 * 
 * Status Flow: pending → verified → (suspended)
 * - pending: ثبت کننده، منتظر تایید مدیر
 * - verified: تایید شده، فعال است
 * - suspended: تعلیق شده (مسائل رفتاری)
 * - rejected: رد شده
 */
class InfluencerModel extends Model
{
    use Filterable;

    protected static string $table = 'influencer_profiles';
    protected static array $searchable = ['ip.username', 'ip.bio', 'u.full_name', 'u.email'];

    protected static array $filterable = [
        'status' => ['ip.status', '='],
        'platform' => ['ip.platform', '='],
        'category' => ['ip.category', '='],
        'min_followers' => ['ip.follower_count', '>='],
        'max_followers' => ['ip.follower_count', '<='],
        'max_price' => ['ip.story_price_24h', '<='],
    ];
    // ==========================================
    // Constants (Profiles)
    // ==========================================
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_PENDING_ADMIN_REVIEW = 'pending_admin_review';

    public const ACTIVE_STATUSES = [self::STATUS_VERIFIED];
    public const INACTIVE_STATUSES = [self::STATUS_REJECTED, self::STATUS_SUSPENDED];

    private const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_PENDING_ADMIN_REVIEW, self::STATUS_REJECTED],
        self::STATUS_PENDING_ADMIN_REVIEW => [self::STATUS_VERIFIED, self::STATUS_REJECTED],
        self::STATUS_VERIFIED => [self::STATUS_SUSPENDED],
        self::STATUS_SUSPENDED => [self::STATUS_VERIFIED],
        self::STATUS_REJECTED => [], // Terminal state
    ];

    // ==========================================
    // Profile Management
    // ==========================================

    public function findProfile(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip
            LEFT JOIN users u ON u.id = ip.user_id
            WHERE ip.id = ? AND ip.deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function findProfileForUpdate(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip
            LEFT JOIN users u ON u.id = ip.user_id
            WHERE ip.id = ? AND ip.deleted_at IS NULL
            FOR UPDATE
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function findProfileByUserId(int $userId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip
            LEFT JOIN users u ON u.id = ip.user_id
            WHERE ip.user_id = ? AND ip.deleted_at IS NULL
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function findProfileOwnedByUser(int $profileId, int $userId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip
            LEFT JOIN users u ON u.id = ip.user_id
            WHERE ip.id = ? AND ip.user_id = ? AND ip.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$profileId, $userId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function createProfile(array $d): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO influencer_profiles
            (user_id, platform, username, page_url, profile_image, follower_count,
             engagement_rate, category, bio, story_price_24h, post_price_24h,
             post_price_48h, post_price_72h, currency, status, verification_code)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $result = $stmt->execute([
            $d['user_id'], $d['platform'] ?? 'instagram', $d['username'],
            $d['page_url'], $d['profile_image'] ?? null, $d['follower_count'] ?? 0,
            $d['engagement_rate'] ?? 0, $d['category'] ?? null, $d['bio'] ?? null,
            $d['story_price_24h'] ?? 0, $d['post_price_24h'] ?? 0,
            $d['post_price_48h'] ?? 0, $d['post_price_72h'] ?? 0,
            $d['currency'] ?? 'irt', $d['status'] ?? 'pending',
            $d['verification_code'] ?? null,
        ]);
        if (!$result) return null;
        return $this->findProfile((int) $this->db->lastInsertId());
    }

    public function updateProfile(int $id, array $data): bool
    {
        $fields = []; $values = [];
        $allowed = [
            'username','page_url','profile_image','follower_count','engagement_rate',
            'category','bio','story_price_24h','post_price_24h','post_price_48h',
            'post_price_72h','currency','total_orders','completed_orders','average_rating',
            'status','rejection_reason','verified_by','verified_at','is_active','priority',
            'verification_code','verification_post_url','suspended_at','suspended_reason',
        ];
        foreach ($allowed as $f) {
            if (\array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?"; $values[] = $data[$f];
            }
        }
        if (empty($fields)) return false;
        $values[] = $id;
        $stmt = $this->db->prepare("UPDATE influencer_profiles SET " . \implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    public function getVerifiedProfiles(array $filters = [], string $sort = 'priority', int $limit = 20, int $offset = 0): array
    {
        $where = ["ip.status = 'verified'", "ip.is_active = 1", "ip.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = "ip.category = ?"; $params[] = $filters['category'];
        }
        if (!empty($filters['min_followers'])) {
            $where[] = "ip.follower_count >= ?"; $params[] = (int) $filters['min_followers'];
        }
        if (!empty($filters['max_price'])) {
            $where[] = "ip.story_price_24h <= ?"; $params[] = (float) $filters['max_price'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR ip.bio LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s; $params[] = $s;
        }

        $whereStr = \implode(' AND ', $where);

        $orderBy = match ($sort) {
            'followers' => 'ip.follower_count DESC',
            'price_low' => 'ip.story_price_24h ASC',
            'price_high' => 'ip.story_price_24h DESC',
            'rating' => 'ip.average_rating DESC',
            'orders' => 'ip.completed_orders DESC',
            default => 'ip.priority DESC, ip.completed_orders DESC',
        };

        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip
            LEFT JOIN users u ON u.id = ip.user_id
            WHERE {$whereStr}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ");
        $index = 1;
        foreach ($params as $val) {
            $stmt->bindValue($index++, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function countVerifiedProfiles(array $filters = []): int
    {
        $where = ["ip.status = 'verified'", "ip.is_active = 1", "ip.deleted_at IS NULL"];
        $params = [];
        if (!empty($filters['category'])) { $where[] = "ip.category = ?"; $params[] = $filters['category']; }
        if (!empty($filters['min_followers'])) { $where[] = "ip.follower_count >= ?"; $params[] = (int) $filters['min_followers']; }
        if (!empty($filters['max_price'])) { $where[] = "ip.story_price_24h <= ?"; $params[] = (float) $filters['max_price']; }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR ip.bio LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s; $params[] = $s;
        }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM influencer_profiles ip LEFT JOIN users u ON u.id = ip.user_id WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function adminListProfiles(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ["ip.deleted_at IS NULL"]; $params = [];
        if (!empty($filters['status'])) { $where[] = "ip.status = ?"; $params[] = $filters['status']; }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s;
        }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip LEFT JOIN users u ON u.id = ip.user_id
            WHERE {$whereStr} ORDER BY ip.created_at DESC LIMIT ? OFFSET ?
        ");
        $params[] = $limit; $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function adminCountProfiles(array $filters = []): int
    {
        $where = ["ip.deleted_at IS NULL"]; $params = [];
        if (!empty($filters['status'])) { $where[] = "ip.status = ?"; $params[] = $filters['status']; }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s;
        }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM influencer_profiles ip LEFT JOIN users u ON u.id = ip.user_id WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function profileStatusLabels(): array
    {
        return [
            'pending'             => 'در انتظار ثبت پست',
            'pending_admin_review'=> 'در انتظار تایید مدیر',
            'verified'            => 'تایید شده',
            'rejected'            => 'رد شده',
            'suspended'           => 'تعلیق شده',
        ];
    }

    public function profileCategories(): array
    {
        $cats = setting('influencer_categories', '');
        return $cats ? \explode(',', $cats) : [];
    }

    public function profileExists(int $profileId): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM influencer_profiles WHERE id = ? AND deleted_at IS NULL LIMIT 1",
            [$profileId]
        )->fetch();
        return $result !== false && $result !== null;
    }

    public function getProfileSafe(int $id): ?object
    {
        if ($id <= 0) return null;
        return $this->findProfile($id);
    }

    public function getProfileByUserSafe(int $userId): ?object
    {
        if ($userId <= 0) return null;
        return $this->findProfileByUserId($userId);
    }

    public function canTransitionTo(string $currentStatus, string $targetStatus): bool
    {
        if (!isset(self::TRANSITIONS[$currentStatus])) return false;
        return \in_array($targetStatus, self::TRANSITIONS[$currentStatus], true);
    }

    public function getAllowedTransitions(string $currentStatus): array
    {
        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    public function isTerminalStatus(string $status): bool
    {
        return empty(self::TRANSITIONS[$status] ?? []);
    }

    public function canAcceptOrders(int $profileId): bool
    {
        $profile = $this->getProfileSafe($profileId);
        if (!$profile) return false;
        return $profile->status === self::STATUS_VERIFIED && $profile->is_active == 1;
    }

    public function canCreateDispute(int $profileId): bool
    {
        $profile = $this->getProfileSafe($profileId);
        if (!$profile) return false;
        return !\in_array($profile->status, self::INACTIVE_STATUSES, true);
    }

    public function belongsToUser(int $profileId, int $userId): bool
    {
        $profile = $this->getProfileSafe($profileId);
        if (!$profile) return false;
        return $profile->user_id === $userId;
    }

    public function getUnreadDisputesCount(int $profileId): int
    {
        if (!$this->profileExists($profileId)) return 0;
        // Refactored to use `disputes` instead of `influencer_disputes` as per Phase 12
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM disputes 
             WHERE ref_type = 'influencer' AND ref_id IN (
                 SELECT id FROM story_orders WHERE influencer_id = ?
             ) AND status = 'open' AND read_at IS NULL"
        );
        $stmt->execute([$profileId]);
        return (int)$stmt->fetchColumn();
    }

    // ==========================================
    // Verifications (InfluencerVerification)
    // ==========================================

    public function findPendingVerificationByProfile(int $profileId): ?object
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM influencer_verifications
             WHERE profile_id = ? AND status = 'pending' AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$profileId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function expirePendingVerificationForProfile(int $profileId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE influencer_verifications
             SET status = 'expired'
             WHERE profile_id = ? AND status = 'pending'"
        );
        return $stmt->execute([$profileId]);
    }

    public function createVerification(int $profileId, string $code, string $expiresAt): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO influencer_verifications
             (profile_id, code, status, expires_at, created_at)
             VALUES (?, ?, 'pending', ?, NOW())"
        );
        return $stmt->execute([$profileId, $code, $expiresAt]);
    }

    public function findVerificationById(int $verificationId): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM influencer_verifications WHERE id = ? LIMIT 1");
        $stmt->execute([$verificationId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function findVerificationByIdForUpdate(int $verificationId): ?object
    {
        $startedTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTransaction = true;
        }
        try {
            $stmt = $this->db->prepare("SELECT * FROM influencer_verifications WHERE id = ? FOR UPDATE");
            $stmt->execute([$verificationId]);
            $result = $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
            if ($startedTransaction) {
                $this->db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function findSubmittedVerificationByProfile(int $profileId): ?object
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM influencer_verifications
             WHERE profile_id = ? AND status = 'submitted'
             ORDER BY submitted_at DESC
             LIMIT 1"
        );
        $stmt->execute([$profileId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function markVerificationAsSubmitted(int $verificationId, string $proofUrl): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE influencer_verifications
             SET status = 'submitted', proof_url = ?, submitted_at = NOW()
             WHERE id = ?"
        );
        return $stmt->execute([$proofUrl, $verificationId]);
    }

    public function updateVerificationStatus(int $verificationId, string $status, array $fields = []): bool
    {
        $assignments = [];
        $values = [];

        foreach ($fields as $key => $value) {
            $assignments[] = "{$key} = ?";
            $values[] = $value;
        }

        $assignments[] = "status = ?";
        $values[] = $status;
        $values[] = $verificationId;

        $stmt = $this->db->prepare(
            "UPDATE influencer_verifications
             SET " . implode(', ', $assignments) . "
             WHERE id = ?"
        );

        return $stmt->execute($values);
    }

    public function getSubmittedVerifications(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT iv.*, ip.username, ip.page_url, ip.platform, u.full_name, u.email
             FROM influencer_verifications iv
             JOIN influencer_profiles ip ON ip.id = iv.profile_id
             LEFT JOIN users u ON u.id = ip.user_id
             WHERE iv.status = 'submitted'
             ORDER BY iv.submitted_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function countSubmittedVerifications(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM influencer_verifications WHERE status = 'submitted'");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function findLatestVerificationByProfile(int $profileId): ?object
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM influencer_verifications
             WHERE profile_id = ?
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->execute([$profileId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function getVerificationHistoryByProfile(int $profileId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, status, code, created_at, submitted_at, approved_at, rejection_reason
             FROM influencer_verifications
             WHERE profile_id = ?
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$profileId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function cleanupExpiredPendingVerifications(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE influencer_verifications
             SET status = 'expired'
             WHERE status = 'pending' AND expires_at < NOW()"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ==========================================
    // Reputation (InfluencerReputation)
    // ==========================================

    public function addReputationEvent(array $d): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO influencer_reputation_events
            (profile_id, user_id, order_id, event_type, points, note, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([
            $d['profile_id'],
            $d['user_id'],
            $d['order_id'] ?? null,
            $d['event_type'],
            $d['points'],
            $d['note'] ?? null,
        ]);
    }

    public function getReputationStats(int $profileId): object
    {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(points), 0)                                              AS total_points,
                COUNT(CASE WHEN points > 0 THEN 1 END)                                AS positive_events,
                COUNT(CASE WHEN points < 0 THEN 1 END)                                AS negative_events
            FROM influencer_reputation_events
            WHERE profile_id = ?
        ");
        $stmt->execute([$profileId]);
        $events = $stmt->fetch(\PDO::FETCH_OBJ) ?: (object)['total_points'=>0,'positive_events'=>0,'negative_events'=>0];

        $stmt2 = $this->db->prepare("
            SELECT
                COUNT(*) AS total_orders,
                COUNT(CASE WHEN status = 'completed' THEN 1 END)            AS completed_orders,
                COUNT(CASE WHEN status IN ('peer_resolution','escalated_to_admin','disputed') THEN 1 END) AS disputed_orders
            FROM story_orders
            WHERE influencer_id = ?
        ");
        $stmt2->execute([$profileId]);
        $orders = $stmt2->fetch(\PDO::FETCH_OBJ) ?: (object)['total_orders'=>0,'completed_orders'=>0,'disputed_orders'=>0];

        $totalOrders    = (int) $orders->total_orders;
        $completedOrders= (int) $orders->completed_orders;
        $disputedOrders = (int) $orders->disputed_orders;
        $totalPoints    = (int) $events->total_points;

        $completionRate = $totalOrders > 0 ? \round(($completedOrders / $totalOrders) * 100) : 0;
        $disputeRate    = $totalOrders > 0 ? \round(($disputedOrders  / $totalOrders) * 100) : 0;

        $grade = $this->calculateReputationGrade($totalPoints, (int) $completionRate, (int) $disputeRate);

        return (object)[
            'total_points'    => $totalPoints,
            'total_orders'    => $totalOrders,
            'completed_orders'=> $completedOrders,
            'disputed_orders' => $disputedOrders,
            'completion_rate' => $completionRate,
            'dispute_rate'    => $disputeRate,
            'grade'           => $grade['letter'],
            'grade_label'     => $grade['label'],
            'grade_color'     => $grade['color'],
            'stars'           => $grade['stars'],
        ];
    }

    public function getReputationHistory(int $profileId, int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT e.*, so.order_type
            FROM influencer_reputation_events e
            LEFT JOIN story_orders so ON so.id = e.order_id
            WHERE e.profile_id = ?
            ORDER BY e.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$profileId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    private function calculateReputationGrade(int $points, int $completionRate, int $disputeRate): array
    {
        $score = $points
            + ($completionRate >= 90 ?  20 : ($completionRate >= 70 ?  10 : 0))
            - ($disputeRate    >= 30 ? -20 : ($disputeRate    >= 15 ? -10 : 0));

        if ($score >= 100 && $completionRate >= 85 && $disputeRate <= 10) {
            return ['letter'=>'A', 'label'=>'عالی', 'color'=>'success', 'stars'=>5];
        }
        if ($score >= 60 && $completionRate >= 70) {
            return ['letter'=>'B', 'label'=>'خوب', 'color'=>'primary', 'stars'=>4];
        }
        if ($score >= 20 && $completionRate >= 50) {
            return ['letter'=>'C', 'label'=>'متوسط', 'color'=>'warning', 'stars'=>3];
        }
        if ($score >= 0) {
            return ['letter'=>'D', 'label'=>'ضعیف', 'color'=>'orange', 'stars'=>2];
        }
        return ['letter'=>'F', 'label'=>'نامناسب', 'color'=>'danger', 'stars'=>1];
    }

    /**
     * Native Modern Search Method utilizing central Filterable architecture.
     */
    public function searchNative(string $q, array $filters, int $limit, int $offset, string $sortColumn = 'created_at', string $sortDir = 'DESC'): array
    {
        $query = $this->db->table('influencer_profiles as ip')
            ->select('ip.*', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'ip.user_id')
            ->whereNull('ip.deleted_at');

        // 1. Standard text search from base Core\Model helper
        if (!empty($q)) {
            $query = $this->applySearch($query, $q);
        }

        // 2. Auto filter resolution via powerful Trait architecture!
        $query = $this->applyFilters($query, $filters);

        // Standard validation to guarantee syntax security for dynamic ordering
        $allowedSortColumns = ['created_at', 'follower_count', 'average_rating', 'story_price_24h', 'priority', 'completed_orders'];
        $sort = in_array($sortColumn, $allowedSortColumns, true) ? $sortColumn : 'created_at';
        $dir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy("ip.{$sort}", $dir)->limit($limit)->offset($offset)->get() ?? []
        ];
    }
}
