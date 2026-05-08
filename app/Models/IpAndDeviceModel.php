<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * IpAndDeviceModel - IP Quality, Geolocation, Device Fingerprinting & Biometrics Data Access Layer
 */
class IpAndDeviceModel extends Model
{
    protected static string $table = 'user_fingerprints';

    // ═══════════════════════════════════════════════════════════════════════
    // IP Quality & Geolocation
    // ═══════════════════════════════════════════════════════════════════════

    public function getSuspiciousIpRanges(): array
    {
        return $this->db->fetchAll("SELECT ip_range FROM vpn_ranges");
    }

    public function isTorNode(string $ip): bool
    {
        $row = $this->db->fetch("SELECT id FROM tor_exit_nodes WHERE ip_address = ? LIMIT 1", [$ip]);
        return (bool)$row;
    }

    public function getUserCountByIp(string $ip, int $days = 7): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(DISTINCT user_id) as count FROM user_sessions 
             WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$ip, $days]
        );
        return (int)($row->count ?? 0);
    }

    public function getIpVelocity(string $ip): ?object
    {
        return $this->db->fetch(
            "SELECT user_id, COUNT(DISTINCT ip_address) AS ip_count FROM user_sessions 
             WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) 
             GROUP BY user_id HAVING ip_count > 5 LIMIT 1",
            [$ip]
        );
    }

    public function isIpBlacklisted(string $ip): bool
    {
        $row = $this->db->fetch(
            "SELECT id FROM ip_blacklist WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1",
            [$ip]
        );
        return (bool)$row;
    }

    public function blacklistIp(string $ip, string $reason, ?string $expiresAt): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO ip_blacklist (ip_address, reason, auto_blocked, expires_at) VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = VALUES(expires_at)",
            [$ip, $reason, $expiresAt]
        );
    }

    public function getLastLoginLocation(int $userId): ?object
    {
        $sql = "SELECT ip_address, country, city, latitude, longitude, created_at as login_at
                FROM user_sessions 
                WHERE user_id = ? 
                AND latitude IS NOT NULL 
                AND longitude IS NOT NULL
                ORDER BY created_at DESC 
                LIMIT 1 OFFSET 1";
        
        return $this->db->fetch($sql, [$userId]);
    }

    public function getSessionsForVelocity(int $userId, string $since): array
    {
        $sql = "SELECT ip_address, country, city, latitude, longitude, created_at
                FROM user_sessions 
                WHERE user_id = ? 
                AND created_at >= ?
                AND latitude IS NOT NULL 
                AND longitude IS NOT NULL
                ORDER BY created_at ASC";
        
        return $this->db->fetchAll($sql, [$userId, $since]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Device Fingerprint & Biometrics
    // ═══════════════════════════════════════════════════════════════════════

    public function upsertFingerprint(int $userId, string $fingerprint, array $metadata): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO user_fingerprints (user_id, fingerprint, metadata, created_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_seen = NOW(), seen_count = seen_count + 1",
            [$userId, $fingerprint, json_encode($metadata)]
        );
    }

    public function getFingerprintUserCount(string $fingerprint, int $days = 30): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(DISTINCT user_id) as count FROM user_fingerprints 
             WHERE fingerprint = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$fingerprint, $days]
        );
        return (int)($row->count ?? 0);
    }

    public function isFingerprintBlacklisted(string $fingerprint): bool
    {
        $row = $this->db->fetch(
            "SELECT id FROM device_blacklist WHERE fingerprint = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1",
            [$fingerprint]
        );
        return (bool)$row;
    }

    public function isBlacklisted(string $fingerprint): bool
    {
        return $this->isFingerprintBlacklisted($fingerprint);
    }

    public function blacklistFingerprint(string $fingerprint, string $reason, ?string $expiresAt): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO device_blacklist (fingerprint, reason, auto_blocked, expires_at) VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = VALUES(expires_at)",
            [$fingerprint, $reason, $expiresAt]
        );
    }

    public function storeFingerprint(int $userId, string $fingerprint, array $metadata): bool
    {
        return $this->upsertFingerprint($userId, $fingerprint, $metadata);
    }

    public function getRecentFingerprints(int $userId, int $limit = 5): array
    {
        $sql = "SELECT * FROM user_fingerprints WHERE user_id = ? ORDER BY created_at DESC LIMIT ?";
        return $this->db->fetchAll($sql, [$userId, $limit]);
    }

    public function getAllUserFingerprints(int $userId, int $limit = 10): array
    {
        $sql = "SELECT * FROM user_fingerprints WHERE user_id = ? ORDER BY created_at DESC LIMIT ?";
        return $this->db->fetchAll($sql, [$userId, $limit]);
    }

    public function logSuspicion(int $userId, int $score, array $analysis): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details, created_at)
             VALUES (?, 'browser_fingerprint', ?, ?, NOW())",
            [$userId, $score, json_encode($analysis, JSON_UNESCAPED_UNICODE)]
        );
    }

    public function getLastTypingPattern(int $userId): ?object
    {
        $sql = "SELECT avg_interval, stddev_interval 
                FROM user_typing_patterns 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 1";
        return $this->db->fetch($sql, [$userId]);
    }

    public function saveTypingPattern(int $userId, array $pattern): bool
    {
        $sql = "INSERT INTO user_typing_patterns 
                (user_id, avg_interval, stddev_interval, avg_hold_time, keystroke_count, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->db->query($sql, [
            $userId,
            $pattern['avg_interval'],
            $pattern['stddev_interval'],
            $pattern['avg_hold_time'],
            $pattern['keystroke_count']
        ]);

        return (bool) $stmt;
    }

    public function saveDeviceAnalysis(int $userId, string $fingerprint, array $deviceInfo, array $analysis, float $riskScore): bool
    {
        $sql = "INSERT INTO device_intelligence 
                (user_id, fingerprint, device_info, analysis_result, risk_score, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->db->query($sql, [
            $userId,
            $fingerprint,
            json_encode($deviceInfo, JSON_UNESCAPED_UNICODE),
            json_encode($analysis, JSON_UNESCAPED_UNICODE),
            $riskScore
        ]);

        return (bool) $stmt;
    }

    public function getDeviceHistory(string $fingerprint): ?object
    {
        $sql = "SELECT COUNT(DISTINCT user_id) as user_count,
                       COUNT(*) as total_uses,
                       MAX(created_at) as last_seen,
                       AVG(risk_score) as avg_risk_score
                FROM device_intelligence 
                WHERE fingerprint = ?";
        
        return $this->db->fetch($sql, [$fingerprint]);
    }

    public function getDeviceSharing(int $userId): array
    {
        $sql = "SELECT device_fingerprint, COUNT(DISTINCT id) as account_count
                FROM users
                WHERE device_fingerprint IN (
                    SELECT device_fingerprint 
                    FROM users 
                    WHERE id = ?
                )
                GROUP BY device_fingerprint
                HAVING account_count > 1";

        return $this->db->fetchAll($sql, [$userId]);
    }
}
