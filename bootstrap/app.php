<?php

use Core\Container;

// Load critical non-PSR4 compliant constants from legacy ecosystem
require_once __DIR__ . '/../app/Constants/MagicNumbers.php';

use Core\Application;
use Core\Session;
use Core\Database;
use Core\Logger;
use App\Models\User;
use App\Models\KpiStatistics;
use App\Models\ExportData;
use App\Services\AuthService;
use App\Services\CaptchaService;
use App\Models\SecurityModel;
use App\Services\AuditTrail;
use App\Services\WalletService;
use App\Services\Notification\NotificationService;
use App\Services\UploadService;
use App\Services\FileAccessService;
use App\Services\WithdrawalService;
use App\Services\UserLevelService;
use App\Services\ContentService;
use App\Services\InvestmentService;
use App\Services\LotteryService;
use App\Services\ManualDepositService;
use App\Services\CryptoDeposit\CryptoDepositService;
use App\Services\Adapters\CryptoVerificationAdapter;
use App\Services\Adapters\CryptoApiAdapter;
use App\Services\Adapters\BankInquiryAdapter;
use App\Services\Adapters\JibitInquiryAdapter;
use App\Services\Adapters\KycFaceVerificationAdapter;
use App\Services\Adapters\DeepFaceKycAdapter;
use App\Services\PaymentService;
use App\Services\InfluencerService;
use App\Services\KYCService;
use App\Services\BannerService;
use App\Services\Auth\TwoFactorService;
use App\Models\TaskExecution;
use App\Models\Transaction;
use App\Models\ReferralCommission;
use App\Models\Notification;
use App\Models\SocialAccount;
use App\Models\Investment;
use App\Models\LotteryRound;

use App\Models\AdvancedAnalytics;


// BASE_PATH
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Composer Autoloader — Loads vendor + PSR-4 (Core, App) + Helpers
$vendorAutoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    $isCli = (PHP_SAPI === 'cli' || defined('STDIN'));
    $errorMessage = "Error: vendor/autoload.php was not found. Please run 'composer install' in the project root.";
    if ($isCli) {
        fwrite(STDERR, $errorMessage . "\n");
        exit(1);
    }
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    die(
        '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>خطای بسته‌های سیستمی</title>' .
        '<style>body{font-family:Tahoma,sans-serif;padding:50px;background:#fcfcfc;} .box{background:#fff;border-right:5px solid #e74c3c;padding:30px;box-shadow:0 5px 20px rgba(0,0,0,0.05);border-radius:4px;}</style></head>' .
        '<body><div class="box"><h2>خطای راه‌اندازی: Composer autoload یافت نشد</h2>' .
        '<p>بسته‌های PHP سیستم نصب نشده‌اند. لطفاً دستور زیر را اجرا نمایید:</p>' .
        '<pre style="background:#f5f5f5;padding:15px;border-radius:4px;color:#c0392b;font-weight:bold;">composer install</pre></div></body></html>'
    );
}
require_once $vendorAutoload;

// ── Hardened Security Defaults (Entry Safeguard) ───────────────
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Helpers از طریق composer autoload (files section) لود می‌شوند
// نیازی به require_once دستی نیست

// ── بارگذاری .env ────────────────────────────────────────────
global $env;
if (empty($env)) {
    $envPath = BASE_PATH . '/.env';
    $env = []; // مقداردهی اولیه
    if (file_exists($envPath)) {
        $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
        if ($env === false) {
            $env = [];
            error_log('[Chortke] .env file is invalid or unreadable');
        } else {
            $isProduction = ($env['APP_ENV'] ?? 'production') === 'production';
            $appDebug = filter_var($env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (!$isProduction && $appDebug) {
                error_reporting(E_ALL);
                ini_set('display_errors', '1');
            }
        }
    } else {
        // بدون .env: امنیت حداکثری از مقادیر پیش‌فرض استفاده می‌شود
    }
}

// Check APP_KEY
if (config('app.key') === '') {
    throw new Exception('APP_KEY must be set in environment variables');
}

// Load config early to avoid circular dependency
$config = config();

// Container Singleton
$container = Container::getInstance();

// Database Singleton - bind early
$container->singleton(\Core\Database::class, function($c) use ($config) {
    return \Core\Database::getInstance($config['database']);
});

// ─── Logger — Singleton مرکزی لاگ ────────────────────────────────────────────
// PSR-3 Compatible Logging System
$container->singleton(\App\Services\LogService::class, function($c) {
    return new \App\Services\LogService(
        $c->make(\Core\Database::class),
        $c->make(\App\Models\ActivityLog::class),
        $c->make(\App\Models\SystemLog::class),
        $c->make(\App\Models\SecurityLog::class),
        $c->make(\App\Models\PerformanceLog::class)
    );
});

$container->singleton(\App\Contracts\LoggerInterface::class, function($c) {
    return new \Core\Logger(
        $c->make(\App\Services\LogService::class)
    );
});

// =========================
// Sentry-like Services
// =========================

$container->singleton(\App\Models\SentryModel::class, function($c) {
    return new \App\Models\SentryModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Services\Sentry\Alerting\AlertDispatcher::class, function($c) {
    return new \App\Services\Sentry\Alerting\AlertDispatcher(
        $c->make(\App\Models\SentryModel::class),
        $c->make(\Core\Logger::class)
    );
});


$container->singleton(\App\Services\CryptoDeposit\CryptoDepositService::class, function($c) {
    return new \App\Services\CryptoDeposit\CryptoDepositService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Models\CryptoDepositIntent::class),
        $c->make(\App\Models\CryptoDeposit::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\Adapters\CryptoVerificationAdapter::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Services\ReconciliationService::class)
    );
});

// CryptoDeposit Adapters
$container->singleton(\App\Services\Adapters\CryptoVerificationAdapter::class, function($c) {
    return new \App\Services\Adapters\CryptoExplorerAdapter(
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\Adapters\CryptoApiAdapter::class, function($c) {
    return new \App\Services\Adapters\CryptoApiAdapter(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\SettingService::class)
    );
});

// Bank Inquiry Adapter (Automatic Fallback enabled)
$container->singleton(\App\Services\Adapters\BankInquiryAdapter::class, function($c) {
    return new \App\Services\Adapters\JibitInquiryAdapter(
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class)
    );
});

// AI KYC Verification Adapter
$container->singleton(\App\Services\Adapters\KycFaceVerificationAdapter::class, function($c) {
    return new \App\Services\Adapters\DeepFaceKycAdapter(
        $c->make(\Core\Logger::class),
        $c->make(\Core\Database::class)
    );
});







$container->singleton(\App\Models\SocialTaskModel::class, function($c) {
    return new \App\Models\SocialTaskModel(
        $c->make(\Core\Database::class),
        $c->make(\App\Models\SocialTaskExecutionModel::class),
        $c->make(\App\Models\SocialTaskAnalyticsModel::class)
    );
});

$container->singleton(\App\Models\SocialTaskExecutionModel::class, function($c) {
    return new \App\Models\SocialTaskExecutionModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\SocialTaskAnalyticsModel::class, function($c) {
    return new \App\Models\SocialTaskAnalyticsModel($c->make(\Core\Database::class));
});





$container->singleton(\App\Services\SocialTask\BehaviorAnalysisService::class, function($c) {
    return new \App\Services\SocialTask\BehaviorAnalysisService(
        $c->make(\App\Services\SocialTask\SocialTaskScoringService::class)
    );
});

$container->singleton(\App\Services\SocialTask\CameraVerificationService::class, function($c) {
    return new \App\Services\SocialTask\CameraVerificationService(
        $c->make(\App\Models\SocialTaskModel::class),
        $c->make(\App\Services\SocialTask\BehaviorAnalysisService::class)
    );
});



$container->singleton(\App\Services\SocialTask\SocialTaskService::class);

$container->singleton(\App\Services\Sentry\Audit\AdvancedAuditTrail::class, function($c) {
    return new \App\Services\Sentry\Audit\AdvancedAuditTrail(
        $c->make(\App\Models\SentryModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\Core\Session::class),
        []
    );
});

$container->singleton(\App\Services\Sentry\SentryExceptionHandler::class, function($c) {
    return new \App\Services\Sentry\SentryExceptionHandler(
        $c->make(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class),
        $c->make(\App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Session::class)
    );
});


$container->singleton(App\Services\AuditTrail::class, function($c) {
    return new App\Services\AuditTrail(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\AuditTrail::class)
    );
});



// ثبت سرویس‌ها و مدل‌ها
$container->singleton(Session::class, function() {
    return Session::getInstance();
});






$container->singleton(\App\Services\EscrowService::class, function($c) {
    return new \App\Services\EscrowService(
        $c->make(App\Models\Escrow::class),
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\FinancialEscrowService::class, function($c) {
    return new \App\Services\FinancialEscrowService(
        $c->make(\App\Services\EscrowService::class),
        $c->make(\App\Models\User::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\WalletService::class)
    );
});

$container->singleton(\App\Services\StateMachineService::class, function($c) {
    return new \App\Services\StateMachineService(
        $c->make(\Core\Logger::class)
    );
});





$container->singleton(App\Models\AdvancedAnalytics::class, function($c) {
    return new App\Models\AdvancedAnalytics($c->make(Database::class));
});


// ========== Analytics Services (Consolidated) ==========
$container->singleton(\App\Services\Analytics\AnalyticsService::class);


$container->singleton(\App\Models\SecurityModel::class, function($c) {
    return new \App\Models\SecurityModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\User::class, function($c) {
    return new \App\Models\User($c->make(\Core\Database::class));
});



$container->singleton(\App\Services\User\ProfileService::class, function($c) {
    return new \App\Services\User\ProfileService(
        $c->make(\App\Models\User::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class)
    );
});



$container->singleton(\App\Services\Auth\TwoFactorService::class, function($c) {
    return new \App\Services\Auth\TwoFactorService(
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\SecurityModel::class),
        $c->make(\Core\Session::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\Auth\AuthService::class, function($c) {
    return new \App\Services\Auth\AuthService(
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\User\UserService::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\SecurityModel::class),
        $c->make(\Core\Session::class),
        $c->make(\Core\RateLimiter::class),
        $c->make(\App\Services\Auth\SessionService::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Services\EmailService::class)
    );
});


$container->singleton(CaptchaService::class, function($c) {
    return new CaptchaService(
        $c->make(App\Models\CaptchaLog::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(Session::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

// User Model is already registered above as \App\Models\User::class

$container->singleton(\App\Services\SettingService::class, function($c) {
    return new \App\Services\SettingService(
        $c->make(\App\Models\Setting::class),
        $c->make(Database::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Models\Setting::class, function($c) {
    return new \App\Models\Setting($c->make(Database::class));
});


// ─── Singletons: Simple Services ─────────────────────────────────────────

$container->singleton(App\Services\WalletService::class);
$container->singleton(\App\Contracts\WalletServiceInterface::class, function($c) {
    return $c->make(App\Services\WalletService::class);
});

// AntiFraud services use \App\Services\AntiFraud\GeoIPService for consistent geolocation checks

// ─── Distributed Lock Service ─────────────────────────────
$container->singleton(\App\Services\DistributedLockService::class, function($c) {
    return new \App\Services\DistributedLockService(
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\RedisEmailQueueService::class, function($c) {
    return new \App\Services\RedisEmailQueueService(
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});



$container->singleton(\App\Services\EmailService::class, function($c) {
    return new \App\Services\EmailService(
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\EmailQueue::class),
        $c->make(\App\Models\NotificationPreference::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Models\User::class),
        $c->make(\Core\Queue::class),
        $c->make(\App\Services\RedisEmailQueueService::class)
    );
});

$container->singleton(\App\Services\Notification\NotificationService::class, function($c) {
    return new \App\Services\Notification\NotificationService(
        $c->make(\App\Models\Notification::class),
        $c->make(\App\Services\Notification\NotificationDispatcher::class),
        $c->make(\App\Services\Notification\FcmService::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\RateLimiter::class),
        $c->make(\App\Services\Notification\NotificationTemplateService::class),
        $c->make(\App\Services\Notification\NotificationPreferenceService::class),
        $c->make(\App\Services\Notification\NotificationTracker::class),
        $c->make(\App\Services\Notification\NotificationAnalyticsService::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Services\EmailService::class)
    );
});
$container->singleton(\App\Contracts\NotificationServiceInterface::class, function($c) {
    return $c->make(\App\Services\Notification\NotificationService::class);
});

$container->singleton(\App\Services\Notification\NotificationDispatcher::class, function($c) {
    return new \App\Services\Notification\NotificationDispatcher(
        $c->make(\App\Services\Notification\Adapters\PushNotificationAdapter::class),
        $c->make(\App\Services\Notification\Adapters\SmsNotificationAdapter::class),
        $c->make(\App\Services\Notification\Adapters\FcmNotificationAdapter::class),
        $c->make(\App\Services\Notification\Adapters\LogNotificationAdapter::class),
        $c->make(Logger::class),
        $c->make(\Core\Queue::class)
    );
});

// ─── AdNotificationDispatcher (with batch optimization) ──────────────────────
$container->singleton(\App\Services\AdNotificationDispatcher::class, function($c) {
    return new \App\Services\AdNotificationDispatcher(
        $c->make(Database::class),
        $c->make(\App\Services\Notification\FcmService::class),
        $c->make(\App\Services\PerformanceOptimizationService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Notification\Adapters\PushNotificationAdapter::class, function($c) {
    return new \App\Services\Notification\Adapters\PushNotificationAdapter(
        $c->make(\App\Services\Notification\Adapters\FcmNotificationAdapter::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\Notification\Adapters\SmsNotificationAdapter::class, function($c) {
    return new \App\Services\Notification\Adapters\SmsNotificationAdapter(
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\Notification\Adapters\FcmNotificationAdapter::class, function($c) {
    return new \App\Services\Notification\Adapters\FcmNotificationAdapter(
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class),
        $c->make(\Core\Database::class)
    );
});

$container->singleton(\App\Services\Notification\Adapters\LogNotificationAdapter::class, function($c) {
    return new \App\Services\Notification\Adapters\LogNotificationAdapter(
        $c->make(\App\Models\Notification::class),
        $c->make(\App\Models\SystemTelemetryModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\Notification\FcmService::class, function($c) {
    return new \App\Services\Notification\FcmService(
        $c->make(\App\Services\Notification\Adapters\FcmNotificationAdapter::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});


$container->singleton(\App\Services\Notification\LogNotificationService::class, function($c) {
    return new \App\Services\Notification\LogNotificationService(
        $c->make(\App\Services\Notification\Adapters\LogNotificationAdapter::class)
    );
});

$container->singleton(UploadService::class);
$container->singleton(FileAccessService::class);


$container->singleton(\App\Models\SystemLog::class, function($c) {
    return new \App\Models\SystemLog($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\SecurityLog::class, function($c) {
    return new \App\Models\SecurityLog($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\PerformanceLog::class, function($c) {
    return new \App\Models\PerformanceLog($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\KpiStatistics::class, function($c) {
    return new \App\Models\KpiStatistics($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\ExportData::class, function($c) {
    return new \App\Models\ExportData($c->make(\Core\Database::class));
});
// M-01: AntiFraudModel proxy removed - use specific models (VelocityAndScoreModel, IpAndDeviceModel) instead

$container->singleton(\App\Models\IpAndDeviceModel::class, function($c) {
    return new \App\Models\IpAndDeviceModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\VelocityAndScoreModel::class, function($c) {
    return new \App\Models\VelocityAndScoreModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\FraudAnalyticsModel::class, function($c) {
    return new \App\Models\FraudAnalyticsModel($c->make(\Core\Database::class));
});





$container->singleton(\App\Services\AntiFraud\GeoIPService::class, function($c) {
    return new \App\Services\AntiFraud\GeoIPService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Models\IpAndDeviceModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});



$container->singleton('oauth_config', function() {
    return [
        'google_client_id' => (string)config('oauth.google.client_id', ''),
        'google_client_secret' => (string)config('oauth.google.client_secret', ''),
        'facebook_app_id' => (string)config('oauth.facebook.app_id', ''),
        'facebook_app_secret' => (string)config('oauth.facebook.app_secret', ''),
        'app_url' => (string)config('app.url', 'http://localhost'),
    ];
});

$container->singleton(\App\Services\Auth\OAuthService::class, function($c) {
    return new \App\Services\Auth\OAuthService(
        $c->make(\App\Models\SecurityModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Services\Auth\AuthService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\Core\Session::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Services\DistributedLockService::class),
        $c->make('oauth_config')
    );
});


$container->singleton(App\Models\CustomTaskSubmissionModel::class, function($c) {
    return new App\Models\CustomTaskSubmissionModel($c->make(Database::class));
});

$container->singleton(App\Models\CustomTaskAnalyticsModel::class, function($c) {
    return new App\Models\CustomTaskAnalyticsModel($c->make(Database::class));
});

$container->singleton(App\Models\UserVacation::class, function($c) {
    return new App\Models\UserVacation($c->make(Database::class));
});

// Service - CustomTaskService (Sprint 4 auto-wired singleton)
$container->singleton(App\Services\CustomTaskService::class);
$container->singleton(App\Services\UnifiedTaskService::class);

$container->singleton(\App\Services\XPEngine::class, function($c) {
    return new \App\Services\XPEngine(
        $c->make(\Core\Database::class),
        $c->make(\App\Models\Score::class),
        $c->make(\App\Models\UserVacation::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\SettingService::class)
    );
});
$container->singleton(App\Services\UserLevelService::class, \App\Services\User\UserLevelService::class);

$container->singleton(\App\Services\User\UserLevelService::class, function($c) {
    return new \App\Services\User\UserLevelService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Shared\ReferralService::class),
        $c->make(\App\Models\UserLevel::class),
        $c->make(\App\Models\UserLevelHistory::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\CronService::class, function($c) {
    return new \App\Services\CronService(
        $c->make(Database::class),
        $c->make(App\Models\ActivityLog::class),
        $c->make(App\Models\Ads::class),
        $c->make(App\Models\CryptoDeposit::class),
        $c->make(App\Models\EmailQueue::class),
        $c->make(App\Models\KYCVerification::class),
        $c->make(App\Models\SecurityModel::class),
        $c->make(App\Models\Transaction::class),
        $c->make(App\Models\User::class),
        $c->make(App\Models\CustomTaskSubmission::class),
        $c->make(App\Models\CustomTask::class),
        $c->make(App\Services\WalletService::class),
        $c->make(\Core\Logger::class)
    );
});

// Controllers

$container->singleton(ContentService::class, function($c) {
    return new ContentService(
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(App\Models\ContentSubmission::class),
        $c->make(App\Models\ContentRevenue::class),
        $c->make(App\Models\ContentAgreement::class),
        $c->make(\Core\TransactionWrapper::class),
        $c->make(\Core\EventDispatcher::class),
        $c->make(Logger::class)
    );
});

$container->singleton(\App\Services\InvestmentService::class, function($c) {
    return new \App\Services\InvestmentService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Models\Investment::class),
        $c->make(\App\Models\TradingRecord::class),
        $c->make(\App\Models\InvestmentProfit::class),
        $c->make(\App\Models\InvestmentWithdrawal::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\Queue::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Services\PerformanceOptimizationService::class)
    );
});

$container->singleton(LotteryService::class, function($c) {
    return new LotteryService(
        $c->make(Database::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(\App\Models\LotteryRound::class),
        $c->make(\App\Models\LotteryParticipation::class),
        $c->make(\App\Models\LotteryDailyNumber::class),
        $c->make(\App\Models\LotteryVote::class),
        $c->make(\App\Models\LotteryChanceLog::class),
        $c->make(\App\Services\FeatureFlagService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(ManualDepositService::class, function($c) {
    return new ManualDepositService(
        $c->make(Database::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(\App\Models\ManualDeposit::class),
        $c->make(\App\Models\BankCard::class),
        $c->make(\App\Models\User::class),
        $c->make(AuditTrail::class),
        $c->make(Logger::class)
    );
});

// CryptoDepositService singleton is already registered above with full dependencies

$container->singleton(\App\Services\Payment\PaymentGatewayFactory::class, function($c) {
    return new \App\Services\Payment\PaymentGatewayFactory(
        $c->make(\App\Contracts\LoggerInterface::class),
        [
            'zarinpal' => $c->make(\App\Services\Payment\ZarinPalGateway::class),
            'nextpay' => $c->make(\App\Services\Payment\NextPayGateway::class),
            'idpay' => $c->make(\App\Services\Payment\IDPayGateway::class),
            'dgpay' => $c->make(\App\Services\Payment\DgPayGateway::class),
        ]
    );
});

$container->singleton(\App\Services\Payment\PaymentService::class, function($c) {
    return new \App\Services\Payment\PaymentService(
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\App\Contracts\NotificationServiceInterface::class),
        $c->make(\App\Models\PaymentLog::class),
        $c->make(\App\Models\BankCard::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\IdempotencyKey::class),
        $c->make(\App\Services\Payment\PaymentGatewayFactory::class),
        $c->make(\App\Services\CurrencyService::class),
        $c->make(\App\Services\ReconciliationService::class)
    );
});



$container->singleton(WithdrawalService::class);






$container->singleton(InfluencerService::class, function($c) {
    return new InfluencerService(
        $c->make(Database::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(\App\Services\Shared\ReferralService::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\InfluencerModel::class),
        $c->make(\App\Models\StoryOrder::class),
        $c->make(\App\Services\InfluencerReputationService::class)
    );
});

$container->singleton(\App\Services\InfluencerReputationService::class, function($c) {
    return new \App\Services\InfluencerReputationService(
        $c->make(Database::class),
        $c->make(\App\Models\InfluencerReputation::class),
        $c->make(\App\Models\InfluencerModel::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\Core\Logger::class)
    );
});





$container->singleton(KYCService::class);
$container->singleton(BannerService::class);


$container->singleton(TwoFactorService::class, function($c) {
    return new TwoFactorService(
        $c->make(User::class),
        $c->make(\App\Models\SecurityModel::class),
        $c->make(Session::class),
        $c->make(\Core\Logger::class)
    );
});


$container->singleton(SEOTaskService::class, function($c) {
    return new SEOTaskService($c->make(WalletService::class));
});


$container->singleton(\App\Services\User\UserDashboardService::class, function($c) {
    return new \App\Services\User\UserDashboardService(
        $c->make(Database::class)
    );
});


// ─── Auto-generated Model Bindings ─────────────────────────────────────


$container->singleton(App\Models\BankCard::class, function($c) {
    return new App\Models\BankCard($c->make(Database::class));
});



$container->singleton(App\Models\BannerPlacement::class, function($c) {
    return new App\Models\BannerPlacement($c->make(Database::class));
});





$container->singleton(App\Models\ContentAgreement::class, function($c) {
    return new App\Models\ContentAgreement($c->make(Database::class));
});

$container->singleton(App\Models\ContentRevenue::class, function($c) {
    return new App\Models\ContentRevenue($c->make(Database::class));
});

$container->singleton(App\Models\ContentSubmission::class, function($c) {
    return new App\Models\ContentSubmission($c->make(Database::class));
});

$container->singleton(App\Models\CryptoDeposit::class, function($c) {
    return new App\Models\CryptoDeposit($c->make(Database::class));
});

$container->singleton(App\Models\CryptoDepositIntent::class, function($c) {
    return new App\Models\CryptoDepositIntent($c->make(Database::class));
});


$container->singleton(App\Models\EmailQueue::class, function($c) {
    return new App\Models\EmailQueue($c->make(Database::class));
});

$container->singleton(App\Models\BackupLog::class, function($c) {
    return new App\Models\BackupLog($c->make(Database::class));
});

$container->singleton(App\Models\ContactMessage::class, function($c) {
    return new App\Models\ContactMessage($c->make(Database::class));
});

$container->singleton(App\Models\CaptchaLog::class, function($c) {
    return new App\Models\CaptchaLog($c->make(Database::class));
});

$container->singleton(App\Models\BulkOperation::class, function($c) {
    return new App\Models\BulkOperation($c->make(Database::class));
});

$container->singleton(App\Models\Ads::class, function($c) {
    return new App\Models\Ads($c->make(Database::class));
});

$container->singleton(App\Models\CronJob::class, function($c) {
    return new App\Models\CronJob($c->make(Database::class));
});



$container->singleton(App\Models\InvestmentProfit::class, function($c) {
    return new App\Models\InvestmentProfit($c->make(Database::class));
});

$container->singleton(App\Models\InvestmentWithdrawal::class, function($c) {
    return new App\Models\InvestmentWithdrawal($c->make(Database::class));
});

$container->singleton(App\Models\KYCVerification::class, function($c) {
    return new App\Models\KYCVerification($c->make(Database::class));
});

$container->singleton(App\Models\DirectMessage::class, function($c) {
    return new App\Models\DirectMessage($c->make(Database::class));
});

$container->singleton(App\Models\FileAccess::class, function($c) {
    return new App\Models\FileAccess($c->make(Database::class));
});



$container->singleton(App\Models\LotteryDailyNumber::class, function($c) {
    return new App\Models\LotteryDailyNumber($c->make(Database::class));
});

$container->singleton(App\Models\LotteryParticipation::class, function($c) {
    return new App\Models\LotteryParticipation($c->make(Database::class));
});

$container->singleton(App\Models\LotteryVote::class, function($c) {
    return new App\Models\LotteryVote($c->make(Database::class));
});

$container->singleton(App\Models\ManualDeposit::class, function($c) {
    return new App\Models\ManualDeposit($c->make(Database::class));
});

$container->singleton(App\Models\NotificationPreference::class, function($c) {
    return new App\Models\NotificationPreference($c->make(Database::class));
});

$container->singleton(App\Models\Page::class, function($c) {
    return new App\Models\Page($c->make(Database::class));
});


$container->singleton(App\Models\SeoExecution::class, function($c) {
    return new App\Models\SeoExecution($c->make(Database::class));
});



$container->singleton(App\Models\StoryOrder::class, function($c) {
    return new App\Models\StoryOrder($c->make(Database::class));
});





$container->singleton(App\Models\Ticket::class, function($c) {
    return new App\Models\Ticket($c->make(Database::class));
});

$container->singleton(App\Models\TicketCategory::class, function($c) {
    return new App\Models\TicketCategory($c->make(Database::class));
});

$container->singleton(App\Models\TicketMessage::class, function($c) {
    return new App\Models\TicketMessage($c->make(Database::class));
});

$container->singleton(App\Models\TradingRecord::class, function($c) {
    return new App\Models\TradingRecord($c->make(Database::class));
});



$container->singleton(App\Models\Withdrawal::class, function($c) {
    return new App\Models\Withdrawal($c->make(Database::class));
});

$container->singleton(App\Models\WithdrawalLimit::class, function($c) {
    return new App\Models\WithdrawalLimit($c->make(Database::class));
});

// ─── Shared Shared Models ─────────────────────────────────────────────
$container->singleton(\App\Models\Dispute::class, function($c) {
    return new \App\Models\Dispute($c->make(Database::class));
});

$container->singleton(\App\Models\InfluencerModel::class, function($c) {
    return new \App\Models\InfluencerModel($c->make(Database::class));
});

$container->singleton(\App\Models\InfluencerReputation::class, function($c) {
    return new \App\Models\InfluencerReputation($c->make(Database::class));
});

$container->singleton(\App\Models\InfluencerVerification::class, function($c) {
    return new \App\Models\InfluencerVerification($c->make(Database::class));
});

$container->singleton(\App\Models\Score::class, function($c) {
    return new \App\Models\Score($c->make(Database::class));
});

$container->singleton(\App\Models\Rating::class, function($c) {
    return new \App\Models\Rating($c->make(Database::class));
});

$container->singleton(\App\Models\Coupon::class, function($c) {
    return new \App\Models\Coupon($c->make(Database::class));
});

$container->singleton(\App\Models\CouponRedemption::class, function($c) {
    return new \App\Models\CouponRedemption($c->make(Database::class));
});

$container->singleton(\App\Models\ReferralCommission::class, function($c) {
    return new \App\Models\ReferralCommission($c->make(Database::class));
});

$container->singleton(\App\Models\Escrow::class, function($c) {
    return new \App\Models\Escrow($c->make(Database::class));
});

$container->singleton(\App\Models\LedgerEntry::class, function($c) {
    return new \App\Models\LedgerEntry($c->make(Database::class));
});



$container->singleton(App\Services\BankCardService::class);




$container->singleton(App\Services\ExportService::class, function($c) {
    return new App\Services\ExportService(
        $c->make(\App\Models\ExportData::class)
    );
});

$container->singleton(\App\Services\AdvancedSearchService::class, function($c) {
    return new \App\Services\AdvancedSearchService(
        $c->make(\App\Models\AdvancedSearch::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class)
    );
});

$container->singleton(\App\Services\AntiFraud\FraudDetectionService::class, function($c) {
    return new \App\Services\AntiFraud\FraudDetectionService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\Core\Logger::class)
    );
});





$container->singleton(\App\Services\DirectMessageService::class, function($c) {
    return new \App\Services\DirectMessageService(
        $c->make(\App\Models\DirectMessage::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Redis::class)
    );
});







$container->singleton(\App\Services\Auth\SessionService::class, function($c) {
    return new \App\Services\Auth\SessionService(
        $c->make(\App\Models\SecurityModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\App\Services\DistributedLockService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(App\Services\SitemapService::class, function($c) {
    return new App\Services\SitemapService(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\Page::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(App\Services\SocialAccountService::class, function($c) {
    return new App\Services\SocialAccountService(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\SocialAccount::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\Notification::class)
    );
});

$container->singleton(\App\Services\User\UserService::class, function($c) {
    return new \App\Services\User\UserService(
        $c->make(\App\Models\User::class),
        $c->make(\Core\Logger::class)
    );
});






$container->singleton(\App\Services\AntiFraud\IPQualityService::class, function($c) {
    return new \App\Services\AntiFraud\IPQualityService(
        $c->make(\App\Models\IpAndDeviceModel::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\AntiFraud\FraudManagementService::class, function($c) {
    return new \App\Services\AntiFraud\FraudManagementService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\App\Services\AntiFraud\IPQualityService::class),
        $c->make(\App\Services\AntiFraud\BrowserFingerprintService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\AntiFraud\SeoFraudDetector::class, function($c) {
    return new \App\Services\AntiFraud\SeoFraudDetector(
        $c->make(\App\Services\AntiFraud\BrowserFingerprintService::class),
        $c->make(\App\Services\AntiFraud\SessionAnomalyService::class),
        $c->make(\App\Models\SeoExecution::class),
        $c->make(\App\Models\IpAndDeviceModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\MLFraudDetectionService::class, function($c) {
    return new \App\Services\AntiFraud\MLFraudDetectionService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\BrowserFingerprintService::class, function($c) {
    return new \App\Services\AntiFraud\BrowserFingerprintService(
        $c->make(\App\Models\IpAndDeviceModel::class),
        $c->make(\Core\Logger::class)
    );
});


$container->singleton(\App\Services\AntiFraud\BehavioralBiometricsService::class, function($c) {
    return new \App\Services\AntiFraud\BehavioralBiometricsService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\Core\Logger::class)
    );
});



$container->singleton(\App\Services\AntiFraud\GraphAnalysisService::class, function($c) {
    return new \App\Services\AntiFraud\GraphAnalysisService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\GeolocationIntelligenceService::class, function($c) {
    return new \App\Services\AntiFraud\GeolocationIntelligenceService(
        $c->make(\App\Models\IpAndDeviceModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\FraudDashboardService::class, function($c) {
    return new \App\Services\AntiFraud\FraudDashboardService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\EmailPhoneIntelligenceService::class, function($c) {
    return new \App\Services\AntiFraud\EmailPhoneIntelligenceService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\DeviceIntelligenceService::class, function($c) {
    return new \App\Services\AntiFraud\DeviceIntelligenceService(
        $c->make(\App\Models\IpAndDeviceModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\AccountTakeoverService::class, function($c) {
    return new \App\Services\AntiFraud\AccountTakeoverService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\App\Services\AntiFraud\SessionAnomalyService::class),
        $c->make(\App\Services\AntiFraud\IPQualityService::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\App\Services\AntiFraud\BrowserFingerprintService::class),
        $c->make(\Core\Logger::class)
    );
});


// ─── FeatureFlagService ───────────────────────────────────────────────────
$container->singleton(\App\Services\FeatureFlagService::class, function($c) {
    return new \App\Services\FeatureFlagService(
        $c->make(\App\Models\FeatureFlag::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\KYCVerification::class),
        $c->make(Database::class),
        $c->make(\Core\Cache::class),
        $c->make(\Core\EventDispatcher::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

// ─── Core Services ────────────────────────────────────────────────────────
$container->singleton(\Core\RateLimiter::class, function($c) {
    return new \Core\RateLimiter();
});

$container->singleton(\Core\RetryPolicy::class, function($c) {
    return new \Core\RetryPolicy();
});

$container->singleton(\Core\Cache::class, function($c) {
    return \Core\Cache::getInstance();
});

$container->singleton(\Core\CircuitBreaker::class, function($c) {
    return new \Core\CircuitBreaker(
        $c->make(\Core\Cache::class)
    );
});

$container->singleton(\Core\TransactionWrapper::class, function($c) {
    return new \Core\TransactionWrapper(
        $c->make(Database::class)
    );
});

$container->singleton(\Core\Queue::class, function($c) {
    return new \Core\Queue(
        $c->make(Database::class)
    );
});

$container->singleton(\Core\EventDispatcher::class, function($c) {
    return new \Core\EventDispatcher(
        $c->make(\Core\Queue::class)
    );
});

// ─── CLI Core framework ────────────────────────────────────────────────────
$container->singleton(\Core\Console\CliDispatcher::class, function($c) {
    $dispatcher = new \Core\Console\CliDispatcher();
    
    // ✅ ثبت مرکزی دستورات خط فرمان به جای Switch-Case های پراکنده
    $dispatcher->register('feature:*', \App\Commands\FeatureFlagCommand::class, 'Feature Flag Management');
    // $dispatcher->register('user:ban', \App\Commands\UserBanCommand::class, 'Manage user bans'); // Example future registry

    return $dispatcher;
});

$container->singleton('event.bootstrap', function($c) {
    $dispatcher = $c->make(\Core\EventDispatcher::class);

    $dispatcher->listen('content.submitted', function($event) {
        if (function_exists('logger')) {
            logger()->info('content.submitted.event', [
                'submission_id' => $event->getData()['submission_id'] ?? null,
                'user_id' => $event->getData()['user_id'] ?? null,
                'platform' => $event->getData()['platform'] ?? null,
            ]);
        }
    });

    $dispatcher->listen('content.approved', function($event) {
        if (function_exists('logger')) {
            logger()->info('content.approved.event', [
                'submission_id' => $event->getData()['submission_id'] ?? null,
                'approved_by' => $event->getData()['approved_by'] ?? null,
            ]);
        }
    });

    // 🚨 Listener برای رهگیری عبور از حد مجاز (حفاظت DoS در برابر حمله)
    $dispatcher->listen('rate_limit.exceeded', function($event) use ($c) {
        $data = $event->getData();
        $key = $data['key'] ?? '';
        
        // اگر کلید مربوط به API کاربر باشد (فرمت api:{userId})
        if (strpos($key, 'api:') === 0) {
            $userId = (int) substr($key, 4);
            if ($userId > 0) {
                try {
                    // افزایش ناهمگام و اتمیک امتیاز فراد برای رصد فعالیت‌های مخرب مداوم
                    $scoreService = $c->make(\App\Services\User\UserScoreService::class);
                    $scoreService->incrementFraudRawScore($userId, 0.5, 'rate_limit_flood', [
                        'strategy' => $data['strategy'] ?? 'unknown',
                        'ip' => $data['ip'] ?? 'unknown'
                    ]);
                } catch (\Throwable $ignore) {}
            }
        }
    });

    return $dispatcher;
});

// Boot event listeners immediately so closures are registered.
$container->make('event.bootstrap');

// ─── DashboardQueryService (with Performance tracking) ─────────────────────────
$container->singleton(\App\Services\AdminDashboard\DashboardQueryService::class);

// ─── AdminDashboardService ────────────────────────────────────────────────────


// ─── BulkOperationsService ────────────────────────────────────────────────────
$container->singleton(\App\Services\BulkOperationsService::class, function($c) {
    return new \App\Services\BulkOperationsService(
        $c->make(Logger::class),
        $c->make(Database::class),
        $c->make(\App\Models\BulkOperation::class),
        $c->make(\App\Contracts\CacheInterface::class),
        $c->make(NotificationService::class)
    );
});

// ─── VitrineService ───────────────────────────────────────────────────────────
$container->singleton(\App\Services\VitrineService::class);

$container->singleton(\App\Services\User\UserScoreService::class);

// ─── Anti-Fraud Domain ────────────────────────────────────────────────────

$container->singleton(\App\Services\AntiFraud\RiskPolicyService::class);
$container->singleton(\App\Services\AntiFraud\RiskDecisionService::class);
$container->singleton(\App\Services\AntiFraud\VelocityCheckService::class, function($c) {
    return new \App\Services\AntiFraud\VelocityCheckService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class)
    );
});
$container->singleton(\App\Services\SocialTask\SocialTaskScoringService::class);
$container->singleton(\App\Services\AntiFraud\TorListUpdater::class, function($c) {
    return new \App\Services\AntiFraud\TorListUpdater(
        $c->make(\App\Models\IpAndDeviceModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\SessionAnomalyService::class, function($c) {
    return new \App\Services\AntiFraud\SessionAnomalyService(
        $c->make(\App\Models\SecurityModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class)
    );
});

// GeoIPService already registered above

$container->singleton(\App\Services\AntiFraud\VideoFingerprintService::class, function($c) {
    return new \App\Services\AntiFraud\VideoFingerprintService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Cache::class)
    );
});

$container->singleton(\App\Services\ApiRateLimiter::class, function($c) {
    return new \App\Services\ApiRateLimiter(
        $c->make(\App\Policies\RateLimitPolicy::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\SocialTask\TrustScoreService::class, function($c) {
    return new \App\Services\SocialTask\TrustScoreService($c->make(\App\Services\Shared\ScoreService::class));
});

$container->singleton(\App\Services\SocialTask\SilentAntiFraudService::class, function($c) {
    return new \App\Services\SocialTask\SilentAntiFraudService(
        $c->make(\App\Models\SocialTaskExecutionModel::class),
        $c->make(\App\Services\AntiFraud\IPQualityService::class),
        $c->make(\App\Services\AntiFraud\BrowserFingerprintService::class),
        $c->make(\App\Services\AntiFraud\SessionAnomalyService::class),
        $c->make(\App\Services\SocialTask\TrustScoreService::class),
        $c->make(\App\Services\SocialTask\SocialTaskScoringService::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Contracts\LoggerInterface::class)  // ✅ Logger اضافه شد
    );
});

// Duplicate SocialTaskService binding removed. Replaced by singleton registration earlier.

$container->singleton(\App\Services\SocialTask\RatingService::class, function($c) {
    return new \App\Services\SocialTask\RatingService(
        $c->make(\App\Services\Shared\RatingService::class),
        $c->make(\App\Models\SocialTaskModel::class),
        $c->make(\App\Services\Shared\ScoreService::class)
    );
});
 




// ─── Phase 3 Services ─────────────────────────────────────────────────────
// ✅ Real-time, Caching, Verification, Performance Optimization

$container->singleton(\App\Services\WebSocketService::class, function($c) {
    return new \App\Services\WebSocketService(
        $c->make(\Core\Redis::class),
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\VerificationService::class, function($c) {
    return new \App\Services\VerificationService(
        $c->make(\App\Models\InfluencerModel::class),
        $c->make(\App\Models\InfluencerVerification::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\PerformanceOptimizationService::class, function($c) {
    return new \App\Services\PerformanceOptimizationService(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});



// ─── Phase 5e: Advanced Settings & Management ─────────────────────────────
$container->singleton(\App\Models\UserSetting::class, function($c) {
    return new \App\Models\UserSetting($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\DataExport::class, function($c) {
    return new \App\Models\DataExport($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\AccountDeletionLog::class, function($c) {
    return new \App\Models\AccountDeletionLog($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\SettingsAuditTrail::class, function($c) {
    return new \App\Models\SettingsAuditTrail($c->make(\Core\Database::class));
});

$container->singleton(\App\Services\DataExportService::class, function($c) {
    return new \App\Services\DataExportService(
        $c->make(\App\Models\DataExport::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\Transaction::class),
        $c->make(\App\Models\Wallet::class),
        $c->make(\App\Models\KYCVerification::class),
        $c->make(\App\Models\UserSetting::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class)
    );
});





// ─── BackupService (Phase 5e) ─────────────────────────────────────────────
$container->singleton(\App\Services\BackupService::class, function($c) {
    return new \App\Services\BackupService(
        $c->make(\App\Models\BackupLog::class),
        $c->make(\Core\Logger::class)
    );
});



// ═════════════════════════════════════════════════════════════════
// ─── Contracts Bindings برای DI و تست‌پذیری ────────────────────
// ═════════════════════════════════════════════════════════════════

// Cache Interface
$container->singleton(\App\Contracts\CacheInterface::class, function($c) {
    return new \App\Services\Cache\CacheManager(
        \Core\Cache::getInstance(),
        $c->make(\Core\Logger::class)
    );
});

// Rate Limiter Interface
$container->singleton(\App\Contracts\RateLimiterInterface::class, function($c) {
    return $c->make(\Core\RateLimiter::class);
});

// Feature Flag Repository Interface
$container->singleton(\App\Contracts\FeatureFlagRepositoryInterface::class, function($c) {
    return $c->make(\App\Models\FeatureFlagUltimate::class);
});

// ─── Policies ─────────────────────────────────────────────────────
$container->singleton(\App\Policies\FeatureFlagPolicy::class, function($c) {
    return new \App\Policies\FeatureFlagPolicy();
});


// ---------------------------------------------------------------------
// AdSystemManager ? Adapter?? (Unified Ad Service - Sprint 1)
// ---------------------------------------------------------------------

// Adapter??
$container->singleton(\App\Services\Adapters\CustomTaskAdapter::class, function($c) {
    return new \App\Services\Adapters\CustomTaskAdapter(
        $c->make(\App\Models\CustomTask::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class)
    );
});

$container->singleton(\App\Services\Adapters\SeoAdAdapter::class, function($c) {
    return new \App\Services\Adapters\SeoAdAdapter(
        $c->make(\App\Models\SeoAd::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class)
    );
});

$container->singleton(\App\Services\Adapters\BannerAdapter::class, function($c) {
    return new \App\Services\Adapters\BannerAdapter(
        $c->make(\App\Models\Ads::class), // ارتقا به مدل متمرکز
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class), // آرگومان گمشده ۱
        $c->make(\App\Services\SettingService::class)   // آرگومان گمشده ۲
    );
});

$container->singleton(\App\Services\Adapters\VitrineAdapter::class, function($c) {
    return new \App\Services\Adapters\VitrineAdapter(
        $c->make(\App\Models\VitrineListing::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class)
    );
});



$container->singleton(\App\Services\Adapters\AdTubeAdapter::class, function($c) {
    return new \App\Services\Adapters\AdTubeAdapter(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\Adapters\AdSocialAdapter::class, function($c) {
    return new \App\Services\Adapters\AdSocialAdapter(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\Adapters\NotificationAdAdapter::class, function($c) {
    return new \App\Services\Adapters\NotificationAdAdapter(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

// AdSystemManager
$container->singleton(\App\Services\AdSystemManager::class, function($c) {
    return new \App\Services\AdSystemManager([
        'custom_task' => $c->make(\App\Services\Adapters\CustomTaskAdapter::class),
        'seo' => $c->make(\App\Services\Adapters\SeoAdAdapter::class),
        'banner' => $c->make(\App\Services\Adapters\BannerAdapter::class),
        'vitrine' => $c->make(\App\Services\Adapters\VitrineAdapter::class),

        'adtube' => $c->make(\App\Services\Adapters\AdTubeAdapter::class),
        'social_task' => $c->make(\App\Services\Adapters\AdSocialAdapter::class),
        'notification' => $c->make(\App\Services\Adapters\NotificationAdAdapter::class),
    ], $c->make(\App\Contracts\LoggerInterface::class));
});

// ---------------------------------------------------------------------
// Transaction Reversal & Reconciliation Services (Sprint 2-3)
// ---------------------------------------------------------------------


$container->singleton(\App\Services\ReconciliationService::class, function($c) {
    return new \App\Services\ReconciliationService(
        $c->make(\App\Models\Transaction::class),
        $c->make(\App\Models\LedgerEntry::class),
        $c->make(\App\Models\Wallet::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\LedgerService::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});

// Old ReferralService (@App\Services) is deprecated
// Use Shared\ReferralService instead

// PolicyService has been moved to Shared\PolicyService

// Legacy binding removed - see Shared\PolicyService instead


// ---------------------------------------------------------------------
// Sprint 6: Upload Enforcement (already exists in app.php)
// ---------------------------------------------------------------------


// ─── Shared Services ───────────────────────────────────────────────────
$container->singleton(\App\Services\Shared\DisputeService::class, function($c) {
    return new \App\Services\Shared\DisputeService(
        $c->make(Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Models\Dispute::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\App\Services\ReconciliationService::class)
    );
});

$container->singleton(\App\Services\Shared\ScoreService::class);

$container->singleton(\App\Services\Shared\RatingService::class, function($c) {
    return new \App\Services\Shared\RatingService(
        $c->make(Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\Rating::class)
    );
});

$container->singleton(\App\Services\Shared\AnalyticsService::class, function($c) {
    return new \App\Services\Shared\AnalyticsService(
        $c->make(Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Services\ReferralAnalyticsService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Models\AdvancedAnalytics::class),
        $c->make(\App\Services\Analytics\AnalyticsService::class)
    );
});


$container->singleton(\App\Services\Shared\ReferralService::class, function($c) {
    return new \App\Services\Shared\ReferralService(
        $c->make(Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Models\ReferralCommission::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\Shared\CouponService::class, function($c) {
    return new \App\Services\Shared\CouponService(
        $c->make(\App\Models\Coupon::class),
        $c->make(\App\Models\CouponRedemption::class)
    );
});

$container->singleton(\App\Services\Shared\FinancialService::class, function($c) {
    return new \App\Services\Shared\FinancialService(
        $c->make(Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\Escrow::class),
        $c->make(\App\Models\LedgerEntry::class)
    );
});

$container->singleton(\App\Services\Shared\PolicyService::class, function($c) {
    return new \App\Services\Shared\PolicyService(
        $c->make(Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\Role::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});


// See: SERVICES_AUDIT_INCOMPLETE.md for details

// --- MISSING SERVICES AUTO-REGISTERED ---
// Missing bindings to append to bootstrap/app.php

$container->singleton(\App\Services\ApiTokenService::class, function($c) {
    return new \App\Services\ApiTokenService(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\ApiToken::class),
        $c->make(\App\Models\User::class),
        $c->make(\Core\RateLimiter::class)
    );
});

$container->singleton(\App\Services\BannerService::class, function($c) {
    return new \App\Services\BannerService(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Models\BannerPlacement::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\UploadService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\CacheAdminService::class, function($c) {
    return new \App\Services\CacheAdminService(
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\CaptchaService::class, function($c) {
    return new \App\Services\CaptchaService(
        $c->make(\App\Models\CaptchaLog::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\Core\Session::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\ContactService::class, function($c) {
    return new \App\Services\ContactService(
        $c->make(\App\Models\ContactMessage::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\ContentService::class, function($c) {
    return new \App\Services\ContentService(
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Models\ContentSubmission::class),
        $c->make(\App\Models\ContentRevenue::class),
        $c->make(\App\Models\ContentAgreement::class),
        $c->make(\Core\TransactionWrapper::class),
        $c->make(\Core\EventDispatcher::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\CurrencyService::class, function($c) {
    return new \App\Services\CurrencyService(
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\Request::class)
    );
});

$container->singleton(\App\Services\FeatureFlagViewHelper::class, function($c) {
    return new \App\Services\FeatureFlagViewHelper(
        $c->make(\App\Services\FeatureFlagService::class)
    );
});

$container->singleton(\App\Services\FileAccessService::class, function($c) {
    return new \App\Services\FileAccessService(
        $c->make(\App\Models\FileAccess::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\InfluencerService::class, function($c) {
    return new \App\Services\InfluencerService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Services\ReferralCommissionService::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Models\InfluencerModel::class),
        $c->make(\App\Models\StoryOrder::class),
        $c->make(\App\Services\InfluencerReputationService::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Services\Shared\RatingService::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\XPEngine::class)
    );
});

$container->singleton(\App\Services\KYCService::class, function($c) {
    return new \App\Services\KYCService(
        $c->make(\App\Models\KYCVerification::class),
        $c->make(\App\Models\User::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Services\UploadService::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Services\Adapters\KycFaceVerificationAdapter::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Notification\NotificationService::class)
    );
});

$container->singleton(\App\Services\LedgerService::class, function($c) {
    return new \App\Services\LedgerService(
        $c->make(\App\Models\LedgerEntry::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\LotteryService::class, function($c) {
    return new \App\Services\LotteryService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Models\LotteryRound::class),
        $c->make(\App\Models\LotteryParticipation::class),
        $c->make(\App\Models\LotteryDailyNumber::class),
        $c->make(\App\Models\LotteryVote::class),
        $c->make(\App\Models\LotteryChanceLog::class),
        $c->make(\App\Services\FeatureFlagService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\ManualDepositService::class, function($c) {
    return new \App\Services\ManualDepositService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Models\ManualDeposit::class),
        $c->make(\App\Models\BankCard::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\ReconciliationService::class)
    );
});

$container->singleton(\App\Services\MessageModerationService::class, function($c) {
    return new \App\Services\MessageModerationService(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\InteractionModel::class),
        $c->make(\App\Models\MessageModerationModel::class)
    );
});

$container->singleton(\App\Services\MigrationManager::class, function($c) {
    return new \App\Services\MigrationManager(
        $c->make(\Core\Database::class)
    );
});

$container->singleton(\App\Services\PredictionService::class, function($c) {
    return new \App\Services\PredictionService(
        $c->make(\App\Services\private::class),
        $c->make(\App\Services\private::class),
        $c->make(\App\Services\private::class),
        $c->make(\App\Services\private::class)
    );
});

$container->singleton(\App\Services\ReferralManagementService::class, function($c) {
    return new \App\Services\ReferralManagementService(
        $c->make(\App\Services\private::class),
        $c->make(\App\Services\private::class),
        $c->make(\App\Services\private::class),
        $c->make(\App\Services\private::class)
    );
});

$container->singleton(\App\Services\RolePolicy::class, function($c) {
    return new \App\Services\RolePolicy(
    );
});

$container->singleton(\App\Services\ScheduledPaymentService::class, function($c) {
    return new \App\Services\ScheduledPaymentService(
        $c->make(\App\Services\private::class),
        $c->make(\App\Services\private::class),
        $c->make(\App\Services\private::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\private::class)
    );
});

$container->singleton(\App\Services\SeoPayoutService::class, function($c) {
    return new \App\Services\SeoPayoutService(
        $c->make(\App\Models\Ads::class)
    );
});

$container->singleton(\App\Services\SeoService::class, function($c) {
    return new \App\Services\SeoService(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Models\SeoExecution::class),
        $c->make(\App\Services\User\UserScoreService::class),
        $c->make(\App\Services\SeoPayoutService::class),
        $c->make(\App\Services\AntiFraud\SeoFraudDetector::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Shared\ReferralService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Services\Shared\RatingService::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\TicketService::class, function($c) {
    return new \App\Services\TicketService(
        $c->make(\App\Models\Ticket::class),
        $c->make(\App\Models\TicketMessage::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Notification\NotificationService::class)
    );
});

$container->singleton(\App\Services\UploadService::class, function($c) {
    return new \App\Services\UploadService(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\VitrineSettingsService::class, function($c) {
    return new \App\Services\VitrineSettingsService(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Models\FeatureFlag::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\WithdrawalService::class, function($c) {
    return new \App\Services\WithdrawalService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Models\Withdrawal::class),
        $c->make(\App\Models\WithdrawalLimit::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Models\BankCard::class),
        $c->make(\App\Services\BankCardService::class),
        $c->make(\App\Services\AntiFraud\RiskDecisionService::class),
        $c->make(\App\Services\KYCService::class),
        $c->make(\App\Models\Transaction::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\PerformanceOptimizationService::class),
        $c->make(\App\Services\StateMachineService::class),
        $c->make(\App\Services\ReconciliationService::class)
    );
});

$container->singleton(\App\Services\Adapters\CryptoExplorerAdapter::class, function($c) {
    return new \App\Services\Adapters\CryptoExplorerAdapter(
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Adapters\DeepFaceKycAdapter::class, function($c) {
    return new \App\Services\Adapters\DeepFaceKycAdapter(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\Database::class)
    );
});

$container->singleton(\App\Services\Adapters\JibitInquiryAdapter::class, function($c) {
    return new \App\Services\Adapters\JibitInquiryAdapter(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\Cache::class)
    );
});

$container->singleton(\App\Services\AdminDashboard\AdminDashboardService::class, function($c) {
    return new \App\Services\AdminDashboard\AdminDashboardService(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\AdminDashboard\DashboardQueryService::class),
        $c->make(\App\Services\AdminDashboard\SystemMonitoringService::class)
    );
});

$container->singleton(\App\Services\AdminDashboard\SystemMonitoringService::class, function($c) {
    return new \App\Services\AdminDashboard\SystemMonitoringService(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Analytics\AnalyticsDataRepository::class, function($c) {
    return new \App\Services\Analytics\AnalyticsDataRepository(
        $c->make(\App\Services\Analytics\private::class),
        $c->make(\App\Services\Analytics\private::class),
        $c->make(\App\Services\Analytics\private::class),
        $c->make(\App\Services\Analytics\private::class),
        $c->make(\App\Services\Analytics\private::class),
        $c->make(\App\Services\Analytics\private::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Analytics\int::class)
    );
});

$container->singleton(\App\Services\Analytics\AnalyticsExporter::class, function($c) {
    return new \App\Services\Analytics\AnalyticsExporter(
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\AntiFraud\RateLimitingService::class, function($c) {
    return new \App\Services\AntiFraud\RateLimitingService(
        $c->make(\App\Policies\RateLimitPolicy::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Auth\LoginRiskService::class, function($c) {
    return new \App\Services\Auth\LoginRiskService(
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Cache\CacheManager::class, function($c) {
    return new \App\Services\Cache\CacheManager(
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Notification\NotificationAnalyticsService::class, function($c) {
    return new \App\Services\Notification\NotificationAnalyticsService(
        $c->make(\App\Services\Notification\private::class),
        $c->make(\App\Services\Notification\private::class),
        $c->make(\App\Services\Notification\protected::class)
    );
});

$container->singleton(\App\Services\Notification\NotificationPreferenceService::class, function($c) {
    return new \App\Services\Notification\NotificationPreferenceService(
        $c->make(\App\Services\Notification\private::class),
        $c->make(\App\Services\Notification\protected::class)
    );
});

$container->singleton(\App\Services\Notification\NotificationTemplateService::class, function($c) {
    return new \App\Services\Notification\NotificationTemplateService(
        $c->make(\App\Services\Notification\private::class),
        $c->make(\App\Services\Notification\private::class),
        $c->make(\App\Services\Notification\protected::class)
    );
});

$container->singleton(\App\Services\Notification\NotificationTracker::class, function($c) {
    return new \App\Services\Notification\NotificationTracker(
        $c->make(\App\Services\Notification\private::class),
        $c->make(\App\Services\Notification\private::class),
        $c->make(\App\Services\Notification\protected::class)
    );
});

$container->singleton(\App\Services\Notification\SmsNotificationService::class, function($c) {
    return new \App\Services\Notification\SmsNotificationService(
        $c->make(\App\Services\Notification\private::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Payment\DgPayGateway::class, function($c) {
    return new \App\Services\Payment\DgPayGateway(
        $c->make(\App\Models\PaymentGateway::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\Payment\IDPayGateway::class, function($c) {
    return new \App\Services\Payment\IDPayGateway(
        $c->make(\App\Models\PaymentGateway::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\Payment\NextPayGateway::class, function($c) {
    return new \App\Services\Payment\NextPayGateway(
        $c->make(\App\Models\PaymentGateway::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\Payment\ZarinPalGateway::class, function($c) {
    return new \App\Services\Payment\ZarinPalGateway(
        $c->make(\App\Models\PaymentGateway::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Services\Sentry\Alerting\AlertRulesEngine::class, function($c) {
    return new \App\Services\Sentry\Alerting\AlertRulesEngine(
        $c->make(\App\Services\Sentry\Alerting\private::class),
        $c->make(\App\Services\Sentry\Alerting\private::class),
        $c->make(\App\Services\Sentry\Alerting\private::class)
    );
});

$container->singleton(\App\Services\Sentry\Alerting\EscalationManager::class, function($c) {
    return new \App\Services\Sentry\Alerting\EscalationManager(
        $c->make(\App\Services\Sentry\Alerting\private::class),
        $c->make(\App\Services\Sentry\Alerting\private::class),
        $c->make(\App\Services\Sentry\Alerting\private::class)
    );
});

$container->singleton(\App\Services\Sentry\Analytics\DashboardService::class, function($c) {
    return new \App\Services\Sentry\Analytics\DashboardService(
        $c->make(\App\Services\Sentry\Analytics\private::class)
    );
});

$container->singleton(\App\Services\Sentry\Analytics\TrendAnalyzer::class, function($c) {
    return new \App\Services\Sentry\Analytics\TrendAnalyzer(
        $c->make(\App\Services\Sentry\Analytics\private::class)
    );
});

$container->singleton(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class, function($c) {
    return new \App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor(
        $c->make(\App\Services\Sentry\ErrorMonitoring\private::class),
        $c->make(\App\Services\Sentry\ErrorMonitoring\private::class),
        $c->make(\App\Services\Sentry\ErrorMonitoring\private::class),
        $c->make(\App\Services\Sentry\ErrorMonitoring\private::class),
        $c->make(\App\Services\Sentry\ErrorMonitoring\array::class)
    );
});

$container->singleton(\App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor::class, function($c) {
    return new \App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor(
        $c->make(\App\Services\Sentry\PerformanceMonitoring\private::class),
        $c->make(\App\Services\Sentry\PerformanceMonitoring\private::class),
        $c->make(\App\Services\Sentry\PerformanceMonitoring\private::class),
        $c->make(\App\Services\Sentry\PerformanceMonitoring\array::class)
    );
});

$container->singleton(\App\Services\Sentry\Utils\BreadcrumbCollector::class, function($c) {
    return new \App\Services\Sentry\Utils\BreadcrumbCollector(
    );
});

$container->singleton(\App\Services\Sentry\Utils\ContextEnricher::class, function($c) {
    return new \App\Services\Sentry\Utils\ContextEnricher(
    );
});

$container->singleton(\App\Services\Sentry\Utils\StackTraceAnalyzer::class, function($c) {
    return new \App\Services\Sentry\Utils\StackTraceAnalyzer(
    );
});

$container->singleton(\App\Services\Shared\ScoreEventService::class, function($c) {
    return new \App\Services\Shared\ScoreEventService(
        $c->make(\App\Models\Score::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Shared\TrustScoreService::class, function($c) {
    return new \App\Services\Shared\TrustScoreService(
        $c->make(\App\Models\Score::class),
        $c->make(\App\Models\SocialTaskAnalyticsModel::class),
        $c->make(\App\Services\Shared\ScoreEventService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\User\AccountDeletionService::class, function($c) {
    return new \App\Services\User\AccountDeletionService(
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\AccountDeletionLog::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\CustomTaskService::class)
    );
});

$container->singleton(\App\Services\User\UserSettingsService::class, function($c) {
    return new \App\Services\User\UserSettingsService(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\User::class),
        $c->make(\Core\Cache::class)
    );
});


// Application — باید آخرین خط باشد
$app = Application::getInstance();

// Debug: Log registered bindings for troubleshooting
try {
    if (function_exists('logger')) {
        $container = \Core\Container::getInstance();
        $bindings = $container->getBindings();
        logger()->debug('bootstrap.bindings.registered', [
            'total_bindings' => count($bindings),
            'bindings' => $bindings,
        ]);
    }
} catch (\Throwable $ignore) {
    // Ignore during bootstrap
}

return $app;