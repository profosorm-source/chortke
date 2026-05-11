<?php

namespace App\Models;

use Core\Database;
use Core\Model;

/**
 * SettingsAuditTrail Model
 */
class SettingsAuditTrail extends Model
{
    protected static $table = 'settings_audit_trail';
    protected $fillable = [
        'user_id',
        'setting_key',
        'old_value',
        'new_value',
        'changed_at',
        'ip_address',
        'user_agent'
    ];
    protected $timestamps = false;

    /**
     * ثبت تغییر تنظیم
     */
    public function logSettingChange(
        int $userId,
        string $settingKey,
        $oldValue,
        $newValue,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int {
        return $this->db->query(
            "INSERT INTO {$this->table} (user_id, setting_key, old_value, new_value, changed_at, ip_address, user_agent)
             VALUES (?, ?, ?, ?, NOW(), ?, ?)",
            [$userId, $settingKey, $oldValue, $newValue, $ipAddress, $userAgent]
        );
    }

    /**
     * دریافت تاریخچه تغییرات کاربر
     */
    public function getUserAuditTrail(int $userId, int $limit = 50): array
    {
        return $this->db->query(
            "SELECT * FROM {$this->table} WHERE user_id = ? ORDER BY changed_at DESC LIMIT ?",
            [$userId, $limit]
        ) ?: [];
    }

    /**
     * دریافت تاریخچه یک تنظیم
     */
    public function getSettingHistory(int $userId, string $settingKey): array
    {
        return $this->db->query(
            "SELECT * FROM {$this->table} WHERE user_id = ? AND setting_key = ? ORDER BY changed_at DESC",
            [$userId, $settingKey]
        ) ?: [];
    }

    /**
     * دریافت تغییرات حساس (مثل privacy settings)
     */
    public function getSensitiveChanges(int $userId): array
    {
        $sensitiveKeys = [
            'profile_visibility',
            'allow_messages',
            'session_timeout',
            'login_alerts'
        ];

        return $this->db->query(
            "SELECT * FROM {$this->table} WHERE user_id = ? AND setting_key IN ('" . implode("','", $sensitiveKeys) . "')
             ORDER BY changed_at DESC",
            [$userId]
        ) ?: [];
    }
}
