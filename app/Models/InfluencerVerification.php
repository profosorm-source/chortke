<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * InfluencerVerification - مدل تخصصی مدیریت فرآیندهای احراز هویت اینفلوئنسرها
 *
 * این کلاس مسئولیت مدیریت مستقیم بر روی جدول `influencer_verifications` شامل
 * ایجاد، انقضا، بروزرسانی وضعیت و کوئری‌های سمت ادمین را بر عهده دارد.
 */
class InfluencerVerification extends Model
{
    protected static string $table = 'influencer_verifications';

    /**
     * یافتن آخرین کد فعال منتظر ثبت پست برای یک پروفایل خاص
     */
    public function findPendingByProfile(int $profileId): ?object
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM influencer_verifications
             WHERE profile_id = ? AND status = 'pending' AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$profileId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    /**
     * منقضی کردن تمام تاییدیه‌های منتظر قبلی برای یک پروفایل جهت ایجاد تاییدیه جدید
     */
    public function expirePendingForProfile(int $profileId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE influencer_verifications
             SET status = 'expired'
             WHERE profile_id = ? AND status = 'pending'"
        );
        return $stmt->execute([$profileId]);
    }

    /**
     * ثبت رکورد جدید احراز هویت به همراه کد تایید و زمان انقضا
     */
    public function createVerification(int $profileId, string $code, string $expiresAt): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO influencer_verifications
             (profile_id, code, status, expires_at, created_at)
             VALUES (?, ?, 'pending', ?, NOW())"
        );
        return $stmt->execute([$profileId, $code, $expiresAt]);
    }

    /**
     * یافتن تاییدیه با شناسه دقیق
     */
    public function findById(int $verificationId): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM influencer_verifications WHERE id = ? LIMIT 1");
        $stmt->execute([$verificationId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    /**
     * واکشی سطر تاییدیه جهت بروزرسانی با قابلیت قفل‌گذاری تراکنشی (Atomic Transaction For Update)
     */
    public function findByIdForUpdate(int $verificationId): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM influencer_verifications WHERE id = ? FOR UPDATE");
        $stmt->execute([$verificationId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    /**
     * یافتن تاییدیه ارسال شده (منتظر تایید ادمین) برای یک پروفایل
     */
    public function findSubmittedByProfile(int $profileId): ?object
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

    /**
     * بروزرسانی وضعیت تاییدیه و فیلدهای پویای مرتبط (Approved/Rejected)
     */
    public function updateStatus(int $verificationId, string $status, array $fields = []): bool
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

    /**
     * دریافت لیست درخواست‌های ارسال شده برای بازبینی ادمین به همراه اطلاعات پروفایل
     */
    public function getSubmittedRequests(int $limit = 50, int $offset = 0): array
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
        
        // تبدیل امن انواع متغیرها برای PDO Statement
        $stmt->bindValue(1, (int)$limit, \PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * شمارش کل تعداد درخواست‌های بازبینی در انتظار ادمین
     */
    public function countSubmittedRequests(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM influencer_verifications WHERE status = 'submitted'");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * واکشی جدیدترین رکورد تاییدیه (بدون درنظر گرفتن وضعیت)
     */
    public function findLatestByProfile(int $profileId): ?object
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

    /**
     * واکشی تاریخچه درخواست‌های احراز هویت اینفلوئنسر
     */
    public function getHistoryByProfile(int $profileId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, status, code, created_at, submitted_at, approved_at, rejection_reason
             FROM influencer_verifications
             WHERE profile_id = ?
             ORDER BY created_at DESC
             LIMIT ?"
        );
        
        $stmt->bindValue(1, (int)$profileId, \PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$limit, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * پاکسازی و تغییر وضعیت کدهای معلق که زمان انقضای آن‌ها سر آمده است (بهبود عملکرد دوره‌ای)
     */
    public function cleanupExpiredPending(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE influencer_verifications
             SET status = 'expired'
             WHERE status = 'pending' AND expires_at < NOW()"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
