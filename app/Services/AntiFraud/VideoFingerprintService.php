<?php
/**
 * Video Fingerprinting Service
 * 
 * تشخیص محتوای تکراری از طریق:
 * - Frame Hashing
 * - Audio Fingerprinting
 * - Metadata Analysis
 * 
 * این سرویس از API های خارجی استفاده نمی‌کند و فقط
 * آماده‌سازی و ساختار لازم را فراهم می‌کند
 * 
 * @package App\Services
 */

namespace App\Services\AntiFraud;

use Core\Database;
use Core\Cache;

use App\Contracts\LoggerInterface;
class VideoFingerprintService
{


    
    // Fingerprint Methods
    private const METHOD_URL_HASH = 'url_hash';
    private const METHOD_METADATA = 'metadata';
    private const METHOD_MANUAL = 'manual';
    
    private \Core\Cache $cache;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    )
    {        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;

        
        }

    /**
     * ایجاد fingerprint از URL ویدیو
     * 
     * @param string $videoUrl
     * @param string $platform
     * @param array $metadata
     * @return array
     */
    public function generateFingerprint(
        string $videoUrl, 
        string $platform, 
        array $metadata = []
    ): array {
        // استخراج شناسه ویدیو از URL
        $videoId = $this->extractVideoId($videoUrl, $platform);
        
        // ایجاد hash منحصر به فرد
        $urlHash = $this->createUrlHash($videoUrl);
        $metadataHash = $this->createMetadataHash($metadata);
        
        // ترکیب fingerprint ها
        $combinedHash = hash('sha256', $urlHash . $metadataHash . $videoId);
        
        return [
            'video_id' => $videoId,
            'platform' => $platform,
            'url_hash' => $urlHash,
            'metadata_hash' => $metadataHash,
            'combined_hash' => $combinedHash,
            'method' => self::METHOD_URL_HASH,
            'confidence' => $this->calculateConfidence($videoId, $metadata),
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * بررسی تکراری بودن
     * 
     * @param array $fingerprint
     * @return array ['is_duplicate' => bool, 'matches' => array, 'confidence' => float]
     */
    public function checkDuplicate(array $fingerprint): array
    {
        // جستجو در cache
        $cacheKey = "video:fingerprint:{$fingerprint['combined_hash']}";
        $cached = $this->cache->get($cacheKey);
        
        if ($cached) {
            return [
                'is_duplicate' => true,
                'matches' => [$cached],
                'confidence' => 1.0,
                'source' => 'cache',
            ];
        }
        
        // جستجو در دیتابیس
        $matches = $this->findSimilarFingerprints($fingerprint);
        
        if (!empty($matches)) {
            // Cache کردن نتیجه
            $this->cache->put($cacheKey, $fingerprint, 1440); // 24h
            
            return [
                'is_duplicate' => true,
                'matches' => $matches,
                'confidence' => $this->calculateMatchConfidence($fingerprint, $matches),
                'source' => 'database',
            ];
        }
        
        // ذخیره fingerprint جدید
        $this->saveFingerprint($fingerprint);
        
        return [
            'is_duplicate' => false,
            'matches' => [],
            'confidence' => 0,
        ];
    }

    /**
     * ذخیره fingerprint
     * 
     * @param array $fingerprint
     * @return int|null
     */
    public function saveFingerprint(array $fingerprint): ?int
    {
        $sql = "INSERT INTO video_fingerprints 
                (platform, video_id, url_hash, metadata_hash, combined_hash, 
                 method, confidence, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    updated_at = NOW()";
        
        $params = [
            $fingerprint['platform'],
            $fingerprint['video_id'],
            $fingerprint['url_hash'],
            $fingerprint['metadata_hash'] ?? null,
            $fingerprint['combined_hash'],
            $fingerprint['method'],
            $fingerprint['confidence'],
            $fingerprint['created_at'] ?? date('Y-m-d H:i:s'),
        ];
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return (int)$this->db->lastInsertId() ?: null;
        } catch (\Exception $e) {
            $this->logger->error('video_fingerprint', ['message' => 'Failed to save fingerprint: ' . $e->getMessage()]);
            return null;
        }
    }

    /**
     * جستجوی fingerprint های مشابه
     * 
     * @param array $fingerprint
     * @return array
     */
    private function findSimilarFingerprints(array $fingerprint): array
    {
        // جستجوی دقیق
        $exactMatch = $this->findExactMatch($fingerprint);
        if ($exactMatch) {
            return [$exactMatch];
        }
        
        // جستجوی fuzzy (مشابه)
        return $this->findFuzzyMatches($fingerprint);
    }

    /**
     * جستجوی دقیق
     * 
     * @param array $fingerprint
     * @return array|null
     */
    private function findExactMatch(array $fingerprint): ?array
    {
        $sql = "SELECT * FROM video_fingerprints 
                WHERE combined_hash = ? 
                LIMIT 1";
        
        try {
            $stmt = $this->db->query($sql, [$fingerprint['combined_hash']]);
            $result = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
            
            return $result ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * جستجوی fuzzy
     * 
     * @param array $fingerprint
     * @return array
     */
    private function findFuzzyMatches(array $fingerprint): array
    {
        // جستجو بر اساس video_id و platform
        $sql = "SELECT * FROM video_fingerprints 
                WHERE platform = ? 
                AND video_id = ? 
                LIMIT 10";
        
        try {
            $stmt = $this->db->query($sql, [
                $fingerprint['platform'],
                $fingerprint['video_id'],
            ]);
            
            return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * استخراج شناسه ویدیو از URL
     * 
     * @param string $url
     * @param string $platform
     * @return string
     */
    private function extractVideoId(string $url, string $platform): string
    {
        if ($platform === 'aparat') {
            // https://www.aparat.com/v/abc123
            if (preg_match('/\/v\/([^\/\?]+)/', $url, $matches)) {
                return $matches[1];
            }
        }
        
        if ($platform === 'youtube') {
            // https://www.youtube.com/watch?v=abc123
            // https://youtu.be/abc123
            if (preg_match('/[?&]v=([^&]+)/', $url, $matches)) {
                return $matches[1];
            }
            if (preg_match('/youtu\.be\/([^?]+)/', $url, $matches)) {
                return $matches[1];
            }
        }
        
        // Fallback: hash کل URL
        return hash('md5', $url);
    }

    /**
     * ایجاد hash از URL
     * 
     * @param string $url
     * @return string
     */
    private function createUrlHash(string $url): string
    {
        // نرمال‌سازی URL
        $normalized = $this->normalizeUrl($url);
        
        return hash('sha256', $normalized);
    }

    /**
     * ایجاد hash از metadata
     * 
     * @param array $metadata
     * @return string
     */
    private function createMetadataHash(array $metadata): string
    {
        if (empty($metadata)) {
            return '';
        }
        
        // فیلدهای مهم برای fingerprinting
        $important = [
            'title' => $metadata['title'] ?? '',
            'description' => $metadata['description'] ?? '',
            'duration' => $metadata['duration'] ?? 0,
            'resolution' => $metadata['resolution'] ?? '',
        ];
        
        // نرمال‌سازی
        $normalized = $this->normalizeMetadata($important);
        
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * نرمال‌سازی URL
     * 
     * @param string $url
     * @return string
     */
    private function normalizeUrl(string $url): string
    {
        // حذف پروتکل
        $url = preg_replace('/^https?:\/\//', '', $url);
        
        // حذف www
        $url = preg_replace('/^www\./', '', $url);
        
        // حذف trailing slash
        $url = rtrim($url, '/');
        
        // تبدیل به lowercase
        $url = strtolower($url);
        
        return $url;
    }

    /**
     * نرمال‌سازی metadata
     * 
     * @param array $metadata
     * @return array
     */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];
        
        foreach ($metadata as $key => $value) {
            if (is_string($value)) {
                // حذف فضاهای اضافی
                $value = trim(preg_replace('/\s+/', ' ', $value));
                
                // تبدیل به lowercase
                $value = mb_strtolower($value, 'UTF-8');
            }
            
            $normalized[$key] = $value;
        }
        
        return $normalized;
    }

    /**
     * محاسبه confidence
     * 
     * @param string $videoId
     * @param array $metadata
     * @return float
     */
    private function calculateConfidence(string $videoId, array $metadata): float
    {
        $confidence = 0.5; // Base confidence
        
        // اگر video ID معتبر باشد
        if (strlen($videoId) > 5 && !str_contains($videoId, 'md5')) {
            $confidence += 0.3;
        }
        
        // اگر metadata کامل باشد
        if (!empty($metadata['title']) && !empty($metadata['duration'])) {
            $confidence += 0.2;
        }
        
        return min(1.0, $confidence);
    }

    /**
     * محاسبه confidence برای match
     * 
     * @param array $fingerprint
     * @param array $matches
     * @return float
     */
    private function calculateMatchConfidence(array $fingerprint, array $matches): float
    {
        if (empty($matches)) {
            return 0;
        }
        
        $maxConfidence = 0;
        
        foreach ($matches as $match) {
            $similarity = 0;
            
            // URL hash match
            if ($match['url_hash'] === $fingerprint['url_hash']) {
                $similarity += 0.6;
            }
            
            // Metadata hash match
            if (isset($match['metadata_hash']) && 
                $match['metadata_hash'] === $fingerprint['metadata_hash']) {
                $similarity += 0.4;
            }
            
            $maxConfidence = max($maxConfidence, $similarity);
        }
        
        return $maxConfidence;
    }

    /**
     * آمار fingerprints
     * 
     * @return array
     */
    public function getStats(): array
    {
        $cacheKey = 'video:fingerprint:stats';
        
        return $this->cache->remember($cacheKey, 60, function() {
            try {
                $stmt = $this->db->query("
                    SELECT 
                        COUNT(*) as total,
                        COUNT(DISTINCT platform) as platforms,
                        AVG(confidence) as avg_confidence,
                        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as today
                    FROM video_fingerprints
                ");
                
                return $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : [];
            } catch (\Exception $e) {
                return [
                    'total' => 0,
                    'platforms' => 0,
                    'avg_confidence' => 0,
                    'today' => 0,
                ];
            }
        });
    }

    /**
     * پاک‌سازی fingerprints قدیمی
     * 
     * @param int $olderThanDays
     * @return int تعداد حذف شده
     */
    public function cleanOldFingerprints(int $olderThanDays = 90): int
    {
        try {
            $sql = "DELETE FROM video_fingerprints 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$olderThanDays]);
            
            $deleted = $stmt->rowCount();
            
            $this->logger->info('video_fingerprint', ['message' => "Cleaned {$deleted} old fingerprints"]);
            
            return $deleted;
        } catch (\Exception $e) {
            $this->logger->error('video_fingerprint', ['message' => 'Failed to clean fingerprints: ' . $e->getMessage()]);
            return 0;
        }
    }
}

/**
 * Migration برای جدول video_fingerprints:
 * 
 * CREATE TABLE IF NOT EXISTS `video_fingerprints` (
 *   `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   `platform` VARCHAR(50) NOT NULL,
 *   `video_id` VARCHAR(255) NOT NULL,
 *   `url_hash` CHAR(64) NOT NULL,
 *   `metadata_hash` CHAR(64) NULL,
 *   `combined_hash` CHAR(64) NOT NULL,
 *   `method` VARCHAR(50) NOT NULL DEFAULT 'url_hash',
 *   `confidence` DECIMAL(3,2) NOT NULL DEFAULT 0.5,
 *   `created_at` DATETIME NOT NULL,
 *   `updated_at` DATETIME NULL,
 *   UNIQUE KEY `unique_combined` (`combined_hash`),
 *   KEY `idx_platform_video` (`platform`, `video_id`),
 *   KEY `idx_url_hash` (`url_hash`),
 *   KEY `idx_created` (`created_at`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 */

