<?php

/**
 * cron.php - نقطه ورود زمانبندی وظایف
 *
 * ثبت در crontab سرور (هر دقیقه یکبار اجرا می‌شود):
 *   * * * * * /usr/bin/php /var/www/html/cron.php >> /var/log/chortke-cron.log 2>&1
 *
 * اجرای دستی برای تست:
 *   php cron.php
 *   php cron.php --job=email_queue   (فقط یک job خاص)
 *   php cron.php --dry-run            (فقط نمایش بدون اجرا)
 */
if (php_sapi_name() !== 'cli') {
    die("Access Denied: Cron jobs can only be run via CLI.\n");
}

if (!defined('CRON_MODE')) {
    define('CRON_MODE', true);
}
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

use Core\Scheduler;
use Core\Container;
use App\Services\EmailService;
use App\Services\CryptoDeposit\CryptoDepositService;
use App\Services\UserLevelService;
use App\Services\LotteryService;
use App\Services\BannerService;
use App\Services\WithdrawalService;
use App\Services\InfluencerService;
use App\Services\Shared\DisputeService;
use App\Services\Notification\NotificationService;
use App\Services\AdNotificationDispatcher;
use App\Models\Notification as NotificationModel;
use App\Models\Advertisement;
use Core\Cache;
use Core\Database;
use App\Services\SocialTask\TrustScoreService as SocialTrustService;
use App\Services\SocialTask\SocialTaskService as SocialTaskSvc;

try {
// بارگذاری bootstrap
require_once __DIR__ . '/bootstrap/app.php';

$container = Container::getInstance();

// ==========================================
//  File Lock — جلوگیری از اجرای همزمان cron (مشکل #9)
// ==========================================
$lockFile = BASE_PATH . '/storage/logs/cron.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // نمونه دیگری در حال اجراست
    if (is_resource($lockHandle)) {
        fclose($lockHandle);
    }
    echo '[' . date('Y-m-d H:i:s') . "] [SKIP] cron.php already running — exiting.\n";
    exit(0);
}

// ==========================================
//  پارامترهای CLI
// ==========================================
$onlyJob = null;
$dryRun  = false;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $onlyJob = substr($arg, 6);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

if ($dryRun) {
    echo "[DRY-RUN] فقط نمایش وظایف - اجرا نمی‌شوند\n";
}

// آزادسازی قفل پس از پایان اسکریپت
register_shutdown_function(function () use ($lockHandle, $lockFile) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
});

// ==========================================
//  تعریف وظایف
// ==========================================
$scheduler = new Scheduler();

/**
 * ─────────────────────────────────────────
 * هر دقیقه
 * ─────────────────────────────────────────
 */

// پردازش صف ایمیل‌ها
$scheduler->everyMinute(function () {
    $service = Container::getInstance()->make(EmailService::class);
    $batchSize = feature_config('cron_email_batch_size', 'rollout_percentage', 20);
    $result  = $service->processQueue($batchSize);
    return [
        'sent'   => $result['sent']   ?? 0,
        'failed' => $result['failed'] ?? 0,
    ];
}, 'email_queue');

// پردازش صف عمومی سیستم
$scheduler->everyMinute(function () {
    $db = Database::getInstance();
    $queue = new \Core\Queue($db);
    $processed = 0;
    $maxJobsToProcess = max(1, min(100, (int) feature_config('cron_queue_jobs_limit', 'rollout_percentage', 10)));
    
    for ($i = 0; $i < $maxJobsToProcess; $i++) {
        $job = $queue->pop();
        if (!$job) {
            break;
        }
        
        $jobClass = $job['job'];
        $data = $job['data'];
        
        try {
            if (class_exists($jobClass)) {
                $handler = Container::getInstance()->make($jobClass);
                if (method_exists($handler, 'handle')) {
                    $handler->handle($data);
                    $queue->delete($job['id']);
                    $processed++;
                } else {
                    logger()->error('queue_job_invalid_method', ['job' => $jobClass]);
                }
            } else {
                logger()->error('queue_job_not_found', ['job' => $jobClass]);
            }
        } catch (\Throwable $e) {
            $logData = [
                'job'   => $jobClass,
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ];
            if (config('app.debug')) {
                $logData['trace'] = substr($e->getTraceAsString(), 0, 2048);
            }
            logger()->error('queue_job_failed', $logData);
        }
    }
    
    return ['processed_jobs' => $processed];
}, 'system_queue_processor');

// تأیید خودکار واریزهای کریپتو در انتظار
$scheduler->everyMinute(function () {
    $db      = Database::getInstance();
    $service = Container::getInstance()->make(\App\Services\CryptoDeposit\CryptoDepositService::class);

    // واریزهای pending که هنوز تأیید نشده‌اند
    // مشکل #10: cast + validate — هرگز مستقیم در SQL interpolate نمی‌شوند
    $hours = max(1, min(720, (int) feature_config('cron_verification_hours', 'rollout_percentage', 12)));
    $limit = max(1, min(500, (int) feature_config('cron_verification_limit', 'rollout_percentage', 10)));

    $pending = $db->fetchAll(
        "SELECT id FROM crypto_deposits
         WHERE verification_status = 'pending'
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
         ORDER BY created_at ASC
         LIMIT ?",
        [$hours, $limit]
    );

    $verified = 0;
    foreach ($pending as $row) {
        $id     = is_array($row) ? (int)$row['id'] : (int)$row->id;
        $result = $service->tryAutoVerify($id);
        if (($result['auto'] ?? false) === true) {
            $verified++;
        }
    }

    return ['pending_checked' => count($pending), 'verified' => $verified];
}, 'crypto_verify');


// پاک‌سازی کش منقضی‌شده
$scheduler->everyMinutes(feature_config('cron_scheduler_interval', 'rollout_percentage', 5), function () {
    $cleaned = Cache::getInstance()->cleanup();
    return ['cleaned_files' => $cleaned];
}, 'cache_cleanup');

// 📢 ارسال اعلان‌های پس‌زمینه کمپین‌های فعال (Ad Notifications)
// اجرا در فواصل کوتاه برای ارسال موازی و کم‌بار بدون سنگین کردن سرور
$scheduler->everyMinutes(feature_config('cron_ad_push_interval', 'rollout_percentage', 3), function () {
    $dispatcher = Container::getInstance()->make(AdNotificationDispatcher::class);
    $stats = $dispatcher->processAdNotifications();
    return $stats;
}, 'ad_notification_push');

/**
 * ─────────────────────────────────────────
 * هر ساعت (دقیقه ۰)
 * ─────────────────────────────────────────
 */

// غیرفعال کردن آگهی‌های منقضی‌شده
$scheduler->hourly(function () {
    $db = Database::getInstance();

    $affected = $db->execute(
        "UPDATE advertisements
         SET status = 'completed', updated_at = NOW()
         WHERE status = 'active'
           AND (
             (end_date IS NOT NULL AND end_date < NOW())
             OR remaining_count <= 0
             OR remaining_budget <= 0
           )"
    );

    return ['expired_ads' => $affected];
}, 'expire_ads');

// غیرفعال کردن بنرهای منقضی‌شده
$scheduler->hourly(function () {
    $service = Container::getInstance()->make(BannerService::class);
    $count   = $service->deactivateExpiredBanners();
    return ['deactivated_banners' => $count];
}, 'expire_banners');

// ✅ **ممیزی ساعتی تراکنش‌ها و دفاتر کل** 🔄
// بررسی و مانیتورینگ سلامت سیستم مالی (فقط گزارش مغایرت)
$scheduler->hourly(function () {
    try {
        $db = Database::getInstance();
        
        // ⚠️ حذف منطق ناامن تایید خودکار تراکنش‌های یتیم که پیش‌تر اینجا بود و یک حفره مالی محسوب می‌شد.
        // ممیزی از این پس فقط به صورت پسیو (تحلیلی) ناسازگاری‌ها را به لاگر اطلاع می‌دهد.
        
        // بررسی ناسازگاری‌های ledger (debit vs credit)
        $mismatches = $db->fetchAll(
            "SELECT user_id, SUM(amount) as balance
             FROM ledger_entries
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
             GROUP BY user_id
             HAVING balance IS NOT NULL"
        );
        
        $warnings = 0;
        foreach ($mismatches as $mismatch) {
            // اگر balance صفر نیست مشکل است
            if ((float)$mismatch->balance !== 0.0) {
                $warnings++;
                logger()->warning('reconciliation.ledger_mismatch', [
                    'user_id' => $mismatch->user_id,
                    'balance_mismatch' => $mismatch->balance,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
        }
        
        return [
            'ledger_mismatches_detected' => $warnings,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    } catch (\Throwable $e) {
        logger()->error('reconciliation.hourly_audit.failed', [
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return ['error' => $e->getMessage()];
    }
}, 'hourly_reconciliation_audit');

// انقضای نشست‌های قدیمی کاربران (بیش از ۳۰ روز)
$scheduler->hourly(function () {
    $db      = Database::getInstance();
    $affected = $db->execute(
        "DELETE FROM user_sessions
         WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    return ['deleted_sessions' => $affected];
}, 'cleanup_sessions');

// پاک‌سازی پیام‌های realtime منقضی‌شده
$scheduler->hourly(function () {
    $service = Container::getInstance()->make(\App\Services\WebSocketService::class);
    $deleted = $service->cleanupExpiredMessages();
    $processed = $service->processAllDelayedMessages();
    return ['deleted_messages' => $deleted, 'processed_delayed' => $processed];
}, 'websocket_cleanup');

/**
 * ─────────────────────────────────────────
 * روزانه ساعت ۰۲:۰۰
 * ─────────────────────────────────────────
 */

// بررسی سطح کاربران (downgrade/upgrade/expire)
$scheduler->daily('02:00', function () {
    $service = Container::getInstance()->make(UserLevelService::class);

    $downgrades = $service->checkDowngrades();
    $expired    = $service->checkExpiredPurchases();

    return [
        'downgraded' => count($downgrades),
        'expired'    => $expired,
    ];
}, 'user_levels');

// ==============================
// Retention: Activity/System/Security Logs (Weekly - Sunday)
// ==============================
$scheduler->daily('02:30', function () {
    if ((int) date('w') !== 0) {
        return ['skipped' => 'cleanup_logs weekly (sunday only)'];
    }

    $logService = \Core\Container::getInstance()->make(\App\Services\LogService::class);
    $days = feature_config('cron_cleanup_days', 'rollout_percentage', 30);

    return [
        'log_cleanup' => $logService->cleanup($days),
    ];
}, 'cleanup_logs');


// ==============================
// Retention: Audit Trail Archive (Check daily, run every 30 days)
// ==============================
$scheduler->daily('02:40', function () {
    $archiveDir = __DIR__ . '/storage/audit-archives';
    if (!is_dir($archiveDir)) {
        @mkdir($archiveDir, 0755, true);
    }

    $stateFile = $archiveDir . '/.last_archive_at';
    $now = time();

    if (file_exists($stateFile)) {
        $last = (int) trim((string) file_get_contents($stateFile));
        if ($last > 0 && ($now - $last) < (30 * 86400)) {
            return ['skipped' => 'archive_audit_trail every 30 days'];
        }
    }

    $audit = \Core\Container::getInstance()->make(\App\Services\AuditTrail::class);
    $result = $audit->archiveOlderThan(30, 2000);

    if (!empty($result['file'])) {
        file_put_contents($stateFile, (string) $now);
    }

    return $result;
}, 'archive_audit_trail');


// ==============================
// Retention: Sentry-like Tables (Weekly - Sunday, chunked)
// ==============================
$scheduler->daily('02:50', function () {
    if ((int) date('w') !== 0) {
        return ['skipped' => 'cleanup_sentry weekly (sunday only)'];
    }

    $db = \Core\Database::getInstance();
    $result = [
        'deleted_sentry_issues' => 0,
        'deleted_system_alerts' => 0,
    ];

    // sentry_issues
    $stmt = $db->query("SHOW TABLES LIKE ?", ['sentry_issues']);
    if ($stmt instanceof \PDOStatement && $stmt->fetchColumn()) {
        do {
            $deleted = (int) $db->execute(
                "DELETE FROM sentry_issues
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
                 LIMIT 5000"
            );
            $result['deleted_sentry_issues'] += $deleted;
        } while ($deleted === 5000);
    }

    // system_alerts
    $stmt = $db->query("SHOW TABLES LIKE ?", ['system_alerts']);
    if ($stmt instanceof \PDOStatement && $stmt->fetchColumn()) {
        do {
            $deleted = (int) $db->execute(
                "DELETE FROM system_alerts
                 WHERE is_active = 0
                   AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
                 LIMIT 5000"
            );
            $result['deleted_system_alerts'] += $deleted;
        } while ($deleted === 5000);
    }

    return $result;
}, 'cleanup_sentry');

// ==========================================
// Log Growth Guard (Daily 03:10)
// اگر حجم لاگ در یک ساعت اخیر غیرعادی شد، هشدار ثبت می‌کند
// ==========================================
$scheduler->daily('03:10', function () {
    $db = \Core\Database::getInstance();

    $threshold = 2000; // می‌تونی بعدا از env بخونی
    $result = [
        'activity_logs_last_hour' => 0,
        'system_logs_last_hour' => 0,
        'security_logs_last_hour' => 0,
        'performance_logs_last_hour' => 0,
        'alerts' => [],
    ];

    $queries = [
        'activity_logs_last_hour' => "SELECT COUNT(*) FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        'system_logs_last_hour' => "SELECT COUNT(*) FROM system_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        'security_logs_last_hour' => "SELECT COUNT(*) FROM security_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        'performance_logs_last_hour' => "SELECT COUNT(*) FROM performance_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
    ];

    foreach ($queries as $key => $sql) {
        try {
            $stmt = $db->query($sql);
            $count = ($stmt instanceof \PDOStatement) ? (int)$stmt->fetchColumn() : 0;
            $result[$key] = $count;

            if ($count >= $threshold) {
                logger()->warning('logs.growth.spike.detected', [
                    'channel' => 'monitoring',
                    'metric' => $key,
                    'count' => $count,
                    'threshold' => $threshold,
                ]);
                $result['alerts'][] = ['metric' => $key, 'count' => $count];
            }
        } catch (\Throwable $e) {
            logger()->error('logs.growth.guard.failed', [
                'channel' => 'monitoring',
                'metric' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    return $result;
}, 'log_growth_guard');

// پاک‌سازی ایمیل‌های ارسال‌شده قدیمی (بیش از ۳۰ روز)
$scheduler->daily('03:00', function () {
    $db      = Database::getInstance();
    $affected = $db->execute(
        "DELETE FROM email_queue
         WHERE status = 'sent'
           AND sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    return ['deleted_emails' => $affected];
}, 'cleanup_email_queue');

// پردازش پرداخت‌های زمانبندی‌شده
$scheduler->daily('03:15', function () {
    $service = Container::getInstance()->make(\App\Services\ScheduledPaymentService::class);
    $result = $service->processDuePayments(feature_config('cron_scheduled_payment_batch_size', 'rollout_percentage', 50));
    return $result;
}, 'scheduled_payments');

// پاک‌سازی تصاویر KYC رد شده قدیمی (۶۰ روز)
$scheduler->daily('03:30', function () {
    $db   = Database::getInstance();
    $rows = $db->fetchAll(
        "SELECT id, document_front, document_back, selfie
         FROM kyc_verifications
         WHERE status = 'rejected'
           AND updated_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
           AND documents_deleted = 0"
    );

    $cleaned = 0;
    foreach ($rows as $row) {
        $row = (array)$row;
        foreach (['document_front', 'document_back', 'selfie'] as $field) {
            if (!empty($row[$field])) {
                $path = BASE_PATH . '/storage/uploads/kyc/' . $row[$field];
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
        $db->execute(
            "UPDATE kyc_verifications SET documents_deleted = 1 WHERE id = ?",
            [$row['id']]
        );
        $cleaned++;
    }

    return ['cleaned_kyc_files' => $cleaned];
}, 'cleanup_kyc_files');

/**
 * ─────────────────────────────────────────
 * روزانه ساعت ۰۴:۰۰ - ریست ماهانه
 * ─────────────────────────────────────────
 */

// ریست آمار ماهانه سطح کاربران (اول هر ماه)
$scheduler->daily('04:00', function () {
    if ((int)date('j') !== 1) {
        return ['skipped' => 'not first day of month'];
    }
    $service = Container::getInstance()->make(UserLevelService::class);
    $reset   = $service->monthlyReset();
    return ['reset_users' => $reset];
}, 'monthly_level_reset');

/**
 * ─────────────────────────────────────────
 * هفتگی - یکشنبه ساعت ۰۵:۰۰
 * ─────────────────────────────────────────
 */

// گزارش هفتگی KPI به ادمین
$scheduler->weekly('Sunday', '05:00', function () {
    $db = Database::getInstance();

    // تعداد ثبت‌نام‌های هفته گذشته
    $newUsers = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM users
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );

    // مجموع تراکنش‌های هفته گذشته
    $txVolume = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM transactions
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           AND status = 'completed'"
    );

    // ذخیره در cache برای داشبورد ادمین
    Cache::getInstance()->put('kpi_weekly_report', [
        'new_users'    => $newUsers,
        'tx_volume'    => $txVolume,
        'generated_at' => date('Y-m-d H:i:s'),
    ], 10080); // یک هفته

    return ['new_users' => $newUsers, 'tx_volume' => $txVolume];
}, 'weekly_kpi_report');

// ==========================================
//  SocialTask Jobs
// ==========================================


// ── هر شب ساعت ۱ — Web/Mobile Split (محاسبه median reward)
$scheduler->daily('01:00', function () {
    $svc = Container::getInstance()->make(SocialTaskSvc::class);
    $median = $svc->updateMedianReward();
    return ['median_reward' => $median];
}, 'social_task_median_reward');

// ── هر شب ساعت ۱:۳۰ — Trust Score هفتگی (بهبود + جریمه soft_excess)
$scheduler->daily('01:30', function () {
    $svc    = Container::getInstance()->make(SocialTrustService::class);
    $result = $svc->processWeeklyRecovery();
    return $result;
}, 'social_task_trust_recovery');

// ── هر ساعت — انقضای execution های زمان‌گذشته (بیش از ۲۴ ساعت pending)
// مشکل #13: از execute() برای DML استفاده می‌کنیم که مستقیماً تعداد affected rows برمی‌گرداند
$scheduler->hourly(function () {
    $db    = Database::getInstance();
    $count = (int) $db->execute(
        "UPDATE social_task_executions
         SET status = 'expired', updated_at = NOW()
         WHERE status = 'pending'
           AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    if ($count > 0) {
        // بازگرداندن slot به آگهی
        $db->execute(
            "UPDATE social_ads sa
             JOIN (
                 SELECT ad_id, COUNT(*) AS cnt
                 FROM social_task_executions
                 WHERE status = 'expired'
                   AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 GROUP BY ad_id
             ) ex ON ex.ad_id = sa.id
             SET sa.remaining_slots = sa.remaining_slots + ex.cnt
             WHERE sa.status = 'active'"
        );
    }
    return ['expired' => $count];
}, 'social_task_expire_pending');

// ==========================================
//  اجرا
// ==========================================

echo '[' . date('Y-m-d H:i:s') . '] شروع اجرای cron jobs' . PHP_EOL;

// ─────────────────────────────────────────────────────────────────
//  اینفلوئنسر مارکت‌پلیس
// ─────────────────────────────────────────────────────────────────

/**
 * هر ساعت: تایید خودکار buyer check هایی که مهلتشان گذشته
 * وقتی buyer در ۲۴ ساعت پاسخ ندهد → auto-approve → پرداخت به اینفلوئنسر
 */
// مشکل #12: نام job برای مانیتورینگ/دیباگ اضافه شد
$scheduler->hourly(function () use ($container) {
    $service = $container->make(InfluencerService::class);
    $count   = $service->processExpiredBuyerChecks();
    if ($count > 0) {
        echo "[Influencer] Auto-approved {$count} buyer-check timeout orders\n";
    }
    return ['approved' => $count];
}, 'influencer_buyer_check_timeout');

/**
 * هر ساعت: رد خودکار سفارش‌هایی که اینفلوئنسر در مهلت پاسخ نداده
 */
$scheduler->hourly(function () use ($container) {
    $service = $container->make(InfluencerService::class);
    $count   = $service->processExpiredPendingAcceptance();
    if ($count > 0) {
        echo "[Influencer] Auto-rejected {$count} orders with no influencer response\n";
    }
    return ['rejected' => $count];
}, 'influencer_expire_pending_acceptance');

/**
 * هر ساعت: escalate اختلاف‌هایی که peer resolution timeout شده
 */
$scheduler->hourly(function () use ($container) {
    $service = $container->make(DisputeService::class);
    $count   = $service->processExpiredPeerResolutions();
    if ($count > 0) {
        echo "[Influencer] Escalated {$count} peer-resolution timeouts to admin\n";
    }
    return ['escalated' => $count];
}, 'influencer_escalate_peer_resolution');

/**
 * روزانه: پاکسازی فایل‌های مدرک قدیمی
 */
$scheduler->daily('05:00', function () use ($container) {
    $service = $container->make(InfluencerService::class);
    $count   = $service->cleanupOldFiles(3);
    if ($count > 0) {
        echo "[Influencer] Cleaned up proof files for {$count} orders\n";
    }
    return ['cleaned_orders' => $count];
}, 'influencer_cleanup_proof_files');

// ─────────────────────────────────────────────────────────────────────────────
// Phase 5e — Advanced Settings & Management
// ─────────────────────────────────────────────────────────────────────────────

/**
 * روزانه: حذف خودکار حساب‌های منقضی و پاک‌سازی فایل‌های صادر شده
 */
$scheduler->daily('04:00', function () use ($container) {
    try {
        $accountDeletionService = $container->make(\App\Services\AccountDeletionService::class);
        $dataExportService = $container->make(\App\Services\DataExportService::class);

        // حذف حساب‌های منقضی
        $deletedCount = $accountDeletionService->processExpiredDeletionRequests();
        echo "[Phase5e] Processed {$deletedCount} expired account deletion requests\n";

        // پاک‌سازی فایل‌های منقضی
        $deletedFiles = $dataExportService->deleteExpiredExports();
        echo "[Phase5e] Cleaned up {$deletedFiles} expired export files\n";

    } catch (\Exception $e) {
        echo "[Phase5e ERROR] " . $e->getMessage() . "\n";
    }
}, 'process_scheduled_tasks');


// ─────────────────────────────────────────────────────────────────────────────
// نوتیفیکیشن — Scheduling & Analytics
// ─────────────────────────────────────────────────────────────────────────────

/**
 * هر دقیقه: ارسال نوتیفیکیشن‌های زمان‌بندی‌شده
 */
$scheduler->everyMinute(function () use ($container) {
    $notifModel = $container->make(NotificationModel::class);
    $pending    = $notifModel->getPendingScheduled(50);

    if (empty($pending)) {
        return ['processed' => 0];
    }

    $notifService = $container->make(NotificationService::class);
    $processed    = 0;

    foreach ($pending as $notif) {
        // علامت ارسال‌شده — جلوگیری از ارسال دوباره
        $notifModel->markAsSent($notif->id);

        // Push برای نوتیف‌های زمان‌بندی‌شده (در صورت نیاز)
        $notifService->invalidateUnreadCache((int)$notif->user_id);
        $processed++;
    }

    // مشکل #11: رشته echo صحیح
    if ($processed > 0) {
        echo "[Notification] Processed {$processed} scheduled notifications\n";
    }

    return ['processed' => $processed];
}, 'notification_scheduled');

/**
 * هر دقیقه: توزیع آگهی‌های نوتیفیکیشن انبوه‌ (Push Ads)
 */
$scheduler->everyMinute(function () use ($container) {
    $dispatcher = $container->make(\App\Services\AdNotificationDispatcher::class);
    $result = $dispatcher->processAdNotifications();
    
    if ($result['total_sent'] > 0) {
        echo "[AdPush] Processed {$result['ads_processed']} ads, sent {$result['total_sent']} push packets\n";
    }
    return $result;
}, 'ad_notification_blast');

/**
 * هر ساعت: آرشیو نوتیفیکیشن‌های منقضی‌شده
 */
$scheduler->hourly(function () use ($container) {
    $notifModel = $container->make(NotificationModel::class);
    $count      = $notifModel->archiveExpired();

    // مشکل #11: رشته echo صحیح
    if ($count > 0) {
        echo "[Notification] Archived {$count} expired notifications\n";
    }

    return ['archived' => $count];
}, 'notification_expire');

/**
 * هر ساعت: batch aggregation آمار نوتیفیکیشن
 */
$scheduler->hourly(function () use ($container) {
    $notificationService = $container->make(\App\Services\Notification\NotificationService::class);
    $stats = $notificationService->runBatchAggregation();

    // مشکل #11: رشته echo صحیح
    if (($stats['processed'] ?? 0) > 0) {
        echo "[Notification] Analytics aggregated {$stats['processed']} rows\n";
    }

    return $stats;
}, 'notification_analytics');

if ($dryRun) {
    echo "وظایف ثبت‌شده - اجرا نشدند (dry-run mode)\n";
    exit(0);
}

$results = $scheduler->run($onlyJob);

// نمایش نتایج
foreach ($results as $name => $result) {
    $status = $result['status'];
    $icon   = match($status) {
        'ok'      => '✓',
        'error'   => '✗',
        'skipped' => '⟳',
        default   => '?',
    };

    echo "[{$icon}] {$name}: {$status}";

    if ($status === 'ok' && isset($result['output'])) {
        $out = $result['output'];
        if (is_array($out)) {
            echo ' - ' . implode(', ', array_map(
                fn($k, $v) => "{$k}={$v}",
                array_keys($out),
                array_values($out)
            ));
        }
    }

    if ($status === 'error') {
        echo ' - ' . ($result['message'] ?? '');
    }

    echo PHP_EOL;
}

echo '[' . date('Y-m-d H:i:s') . '] پایان' . PHP_EOL;

} catch (\Throwable $e) {
    $errorMsg = "CRON FATAL ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
    // 1. Echo to standard output (will capture in redirected logs)
    echo "\n❌ " . $errorMsg . "\n";
    echo substr($e->getTraceAsString(), 0, 2000) . "\n";

    // 2. Try to utilize framework Logger if initialized
    try {
        if (function_exists('logger')) {
            logger()->critical('cron_execution_failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 2048)
            ]);
        }
    } catch (\Throwable $loggerException) {
        // Logger failed or not booted
    }

    // 3. Native server logging
    error_log($errorMsg);
    exit(1);
}
