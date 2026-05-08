<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * UserSetting Model
 */
class UserSetting extends Model
{
    protected static string $table = 'user_settings';
    protected array $fillable = ['user_id', 'setting_key', 'setting_value'];
    protected bool $timestamps = true;

    /**
     * دریافت تنظیمات کاربر
     */
    public function getUserSettings(int $userId): array
    {
        return $this->db->table(static::$table)
            ->where('user_id', '=', $userId)
            ->get();
    }

    /**
     * دریافت یک تنظیم
     */
    public function getSetting(int $userId, string $key): ?string
    {
        $result = $this->db->table(static::$table)
            ->select('setting_value')
            ->where('user_id', '=', $userId)
            ->where('setting_key', '=', $key)
            ->first();

        return $result ? (string)$result->setting_value : null;
    }
}
