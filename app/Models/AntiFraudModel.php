<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * AntiFraudModel - Backwards-Compatible Proxy for Hybrid Anti-Fraud Models
 */
class AntiFraudModel extends Model
{
    protected static string $table = 'fraud_logs';

    private IpAndDeviceModel $ipAndDevice;
    private VelocityAndScoreModel $velocityAndScore;
    private FraudAnalyticsModel $analytics;

    public function __construct(\Core\Database $db)
    {
        parent::__construct($db);
        $this->ipAndDevice = new IpAndDeviceModel($db);
        $this->velocityAndScore = new VelocityAndScoreModel($db);
        $this->analytics = new FraudAnalyticsModel($db);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // IP Quality & Geolocation (IpAndDeviceModel)
    // ═══════════════════════════════════════════════════════════════════════

    public function getSuspiciousIpRanges(): array
    {
        return $this->ipAndDevice->getSuspiciousIpRanges();
    }

    public function isTorNode(string $ip): bool
    {
        return $this->ipAndDevice->isTorNode($ip);
    }

    public function getUserCountByIp(string $ip, int $days = 7): int
    {
        return $this->ipAndDevice->getUserCountByIp($ip, $days);
    }

    public function getIpVelocity(string $ip): ?object
    {
        return $this->ipAndDevice->getIpVelocity($ip);
    }

    public function isIpBlacklisted(string $ip): bool
    {
        return $this->ipAndDevice->isIpBlacklisted($ip);
    }

    public function blacklistIp(string $ip, string $reason, ?string $expiresAt): bool
    {
        return $this->ipAndDevice->blacklistIp($ip, $reason, $expiresAt);
    }

    public function getLastLoginLocation(int $userId): ?object
    {
        return $this->ipAndDevice->getLastLoginLocation($userId);
    }

    public function getSessionsForVelocity(int $userId, string $since): array
    {
        return $this->ipAndDevice->getSessionsForVelocity($userId, $since);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Device Fingerprint & Biometrics (IpAndDeviceModel)
    // ═══════════════════════════════════════════════════════════════════════

    public function upsertFingerprint(int $userId, string $fingerprint, array $metadata): bool
    {
        return $this->ipAndDevice->upsertFingerprint($userId, $fingerprint, $metadata);
    }

    public function getFingerprintUserCount(string $fingerprint, int $days = 30): int
    {
        return $this->ipAndDevice->getFingerprintUserCount($fingerprint, $days);
    }

    public function isFingerprintBlacklisted(string $fingerprint): bool
    {
        return $this->ipAndDevice->isFingerprintBlacklisted($fingerprint);
    }

    public function isBlacklisted(string $fingerprint): bool
    {
        return $this->ipAndDevice->isBlacklisted($fingerprint);
    }

    public function blacklistFingerprint(string $fingerprint, string $reason, ?string $expiresAt): bool
    {
        return $this->ipAndDevice->blacklistFingerprint($fingerprint, $reason, $expiresAt);
    }

    public function storeFingerprint(int $userId, string $fingerprint, array $metadata): bool
    {
        return $this->ipAndDevice->storeFingerprint($userId, $fingerprint, $metadata);
    }

    public function getRecentFingerprints(int $userId, int $limit = 5): array
    {
        return $this->ipAndDevice->getRecentFingerprints($userId, $limit);
    }

    public function getAllUserFingerprints(int $userId, int $limit = 10): array
    {
        return $this->ipAndDevice->getAllUserFingerprints($userId, $limit);
    }

    public function logSuspicion(int $userId, int $score, array $analysis): bool
    {
        return $this->ipAndDevice->logSuspicion($userId, $score, $analysis);
    }

    public function getLastTypingPattern(int $userId): ?object
    {
        return $this->ipAndDevice->getLastTypingPattern($userId);
    }

    public function saveTypingPattern(int $userId, array $pattern): bool
    {
        return $this->ipAndDevice->saveTypingPattern($userId, $pattern);
    }

    public function saveDeviceAnalysis(int $userId, string $fingerprint, array $deviceInfo, array $analysis, float $riskScore): bool
    {
        return $this->ipAndDevice->saveDeviceAnalysis($userId, $fingerprint, $deviceInfo, $analysis, $riskScore);
    }

    public function getDeviceHistory(string $fingerprint): ?object
    {
        return $this->ipAndDevice->getDeviceHistory($fingerprint);
    }

    public function getDeviceSharing(int $userId): array
    {
        return $this->ipAndDevice->getDeviceSharing($userId);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Velocity & Behavior Check (VelocityAndScoreModel)
    // ═══════════════════════════════════════════════════════════════════════

    public function getTransactionCount(int $userId, string $type, int $seconds): int
    {
        return $this->velocityAndScore->getTransactionCount($userId, $type, $seconds);
    }

    public function getRecentTransactionCount(int $userId, int $hours): int
    {
        return $this->velocityAndScore->getRecentTransactionCount($userId, $hours);
    }

    public function getUserAverageDaily(int $userId): float
    {
        return $this->velocityAndScore->getUserAverageDaily($userId);
    }

    public function getTransactionAmountStats(int $userId, int $days = 90): array
    {
        return $this->velocityAndScore->getTransactionAmountStats($userId, $days);
    }

    public function getHourlyActivity(int $userId, int $days = 30): array
    {
        return $this->velocityAndScore->getHourlyActivity($userId, $days);
    }

    public function getDeviceCount(int $userId, int $days = 7): int
    {
        return $this->velocityAndScore->getDeviceCount($userId, $days);
    }

    public function getBehaviorMetrics(int $userId, int $days, int $offset = 0): array
    {
        return $this->velocityAndScore->getBehaviorMetrics($userId, $days, $offset);
    }

    public function getUserAndReferrerInfo(int $userId): ?object
    {
        return $this->velocityAndScore->getUserAndReferrerInfo($userId);
    }

    public function getSharedIPData(int $userId, int $days = 30): array
    {
        return $this->velocityAndScore->getSharedIPData($userId, $days);
    }

    public function storePrediction(int $userId, float $riskScore, array $features): bool
    {
        return $this->velocityAndScore->storePrediction($userId, $riskScore, $features);
    }

    public function updatePredictionFeedback(int $userId, string $actualOutcome): bool
    {
        return $this->velocityAndScore->updatePredictionFeedback($userId, $actualOutcome);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Account Takeover & Contact Verification Caching (VelocityAndScoreModel)
    // ═══════════════════════════════════════════════════════════════════════

    public function getLastPasswordChange(int $userId): ?string
    {
        return $this->velocityAndScore->getLastPasswordChange($userId);
    }

    public function getLastEmailChange(int $userId): ?string
    {
        return $this->velocityAndScore->getLastEmailChange($userId);
    }

    public function getIPUsageCount(int $userId, string $ip): int
    {
        return $this->velocityAndScore->getIPUsageCount($userId, $ip);
    }

    public function getDeviceUsageCount(int $userId, string $userAgent): int
    {
        return $this->velocityAndScore->getDeviceUsageCount($userId, $userAgent);
    }

    public function getRecentFailedAttempts(int $userId): int
    {
        return $this->velocityAndScore->getRecentFailedAttempts($userId);
    }

    public function logTakeoverDetection(int $userId, string $ip, string $userAgent, array $detection): void
    {
        $this->velocityAndScore->logTakeoverDetection($userId, $ip, $userAgent, $detection);
    }

    public function getEmailFromCache(string $email): ?object
    {
        return $this->velocityAndScore->getEmailFromCache($email);
    }

    public function getDomainIntelligence(string $domain): ?object
    {
        return $this->velocityAndScore->getDomainIntelligence($domain);
    }

    public function saveEmailToCache(string $email, string $domain, array $analysis): bool
    {
        return $this->velocityAndScore->saveEmailToCache($email, $domain, $analysis);
    }

    public function getPhoneFromCache(string $phone): ?object
    {
        return $this->velocityAndScore->getPhoneFromCache($phone);
    }

    public function savePhoneToCache(string $phone, array $analysis): bool
    {
        return $this->velocityAndScore->savePhoneToCache($phone, $analysis);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scoring, Reputation & Connections Graphs (VelocityAndScoreModel)
    // ═══════════════════════════════════════════════════════════════════════

    public function getAccountAge(int $userId): int
    {
        return $this->velocityAndScore->getAccountAge($userId);
    }

    public function getUserReputation(int $userId): int
    {
        return $this->velocityAndScore->getUserReputation($userId);
    }

    public function getDailyTransactionCount(int $userId): int
    {
        return $this->velocityAndScore->getDailyTransactionCount($userId);
    }

    public function getWeeklyTransactionCount(int $userId): int
    {
        return $this->velocityAndScore->getWeeklyTransactionCount($userId);
    }

    public function getPreviousWeeklyTransactionCount(int $userId): int
    {
        return $this->velocityAndScore->getPreviousWeeklyTransactionCount($userId);
    }

    public function getCountryChanges(int $userId): int
    {
        return $this->velocityAndScore->getCountryChanges($userId);
    }

    public function getCityChanges(int $userId): int
    {
        return $this->velocityAndScore->getCityChanges($userId);
    }

    public function getSuspiciousIPCount(int $userId): int
    {
        return $this->velocityAndScore->getSuspiciousIPCount($userId);
    }

    public function getUserInfo(int $userId): ?object
    {
        return $this->velocityAndScore->getUserInfo($userId);
    }

    public function getReferralConnections(int $userId): array
    {
        return $this->velocityAndScore->getReferralConnections($userId);
    }

    public function getTransactionConnections(int $userId, int $days = 30): array
    {
        return $this->velocityAndScore->getTransactionConnections($userId, $days);
    }

    public function getIPConnections(int $userId, int $days = 30): array
    {
        return $this->velocityAndScore->getIPConnections($userId, $days);
    }

    public function getSharedIPs(int $userId, int $days = 30): array
    {
        return $this->velocityAndScore->getSharedIPs($userId, $days);
    }

    public function getCircularPaths(int $userId, int $minDepth, int $days = 7): array
    {
        return $this->velocityAndScore->getCircularPaths($userId, $minDepth, $days);
    }

    public function updateUserFraudScore(int $userId, int $score): bool
    {
        return $this->velocityAndScore->updateUserFraudScore($userId, $score);
    }

    public function logFraudCalculation(int $userId, array $factors, int $finalScore): bool
    {
        return $this->velocityAndScore->logFraudCalculation($userId, $factors, $finalScore);
    }

    public function flagForReview(int $userId, int $score): bool
    {
        return $this->velocityAndScore->flagForReview($userId, $score);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Fraud Dashboards & Analytics (FraudAnalyticsModel)
    // ═══════════════════════════════════════════════════════════════════════

    public function getOverviewCounts(string $since): object
    {
        return $this->analytics->getOverviewCounts($since);
    }

    public function getRecentAlerts(int $limit, ?string $severity = null): array
    {
        return $this->analytics->getRecentAlerts($limit, $severity);
    }

    public function getFraudTypeDistribution(string $since): array
    {
        return $this->analytics->getFraudTypeDistribution($since);
    }

    public function getHourlyTrend(string $since): array
    {
        return $this->analytics->getHourlyTrend($since);
    }

    public function getGeographicThreats(string $since): array
    {
        return $this->analytics->getGeographicThreats($since);
    }

    public function logFraudEvent(array $data): bool
    {
        return $this->analytics->logFraudEvent($data);
    }
    public function getUserTimezone(int $userId): string
    {
        $row = $this->db->fetch("SELECT timezone FROM users WHERE id = ? LIMIT 1", [$userId]);
        return (string)($row->timezone ?? 'UTC');
    }
}
