<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * VelocityAndScoreModel - Transaction Velocity, Account Takeover, Fraud Scoring & Connection Graph Data Access Layer
 */
class VelocityAndScoreModel extends Model
{
    protected static string $table = 'transactions';

    // ═══════════════════════════════════════════════════════════════════════
    // Velocity & Behavior Check
    // ═══════════════════════════════════════════════════════════════════════

    public function getTransactionCount(int $userId, string $type, int $seconds): int
    {
        if ($type === 'login') {
            $row = $this->db->fetch(
                "SELECT COUNT(*) as count FROM activity_logs 
                 WHERE user_id = ? AND action IN ('login', 'login_success', 'login_failed') AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
                [$userId, $seconds]
            );
            return (int)($row->count ?? 0);
        }
        if ($type === 'password_change') {
            $row = $this->db->fetch(
                "SELECT COUNT(*) as count FROM activity_logs 
                 WHERE user_id = ? AND action = 'password_changed' AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
                [$userId, $seconds]
            );
            return (int)($row->count ?? 0);
        }

        $row = $this->db->fetch(
            "SELECT COUNT(*) as count FROM transactions 
             WHERE user_id = ? AND type = ? 
             AND status NOT IN ('failed', 'rejected', 'cancelled')
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$userId, $type, $seconds]
        );
        return (int)($row->count ?? 0);
    }

    // M-01: Missing method - Get total transaction amount within time period
    public function getTotalAmount(int $userId, string $type, int $seconds): float
    {
        $row = $this->db->fetch(
            "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
             WHERE user_id = ? AND type = ? 
             AND status NOT IN ('failed', 'rejected', 'cancelled')
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$userId, $type, $seconds]
        );
        return (float)($row->total ?? 0);
    }

    // M-01: Missing method - Count repeated transactions with same amount
    public function getRepeatedTransactionsCount(int $userId, string $type, float $amount): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) as count FROM transactions 
             WHERE user_id = ? AND type = ? AND amount = ? 
             AND status NOT IN ('failed', 'rejected', 'cancelled')
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$userId, $type, $amount]
        );
        return (int)($row->count ?? 0);
    }

    // M-01: Missing method - Get stats on round number transactions
    public function getRoundNumberStats(int $userId): array
    {
        $roundNumbers = [10000, 50000, 100000, 500000, 1000000, 5000000, 10000000];
        $placeholders = implode(',', array_fill(0, count($roundNumbers), '?'));
        
        $row = $this->db->fetch(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN amount IN ($placeholders) THEN 1 ELSE 0 END) as round_count
             FROM transactions 
             WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)",
            array_merge($roundNumbers, [$userId])
        );
        
        return [
            'total' => (int)($row->total ?? 0),
            'round_count' => (int)($row->round_count ?? 0)
        ];
    }

    public function getRecentTransactionCount(int $userId, int $hours): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) as count FROM transactions 
             WHERE user_id = ? 
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$userId, $hours]
        );
        return (int)($row->count ?? 0);
    }

    public function getUserAverageDaily(int $userId): float
    {
        $sql = "SELECT COUNT(*) / GREATEST(DATEDIFF(NOW(), MIN(created_at)), 1) as avg_daily
                FROM transactions
                WHERE user_id = ?";
        $result = $this->db->fetch($sql, [$userId]);
        return (float)($result->avg_daily ?? 0);
    }

    public function getTransactionAmountStats(int $userId, int $days = 90): array
    {
        $sql = "SELECT 
                    COUNT(*) as count,
                    AVG(amount) as mean,
                    STDDEV(amount) as std_dev
                FROM transactions
                WHERE user_id = ?
                AND amount > 0
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

        $result = $this->db->fetch($sql, [$userId, $days]);

        return [
            'count' => (int)($result->count ?? 0),
            'mean' => (float)($result->mean ?? 0),
            'std_dev' => (float)($result->std_dev ?? 0),
        ];
    }

    public function getHourlyActivity(int $userId, int $days = 30): array
    {
        $sql = "SELECT HOUR(created_at) as hour, COUNT(*) as count
                FROM transactions
                WHERE user_id = ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY HOUR(created_at)";

        $results = $this->db->fetchAll($sql, [$userId, $days]);
        $hourlyActivity = [];

        foreach ($results as $row) {
            $hourlyActivity[(int)$row->hour] = (int)$row->count;
        }

        return $hourlyActivity;
    }

    public function getDeviceCount(int $userId, int $days = 7): int
    {
        $sql = "SELECT COUNT(DISTINCT device_fingerprint) as device_count
                FROM transactions
                WHERE user_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                AND device_fingerprint IS NOT NULL";

        $result = $this->db->fetch($sql, [$userId, $days]);
        return (int)($result->device_count ?? 0);
    }

    public function getBehaviorMetrics(int $userId, int $days, int $offset = 0): array
    {
        $sql = "SELECT 
                    COUNT(*) as transaction_count,
                    AVG(amount) as avg_amount,
                    SUM(amount) as total_amount
                FROM transactions
                WHERE user_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";

        $result = $this->db->fetch($sql, [$userId, $days + $offset, $offset]);

        return [
            'transaction_count' => (int)($result->transaction_count ?? 0),
            'avg_amount' => (float)($result->avg_amount ?? 0),
            'total_amount' => (float)($result->total_amount ?? 0),
        ];
    }

    public function getUserAndReferrerInfo(int $userId): ?object
    {
        $sql = "SELECT u.referred_by, r.fraud_score as referrer_fraud_score, r.is_blacklisted as referrer_is_blacklisted
                FROM users u
                LEFT JOIN users r ON u.referred_by = r.id
                WHERE u.id = ?";
        return $this->db->fetch($sql, [$userId]);
    }

    public function getSharedIPData(int $userId, int $days = 30): array
    {
        $sql = "SELECT t.ip_address, COUNT(DISTINCT t.user_id) as user_count,
                       SUM(CASE WHEN u.fraud_score > 70 THEN 1 ELSE 0 END) as suspicious_users
                FROM transactions t
                JOIN users u ON t.user_id = u.id
                WHERE t.user_id = ?
                AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                AND t.ip_address IS NOT NULL
                GROUP BY t.ip_address
                HAVING user_count > 1";

        return $this->db->fetchAll($sql, [$userId, $days]);
    }

    public function storePrediction(int $userId, float $riskScore, array $features): bool
    {
        $sql = "INSERT INTO ml_fraud_predictions 
                (user_id, risk_score, features, created_at)
                VALUES (?, ?, ?, NOW())";

        $stmt = $this->db->query($sql, [
            $userId,
            $riskScore,
            json_encode($features, JSON_UNESCAPED_UNICODE)
        ]);

        return (bool) $stmt;
    }

    public function updatePredictionFeedback(int $userId, string $actualOutcome): bool
    {
        $sql = "UPDATE ml_fraud_predictions
                SET actual_outcome = ?, updated_at = NOW()
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 1";

        $stmt = $this->db->query($sql, [$actualOutcome, $userId]);
        return (bool) $stmt;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Account Takeover & Contact Verification Caching
    // ═══════════════════════════════════════════════════════════════════════

    public function getLastPasswordChange(int $userId): ?string
    {
        $sql = "SELECT created_at FROM activity_logs 
                WHERE user_id = ? AND action = 'password_changed' 
                ORDER BY created_at DESC LIMIT 1";
        $result = $this->db->fetch($sql, [$userId]);
        return $result ? $result->created_at : null;
    }

    public function getLastEmailChange(int $userId): ?string
    {
        $sql = "SELECT created_at FROM activity_logs 
                WHERE user_id = ? AND action = 'email_changed' 
                ORDER BY created_at DESC LIMIT 1";
        $result = $this->db->fetch($sql, [$userId]);
        return $result ? $result->created_at : null;
    }

    public function getIPUsageCount(int $userId, string $ip): int
    {
        $sql = 'SELECT COUNT(*) as count FROM user_sessions WHERE user_id = ? AND ip_address = ?';
        $result = $this->db->fetch($sql, [$userId, $ip]);
        return $result ? (int) $result->count : 0;
    }

    public function getDeviceUsageCount(int $userId, string $userAgent): int
    {
        $sql = 'SELECT COUNT(*) as count FROM user_sessions WHERE user_id = ? AND user_agent = ?';
        $result = $this->db->fetch($sql, [$userId, $userAgent]);
        return $result ? (int) $result->count : 0;
    }

    public function getRecentFailedAttempts(int $userId): int
    {
        $sql = "SELECT COUNT(*) as count FROM activity_logs 
                WHERE user_id = ? AND action = 'login_failed' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $result = $this->db->fetch($sql, [$userId]);
        return $result ? (int) $result->count : 0;
    }

    public function logTakeoverDetection(int $userId, string $ip, string $userAgent, array $detection): void
    {
        $sql = "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details, action_taken, ip_address, user_agent, created_at) 
                VALUES (?, 'account_takeover', ?, ?, ?, ?, ?, NOW())";
        $this->db->query($sql, [
            $userId,
            $detection['risk_score'],
            json_encode($detection, JSON_UNESCAPED_UNICODE),
            $detection['action'],
            $ip,
            $userAgent,
        ]);
    }

    public function getEmailFromCache(string $email): ?object
    {
        $sql = "SELECT * FROM email_intelligence 
                WHERE email = ? 
                AND last_checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        return $this->db->fetch($sql, [$email]);
    }

    public function getDomainIntelligence(string $domain): ?object
    {
        $sql = "SELECT is_disposable FROM email_intelligence WHERE domain = ?";
        return $this->db->fetch($sql, [$domain]);
    }

    public function saveEmailToCache(string $email, string $domain, array $analysis): bool
    {
        $sql = "INSERT INTO email_intelligence 
                (email, domain, is_disposable, is_free_provider, mx_records_valid, last_checked_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                is_disposable = VALUES(is_disposable),
                is_free_provider = VALUES(is_free_provider),
                mx_records_valid = VALUES(mx_records_valid),
                last_checked_at = NOW()";
        
        $stmt = $this->db->query($sql, [
            $email,
            $domain,
            (int) $analysis['is_disposable'],
            (int) $analysis['is_free_provider'],
            (int) $analysis['mx_records_valid']
        ]);

        return (bool) $stmt;
    }

    public function getPhoneFromCache(string $phone): ?object
    {
        $sql = "SELECT * FROM phone_intelligence 
                WHERE phone = ? 
                AND last_checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        return $this->db->fetch($sql, [$phone]);
    }

    public function savePhoneToCache(string $phone, array $analysis): bool
    {
        $sql = "INSERT INTO phone_intelligence 
                (phone, country_code, line_type, is_voip, is_valid, last_checked_at)
                VALUES (?, ?, ?, ?, TRUE, NOW())
                ON DUPLICATE KEY UPDATE
                country_code = VALUES(country_code),
                line_type = VALUES(line_type),
                is_voip = VALUES(is_voip),
                last_checked_at = NOW()";
        
        $stmt = $this->db->query($sql, [
            $phone,
            $analysis['country_code'],
            $analysis['line_type'],
            (int) $analysis['is_voip']
        ]);

        return (bool) $stmt;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scoring, Reputation & Connections Graphs
    // ═══════════════════════════════════════════════════════════════════════

    public function getAccountAge(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE id = ?",
            [$userId]
        );
        
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($result->days ?? 0);
    }

    public function getUserReputation(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT reputation_score FROM user_fraud_flags WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1",
            [$userId]
        );
        
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($result->reputation_score ?? 0);
    }

    public function getDailyTransactionCount(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as count FROM transactions WHERE user_id = ? AND DATE(created_at) = CURDATE()",
            [$userId]
        );
        
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($result->count ?? 0);
    }

    public function getWeeklyTransactionCount(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as count FROM transactions WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$userId]
        );
        
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($result->count ?? 0);
    }

    public function getPreviousWeeklyTransactionCount(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as count FROM transactions 
             WHERE user_id = ? 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$userId]
        );
        
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($result->count ?? 0);
    }

    public function getCountryChanges(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(DISTINCT country) as count 
             FROM user_sessions 
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$userId]
        );
        
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($result->count ?? 0);
    }

    public function getCityChanges(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(DISTINCT city) as count 
             FROM user_sessions 
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$userId]
        );
        
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($result->count ?? 0);
    }

    public function getSuspiciousIPCount(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as count FROM user_sessions us
             JOIN ip_blacklist ib ON us.ip_address = ib.ip_address
             WHERE us.user_id = ? AND us.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$userId]
        );
        
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($result->count ?? 0);
    }

    public function getUserInfo(int $userId): ?object
    {
        $sql = "SELECT id, fraud_score, is_blacklisted, status, created_at
                FROM users
                WHERE id = ?";
        return $this->db->fetch($sql, [$userId]);
    }

    public function getReferralConnections(int $userId): array
    {
        $sql = "SELECT id as user_id, 'referral' as connection_type, 3 as strength
                FROM users
                WHERE referred_by = ?
                
                UNION
                
                SELECT referred_by as user_id, 'referred_by' as connection_type, 2 as strength
                FROM users
                WHERE id = ? AND referred_by IS NOT NULL";

        return $this->db->fetchAll($sql, [$userId, $userId]);
    }

    public function getTransactionConnections(int $userId, int $days = 30): array
    {
        $sql = "SELECT 
                    CASE 
                        WHEN from_user_id = ? THEN to_user_id
                        ELSE from_user_id
                    END as user_id,
                    'transaction' as connection_type,
                    COUNT(*) as strength
                FROM transactions
                WHERE (from_user_id = ? OR to_user_id = ?)
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY user_id
                HAVING strength >= 2";

        return $this->db->fetchAll($sql, [$userId, $userId, $userId, $days]);
    }

    public function getIPConnections(int $userId, int $days = 30): array
    {
        $sql = "SELECT DISTINCT t2.user_id, 'shared_ip' as connection_type, 1 as strength
                FROM transactions t1
                JOIN transactions t2 ON t1.ip_address = t2.ip_address
                WHERE t1.user_id = ?
                AND t2.user_id != ?
                AND t1.ip_address IS NOT NULL
                AND t1.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

        return $this->db->fetchAll($sql, [$userId, $userId, $days]);
    }

    public function getSharedIPs(int $userId, int $days = 30): array
    {
        $sql = "SELECT 
                    t.ip_address,
                    COUNT(DISTINCT t.user_id) as user_count,
                    GROUP_CONCAT(DISTINCT t.user_id) as user_ids
                FROM transactions t
                WHERE t.ip_address IN (
                    SELECT DISTINCT ip_address 
                    FROM transactions 
                    WHERE user_id = ? 
                    AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                )
                AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY t.ip_address
                HAVING user_count > 1";

        return $this->db->fetchAll($sql, [$userId, $days, $days]);
    }

    public function getCircularPaths(int $userId, int $minDepth, int $days = 7): array
    {
        $sql = "WITH RECURSIVE paths AS (
                    SELECT 
                        from_user_id as start_user,
                        to_user_id as current_user,
                        CAST(from_user_id AS CHAR(1000)) as path,
                        1 as depth,
                        amount
                    FROM transactions
                    WHERE from_user_id = ?
                    AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    
                    UNION ALL
                    
                    SELECT 
                        p.start_user,
                        t.to_user_id,
                        CONCAT(p.path, '->', t.to_user_id),
                        p.depth + 1,
                        p.amount
                    FROM paths p
                    JOIN transactions t ON p.current_user = t.from_user_id
                    WHERE p.depth < 5
                    AND FIND_IN_SET(t.to_user_id, REPLACE(p.path, '->', ',')) = 0
                    AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                )
                SELECT * FROM paths
                WHERE current_user = start_user
                AND depth >= ?";

        return $this->db->fetchAll($sql, [$userId, $days, $days, $minDepth]);
    }

    public function updateUserFraudScore(int $userId, int $score): bool
    {
        $stmt = $this->db->query(
            "UPDATE users SET fraud_score = ?, fraud_score_updated_at = NOW() WHERE id = ?",
            [$score, $userId]
        );
        
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() >= 0 : (bool) $stmt;
    }

    public function logFraudCalculation(int $userId, array $factors, int $finalScore): bool
    {
        $stmt = $this->db->query(
            "INSERT INTO fraud_calculation_logs 
             (user_id, account_age_factor, reputation_factor, velocity_factor, geographic_factor, final_score, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId,
                $factors['account_age_factor'] ?? 0,
                $factors['reputation_factor'] ?? 0,
                $factors['velocity_factor'] ?? 0,
                $factors['geographic_factor'] ?? 0,
                $finalScore
            ]
        );
        
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() >= 0 : (bool) $stmt;
    }

    public function flagForReview(int $userId, int $score): bool
    {
        $stmt = $this->db->query(
            "INSERT INTO user_fraud_flags (user_id, flag_type, fraud_score, created_at)
             VALUES (?, 'flagged', ?, NOW())",
            [$userId, $score]
        );
        
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() >= 0 : (bool) $stmt;
    }

    public function getUserTimezone(int $userId): string
    {
        $row = $this->db->fetch("SELECT timezone FROM users WHERE id = ? LIMIT 1", [$userId]);
        return (string)($row->timezone ?? 'Asia/Tehran');
    }
}
