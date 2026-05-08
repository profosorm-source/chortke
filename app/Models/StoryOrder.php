<?php

namespace App\Models;
use Core\Model;

use Core\Database;

class StoryOrder extends Model {
    // $db از کلاس والد (Core\Model) به ارث می‌رسد - نیازی به تعریف مجدد نیست
public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT so.*, ip.username AS influencer_username, ip.page_url,
                   ip.profile_image AS influencer_avatar, ip.follower_count,
                   customer.full_name AS customer_name, customer.email AS customer_email,
                   inf_user.full_name AS influencer_name
            FROM story_orders so
            LEFT JOIN influencer_profiles ip ON ip.id = so.influencer_id
            LEFT JOIN users customer ON customer.id = so.customer_id
            LEFT JOIN users inf_user ON inf_user.id = so.influencer_user_id
            WHERE so.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_OBJ);

        return $r ?: null;
    }

    public function create(array $d): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO story_orders
            (customer_id, influencer_id, influencer_user_id, order_type, duration_hours,
             media_path, caption, link, preferred_publish_time, verification_code,
             price, currency, site_fee_percent, site_fee_amount, influencer_earning,
             status, payment_transaction_id, idempotency_key, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
        ");

        $result = $stmt->execute([
            $d['customer_id'],
            $d['influencer_id'],
            $d['influencer_user_id'],
            $d['order_type'] ?? 'story',
            $d['duration_hours'] ?? 24,
            $d['media_path'] ?? null,
            $d['caption'] ?? null,
            $d['link'] ?? null,
            $d['preferred_publish_time'] ?? null,
            $d['verification_code'],
            $d['price'],
            $d['currency'] ?? 'irt',
            $d['site_fee_percent'] ?? 0,
            $d['site_fee_amount'] ?? 0,
            $d['influencer_earning'] ?? 0,
            $d['status'] ?? 'pending_payment',
            $d['payment_transaction_id'] ?? null,
            $d['idempotency_key'],
        ]);

        if (!$result) return null;

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $this->find($id) : null;
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowed = [
            'media_path','caption','link','preferred_publish_time','actual_publish_time',
            'proof_screenshot','proof_video','proof_submitted_at','proof_link','proof_notes',
            'status','rejection_reason',
            'buyer_check_notified_at','buyer_confirmed_at','buyer_check_deadline',
            'peer_resolution_started_at',
            'customer_rating','customer_review','payment_transaction_id','payout_transaction_id',
            'reviewed_by','reviewed_at','admin_note','metadata',
        ];

        foreach ($allowed as $f) {
            if (\array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $values[] = $data[$f];
            }
        }

        if (empty($fields)) return false;

        // همیشه updated_at
        $fields[] = "updated_at = NOW()";

        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE story_orders SET " . \implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    /**
     * سفارش‌های تبلیغ‌دهنده
     */
    public function getByCustomer(int $customerId, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $where = ["so.customer_id = ?"];
        $params = [$customerId];

        if ($status) {
            $where[] = "so.status = ?";
            $params[] = $status;
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT so.*, ip.username AS influencer_username, ip.profile_image AS influencer_avatar
            FROM story_orders so
            LEFT JOIN influencer_profiles ip ON ip.id = so.influencer_id
            WHERE {$whereStr}
            ORDER BY so.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * سفارش‌های اینفلوئنسر
     */
    public function getByInfluencer(int $influencerUserId, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $where = ["so.influencer_user_id = ?"];
        $params = [$influencerUserId];

        if ($status) {
            $where[] = "so.status = ?";
            $params[] = $status;
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT so.*, customer.full_name AS customer_name
            FROM story_orders so
            LEFT JOIN users customer ON customer.id = so.customer_id
            WHERE {$whereStr}
            ORDER BY so.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * لیست ادمین
     */
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $where = ["1=1"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "so.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['order_type'])) {
            $where[] = "so.order_type = ?";
            $params[] = $filters['order_type'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR customer.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT so.*, ip.username AS influencer_username,
                   customer.full_name AS customer_name, inf_user.full_name AS influencer_name
            FROM story_orders so
            LEFT JOIN influencer_profiles ip ON ip.id = so.influencer_id
            LEFT JOIN users customer ON customer.id = so.customer_id
            LEFT JOIN users inf_user ON inf_user.id = so.influencer_user_id
            WHERE {$whereStr}
            ORDER BY so.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function adminCount(array $filters = []): int
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "so.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['order_type'])) {
            $where[] = "so.order_type = ?";
            $params[] = $filters['order_type'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR customer.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM story_orders so
            LEFT JOIN influencer_profiles ip ON ip.id = so.influencer_id
            LEFT JOIN users customer ON customer.id = so.customer_id
            WHERE {$whereStr}
        ");
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * آمار کلی
     */
    public function globalStats(): object
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) AS total_orders,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders,
                COUNT(CASE WHEN status IN (
                    'paid','accepted','proof_submitted',
                    'awaiting_buyer_check','peer_resolution','escalated_to_admin'
                ) THEN 1 END) AS active_orders,
                COUNT(CASE WHEN status = 'awaiting_buyer_check' THEN 1 END) AS pending_buyer_check,
                COUNT(CASE WHEN status IN ('peer_resolution','escalated_to_admin') THEN 1 END) AS in_dispute,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN site_fee_amount ELSE 0 END), 0) AS total_site_earning,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN influencer_earning ELSE 0 END), 0) AS total_influencer_earning,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN price ELSE 0 END), 0) AS total_revenue
            FROM story_orders
        ");
        $stmt->execute();

        return $stmt->fetch(\PDO::FETCH_OBJ) ?: (object)[];
    }

    /**
     * سفارش‌هایی که proof ثبت شده ولی buyer هنوز تایید نکرده و deadline گذشته
     */
    public function getExpiredBuyerChecks(): array
    {
        $stmt = $this->db->prepare("
            SELECT id FROM story_orders
            WHERE status = 'awaiting_buyer_check'
            AND buyer_check_deadline <= NOW()
        ");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * سفارش‌هایی که در peer_resolution هستند و deadline گذشته
     */
    public function getExpiredPeerResolutions(): array
    {
        $hours = (int) setting('story_peer_resolution_hours', 24);
        $stmt = $this->db->prepare("
            SELECT id FROM story_orders
            WHERE status = 'peer_resolution'
            AND peer_resolution_started_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * سفارش‌هایی که اینفلوئنسر در مهلت پاسخ نداده
     */
    public function getExpiredPendingAcceptance(): array
    {
        $hours = (int) setting('story_accept_deadline_hours', 12);
        $stmt = $this->db->prepare("
            SELECT id FROM story_orders
            WHERE status = 'paid'
            AND created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * تولید کد تأیید
     */
    public function generateVerificationCode(): string
    {
        return 'CK-' . \strtoupper(\substr(\md5(\random_bytes(16)), 0, 6));
    }

    public function statusLabels(): array
    {
        return [
            'pending_payment'        => 'در انتظار پرداخت',
            'paid'                   => 'پرداخت شده',
            'accepted'               => 'پذیرفته شده',
            'rejected_by_influencer' => 'رد توسط اینفلوئنسر',
            'published'              => 'منتشر شده',
            'proof_submitted'        => 'مدرک ارسال شده',
            'awaiting_buyer_check'   => 'در انتظار تأیید خریدار',
            'peer_resolution'        => 'حل اختلاف دوطرفه',
            'escalated_to_admin'     => 'ارجاع به مدیر',
            'verified'               => 'تأیید مدرک',
            'rejected'               => 'رد شده',
            'disputed'               => 'اختلاف',
            'completed'              => 'تکمیل‌شده',
            'cancelled'              => 'لغو شده',
            'refunded'               => 'بازگشت وجه',
            'expired'                => 'منقضی',
        ];
    }

    public function statusClasses(): array
    {
        return [
            'pending_payment'        => 'badge-secondary',
            'paid'                   => 'badge-info',
            'accepted'               => 'badge-primary',
            'rejected_by_influencer' => 'badge-danger',
            'published'              => 'badge-info',
            'proof_submitted'        => 'badge-warning',
            'awaiting_buyer_check'   => 'badge-warning',
            'peer_resolution'        => 'badge-orange',
            'escalated_to_admin'     => 'badge-danger',
            'verified'               => 'badge-success',
            'rejected'               => 'badge-danger',
            'disputed'               => 'badge-danger',
            'completed'              => 'badge-success',
            'cancelled'              => 'badge-secondary',
            'refunded'               => 'badge-warning',
            'expired'                => 'badge-secondary',
        ];
    }
}