<?php

namespace App\Models;

use Core\Model;

/**
 * Model برای جدول security_events
 */
class SecurityEvent extends Model
{
    protected static string $table = 'security_events';

    /**
     * ثبت رویداد امنیتی
     */
    public function log(array $data): bool
    {
        $sql = "INSERT INTO " . static::$table . "
                (event_type, user_id, ip_address, device_fingerprint, request_id, details, created_at)
                VALUES (:event_type, :user_id, :ip_address, :device_fingerprint, :request_id, :details, :created_at)";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'event_type'         => $data['event_type'],
            'user_id'            => $data['user_id']            ?? null,
            'ip_address'         => $data['ip_address']         ?? null,
            'device_fingerprint' => $data['device_fingerprint'] ?? null,
            'request_id'         => $data['request_id']         ?? null,
            'details'            => is_array($data['details']) ? json_encode($data['details'], JSON_UNESCAPED_UNICODE) : ($data['details'] ?? null),
            'created_at'         => $data['created_at']         ?? date('Y-m-d H:i:s'),
        ]);
    }
}
