<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * UserVacation - مدل مدیریت مرخصی کاربران چورتکه
 */
class UserVacation extends Model
{
    protected static string $table = 'user_vacations';

    /**
     * محاسبه مجموع روزهای مرخصی کاربر در بازه زمانی گذشته
     */
    public function getCumulativeVacationDays(int $userId, int $daysBack = 90): int
    {
        $stmt = $this->db->prepare("
            SELECT SUM(duration_days) FROM user_vacations
            WHERE user_id = ?
            AND status IN ('active', 'completed')
            AND start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL ? DAY)
        ");
        $stmt->execute([$userId, $daysBack]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * بررسی اینکه آیا کاربر در ۹۰ روز گذشته از مرخصی استفاده کرده است یا خیر
     */
    public function hasRecentVacation(int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_vacations
            WHERE user_id = ? 
            AND status IN ('active', 'completed')
            AND start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 90 DAY)
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
     * بررسی اینکه آیا کاربر در حال حاضر در مرخصی فعال به سر می‌برد یا خیر
     */
    public function isUserOnVacation(int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_vacations
            WHERE user_id = ? 
            AND status = 'active'
            AND CURRENT_DATE() BETWEEN start_date AND end_date
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
     * ثبت مرخصی جدید برای کاربر
     * 
     * @param int $userId شناسه کاربر
     * @param int $durationDays مدت مرخصی (بین ۳ تا ۷ روز)
     * @param float $costPaid هزینه پرداخت‌شده (کیف پول یا امتیاز)
     */
    public function registerVacation(int $userId, int $durationDays, float $costPaid): ?object
    {
        // مدت زمان مرخصی باید دقیقاً بین ۳ تا ۷ روز باشد
        $durationDays = \max(3, \min(7, $durationDays));

        // ✅ بررسی سقف کل مرخصی در ۹۰ روز اخیر (حداکثر ۳۰ روز)
        $cumulative = $this->getCumulativeVacationDays($userId, 90);
        if (($cumulative + $durationDays) > 30) {
            throw new \RuntimeException('مجموع روزهای مرخصی شما در ۹۰ روز گذشته نمی‌تواند از ۳۰ روز بیشتر شود');
        }

        // شروع مرخصی از امروز یا از فردا
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$durationDays} days"));

        $stmt = $this->db->prepare("
            INSERT INTO user_vacations (user_id, start_date, end_date, duration_days, cost_paid, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'active', NOW())
        ");

        $ok = $stmt->execute([
            $userId,
            $startDate,
            $endDate,
            $durationDays,
            $costPaid
        ]);

        if (!$ok) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        return $this->find($id);
    }

    /**
     * یافتن یک رکورد مرخصی با شناسه
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM user_vacations WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * دریافت لیست تاریخچه مرخصی‌های یک کاربر
     */
    public function getByUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM user_vacations
            WHERE user_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
}
