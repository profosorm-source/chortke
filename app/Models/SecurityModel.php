<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * SecurityModel - Centralized database operations for Security & Auth (2FA, Sessions, Password Resets, Fraud)
 */
class SecurityModel extends Model
{
    protected static string $table = 'user_sessions';

    // --- 2FA Methods ---

    public function deleteTwoFactorCodes(int $userId): bool
    {
        return (bool)$this->db->query("DELETE FROM two_factor_codes WHERE user_id = ?", [$userId]);
    }

    public function insertTwoFactorCode(int $userId, string $hashedCode, string $expiresAt): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO two_factor_codes (user_id, code, used, expires_at, created_at) VALUES (?, ?, 0, ?, NOW())",
            [$userId, $hashedCode, $expiresAt]
        );
    }

    public function findValidTwoFactorCode(int $userId, string $hashedCode): ?object
    {
        return $this->db->fetch(
            "SELECT id FROM two_factor_codes WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > NOW() LIMIT 1",
            [$userId, $hashedCode]
        );
    }

    public function getValidRecoveryCodes(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT id, code FROM two_factor_codes WHERE user_id = ? AND used = 0 AND expires_at > NOW()",
            [$userId]
        ) ?: [];
    }

    public function markTwoFactorCodeAsUsed(int $id): bool
    {
        return (bool)$this->db->query("UPDATE two_factor_codes SET used = 1 WHERE id = ?", [$id]);
    }

    /**
     * CRIT-04 Fix: حذف فیزیکی کد بازیابی بلافاصله پس از استفاده برای جلوگیری از Replay Attack
     */
    public function deleteTwoFactorCode(int $id): bool
    {
        return (bool)$this->db->query("DELETE FROM two_factor_codes WHERE id = ?", [$id]);
    }

    public function deleteTwoFactorCodeAtomic(int $codeId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM two_factor_codes 
             WHERE id = ? AND user_id = ? AND used = 0 
             LIMIT 1"
        );

        $stmt->execute([$codeId, $userId]);
        return $stmt->rowCount() === 1;
    }

    // --- Password Reset Methods ---

    public function createPasswordResetToken(string $email, string $token): bool
    {
        // CRITICAL-C-03 Fix: Using HMAC-SHA256 with application key to prevent rainbow table attacks
        $hashedToken = hash_hmac('sha256', $token, secure_key());
        
        // LOW-L-01 Fix: Using ON DUPLICATE KEY UPDATE to prevent race conditions and handle unique constraints
        return (bool)$this->db->query(
            "INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE token = VALUES(token), created_at = NOW()",
            [$email, $hashedToken]
        );
    }

    public function findPasswordResetByToken(string $token, int $timeout = 3600): ?object
    {
        // CRITICAL-C-03 Fix: Using HMAC-SHA256 with application key
        $hashedToken = hash_hmac('sha256', $token, secure_key());
        
        // HIGH-H-11 Fix: Enforcing TTL check at database level to prevent clock-drift bypasses
        return $this->db->fetch(
            "SELECT * FROM password_resets WHERE token = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND) LIMIT 1",
            [$hashedToken, $timeout]
        );
    }

    public function deletePasswordResetByEmail(string $email): bool
    {
        return (bool)$this->db->query("DELETE FROM password_resets WHERE email = ?", [$email]);
    }

    public function deleteExpiredPasswordResets(int $seconds = 3600): int
    {
        $result = $this->db->query("DELETE FROM password_resets WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)", [$seconds]);
        return $result instanceof \PDOStatement ? $result->rowCount() : 0;
    }

    // --- User Session Methods ---

    public function upsertSession(array $data): bool
    {
        $sql = "INSERT INTO user_sessions (
                    user_id, session_id, ip_address, user_agent, 
                    device_type, browser, os, country, city, fingerprint,
                    last_activity, created_at, updated_at, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), 1)
                ON DUPLICATE KEY UPDATE
                    ip_address    = VALUES(ip_address),
                    user_agent    = VALUES(user_agent),
                    device_type   = VALUES(device_type),
                    browser       = VALUES(browser),
                    os            = VALUES(os),
                    country       = VALUES(country),
                    city          = VALUES(city),
                    fingerprint   = VALUES(fingerprint),
                    last_activity = NOW(),
                    updated_at    = NOW(),
                    is_active     = 1";

        return (bool)$this->db->query($sql, [
            $data['user_id'],
            $data['session_id'],
            $data['ip_address'],
            $data['user_agent'],
            $data['device_type'] ?? null,
            $data['browser'] ?? null,
            $data['os'] ?? null,
            $data['country'] ?? null,
            $data['city'] ?? null,
            $data['fingerprint'] ?? null
        ]);
    }

    public function updateSessionActivity(string $sessionId): bool
    {
        return (bool)$this->db->query(
            "UPDATE user_sessions SET last_activity = NOW(), updated_at = NOW() WHERE session_id = ? AND is_active = 1",
            [$sessionId]
        );
    }

    public function getActiveSessions(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM user_sessions WHERE user_id = ? AND is_active = 1 ORDER BY last_activity DESC",
            [$userId]
        );
    }

    public function findSessionBySessionId(string $sessionId): ?object
    {
        return $this->db->fetch("SELECT * FROM user_sessions WHERE session_id = ? LIMIT 1", [$sessionId]);
    }

    public function findSessionById(int $id): ?object
    {
        return $this->db->fetch("SELECT * FROM user_sessions WHERE id = ? LIMIT 1", [$id]);
    }

    public function deactivateSession(int $id): bool
    {
        return (bool)$this->db->query("UPDATE user_sessions SET is_active = 0, updated_at = NOW() WHERE id = ?", [$id]);
    }

    public function deactivateUserSessions(int $userId, ?string $excludeSessionId = null): bool
    {
        $sql = "UPDATE user_sessions SET is_active = 0, updated_at = NOW() WHERE user_id = ? AND is_active = 1";
        $params = [$userId];
        if ($excludeSessionId) {
            $sql .= " AND session_id != ?";
            $params[] = $excludeSessionId;
        }
        return (bool)$this->db->query($sql, $params);
    }

    public function deactivateOldestSession(int $userId): bool
    {
        return (bool)$this->db->query(
            "UPDATE user_sessions 
             SET is_active = 0, updated_at = NOW() 
             WHERE user_id = ? AND is_active = 1 
             ORDER BY last_activity ASC LIMIT 1",
            [$userId]
        );
    }

    public function getOldestActiveSession(int $userId): ?object
    {
        return $this->db->fetch(
            "SELECT * FROM user_sessions 
             WHERE user_id = ? AND is_active = 1 
             ORDER BY last_activity ASC LIMIT 1",
            [$userId]
        );
    }

    public function countActiveSessions(int $userId): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) as count FROM user_sessions 
             WHERE user_id = ? AND is_active = 1",
            [$userId]
        );
        return (int)($row->count ?? 0);
    }

    public function deleteInactiveSessions(int $days = 30): int
    {
        $result = $this->db->query(
            "DELETE FROM user_sessions WHERE is_active = 0 AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        return $result instanceof \PDOStatement ? $result->rowCount() : 0;
    }

    public function expireOldSessions(int $days = 7): int
    {
        $result = $this->db->query(
            "UPDATE user_sessions SET is_active = 0 WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        return $result instanceof \PDOStatement ? $result->rowCount() : 0;
    }

    // --- Social & OAuth ---

    public function getSocialAccount(string $provider, string $providerId): ?object
    {
        return $this->db->fetch("SELECT * FROM social_accounts WHERE provider = ? AND provider_id = ? LIMIT 1", [$provider, $providerId]);
    }

    public function createSocialAccount(array $data): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO social_accounts (user_id, provider, provider_id, avatar, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$data['user_id'], $data['provider'], $data['provider_id'], $data['avatar'] ?? null]
        );
    }

    // --- Fraud & Anomaly ---

    public function logFraudEvent(array $data): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO fraud_logs (user_id, session_id, fraud_type, risk_score, details, created_at) 
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$data['user_id'], $data['session_id'], $data['type'], $data['score'], $data['details']]
        );
    }

    public function getRecentUserAgents(int $userId, int $limit = 2): array
    {
        return $this->db->fetchAll(
            "SELECT user_agent, created_at FROM user_sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    public function getRecentGeolocations(int $userId, int $limit = 2): array
    {
        return $this->db->fetchAll(
            "SELECT country, city, created_at FROM user_sessions WHERE user_id = ? AND country IS NOT NULL ORDER BY created_at DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    public function getUnusualHourActivityCount(int $userId, int $days = 7): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) as count FROM user_sessions 
             WHERE user_id = ? AND HOUR(created_at) BETWEEN 2 AND 6 
             AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$userId, $days]
        );
        return (int)($row->count ?? 0);
    }

    public function getActionCount(int $userId, int $intervalMinutes = 1): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) as count FROM activity_logs 
             WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$userId, $intervalMinutes]
        );
        return (int)($row->count ?? 0);
    }
    public function getUserTimezone(int $userId): string
    {
        $row = $this->db->fetch("SELECT timezone FROM users WHERE id = ? LIMIT 1", [$userId]);
        return (string)($row->timezone ?? 'UTC');
    }
}
