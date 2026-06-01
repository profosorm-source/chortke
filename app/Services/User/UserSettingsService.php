<?php
declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Models\UserSetting;
use Core\Database;
use Core\Cache;

use App\Contracts\LoggerInterface;

/**
 * UserSettingsService — مدیریت تنظیمات پیشرفته کاربر
 */
class UserSettingsService
{


    private User $userModel;

    // ─── Cache ───────────────────────────────────────────────────────────────
    private const CACHE_PREFIX = 'user_settings:';
    private const CACHE_TTL = 3600; // 1 ساعت

    // لیست کلیدهای حساس که نباید در لاگ‌ها دیده شوند
    private const SENSITIVE_KEYS = [
        'phone', 'mobile', 'address', 'national_id', 'card_number', 
        'password', 'secret', '2fa_secret', 'backup_codes',
        'email', 'full_name', 'birth_date', 'sheba', 'iban',
        'passport_number', 'ssn', 'tax_id'
    ];

    // ─── تنظیمات پیش‌فرض ─────────────────────────────────────────────────────
    private const DEFAULT_SETTINGS = [
        // عمومی
        'language' => 'fa',
        'timezone' => 'Asia/Tehran',
        'theme' => 'light',
        'date_format' => 'jalali',
        'currency' => 'IRT',

        // حریم خصوصی
        'profile_visibility' => 'public', // public, friends, private
        'show_online_status' => true,
        'show_activity' => true,
        'allow_messages' => true,
        'allow_friend_requests' => true,

        // اعلان‌ها
        'email_notifications' => true,
        'push_notifications' => true,
        'sms_notifications' => false,
        'marketing_emails' => false,

        // امنیتی
        'session_timeout' => 30, // دقیقه
        'login_alerts' => true,
        'suspicious_activity_alerts' => true,

        // عملکرد
        'items_per_page' => 20,
        'auto_refresh' => true,
        'compact_view' => false,
    ];

    private \Core\RateLimiter $rateLimiter;
    private ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        User $userModel,
        \Core\RateLimiter $rateLimiter,
        ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation = null
    ) {        $this->db = $db;
        $this->logger = $logger;

        
        $this->userModel = $userModel;
        $this->rateLimiter = $rateLimiter;
        $this->cacheInvalidation = $cacheInvalidation;
    }

    /**
     * دریافت تمام تنظیمات کاربر
     */
    public function getAll(int $userId): array
    {
        $cacheKey = self::CACHE_PREFIX . $userId;

        $settings = $this->cache->get($cacheKey);
        if ($settings !== null) {
            return $settings;
        }

        $settings = self::DEFAULT_SETTINGS;

        try {
            $userSettings = $this->db->query(
                "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?",
                [$userId]
            );

            foreach ($userSettings as $setting) {
                $settings[$setting['setting_key']] = $this->deserializeValue($setting['setting_value']);
            }
        } catch (\Exception $e) {
            $this->logger->error('settings.get_all.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }

        $this->cache->set($cacheKey, $settings, self::CACHE_TTL);
        return $settings;
    }

    /**
     * دریافت یک تنظیم خاص
     */
    public function get(int $userId, string $key, $default = null)
    {
        $settings = $this->getAll($userId);
        return $settings[$key] ?? $default ?? (self::DEFAULT_SETTINGS[$key] ?? null);
    }

    /**
     * تنظیم مقدار یک تنظیم
     */
    public function set(int $userId, string $key, $value): bool
    {
        // ✅ Multi-layer rate limiting
        $ip = \function_exists('get_client_ip') ? \get_client_ip() : '127.0.0.1';
        $limits = [
            "settings:user:{$userId}" => [20, 60],
            "settings:ip:{$ip}" => [50, 60],
            "settings:global" => [500, 60],
        ];

        foreach ($limits as $limKey => [$max, $window]) {
            if (!$this->rateLimiter->attempt($limKey, $max, $window)) {
                $this->logger->warning('settings.rate_limit_exceeded', ['key' => $limKey, 'user_id' => $userId]);
                return false;
            }
        }

        // اعتبارسنجی مقدار
        if (!$this->validateSetting($key, $value)) {
            return false;
        }

        try {
            $serializedValue = $this->serializeValue($value);

            $this->db->query(
                "INSERT INTO user_settings (user_id, setting_key, setting_value, updated_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
                [$userId, $key, $serializedValue]
            );

            // پاک کردن cache
            $this->invalidateCache($userId);

            // رداکت کردن مقادیر حساس در لاگ (Issue #25)
            $logValue = in_array($key, self::SENSITIVE_KEYS) ? '***REDACTED***' : $value;

            $this->logger->info('settings.updated', [
                'user_id' => $userId,
                'key' => $key,
                'value' => $logValue
            ]);

            return true;
        } catch (\Exception $e) {
            $logValue = in_array($key, self::SENSITIVE_KEYS) ? '***REDACTED***' : $value;
            $this->logger->error('settings.set.failed', [
                'user_id' => $userId,
                'key' => $key,
                'value' => $logValue,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * تنظیم چندین تنظیم به صورت دسته‌ای
     */
    public function setMultiple(int $userId, array $settings): bool
    {
        $this->db->beginTransaction();

        try {
            foreach ($settings as $key => $value) {
                if (!$this->validateSetting($key, $value)) {
                    $this->db->rollback();
                    return false;
                }

                $serializedValue = $this->serializeValue($value);

                $this->db->query(
                    "INSERT INTO user_settings (user_id, setting_key, setting_value, updated_at)
                     VALUES (?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
                    [$userId, $key, $serializedValue]
                );
            }

            $this->db->commit();
            $this->invalidateCache($userId);

            $this->logger->info('settings.batch_updated', [
                'user_id' => $userId,
                'count' => count($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->error('settings.batch_update.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * بازنشانی تنظیم به مقدار پیش‌فرض
     */
    public function reset(int $userId, string $key): bool
    {
        if (!isset(self::DEFAULT_SETTINGS[$key])) {
            return false;
        }

        try {
            $this->db->query(
                "DELETE FROM user_settings WHERE user_id = ? AND setting_key = ?",
                [$userId, $key]
            );

            $this->invalidateCache($userId);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('settings.reset.failed', [
                'user_id' => $userId,
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * بازنشانی تمام تنظیمات به پیش‌فرض
     */
    public function resetAll(int $userId): bool
    {
        try {
            $this->db->query(
                "DELETE FROM user_settings WHERE user_id = ?",
                [$userId]
            );

            $this->invalidateCache($userId);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('settings.reset_all.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * دریافت تنظیمات عمومی (برای نمایش در پروفایل)
     */
    public function getPublicSettings(int $userId): array
    {
        $settings = $this->getAll($userId);

        return [
            'language' => $settings['language'],
            'timezone' => $settings['timezone'],
            'theme' => $settings['theme'],
            'profile_visibility' => $settings['profile_visibility'],
            'show_online_status' => $settings['show_online_status'],
            'show_activity' => $settings['show_activity'],
        ];
    }

    /**
     * پاک کردن cache
     */
    private function invalidateCache(int $userId): void
    {
        if ($this->cacheInvalidation) {
            $this->cacheInvalidation->invalidateUser($userId);
        } else {
            $this->cache->forget(self::CACHE_PREFIX . $userId);
        }
    }

    /**
     * درخواست حذف حساب کاربری
     */
    public function requestAccountDeletion(int $userId, string $password): array
    {
        try {
            $user = $this->userModel->find($userId);
            if (!$user || !verify_user_password($password, $user['password'] ?? '', (int)($user['id'] ?? 0))) {
                return ['ok' => false, 'message' => 'رمزعبور نادرست'];
            }

            $this->db->query(
                "UPDATE users SET account_deletion_requested_at = NOW(), account_deletion_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?",
                [$userId]
            );

            $this->logger->warning('settings.account_deletion_requested', ['user_id' => $userId]);

            return ['ok' => true, 'message' => 'درخواست شما ثبت شد. حساب شما در 7 روز حذف خواهد شد'];
        } catch (\Exception $e) {
            $this->logger->error('settings.account_deletion_request.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return ['ok' => false, 'message' => 'خطا در درخواست حذف حساب'];
        }
    }

    /**
     * لغو درخواست حذف حساب کاربری
     */
    public function cancelAccountDeletion(int $userId): bool
    {
        try {
            $this->db->query(
                "UPDATE users SET account_deletion_requested_at = NULL, account_deletion_expires_at = NULL WHERE id = ?",
                [$userId]
            );

            $this->logger->info('settings.account_deletion_cancelled', ['user_id' => $userId]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('settings.account_deletion_cancel.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * سریال‌سازی مقدار برای ذخیره در DB
     */
    private function serializeValue($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * دی‌سریال‌سازی مقدار از DB
     */
    private function deserializeValue(string $value)
    {
        // Boolean
        if ($value === '1' || $value === '0') {
            return $value === '1';
        }

        // Integer
        if (is_numeric($value) && strpos($value, '.') === false) {
            return (int) $value;
        }

        // Float
        if (is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * اعتبارسنجی مقدار تنظیم
     */
    private function validateSetting(string $key, $value): bool
    {
        // Whitelist allowed keys
        if (!array_key_exists($key, self::DEFAULT_SETTINGS)) {
            $this->logger->warning('settings.invalid_key', ['key' => $key]);
            return false;
        }

        // Type validation
        $expected = self::DEFAULT_SETTINGS[$key];
        if (is_bool($expected) && !is_bool($value)) {
            return false;
        }
        if (is_int($expected) && !is_int($value)) {
            return false;
        }
        if (is_string($expected) && !is_string($value)) {
            return false;
        }

        $validations = [
            'language' => fn($v) => in_array($v, ['fa', 'en']),
            'timezone' => fn($v) => in_array($v, timezone_identifiers_list()),
            'theme' => fn($v) => in_array($v, ['light', 'dark', 'auto']),
            'date_format' => fn($v) => in_array($v, ['jalali', 'gregorian']),
            'currency' => fn($v) => in_array($v, ['IRT', 'IRR', 'USD']),
            'profile_visibility' => fn($v) => in_array($v, ['public', 'friends', 'private']),
            'session_timeout' => fn($v) => is_int($v) && $v >= 5 && $v <= 1440,
            'items_per_page' => fn($v) => is_int($v) && $v >= 10 && $v <= 100,
        ];

        if (isset($validations[$key])) {
            return $validations[$key]($value);
        }

        return true;
    }
}
