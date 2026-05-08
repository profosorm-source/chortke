<?php

namespace App\Models;

use Core\Model;

class NotificationPreference extends Model
{
    protected static string $table = 'notification_preferences';

    // ─── فیلدهای مجاز ────────────────────────────────────────────────────────
    private const ALLOWED_FIELDS = [
        // In-App
        'in_app_enabled', 'in_app_deposit', 'in_app_withdrawal', 'in_app_task',
        'in_app_kyc', 'in_app_lottery', 'in_app_referral', 'in_app_security',
        'in_app_investment', 'in_app_system', 'in_app_info',
        // Email
        'email_enabled', 'email_deposit', 'email_withdrawal', 'email_task',
        'email_kyc', 'email_lottery', 'email_referral', 'email_security',
        'email_investment', 'email_system', 'email_marketing',
        // Push (FCM)
        'push_enabled', 'push_deposit', 'push_withdrawal', 'push_task',
        'push_security', 'push_lottery', 'push_system',
        // SMS
        'sms_enabled', 'sms_security', 'sms_withdrawal',
        // Do Not Disturb
        'dnd_enabled', 'dnd_start', 'dnd_end',
    ];

    /**
     * دریافت یا ایجاد تنظیمات کاربر
     */
    public function getOrCreate(int $userId): object
    {
        $prefs = $this->db->query(
            "SELECT * FROM " . static::$table . " WHERE user_id = ? LIMIT 1",
            [$userId]
        )->fetch(\PDO::FETCH_OBJ);

        if (!$prefs) {
            $now = date('Y-m-d H:i:s');
            $this->db->query(
                "INSERT INTO " . static::$table . " (user_id, created_at, updated_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE updated_at = updated_at",
                [$userId, $now, $now]
            );

            $prefs = $this->db->query(
                "SELECT * FROM " . static::$table . " WHERE user_id = ? LIMIT 1",
                [$userId]
            )->fetch(\PDO::FETCH_OBJ);
        }

        // fallback اگر جدول هنوز migrate نشده
        if (!$prefs) {
            return (object)[
                'user_id'         => $userId,
                'in_app_enabled'  => 1,
                'email_enabled'   => 1,
                'push_enabled'    => 1,
                'sms_enabled'     => 0,
                'dnd_enabled'     => 0,
                'dnd_start'       => '23:00:00',
                'dnd_end'         => '07:00:00',
            ];
        }

        return $prefs;
    }

    /**
     * بررسی فعال بودن In-App
     */
    public function isInAppEnabled(int $userId, string $type): bool
    {
        try {
            $prefs = $this->getOrCreate($userId);
        } catch (\Throwable) {
            return true;
        }

        if (isset($prefs->in_app_enabled) && !$prefs->in_app_enabled) {
            return false;
        }

        $field = 'in_app_' . $type;
        if (property_exists($prefs, $field)) {
            return (bool)$prefs->$field;
        }

        return true;
    }

    /**
     * بررسی فعال بودن Email
     */
    public function isEmailEnabled(int $userId, string $type): bool
    {
        try {
            $prefs = $this->getOrCreate($userId);
        } catch (\Throwable) {
            return true;
        }

        if (isset($prefs->email_enabled) && !$prefs->email_enabled) {
            return false;
        }

        $field = 'email_' . $type;
        if (property_exists($prefs, $field)) {
            return (bool)$prefs->$field;
        }

        return true;
    }

    /**
     * بررسی فعال بودن Push (FCM)
     */
    public function isPushEnabled(int $userId, string $type): bool
    {
        try {
            $prefs = $this->getOrCreate($userId);
        } catch (\Throwable) {
            return true;
        }

        if (isset($prefs->push_enabled) && !$prefs->push_enabled) {
            return false;
        }

        $field = 'push_' . $type;
        if (property_exists($prefs, $field)) {
            return (bool)$prefs->$field;
        }

        return true;
    }

    /**
     * بررسی فعال بودن SMS
     */
    public function isSmsEnabled(int $userId, string $type): bool
    {
        try {
            $prefs = $this->getOrCreate($userId);
        } catch (\Throwable) {
            return false;
        }

        if (empty($prefs->sms_enabled)) {
            return false;
        }

        $field = 'sms_' . $type;
        if (property_exists($prefs, $field)) {
            return (bool)$prefs->$field;
        }

        return false;
    }

    /**
     * بررسی Do Not Disturb — آیا الان در بازه ساکت است؟
     */
    public function isInDndMode(int $userId): bool
    {
        try {
            $prefs = $this->getOrCreate($userId);
        } catch (\Throwable) {
            return false;
        }

        if (empty($prefs->dnd_enabled)) {
            return false;
        }

        $start = $prefs->dnd_start ?? '23:00:00';
        $end   = $prefs->dnd_end   ?? '07:00:00';

        $now       = date('H:i:s');
        $startTime = $start;
        $endTime   = $end;

        // بازه‌ای که از شب می‌گذرد (مثلاً 23:00 تا 07:00)
        if ($startTime > $endTime) {
            return $now >= $startTime || $now < $endTime;
        }

        // بازه عادی (مثلاً 13:00 تا 15:00)
        return $now >= $startTime && $now < $endTime;
    }

    /**
     * آپدیت تنظیمات با whitelist
     */
    public function updateForUser(int $userId, array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        // فقط فیلدهای مجاز
        $filtered = array_filter(
            $data,
            fn($key) => in_array($key, self::ALLOWED_FIELDS, true),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($filtered)) {
            return false;
        }

        $this->getOrCreate($userId);

        $sets   = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($filtered)));
        $values = array_values($filtered);
        $values[] = $userId;

        $stmt = $this->db->query(
            "UPDATE " . static::$table . " SET {$sets}, updated_at = NOW() WHERE user_id = ?",
            $values
        );

        return $stmt instanceof \PDOStatement;
    }

    /**
     * لیست فیلدهای مجاز (برای admin UI)
     */
    public function getAllowedFields(): array
    {
        return self::ALLOWED_FIELDS;
    }
}
