<?php

declare(strict_types=1);

namespace App\Services\User;

use Core\Database;
use App\Services\AntiFraud\RiskPolicyService;
use App\Contracts\LoggerInterface;
use Core\Cache;
use Core\Queue;
use App\Jobs\UpdateFraudScoreJob;

class UserScoreService extends \App\Services\BaseService
{
    public function __construct(
        private Database $db,
        private RiskPolicyService $policyService,
        protected LoggerInterface $logger,
        private Cache $cache,
        private Queue $queue
    ) {
        parent::__construct($logger);
    }

    public function applyEventDelta(int $userId, string $domain, float $delta, string $source, array $meta = []): bool
    {
        // برای دامنه حساس Fraud، از مکانیسم ضد DoS ناهمگام استفاده می‌کنیم
        if ($domain === 'fraud') {
            // 1. ثبت آنی در کش برای قابلیت اطمینان لحظه‌ای
            $cacheKey = "temp_fraud_score:{$userId}";
            $this->cache->incrementFloat($cacheKey, $delta);

            // 2. ارسال دستور نهایی نوشتن در دیتابیس به صف پردازش پس‌زمینه
            return $this->queue->push(UpdateFraudScoreJob::class, [
                'user_id' => $userId,
                'delta'   => $delta,
                'source'  => $source,
                'meta'    => $meta
            ]);
        }

        // برای سایر دامنه ها (فعلاً) نوشتن مستقیم در دیتابیس ادامه می‌یابد
        return $this->commitDeltaToDatabase($userId, $domain, $delta, $source, $meta);
    }

    /**
     * نوشتن نهایی و فیزیکی تغییرات در پایگاه داده
     */
    public function commitDeltaToDatabase(int $userId, string $domain, float $delta, string $source, array $meta = []): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_scores (user_id, domain, score, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE score = score + VALUES(score), updated_at = NOW()
            ");
            $ok = $stmt->execute([$userId, $domain, $delta]);
            
            // در صورت موفقیت‌آمیز بودن ثبت دامنه fraud، کش موقت همگام‌ساز را پاک یا کسر می‌کنیم
            if ($ok && $domain === 'fraud') {
                // کسر کردن از کلید کش (جلوگیری از دوباره شماری در خواندن ترکیبی)
                $this->cache->incrementFloat("temp_fraud_score:{$userId}", -$delta);
            }
            
            return $ok;
        } catch (\Throwable $e) {
            $this->logger->error('user_score.commit_db.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function getFraudScore(int $userId): float
    {
        try {
            // 1. خواندن مقدار نهایی ثبت شده در دیتابیس
            $stmt = $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = 'fraud' LIMIT 1");
            $stmt->execute([$userId]);
            $dbScore = (float)$stmt->fetchColumn();

            // 2. خواندن مقادیر تجمیع شده در صف (در صف انتظار برای درج)
            $cachedDelta = (float)$this->cache->get("temp_fraud_score:{$userId}", 0.0);

            // مجموع دو مقدار نمایانگر وضعیت ۱۰۰٪ ریل تایم است
            return $dbScore + $cachedDelta;
        } catch (\Throwable $ignore) {
            return (float)$this->cache->get("temp_fraud_score:{$userId}", 0.0);
        }
    }

    public function getTaskScore(int $userId): float
    {
        try {
            $stmt = $this->db->prepare("SELECT score FROM user_scores WHERE user_id = ? AND domain = 'task' LIMIT 1");
            $stmt->execute([$userId]);
            $score = $stmt->fetchColumn();
            return $score ? (float)$score : 0.0;
        } catch (\Throwable $ignore) {
            return 0.0;
        }
    }

    public function getEffectiveScore(int $userId, string $domain, float $rawScore): float
    {
        return $rawScore;
    }

    public function incrementFraudRawScore(int $userId, float $delta, string $source, array $meta = []): bool
    {
        return $this->applyEventDelta($userId, 'fraud', $delta, $source, $meta);
    }

    public function createAdjustment(int $userId, string $domain, float $adjustment, string $reason, ?string $expiry = null, ?int $createdBy = null): array
    {
        return ['success' => true];
    }
}
