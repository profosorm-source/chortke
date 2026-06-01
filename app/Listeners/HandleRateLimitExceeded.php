<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\RateLimitExceededEvent;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\LoggerInterface;
use Core\Cache;
use Core\Database;

/**
 * HandleRateLimitExceeded
 *
 * پس از تجاوز از محدودیت نرخ:
 * ۱. Alert فوری به ادمین‌ها
 * ۲. Flag کردن کاربر / IP برای بررسی بیشتر
 * ۳. ثبت در جدول suspect_flags برای داشبورد امنیت
 */
class HandleRateLimitExceeded
{
    // حداقل فاصله زمانی بین alert‌ها برای یک key (ثانیه)، برای جلوگیری از alert storm
    private const ALERT_COOLDOWN_SECONDS = 300;

    // تعداد دفعاتی که یک IP در ۱ ساعت نرخ را رد کند تا Flag شود
    private const FLAG_THRESHOLD = 5;
    private const FLAG_WINDOW_SECONDS = 3600;

    private NotificationServiceInterface $notificationService;
    private LoggerInterface $logger;
    private Cache $cache;
    private Database $db;
    public function __construct(
        NotificationServiceInterface $notificationService,
        LoggerInterface $logger,
        Cache $cache,
        Database $db
    ) {        $this->notificationService = $notificationService;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->db = $db;
}

    public function handle(RateLimitExceededEvent $event): void
    {
        $key       = $event->key;
        $strategy  = $event->strategy;
        $ip        = $event->ipAddress;

        // ۱. Alert به ادمین (با cooldown برای جلوگیری از spam)
        $this->alertAdminIfNotCoolingDown($key, $strategy, $ip);

        // ۲. افزایش شمارنده IP و Flag در صورت عبور از آستانه
        $this->incrementAndMaybeFlagIp($ip, $key);
    }

    private function alertAdminIfNotCoolingDown(string $key, string $strategy, string $ip): void
    {
        $cooldownKey = 'rate_limit_alert_cooldown:' . md5($key);

        try {
            // بررسی cooldown از کش
            $onCooldown = $this->cache->get($cooldownKey, false);
            if ($onCooldown) {
                return;
            }

            // ثبت cooldown در کش
            $this->cache->putSeconds($cooldownKey, true, self::ALERT_COOLDOWN_SECONDS);
        } catch (\Throwable) {
            // اگر کش در دسترس نبود، ادامه بده و alert بفرست
        }

        try {
            $this->notificationService->sendToAdmins(
                'rate_limit_exceeded',
                '⚠️ هشدار امنیتی: Rate Limit',
                "محدودیت نرخ برای کلید «{$key}» نقض شد.\nIP: {$ip}\nاستراتژی: {$strategy}",
                [
                    'key'      => $key,
                    'ip'       => $ip,
                    'strategy' => $strategy,
                ],
                'high'
            );
        } catch (\Throwable $e) {
            $this->logger->error('rate_limit.admin_alert_failed', [
                'key'   => $key,
                'ip'    => $ip,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function incrementAndMaybeFlagIp(string $ip, string $key): void
    {
        $counterKey = 'rate_exceeded_count:' . md5($ip);

        try {
            $count = (int) $this->cache->increment($counterKey, 1, self::FLAG_WINDOW_SECONDS);

            if ($count < self::FLAG_THRESHOLD) {
                return;
            }

            // Flag IP در دیتابیس برای بررسی دستی توسط ادمین
            $this->db->prepare(
                "INSERT INTO suspect_flags (type, identifier, reason, count, flagged_at)
                 VALUES ('ip', ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                     count = count + 1,
                     reason = VALUES(reason),
                     flagged_at = NOW()"
            )->execute([
                $ip,
                "Rate limit exceeded {$count}x on key: {$key}",
                $count,
            ]);

            $this->logger->warning('rate_limit.ip_flagged', [
                'ip'    => $ip,
                'key'   => $key,
                'count' => $count,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('rate_limit.flag_failed', [
                'ip'    => $ip,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
