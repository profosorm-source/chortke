<?php
namespace App\Console;
use Core\Scheduler;
use Core\Container;
use App\Services\EmailService;
use App\Services\CryptoDeposit\CryptoDepositService;
use App\Services\User\UserLevelService;
use App\Services\Lottery\LotteryService;
use App\Services\BannerService;
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

class Kernel {
    public static function schedule(Scheduler $scheduler) {
        $container = Container::getInstance();
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
    $maxJobsToProcess = max(1, min(100, (int) feature_config('cron_queue_jobs_limit', 'rollout_percentage', 10)));
    
    $command = Container::getInstance()->make(\App\Commands\QueueWorkCommand::class);
    $result = $command->run(['cli.php', 'queue:work', "--limit={$maxJobsToProcess}"]);
    
    return ['processed_jobs' => $result['processed_jobs'] ?? 0];
}, 'system_queue_processor');

// Poison Message Handler: بازپخش هوشمند خطاهای موقت
$scheduler->everyMinutes(5, function () {
    $queue = Container::getInstance()->make(\Core\Queue::class);
    $limit = max(1, min(100, (int) feature_config('cron_dlq_retry_limit', 'rollout_percentage', 50)));
    
    $stats = $queue->retryEligibleFailedJobs(null, $limit, false);
    
    return [
        'requeued_poison_messages' => $stats['requeued'] ?? 0,
        'failed_retries' => $stats['errors'] ?? 0
    ];
}, 'poison_message_smart_retry');

// تخلیه بافر امتیازات از Redis به دیتابیس (حلوگیری از از دست رفتن دیتا)
$scheduler->everyMinute(function () {
    $cronService = Container::getInstance()->make(\App\Services\Cron\CronService::class);
    $result = $cronService->flushScoreEventsBuffer();
    return [
        'flushed_scores' => $result['count'] ?? 0
    ];
}, 'flush_score_events_buffer');

// تأیید خودکار واریزهای کریپتو در انتظار
$scheduler->everyMinute(function () {
    $db      = Database::getInstance();
    $job = Container::getInstance()->make(\App\Jobs\VerifyCryptoDepositJob::class);

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
        $result = $job->handle(['deposit_id' => $id]);
        if (($result['auto'] ?? false) === true) {
            $verified++;
        }
    }

    return ['pending_checked' => count($pending), 'verified' => $verified];
}, 'crypto_verify');

// تایید خودکار پرداخت‌های معلق درگاه‌های آنلاین
$scheduler->everyMinutes(10, function () {
    $paymentService = \Core\Container::getInstance()->make(\App\Services\Payment\PaymentService::class);
    $pending = $paymentService->getPendingVerificationPayments();
    
    $completed = 0;
    $failed = 0;
    
    foreach ($pending as $payment) {
        $createdAt = strtotime($payment->created_at);
        $age = time() - $createdAt;
        
        // فقط برای تراکنش‌های کمتر از ۲۴ ساعت و بیشتر از ۵ دقیقه (جهت فرصت دادن به پردازش‌های آنی و عادی درگاه)
        if ($age > 300 && $age < 86400) {
            try {
                // تایید خودکار با شناسه سیستم (0)
                $result = $paymentService->manuallyVerifyPayment((int)$payment->id, 0);
                if (!empty($result['success'])) {
                    $completed++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                logger()->error('payment.auto_retry_verification_failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage()
                ]);
                $failed++;
            }
        }
    }
    
    return [
        'total_pending' => count($pending),
        'auto_completed' => $completed,
        'auto_failed' => $failed
    ];
}, 'payment_pending_verification_retry');

// پردازش خودکار قوانین هشدار (Alert Engine)
$scheduler->everyMinute(function () {
    $dispatcher = \Core\Container::getInstance()->make(\App\Services\Sentry\Alerting\AlertDispatcher::class);
    $triggered = $dispatcher->processRules();
    return ['triggered_rules' => $triggered];
}, 'alert_rule_engine');

// بررسی وضعیت دیسک و حافظه (مانیتورینگ پیشگیرانه)
$scheduler->everyMinutes(5, function () {
    $monitoring = \Core\Container::getInstance()->make(\App\Services\AdminDashboard\SystemMonitoringService::class);
    $monitoring->checkAndAlert();
    return ['checked' => true];
}, 'system_monitoring_alert');


// پاک‌سازی کش منقضی‌شده
$scheduler->everyMinutes(feature_config('cron_scheduler_interval', 'rollout_percentage', 5), function () {
    $cleaned = Cache::getInstance()->cleanup();
    return ['cleaned_files' => $cleaned];
}, 'cache_cleanup');

// 📢 ارسال اعلان‌های پس‌زمینه کمپین‌های فعال (Ad Notifications)
// اجرا در فواصل کوتاه برای ارسال موازی و کم‌بار بدون سنگین کردن سرور
$scheduler->everyMinutes(feature_config('cron_ad_push_interval', 'rollout_percentage', 3), function () {
    $dispatcher = Container::getInstance()->make(AdNotificationDispatcher::class);
    $result = $dispatcher->processAdNotifications();
    if (($result['total_sent'] ?? 0) > 0) {
        echo "[AdPush] Processed {$result['ads_processed']} ads, sent {$result['total_sent']} push packets\n";
    }
    return $result;
}, 'ad_notification_push');

// H-06 Fix: تخلیه بافر بازدید بنرها از Redis به دیتابیس هر ۵ دقیقه
$scheduler->everyMinutes(5, function () {
    $service = \Core\Container::getInstance()->make(\App\Services\BannerService::class);
    $count   = $service->flushImpressionsBuffer();
    return ['flushed_banners' => $count];
}, 'flush_banner_impressions');

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

// پاک‌سازی ساعتی فایل‌های جلسه‌ی فایلی در صورت fallback از Redis
$scheduler->hourly(function () {
    try {
        $handler = new \Core\RedisSessionHandler();
        $maxLifetime = max(60, min(86400, (int) config('session.lifetime', 7200)));
        $deleted = $handler->gc($maxLifetime);
        return [
            'session_gc_deleted' => $deleted === false ? 0 : $deleted,
            'session_driver' => $handler->driver(),
        ];
    } catch (\Throwable $e) {
        logger()->error('cron.session_file_gc.failed', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return ['error' => $e->getMessage()];
    }
}, 'session_file_gc');

// غیرفعال کردن فیچر فلگ‌های منقضی شده و cleanup metrics
$scheduler->hourly(function () {
    try {
        $db = Database::getInstance();
        $model = Container::getInstance()->make(\App\Models\FeatureFlag::class);

        $affected = (int) $db->execute(
            "UPDATE feature_flags
             SET enabled = 0, updated_at = NOW()
             WHERE enabled = 1
               AND enabled_until IS NOT NULL
               AND enabled_until < NOW()"
        );

        $metricDays = max(7, min(365, (int) feature_config('feature_flag_metrics_retention_days', 'rollout_percentage', 30)));
        $model->cleanupMetrics($metricDays);

        return [
            'expired_feature_flags_disabled' => $affected,
            'feature_flag_metrics_retention_days' => $metricDays,
        ];
    } catch (\Throwable $e) {
        logger()->error('cron.expired_feature_flag_cleanup.failed', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return ['error' => $e->getMessage()];
    }
}, 'expired_feature_flag_cleanup');

// پاکسازی کش جستجو/تگ‌های سالمند: حذف index فایل خراب در File mode و orphan members در Redis
$scheduler->hourly(function () {
    $cache = Cache::getInstance();
    $driver = $cache->driver();
    $cleaned = 0;

    if ($driver === 'redis') {
        $redis = $cache->redis();
        if ($redis instanceof \Redis) {
            $iterator = null;
            $pattern = $cache->redisKey('tag:*');

            while (false !== ($setKeys = $redis->scan($iterator, $pattern, 100))) {
                foreach ($setKeys as $setKey) {
                    $members = $redis->sMembers($setKey) ?: [];
                    if (empty($members)) {
                        $redis->del($setKey);
                        continue;
                    }

                    $validMembers = [];
                    foreach ($members as $member) {
                        if ($redis->exists($member)) {
                            $validMembers[] = $member;
                        }
                    }

                    if (count($validMembers) !== count($members)) {
                        $redis->del($setKey);
                        if (!empty($validMembers)) {
                            $redis->sAdd($setKey, ...$validMembers);
                        }
                        $cleaned += count($members) - count($validMembers);
                    }
                }
            }
        }
    } else {
        $tagsDir = __DIR__ . '/storage/cache/tags/';
        foreach (glob($tagsDir . '*.json') ?: [] as $indexFile) {
            $content = @file_get_contents($indexFile);
            $keys = $content ? (json_decode($content, true) ?? []) : [];
            $valid = [];

            foreach ($keys as $key) {
                if (is_string($key) && $cache->has($key)) {
                    $valid[] = $key;
                }
            }

            if (empty($valid)) {
                @unlink($indexFile);
                $cleaned += count($keys);
            } elseif (count($valid) !== count($keys)) {
                file_put_contents($indexFile, json_encode(array_values($valid)));
                $cleaned += count($keys) - count($valid);
            }
        }
    }

    return [
        'stale_search_cache_cleaned' => $cleaned,
        'cache_driver' => $driver,
    ];
}, 'stale_search_cache_cleanup');

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

// MED-04 Fix: پاک‌سازی توکن‌های بازیابی رمز عبور منقضی شده (بیش از یک ساعت)
$scheduler->hourly(function () {
    $model = \Core\Container::getInstance()->make(\App\Models\SecurityModel::class);
    $count = $model->deleteExpiredPasswordResets(3600);
    return ['deleted_tokens' => $count];
}, 'cleanup_password_resets');

// چرخش لاگ‌های اپلیکیشن (جلوگیری از حجیم شدن فایل‌ها)
$scheduler->hourly(function () {
    $logService = \Core\Container::getInstance()->make(\App\Services\LogService::class);
    
    // استفاده از متد بازتابی یا فراخوانی مستقیم در صورتی که عمومی شود
    // اما در اینجا می‌توانیم cleanup را با 0 روز صدا بزنیم که هم لاگ‌های دیتابیس را پاک نکند، هم فایل‌ها را بچرخاند.
    // یا اینکه از یک Job یا تابع عمومی برای Rotate استفاده کنیم.
    $logService->cleanup(90); // این هم rotate می‌کند و هم قدیمی‌ها را پاک می‌کند
    return ['status' => 'rotated'];
}, 'rotate_logs');

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
// Financial Export Backup (Daily)
// ==============================
$scheduler->daily('04:00', function () {
    $db = Database::getInstance();
    $exportDir = __DIR__ . '/storage/exports/financial/';
    if (!is_dir($exportDir)) {
        @mkdir($exportDir, 0755, true);
    }

    $windowStart = date('Y-m-d 00:00:00', strtotime('yesterday'));
    $windowEnd = date('Y-m-d 23:59:59', strtotime('yesterday'));
    $timeTag = date('Ymd_His');

    $files = [];
    $written = 0;

    $writeCsv = function (\PDOStatement $stmt, string $filePath) use (&$written) {
        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            return 0;
        }

        $first = true;
        $count = 0;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($first) {
                fputcsv($handle, array_keys($row));
                $first = false;
            }
            fputcsv($handle, array_values($row));
            $count++;
        }

        fclose($handle);
        $written += $count;
        return $count;
    };

    $transactionsFile = $exportDir . 'transactions_' . $timeTag . '.csv';
    $ledgerFile = $exportDir . 'ledger_entries_' . $timeTag . '.csv';

    $txnStmt = $db->query(
        "SELECT * FROM transactions WHERE created_at BETWEEN ? AND ? ORDER BY created_at ASC",
        [$windowStart, $windowEnd]
    );
    $ledgerStmt = $db->query(
        "SELECT * FROM ledger_entries WHERE created_at BETWEEN ? AND ? ORDER BY created_at ASC",
        [$windowStart, $windowEnd]
    );

    $txCount = ($txnStmt instanceof \PDOStatement) ? $writeCsv($txnStmt, $transactionsFile) : 0;
    $ledgerCount = ($ledgerStmt instanceof \PDOStatement) ? $writeCsv($ledgerStmt, $ledgerFile) : 0;

    if ($txCount > 0) {
        $files[] = $transactionsFile;
    }
    if ($ledgerCount > 0) {
        $files[] = $ledgerFile;
    }

    return [
        'transactions_exported' => $txCount,
        'ledger_entries_exported' => $ledgerCount,
        'files' => $files,
        'export_window' => date('Y-m-d', strtotime('yesterday')),
    ];
}, 'financial_export_backup');


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

// پاک‌سازی خطاهای قدیمی نهایی شده در Poison Messages (بیش از ۳۰ روز)
$scheduler->daily('03:05', function () {
    $queue = Container::getInstance()->make(\Core\Queue::class);
    $days = max(1, min(365, (int) feature_config('cron_dlq_retention_days', 'rollout_percentage', 30)));
    
    $deleted = $queue->cleanDeadLetters($days);
    
    return ['deleted_dead_letters' => $deleted];
}, 'cleanup_dead_letters');

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
                $baseDir = realpath(BASE_PATH . '/storage/uploads/kyc');
$file = basename((string) $row[$field]);
$path = realpath($baseDir . DIRECTORY_SEPARATOR . $file);

if (
    $path !== false &&
    $baseDir !== false &&
    str_starts_with($path, $baseDir . DIRECTORY_SEPARATOR)
) {
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

// ✅ **Idempotency Key Cleanup - مهم برای جلوگیری از رشد نامحدود DB**
// حذف کلیدهای منقضی‌شده (۹۰ روز برای عملیات مالی)
$scheduler->daily('03:45', function () {
    try {
        $idempotencyKey = \Core\Container::getInstance()->make(\Core\IdempotencyKey::class);
        $deleted = $idempotencyKey->cleanup(false); // Live delete
        
        if ($deleted > 0) {
            logger()->info('idempotency.cleanup.completed', [
                'channel' => 'maintenance',
                'deleted_keys' => $deleted,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        }
        
        return [
            'deleted_idempotency_keys' => $deleted,
            'retention_days' => 90,
        ];
    } catch (\Throwable $e) {
        logger()->error('idempotency.cleanup.failed', [
            'channel' => 'maintenance',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return ['error' => $e->getMessage()];
    }
}, 'idempotency_cleanup');

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

// توزیع سود/ضرر سرمایه‌گذاری هفتگی به صورت Asynchronous (جلوگیری از قفل شدن Cron)
$scheduler->weekly('Sunday', '05:10', function () use ($container) {
    $container->make(\Core\Queue::class)->push(\App\Jobs\InvestmentProfitDistributionJob::class);
    return ['status' => 'queued'];
}, 'investment_profit_distribution');

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

// ── هر روز صبح — ارسال یادآوری برای بررسی تسک‌های انجام‌شده (Task Management)
$scheduler->daily('09:00', function () use ($container) {
    $container->make(\Core\Queue::class)->push(\App\Jobs\SocialTaskApprovalReminderJob::class);
    return ['status' => 'queued'];
}, 'social_task_approval_reminder');

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
        $accountDeletionService = $container->make(\App\Services\User\AccountDeletionService::class);
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

/**
 * هر ساعت: آزادسازی خودکار escrow های منقضی شده
 */
$scheduler->hourly(function () use ($container) {
    $container->make(\Core\Queue::class)->push(\App\Jobs\EscrowTimeoutJob::class);
    return ['status' => 'queued'];
}, 'escrow_timeout_release');

/**
 * روزانه: پاکسازی اعلان‌های قدیمی
 */
$scheduler->daily('04:10', function () use ($container) {
    $container->make(\Core\Queue::class)->push(\App\Jobs\NotificationCleanupJob::class);
    return ['status' => 'queued'];
}, 'notification_cleanup');

/**
 * هر ساعت: انقضای لیست ویترین و آزادسازی Holdهای منقضی شده
 */
$scheduler->hourly(function () use ($container) {
    $container->make(\Core\Queue::class)->push(\App\Jobs\VitrineListingExpiryJob::class);
    return ['status' => 'queued'];
}, 'vitrine_listing_expiry');

/**
 * روزانه: ریزش امتیاز کاربران غایب
 */
$scheduler->daily('02:20', function () use ($container) {
    $cronService = $container->make(\App\Services\Cron\CronService::class);
    return $cronService->applyInactivityScoreDecay();
}, 'inactivity_score_decay');

/**
 * هر ۱۵ دقیقه: تسویه خودکار بازی‌های پیش‌بینی
 */
$scheduler->everyMinutes(15, function () use ($container) {
    $container->make(\Core\Queue::class)->push(\App\Jobs\PredictionGameSettlementJob::class);
    return ['status' => 'queued'];
}, 'prediction_game_settlement');

/**
 * روزانه: به‌روزرسانی لیست Tor Exit Nodes
 */
$scheduler->daily('04:20', function () use ($container) {
    $command = $container->make(\App\Commands\UpdateTorExitNodesCommand::class);
    $command->run([]);
    return ['status' => 'ok'];
}, 'update_tor_exit_nodes');


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
    echo "Running DLQ Auto-Retry...\n";
    try {
        $queue = $container->make(\Core\Queue::class);
        $stats = $queue->retryFailedJobsBatch(null, 50);
        if ($stats['requeued'] > 0 || $stats['errors'] > 0) {
            echo "DLQ Auto-Retry: {$stats['requeued']} requeued, {$stats['errors']} errors.\n";
            logger()->info('queue_dlq_auto_retry_results', $stats);
        }
    } catch (\Throwable $e) {
        logger()->error('queue_dlq_auto_retry_exception', ['error' => $e->getMessage()]);
    }
}, 'queue_dlq_auto_retry');

$scheduler->hourly(function () use ($container) {
    $notificationService = $container->make(\App\Services\Notification\NotificationService::class);
    $stats = $notificationService->runBatchAggregation();

    // مشکل #11: رشته echo صحیح
    if (($stats['processed'] ?? 0) > 0) {
        echo "[Notification] Analytics aggregated {$stats['processed']} rows\n";
    }

    return $stats;
}, 'notification_analytics');

// اجرای OutboxPublisher هر X ثانیه (قابل تنظیم توسط ادمین)
$scheduler->everySeconds((int)setting('outbox_publish_interval', 60), function () {
    // توضیح: OutboxPublisher مسئول ارسال رویدادهای ذخیره‌شده در جدول Outbox به سیستم پیام‌رسان است.
    // کاهش این مقدار باعث ارسال سریع‌تر پیام‌ها می‌شود اما بار سرور را افزایش می‌دهد.
    $command = 'php ' . BASE_PATH . '/cli.php outbox:publish --limit=100';
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        logger()->error('cron.outbox_publish.failed', ['output' => $output, 'exit_code' => $exitCode]);
    }
    return ['output' => $output, 'exit_code' => $exitCode];
}, 'outbox_publisher');

$scheduler->daily('04:30', function () use ($container) {
    echo "Running Database Daily Maintenance (Retention, Archival, Backup)... \n";
    try {
        $dbService = $container->make(\App\Services\DatabaseService::class);
        $results = $dbService->runDailyMaintenance();
        
        echo "Maintenance Completed. Results: " . json_encode($results) . "\n";
    } catch (\Throwable $e) {
        logger()->error('cron.database_maintenance.failed', ['error' => $e->getMessage()]);
        echo "Maintenance Failed: " . $e->getMessage() . "\n";
    }
}, 'database_maintenance');

// هر ۵ دقیقه: تازه‌سازی داده‌های داشبورد (Materialized View)
$scheduler->everyMinutes(5, function () use ($container) {
    echo "Refreshing Dashboard Materialized Views...\n";
    try {
        $transactionQuery = $container->make(\App\Models\TransactionQuery::class);
        $transactionQuery->refreshMaterializedView();
        echo "Dashboard Materialized Views refreshed successfully.\n";
    } catch (\Throwable $e) {
        logger()->error('cron.mv_refresh.failed', ['error' => $e->getMessage()]);
        echo "MV Refresh Failed: " . $e->getMessage() . "\n";
    }
}, 'dashboard_mv_refresh');

// هر یک دقیقه: مانیتورینگ بلادرنگ عمق صف‌ها (Queue Depth Monitoring)
$scheduler->everyMinutes(1, function () use ($container) {
    try {
        $queue = $container->make(\Core\Queue::class);
        $queues = ['high_priority', 'default', 'analytics', 'notifications', 'maintenance'];
        
        $stats = [];
        $hasBacklog = false;
        
        foreach ($queues as $qName) {
            $size = $queue->size($qName);
            $stats[$qName] = $size;
            
            // آستانه هشدار برای هر صف
            $threshold = match($qName) {
                'high_priority' => 1000,
                'default' => 5000,
                default => 10000,
            };
            
            if ($size >= $threshold) {
                $hasBacklog = true;
                logger()->critical('queue_monitoring.backlog_alert', [
                    'queue' => $qName,
                    'current_depth' => $size,
                    'threshold' => $threshold,
                    'action_required' => 'Auto-scale consumers or investigate blockages'
                ]);
            }
        }
        
        // ارسال متریک‌ها به سرویس مانیتورینگ/گرافانا
        logger()->info('queue_monitoring.depth_metrics', $stats);
        
        if ($hasBacklog) {
            echo "⚠️ [ALERT] Queue backlog detected! Check logs.\n";
        }
        
        return ['status' => 'ok', 'depth' => $stats];
    } catch (\Throwable $e) {
        logger()->error('queue_monitoring.failed', ['error' => $e->getMessage()]);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}, 'queue_depth_monitor');
    }
}