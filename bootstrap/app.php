<?php

use Core\Container;
use Core\Application;
use Core\Session;
use Core\Database;
use Core\Logger;
use App\Models\User;
use App\Models\UserStatistics;
use App\Models\FinancialStatistics;
use App\Models\LogStatistics;
use App\Models\KpiStatistics;
use App\Models\TaskStatistics;
use App\Models\ExportData;
use App\Services\AuthService;
use App\Services\CaptchaService;
use App\Models\SystemSetting;
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
use App\Services\PaymentService;
use App\Services\StoryPromotionService;
use App\Services\KYCService;
use App\Services\BannerService;
use App\Services\Auth\TwoFactorService;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\AnalyticsDataRepository;
use App\Services\Analytics\AnalyticsExporter;
use App\Models\TaskExecution;
use App\Models\Transaction;
use App\Models\ReferralCommission;
use App\Models\Notification;
use App\Models\SocialAccount;
use App\Models\Investment;
use App\Models\LotteryRound;

use App\Models\CustomTask;
use App\Models\CustomTaskSubmission;
use App\Models\CustomTaskAnalytics;
use App\Models\AdvancedAnalytics;
use App\Models\Analytics;


// BASE_PATH
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/helpers/config_helper.php';

// Autoloader — vendor/autoload.php + PSR-4 (Core, App)
require_once BASE_PATH . '/core/Autoloader.php';
\Core\Autoloader::register();

// Helpers از طریق composer autoload (files section) لود می‌شوند
// نیازی به require_once دستی نیست

// ── بارگذاری .env ────────────────────────────────────────────
$envPath = BASE_PATH . '/.env';
$env = []; // مقداردهی اولیه
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if ($env === false) {
        $env = [];
        error_reporting(0);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_log('[Chortke] .env file is invalid or unreadable');
    } else {
        $appDebug = filter_var($env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($appDebug) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
        }
    }
} else {
    // بدون .env: خطاها فقط لاگ می‌شوند — هرگز نمایش داده نمی‌شوند
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
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

$container->singleton(\App\Controllers\Admin\VitrineController::class, function($c) {
    return new \App\Controllers\Admin\VitrineController(
        $c->make(\App\Models\VitrineListing::class),
        $c->make(\App\Models\VitrineRequest::class),
        $c->make(\App\Services\VitrineService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});

// =========================
// Sentry-like Services
// =========================

$container->singleton(\App\Services\Sentry\Alerting\AlertDispatcher::class, function($c) {
    return new \App\Services\Sentry\Alerting\AlertDispatcher(
        $c->make(\App\Models\SentryModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\Sentry\Alerting\AlertRulesEngine::class, function($c) {
    return new \App\Services\Sentry\Alerting\AlertRulesEngine(
        $c->make(\App\Models\SentryModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\Sentry\Alerting\AlertDispatcher::class)
    );
});

$container->singleton(\App\Services\Sentry\Alerting\EscalationManager::class, function($c) {
    return new \App\Services\Sentry\Alerting\EscalationManager(
        $c->make(\App\Models\SentryModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\Sentry\Alerting\AlertDispatcher::class)
    );
});

$container->singleton(\App\Services\Sentry\Analytics\DashboardService::class, function($c) {
    return new \App\Services\Sentry\Analytics\DashboardService(
        $c->make(\App\Models\SentryModel::class)
    );
});

$container->singleton(\App\Services\Sentry\Analytics\TrendAnalyzer::class, function($c) {
    return new \App\Services\Sentry\Analytics\TrendAnalyzer(
        $c->make(\App\Models\SentryModel::class)
    );
});

$container->singleton(\App\Controllers\Admin\ManualDepositController::class, function($c) {
    return new \App\Controllers\Admin\ManualDepositController(
        $c->make(\App\Models\UserBankCard::class),
        $c->make(\App\Services\User\UserService::class),
        $c->make(\App\Models\ManualDeposit::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\ManualDepositService::class),
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
        $c->make(\App\Services\Adapters\CryptoVerificationAdapter::class)
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
        $c->make(\App\Models\Setting::class)
    );
});

$container->singleton(\App\Controllers\User\WithdrawalController::class, function($c) {
    return new \App\Controllers\User\WithdrawalController(
        $c->make(\App\Models\Withdrawal::class),
        $c->make(\App\Models\UserBankCard::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\AntiFraud\RiskDecisionService::class),
        $c->make(\App\Services\WithdrawalService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Admin\CryptoDepositController::class, function($c) {
    return new \App\Controllers\Admin\CryptoDepositController(
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\CryptoDeposit::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\CryptoDeposit\CryptoDepositService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor::class, function($c) {
    return new \App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor(
        $c->make(\App\Models\SentryModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\Sentry\Alerting\AlertDispatcher::class),
        []
    );
});

$container->singleton(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class, function($c) {
    return new \App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor(
        $c->make(\App\Models\SentryModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\Sentry\Alerting\AlertDispatcher::class),
        $c->make(\App\Services\AuditTrail::class),
        []
    );
});

$container->singleton(\App\Models\SocialTaskModel::class, function($c) {
    return new \App\Models\SocialTaskModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\SocialTaskExecutionModel::class, function($c) {
    return new \App\Models\SocialTaskExecutionModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\SocialTaskAnalyticsModel::class, function($c) {
    return new \App\Models\SocialTaskAnalyticsModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Services\SocialTask\SocialTaskScoringService::class, function($c) {
    return new \App\Services\SocialTask\SocialTaskScoringService();
});

$container->singleton(\App\Services\Shared\ScoreService::class, function($c) {
    return new \App\Services\Shared\ScoreService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\Score::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\SocialTaskAnalyticsModel::class),
        $c->make(\App\Services\User\UserScoreService::class),
        $c->make(\App\Services\InfluencerReputationService::class)
    );
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

$container->singleton(\App\Services\SocialTask\RatingService::class, function($c) {
    return new \App\Services\SocialTask\RatingService(
        $c->make(\App\Services\Shared\RatingService::class),
        $c->make(\App\Models\SocialTaskModel::class),
        $c->make(\App\Services\Shared\ScoreService::class)
    );
});

$container->singleton(\App\Services\SocialTask\SocialTaskService::class, function($c) {
    return new \App\Services\SocialTask\SocialTaskService(
        $c->make(\App\Models\SocialTaskModel::class),
        $c->make(\App\Services\SocialTask\SocialTaskScoringService::class),
        $c->make(\App\Services\Shared\ScoreService::class),
        $c->make(\App\Services\SocialTask\SilentAntiFraudService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Services\ApiRateLimiter::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\FinancialEscrowService::class),
        $c->make(\App\Services\StateMachineService::class),
        $c->make(\App\Services\RealTimeService::class)
    );
});

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

$container->singleton(Database::class, function() {
    return Database::getInstance();
});


$container->singleton(\App\Services\AntiFraud\RiskPolicyService::class, function($c) {
    return new \App\Services\AntiFraud\RiskPolicyService(
        $c->make(\Core\Database::class)
    );
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

$container->singleton(\App\Services\RealTimeService::class, function($c) {
    return new \App\Services\RealTimeService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Redis::class)
    );
});

$container->singleton(App\Models\CustomTaskAnalytics::class, function($c) {
    return new App\Models\CustomTaskAnalytics($c->make(Database::class));
});

$container->singleton(App\Models\AdvancedAnalytics::class, function($c) {
    return new App\Models\AdvancedAnalytics($c->make(Database::class));
});

$container->singleton(App\Models\Appeal::class, function($c) {
    return new App\Models\Appeal($c->make(Database::class));
});

// ========== Analytics Services (Consolidated) ==========
$container->singleton(AnalyticsDataRepository::class, function($c) {
    return new AnalyticsDataRepository(
        $c->make(\App\Models\KpiStatistics::class),
        $c->make(\Core\Cache::class),
        $c->make(App\Models\CustomTaskModel::class),
        $c->make(App\Models\User::class),
        $c->make(App\Models\KYCVerification::class),
        $c->make(App\Models\Transaction::class)
    );
});

$container->singleton(AnalyticsExporter::class, function($c) {
    return new AnalyticsExporter();
});

$container->singleton(AnalyticsService::class, function($c) {
    return new AnalyticsService(
        $c->make(AnalyticsDataRepository::class),
        $c->make(AnalyticsExporter::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(SystemSetting::class, function($c) {
    return new SystemSetting($c->make(Database::class));
});

$container->singleton(\App\Models\SecurityModel::class, function($c) {
    return new \App\Models\SecurityModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\User::class, function($c) {
    return new \App\Models\User($c->make(\Core\Database::class));
});

$container->singleton(\App\Services\User\UserService::class, function($c) {
    return new \App\Services\User\UserService(
        $c->make(\App\Models\User::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\User\ProfileService::class, function($c) {
    return new \App\Services\User\ProfileService(
        $c->make(\App\Models\User::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class)
    );
});

$container->singleton(\App\Services\Auth\SessionService::class, function($c) {
    return new \App\Services\Auth\SessionService(
        $c->make(\App\Models\SecurityModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\Core\Logger::class)
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
        $c->make(SystemSetting::class),
        $c->make(Session::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

// User Model is already registered above as \App\Models\User::class

$container->singleton(\App\Services\SettingService::class, function($c) {
    return new \App\Services\SettingService(
        $c->make(\App\Models\Setting::class),
        $c->make(Database::class),
        $c->make(\Core\Cache::class)
    );
});

$container->singleton(\App\Models\Setting::class, function($c) {
    return new \App\Models\Setting($c->make(Database::class));
});


// ─── Singletons: Simple Services ─────────────────────────────────────────
$container->singleton(App\Services\WalletService::class, function($c) {
    return new App\Services\WalletService(
        $c->make(Database::class),
        $c->make(App\Models\Wallet::class),
        $c->make(App\Models\Transaction::class),
        $c->make(\Core\IdempotencyKey::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(App\Services\AuditTrail::class),
        $c->make(App\Services\LedgerService::class)
    );
});

// AntiFraud services use \App\Services\AntiFraud\GeoIPService for consistent geolocation checks

// ─── Distributed Lock Service ─────────────────────────────
$container->singleton(\App\Services\DistributedLockService::class, function($c) {
    return new \App\Services\DistributedLockService();
});

// ─── Query Optimization Service ───────────────────────────
$container->singleton(\App\Services\QueryOptimizationService::class, function($c) {
    return new \App\Services\QueryOptimizationService(
        $c->make(Database::class)
    );
});

// ─── Log Rotation Service ─────────────────────────────────
$container->singleton(\App\Services\LogRotationService::class, function($c) {
    return new \App\Services\LogRotationService(
        $c->make(Database::class)
    );
});

$container->singleton(\App\Services\EmailService::class, function($c) {
    return new \App\Services\EmailService(
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\EmailQueue::class),
        $c->make(\App\Models\NotificationPreference::class),
        $c->make(\App\Models\Setting::class),
        $c->make(\App\Models\User::class),
        $c->make(\Core\Queue::class)
    );
});

$container->singleton(\App\Services\Notification\NotificationService::class, function($c) {
    return new \App\Services\Notification\NotificationService(
        $c->make(\App\Models\NotificationModel::class),
        $c->make(\App\Models\Notification::class),
        $c->make(\App\Models\NotificationPreference::class),
        $c->make(\App\Services\Notification\NotificationDispatcher::class),
        $c->make(\App\Services\Notification\FcmService::class),
        $c->make(Logger::class),
        $c->make(\Core\RateLimiter::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Services\EmailService::class)
    );
});

$container->singleton(\App\Services\Notification\NotificationDispatcher::class, function($c) {
    return new \App\Services\Notification\NotificationDispatcher(
        $c->make(\App\Services\Notification\Adapters\PushNotificationAdapter::class),
        $c->make(\App\Services\Notification\Adapters\SmsNotificationAdapter::class),
        $c->make(\App\Services\Notification\Adapters\FcmNotificationAdapter::class),
        $c->make(\App\Services\Notification\Adapters\LogNotificationAdapter::class),
        $c->make(Logger::class)
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
        $c->make(\Core\Cache::class)
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
        $c->make(\App\Services\Notification\Adapters\FcmNotificationAdapter::class)
    );
});

if (!class_exists(\App\Models\NotificationModel::class)) {
    class_alias(\App\Models\Notification::class, \App\Models\NotificationModel::class);
}

$container->singleton(\App\Models\NotificationModel::class, function($c) {
    return $c->make(\App\Models\Notification::class);
});

$container->singleton(\App\Services\Notification\LogNotificationService::class, function($c) {
    return new \App\Services\Notification\LogNotificationService(
        $c->make(\App\Services\Notification\Adapters\LogNotificationAdapter::class)
    );
});

$container->singleton(UploadService::class, function($c) {
    return new UploadService($c->make(Database::class));
});
$container->singleton(FileAccessService::class, function($c) {
    return new FileAccessService(
        $c->make(\App\Models\FileAccess::class),
        $c->make(\Core\Logger::class)
    );
});
$container->singleton(ActivityLog::class, function($c) {
    return new ActivityLog($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\SystemLog::class, function($c) {
    return new \App\Models\SystemLog($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\SecurityLog::class, function($c) {
    return new \App\Models\SecurityLog($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\PerformanceLog::class, function($c) {
    return new \App\Models\PerformanceLog($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\UserStatistics::class, function($c) {
    return new \App\Models\UserStatistics($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\FinancialStatistics::class, function($c) {
    return new \App\Models\FinancialStatistics($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\LogStatistics::class, function($c) {
    return new \App\Models\LogStatistics($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\KpiStatistics::class, function($c) {
    return new \App\Models\KpiStatistics($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\TaskStatistics::class, function($c) {
    return new \App\Models\TaskStatistics($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\ExportData::class, function($c) {
    return new \App\Models\ExportData($c->make(\Core\Database::class));
});
$container->singleton(\App\Models\AntiFraudModel::class, function($c) {
    return new \App\Models\AntiFraudModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\IpAndDeviceModel::class, function($c) {
    return new \App\Models\IpAndDeviceModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\VelocityAndScoreModel::class, function($c) {
    return new \App\Models\VelocityAndScoreModel($c->make(\Core\Database::class));
});

$container->singleton(\App\Models\FraudAnalyticsModel::class, function($c) {
    return new \App\Models\FraudAnalyticsModel($c->make(\Core\Database::class));
});



$container->singleton(\App\Services\AntiFraud\SessionAnomalyService::class, function($c) {
    return new \App\Services\AntiFraud\SessionAnomalyService(
        $c->make(\App\Models\SecurityModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class)
    );
});

$container->singleton(\App\Services\AntiFraud\GeoIPService::class, function($c) {
    return new \App\Services\AntiFraud\GeoIPService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Models\AntiFraudModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class)
    );
});

$container->singleton(\App\Services\AntiFraud\BrowserFingerprintService::class, function($c) {
    return new \App\Services\AntiFraud\BrowserFingerprintService(
        $c->make(\App\Models\AntiFraudModel::class)
    );
});

$container->singleton(\App\Services\Auth\OAuthService::class, function($c) {
    return new \App\Services\Auth\OAuthService(
        $c->make(\App\Models\SecurityModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Services\Auth\AuthService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});

// Models - Consolidated CustomTaskModel
$container->singleton(App\Models\CustomTaskModel::class, function($c) {
    return new App\Models\CustomTaskModel($c->make(Database::class));
});

$container->singleton(App\Models\CustomTaskSubmissionModel::class, function($c) {
    return new App\Models\CustomTaskSubmissionModel($c->make(Database::class));
});

$container->singleton(App\Models\CustomTaskAnalyticsModel::class, function($c) {
    return new App\Models\CustomTaskAnalyticsModel($c->make(Database::class));
});

// Backward compatibility aliases (deprecated - use CustomTaskModel directly)
$container->singleton(App\Models\CustomTask::class, function($c) {
    return $c->make(App\Models\CustomTaskModel::class);
});

$container->singleton(App\Models\CustomTaskSubmission::class, function($c) {
    return $c->make(App\Models\CustomTaskSubmissionModel::class);
});

$container->singleton(App\Models\CustomTaskAnalytics::class, function($c) {
    return $c->make(App\Models\CustomTaskAnalyticsModel::class);
});



// Service - CustomTaskService (Sprint 4 auto-wired singleton)
$container->singleton(App\Services\CustomTaskService::class);
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
        $c->make(App\Models\Advertisement::class),
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
$container->singleton(App\Controllers\User\CustomTaskController::class, function($c) {
    return new App\Controllers\User\CustomTaskController(
        $c->make(App\Models\CustomTask::class),
        $c->make(App\Models\CustomTaskSubmission::class),
        $c->make(App\Models\InteractionModel::class),
        $c->make(App\Models\InteractionModel::class),
        $c->make(App\Services\CustomTaskService::class),
        $c->make(App\Services\Analytics\AnalyticsService::class),
        $c->make(App\Services\UploadService::class)
    );
});

$container->singleton(App\Controllers\Admin\AdTaskController::class, function($c) {
    return new App\Controllers\Admin\AdTaskController(
        $c->make(App\Services\CustomTaskService::class),
        $c->make(App\Services\Analytics\AnalyticsService::class),
        $c->make(App\Services\WalletService::class),
        $c->make(App\Models\CustomTaskModel::class)
    );
});

$container->singleton(App\Controllers\Admin\ExecutorTaskController::class, function($c) {
    return new App\Controllers\Admin\ExecutorTaskController(
        $c->make(App\Services\CustomTaskService::class),
        $c->make(App\Models\CustomTaskModel::class),
        $c->make(App\Services\Shared\DisputeService::class),
        $c->make(App\Models\InteractionModel::class)
    );
});

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
        $c->make(\App\Services\SettingService::class)
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
    return new \App\Services\Payment\PaymentGatewayFactory([
        'zarinpal' => $c->make(\App\Services\Payment\ZarinPalGateway::class),
        'nextpay' => $c->make(\App\Services\Payment\NextPayGateway::class),
        'idpay' => $c->make(\App\Services\Payment\IDPayGateway::class),
        'dgpay' => $c->make(\App\Services\Payment\DgPayGateway::class),
    ]);
});

$container->singleton(\App\Services\PaymentService::class, function($c) {
    return new \App\Services\PaymentService(
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Models\PaymentLog::class),
        $c->make(\App\Models\BankCard::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\IdempotencyKey::class),
        $c->make(\App\Services\Payment\PaymentGatewayFactory::class)
    );
});

$container->singleton(\App\Controllers\Admin\BankCardController::class, function($c) {
    return new \App\Controllers\Admin\BankCardController(
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Services\BankCardService::class),
        $c->make(\App\Models\BankCard::class)
    );
});

$container->singleton(WithdrawalService::class, function($c) {
    return new WithdrawalService(
        $c->make(Database::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(\App\Models\Withdrawal::class),
        $c->make(\App\Models\WithdrawalLimit::class),
        $c->make(\App\Models\Setting::class),
        $c->make(\App\Models\BankCard::class),
        $c->make(\App\Services\BankCardService::class),
        $c->make(\App\Services\AntiFraud\RiskDecisionService::class),
        $c->make(\App\Services\KYCService::class),
        $c->make(\App\Models\Transaction::class),
        $c->make(\App\Models\User::class),
        $c->make(AuditTrail::class),
        $c->make(Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\RiskPolicyService::class, function($c) {
    return new \App\Services\AntiFraud\RiskPolicyService($c->make(Database::class));
});

$container->singleton(\App\Services\Shared\ScoreService::class, function($c) {
    return new \App\Services\Shared\ScoreService(
        $c->make(Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\Score::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\SocialTaskAnalyticsModel::class),
        $c->make(\App\Services\User\UserScoreService::class),
        $c->make(\App\Services\InfluencerReputationService::class)
    );
});

$container->singleton(\App\Services\AntiFraud\RiskDecisionService::class, function($c) {
    return new \App\Services\AntiFraud\RiskDecisionService(
        $c->make(Database::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\App\Services\Shared\ScoreService::class)
    );
});

$container->singleton(StoryPromotionService::class, function($c) {
    return new StoryPromotionService(
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



$container->singleton(\App\Controllers\User\InfluencerController::class, function($c) {
    return new \App\Controllers\User\InfluencerController(
        $c->make(\App\Models\InfluencerModel::class),
        $c->make(\App\Models\StoryOrder::class),
        $c->make(\App\Models\Dispute::class),
        $c->make(\App\Models\InfluencerModel::class),
        $c->make(\App\Services\StoryPromotionService::class),
        $c->make(\App\Services\Shared\DisputeService::class),
        $c->make(\App\Services\Shared\ScoreService::class),
        $c->make(\App\Services\VerificationService::class),
        $c->make(UploadService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Admin\InfluencerController::class, function($c) {
    return new \App\Controllers\Admin\InfluencerController(
        $c->make(\App\Models\InfluencerModel::class),
        $c->make(\App\Models\StoryOrder::class),
        $c->make(\App\Models\Dispute::class),
        $c->make(\App\Services\StoryPromotionService::class),
        $c->make(\App\Services\Shared\DisputeService::class),
        $c->make(\App\Services\VerificationService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Api\InfluencerController::class, function($c) {
    return new \App\Controllers\Api\InfluencerController(
        $c->make(\App\Models\InfluencerModel::class),
        $c->make(\App\Models\StoryOrder::class),
        $c->make(\App\Models\Dispute::class),
        $c->make(\App\Services\StoryPromotionService::class),
        $c->make(\App\Services\Shared\DisputeService::class),
        $c->make(\App\Services\Shared\ScoreService::class),
        $c->make(\App\Services\VerificationService::class),
        $c->make(UploadService::class)
    );
});

$container->singleton(KYCService::class, function($c) {
    return new KYCService(
        $c->make(\App\Models\KYCVerification::class),
        $c->make(\App\Models\User::class),
        $c->make(Database::class),
        $c->make(UploadService::class),
        $c->make(AuditTrail::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(NotificationService::class)
    );
});

$container->singleton(BannerService::class, function($c) {
    return new BannerService(
        $c->make(\App\Models\Banner::class),
        $c->make(\App\Models\BannerPlacement::class),
        $c->make(\App\Models\BannerPlacement::class),
        $c->make(UploadService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

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
$container->singleton(App\Models\AdTask::class, function($c) {
    return new App\Models\AdTask($c->make(Database::class));
});

$container->singleton(App\Models\BankCard::class, function($c) {
    return new App\Models\BankCard($c->make(Database::class));
});

$container->singleton(App\Models\Banner::class, function($c) {
    return new App\Models\Banner($c->make(Database::class));
});

$container->singleton(App\Models\BannerPlacement::class, function($c) {
    return new App\Models\BannerPlacement($c->make(Database::class));
});

$container->singleton(App\Models\BugReport::class, function($c) {
    return new App\Models\BugReport($c->make(Database::class));
});

$container->singleton(App\Models\BugReportComment::class, function($c) {
    return new App\Models\BugReportComment($c->make(Database::class));
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

$container->singleton(App\Models\Advertisement::class, function($c) {
    return new App\Models\Advertisement($c->make(Database::class));
});

$container->singleton(App\Models\CronJob::class, function($c) {
    return new App\Models\CronJob($c->make(Database::class));
});

$container->singleton(App\Models\InfluencerModel::class, function($c) {
    return new App\Models\InfluencerModel($c->make(Database::class));
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

$container->singleton(App\Models\Escrow::class, function($c) {
    return new App\Models\Escrow($c->make(Database::class));
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


$container->singleton(App\Models\SEOExecution::class, function($c) {
    return new App\Models\SEOExecution($c->make(Database::class));
});

$container->singleton(App\Models\SEOKeyword::class, function($c) {
    return new App\Models\SEOKeyword($c->make(Database::class));
});

$container->singleton(App\Models\StoryOrder::class, function($c) {
    return new App\Models\StoryOrder($c->make(Database::class));
});

$container->singleton(App\Models\TaskDispute::class, function($c) {
    return new App\Models\TaskDispute($c->make(Database::class));
});

$container->singleton(App\Models\TaskRecheck::class, function($c) {
    return new App\Models\TaskRecheck($c->make(Database::class));
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

$container->singleton(App\Models\UserBankCard::class, function($c) {
    return new App\Models\UserBankCard($c->make(Database::class));
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

$container->singleton(App\Services\AdvertiserDashboardService::class, function($c) {
    return new App\Services\AdvertiserDashboardService(
        $c->make(Database::class)
    );
});

$container->singleton(App\Services\BankCardService::class, function($c) {
    return new App\Services\BankCardService(
        $c->make(\App\Models\BankCard::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(App\Services\BugReportService::class, function($c) {
    return new App\Services\BugReportService(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(Database::class),
        $c->make(\App\Models\BugReport::class),
        $c->make(\App\Models\BugReportComment::class),
        $c->make(\App\Models\Notification::class),
        $c->make(\App\Services\UploadService::class)
    );
});

$container->singleton(\App\Controllers\Admin\AuditTrailController::class, function($c) {
    return new \App\Controllers\Admin\AuditTrailController(
        $c->make(\App\Services\ExportService::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});

$container->singleton(\App\Controllers\Admin\AuthController::class, function($c) {
    return new \App\Controllers\Admin\AuthController(
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Services\Auth\AuthService::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\Shared\PolicyService::class)
    );
});

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
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

    // Old AppealService removed. Handled by Shared\DisputeService.

$container->singleton(\App\Controllers\Admin\FraudController::class, function($c) {
    return new \App\Controllers\Admin\FraudController(
        $c->make(\App\Services\AntiFraud\FraudDetectionService::class)
    );
});

$container->singleton(\App\Controllers\Admin\ScoreManagementController::class, function($c) {
    return new \App\Controllers\Admin\ScoreManagementController(
        $c->make(\App\Services\Shared\ScoreService::class)
    );
});

$container->singleton(\App\Controllers\Admin\AppealAdminController::class, function($c) {
    return new \App\Controllers\Admin\AppealAdminController(
        $c->make(\App\Services\Shared\DisputeService::class)
    );
});

$container->singleton(\App\Controllers\Admin\SeoAdController::class, function($c) {
    return new \App\Controllers\Admin\SeoAdController(
        $c->make(\App\Models\SeoAd::class),
        $c->make(\App\Models\SeoExecution::class),
        $c->make(\App\Services\Shared\AnalyticsService::class)
    );
});

$container->singleton(\App\Controllers\User\AppealController::class, function($c) {
    return new \App\Controllers\User\AppealController(
        $c->make(\App\Services\Shared\DisputeService::class),
        $c->make(\App\Services\UploadService::class)
    );
});

$container->singleton(\App\Services\DirectMessageService::class, function($c) {
    return new \App\Services\DirectMessageService(
        $c->make(\App\Models\DirectMessage::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Redis::class)
    );
});

$container->singleton(\App\Controllers\User\MessageController::class, function($c) {
    return new \App\Controllers\User\MessageController(
        $c->make(\App\Services\DirectMessageService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Admin\MessageModerationController::class, function($c) {
    return new \App\Controllers\Admin\MessageModerationController(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\User\SocialTaskController::class, function($c) {
    return new \App\Controllers\User\SocialTaskController(
        $c->make(\App\Services\SocialTask\SocialTaskService::class),
        $c->make(\App\Services\Shared\ScoreService::class),
        $c->make(\App\Services\SocialTask\RatingService::class),
        $c->make(\Core\Logger::class)
    );
});

// ===== OLD SERVICES (Deprecated - use Analytics\AnalyticsService instead) =====
// ReportService and KpiService are consolidated into Analytics\AnalyticsService
// AnalyticsService (old) is deprecated - use App\Services\Analytics\AnalyticsService instead

$container->singleton(\App\Controllers\Admin\AdminAnalyticsController::class, function($c) {
    return new \App\Controllers\Admin\AdminAnalyticsController(
        $c->make(\App\Services\Analytics\AnalyticsService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(App\Services\SEOExecutionService::class, function($c) {
    return new App\Services\SEOExecutionService(
        $c->make(Database::class),
        $c->make(\App\Models\SEOExecution::class)
    );
});

$container->singleton(App\Services\SEOKeywordService::class, function($c) {
    return new App\Services\SEOKeywordService(
        $c->make(Database::class),
        $c->make(\App\Models\SEOKeyword::class)
    );
});

$container->singleton(\App\Services\Auth\SessionService::class, function($c) {
    return new \App\Services\Auth\SessionService(
        $c->make(\App\Models\SecurityModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
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
        $c->make(Database::class),
        $c->make(\App\Models\SocialAccount::class)
    );
});

$container->singleton(\App\Services\User\UserService::class, function($c) {
    return new \App\Services\User\UserService(
        $c->make(\App\Models\User::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\UserSettingsService::class, function($c) {
    return new \App\Services\UserSettingsService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\User::class),
        $c->make(\Core\Cache::class)
    );
});

$container->singleton(\App\Controllers\User\SettingsController::class, function($c) {
    return new \App\Controllers\User\SettingsController(
        $c->make(\App\Models\User::class),
        $c->make(\App\Services\UserSettingsService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\User\SeoController::class, function($c) {
    return new \App\Controllers\User\SeoController(
        $c->make(\App\Models\SeoAd::class),
        $c->make(\App\Models\SeoExecution::class),
        $c->make(\App\Services\SeoService::class),
        $c->make(\App\Services\Shared\AnalyticsService::class)
    );
});

$container->singleton(\App\Controllers\User\SeoAdController::class, function($c) {
    return new \App\Controllers\User\SeoAdController(
        $c->make(\App\Models\SeoAd::class),
        $c->make(\App\Models\SeoExecution::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Shared\AnalyticsService::class),
        $c->make(\App\Services\SeoPayoutService::class),
        $c->make(\App\Services\AdSystemManager::class)
    );
});


$container->singleton(\App\Services\AntiFraud\FraudManagementService::class, function($c) {
    return new \App\Services\AntiFraud\FraudManagementService(
        $c->make(\App\Models\AntiFraudModel::class),
        $c->make(\App\Services\AntiFraud\GeoIPService::class),
        $c->make(\App\Services\AntiFraud\BrowserFingerprintService::class)
    );
});

$container->singleton(\App\Services\AntiFraud\SeoFraudDetector::class, function($c) {
    return new \App\Services\AntiFraud\SeoFraudDetector(
        $c->make(\App\Services\AntiFraud\BrowserFingerprintService::class),
        $c->make(\App\Services\AntiFraud\SessionAnomalyService::class),
        $c->make(\App\Models\SeoExecution::class),
        $c->make(\App\Models\AntiFraudModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\MLFraudDetectionService::class, function($c) {
    return new \App\Services\AntiFraud\MLFraudDetectionService(
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\BrowserFingerprintService::class, function($c) {
    return new \App\Services\AntiFraud\BrowserFingerprintService($c->make(\App\Models\IpAndDeviceModel::class));
});


$container->singleton(\App\Services\AntiFraud\BehavioralBiometricsService::class, function($c) {
    return new \App\Services\AntiFraud\BehavioralBiometricsService(
        $c->make(\App\Models\AntiFraudModel::class),
        $c->make(\Core\Logger::class)
    );
});



$container->singleton(\App\Services\AntiFraud\GraphAnalysisService::class, function($c) {
    return new \App\Services\AntiFraud\GraphAnalysisService(
        $c->make(\App\Models\AntiFraudModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\GeolocationIntelligenceService::class, function($c) {
    return new \App\Services\AntiFraud\GeolocationIntelligenceService(
        $c->make(\App\Models\AntiFraudModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\FraudDashboardService::class, function($c) {
    return new \App\Services\AntiFraud\FraudDashboardService(
        $c->make(\App\Models\AntiFraudModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\EmailPhoneIntelligenceService::class, function($c) {
    return new \App\Services\AntiFraud\EmailPhoneIntelligenceService(
        $c->make(\App\Models\AntiFraudModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\DeviceIntelligenceService::class, function($c) {
    return new \App\Services\AntiFraud\DeviceIntelligenceService(
        $c->make(\App\Models\AntiFraudModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\AntiFraud\AccountTakeoverService::class, function($c) {
    return new \App\Services\AntiFraud\AccountTakeoverService(
        $c->make(\App\Models\AntiFraudModel::class),
        $c->make(\App\Services\AntiFraud\SessionAnomalyService::class),
        $c->make(\App\Services\AntiFraud\IPQualityService::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\Core\Logger::class)
    );
});


// ─── FeatureFlagService ───────────────────────────────────────────────────
$container->singleton(\App\Services\FeatureFlagService::class, function($c) {
    return new \App\Services\FeatureFlagService(
        $c->make(\App\Models\FeatureFlag::class),
        $c->make(Database::class),
        $c->make(\Core\Cache::class),
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

    return $dispatcher;
});

// Boot event listeners immediately so closures are registered.
$container->make('event.bootstrap');

// ─── AdminDashboardService ────────────────────────────────────────────────────
$container->singleton(\App\Services\AdminDashboardService::class, function($c) {
    return new \App\Services\AdminDashboardService(
        $c->make(\App\Models\User::class),
        $c->make(Logger::class),
        $c->make(\App\Services\AdminDashboard\DashboardQueryService::class),
        $c->make(\App\Services\AdminDashboard\SystemMonitoringService::class)
    );
});

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
$container->singleton(\App\Services\VitrineService::class, function($c) {
    return new \App\Services\VitrineService(
        $c->make(\App\Models\VitrineListing::class),
        $c->make(\App\Models\VitrineRequest::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(\App\Services\FeatureFlagService::class),
        $c->make(Database::class),
        $c->make(Logger::class)
    );
});

$container->singleton(\App\Services\User\UserScoreService::class, function($c) {
    return new \App\Services\User\UserScoreService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\Core\Logger::class)
    );
});

// ─── Anti-Fraud Domain ────────────────────────────────────────────────────

$container->singleton(\App\Services\AntiFraud\RiskPolicyService::class, function($c) {
    return new \App\Services\AntiFraud\RiskPolicyService(
        $c->make(\Core\Database::class)
    );
});

$container->singleton(\App\Services\AntiFraud\RiskDecisionService::class, function($c) {
    return new \App\Services\AntiFraud\RiskDecisionService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\App\Services\Shared\ScoreService::class)
    );
});

$container->singleton(\App\Services\AntiFraud\VelocityCheckService::class, function($c) {
    return new \App\Services\AntiFraud\VelocityCheckService(
        $c->make(\App\Models\AntiFraudModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class)
    );
});

$container->singleton(\App\Services\SocialTask\SocialTaskScoringService::class, function($c) {
    return new \App\Services\SocialTask\SocialTaskScoringService($c->make(\App\Contracts\LoggerInterface::class));
});

$container->singleton(\App\Services\AntiFraud\TorListUpdater::class, function($c) {
    return new \App\Services\AntiFraud\TorListUpdater(
        $c->make(\App\Models\AntiFraudModel::class)
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
        $c->make(\App\Policies\RateLimitPolicy::class)
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
        $c->make(\App\Services\Notification\NotificationService::class)
    );
});

$container->singleton(\App\Services\SocialTask\SocialTaskService::class, function($c) {
    return new \App\Services\SocialTask\SocialTaskService(
        $c->make(\App\Models\SocialTaskModel::class),
        $c->make(\App\Services\SocialTask\SocialTaskScoringService::class),
        $c->make(\App\Services\SocialTask\TrustScoreService::class),
        $c->make(\App\Services\SocialTask\SilentAntiFraudService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Services\ApiRateLimiter::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\FinancialEscrowService::class),
        $c->make(\App\Services\StateMachineService::class),
        $c->make(\App\Services\RealTimeService::class)
    );
});

$container->singleton(\App\Services\SocialTask\RatingService::class, function($c) {
    return new \App\Services\SocialTask\RatingService(
        $c->make(\App\Services\Shared\RatingService::class),
        $c->make(\App\Models\SocialTaskModel::class),
        $c->make(\App\Services\Shared\ScoreService::class)
    );
});
 
// ─── Admin SocialTask Controller ─────────────────────────────────────────
$container->singleton(\App\Controllers\Admin\SocialTaskController::class, function($c) {
    return new \App\Controllers\Admin\SocialTaskController(
        $c->make(\App\Services\SocialTask\SocialTaskService::class),
        $c->make(\App\Services\Shared\ScoreService::class),
        $c->make(\App\Services\SocialTask\RatingService::class),
        $c->make(\App\Services\SocialTask\SilentAntiFraudService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});

$container->singleton(\App\Controllers\PaymentController::class, function($c) {
    return new \App\Controllers\PaymentController(
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\PaymentService::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\Core\Database::class, function () {
    return \Core\Database::getInstance();
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
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\PerformanceOptimizationService::class, function($c) {
    return new \App\Services\PerformanceOptimizationService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

// Real-time API Controllers
$container->singleton(\App\Controllers\Api\RealTimeController::class, function($c) {
    return new \App\Controllers\Api\RealTimeController(
        $c->make(\App\Services\WebSocketService::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Request::class),
        $c->make(\Core\Response::class)
    );
});

$container->singleton(\App\Controllers\Api\VerificationController::class, function($c) {
    return new \App\Controllers\Api\VerificationController(
        $c->make(\App\Services\VerificationService::class),
        $c->make(\App\Models\InfluencerProfile::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Request::class),
        $c->make(\Core\Response::class)
    );
});

$container->singleton(\App\Controllers\Admin\WithdrawalController::class, function($c) {
    return new \App\Controllers\Admin\WithdrawalController(
        $c->make(\App\Models\Withdrawal::class),
        $c->make(\App\Services\BankCardService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\User\UserService::class),
        $c->make(\App\Services\WithdrawalService::class),
        $c->make(\Core\Logger::class)
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

$container->singleton(\App\Services\AccountDeletionService::class, function($c) {
    return new \App\Services\AccountDeletionService(
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\AccountDeletionLog::class),
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Admin\AccountDeletionManagementController::class, function($c) {
    return new \App\Controllers\Admin\AccountDeletionManagementController(
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\AccountDeletionLog::class),
        $c->make(\App\Services\AccountDeletionService::class),
        $c->make(\Core\Logger::class)
    );
});

// ─── BackupService (Phase 5e) ─────────────────────────────────────────────
$container->singleton(\App\Services\BackupService::class, function($c) {
    return new \App\Services\BackupService(
        $c->make(\App\Models\BackupLog::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Controllers\Admin\BackupManagementController::class, function($c) {
    return new \App\Controllers\Admin\BackupManagementController(
        $c->make(\App\Services\BackupService::class),
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
        $c->make(\App\Models\Banner::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class)
    );
});

$container->singleton(\App\Services\Adapters\VitrineAdapter::class, function($c) {
    return new \App\Services\Adapters\VitrineAdapter(
        $c->make(\App\Models\VitrineListing::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class)
    );
});

$container->singleton(\App\Services\Adapters\StoryPromotionAdapter::class, function($c) {
    return new \App\Services\Adapters\StoryPromotionAdapter(
        $c->make(\Core\Database::class)
    );
});

$container->singleton(\App\Services\Adapters\AdTubeAdapter::class, function($c) {
    return new \App\Services\Adapters\AdTubeAdapter(
        $c->make(\Core\Database::class)
    );
});

// AdSystemManager
$container->singleton(\App\Services\AdSystemManager::class, function($c) {
    return new \App\Services\AdSystemManager([
        'custom_task' => $c->make(\App\Services\Adapters\CustomTaskAdapter::class),
        'seo' => $c->make(\App\Services\Adapters\SeoAdAdapter::class),
        'banner' => $c->make(\App\Services\Adapters\BannerAdapter::class),
        'vitrine' => $c->make(\App\Services\Adapters\VitrineAdapter::class),
        'story_promotion' => $c->make(\App\Services\Adapters\StoryPromotionAdapter::class),
        'adtube' => $c->make(\App\Services\Adapters\AdTubeAdapter::class),
    ]);
});

// ---------------------------------------------------------------------
// Transaction Reversal & Reconciliation Services (Sprint 2-3)
// ---------------------------------------------------------------------

$container->singleton(\App\Services\TransactionReversalService::class, function($c) {
    return new \App\Services\TransactionReversalService(
        $c->make(\App\Models\Transaction::class),
        $c->make(\App\Models\Wallet::class),
        $c->make(\App\Models\LedgerEntry::class),
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\LedgerService::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});

$container->singleton(\App\Services\ReconciliationService::class, function($c) {
    return new \App\Services\ReconciliationService(
        $c->make(\App\Models\Order::class),
        $c->make(\App\Models\Transaction::class),
        $c->make(\App\Models\LedgerEntry::class),
        $c->make(\App\Models\Wallet::class),
        $c->make(\Core\Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\LedgerService::class),
        $c->make(\App\Services\ReferralCommissionService::class),
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

// ---------------------------------------------------------------------
// Sprint 7: OAuthService (Social Login)
// ---------------------------------------------------------------------

$container->singleton(\App\Services\Auth\OAuthService::class, function($c) {
    return new \App\Services\Auth\OAuthService(
        $c->make(\App\Models\AuthModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\SocialAccount::class),
        $c->make(\App\Services\Auth\AuthService::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Services\Auth\AuditTrail::class)
    );
});
// ─── Shared Services ───────────────────────────────────────────────────
$container->singleton(\App\Services\Shared\DisputeService::class, function($c) {
    return new \App\Services\Shared\DisputeService(
        $c->make(Database::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\Notification\NotificationService::class),
        $c->make(\App\Models\Dispute::class),
        $c->make(\App\Models\Appeal::class)
    );
});

$container->singleton(\App\Services\Shared\ScoreService::class, function($c) {
    return new \App\Services\Shared\ScoreService(
        $c->make(Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\Score::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\SocialTaskAnalyticsModel::class),
        $c->make(\App\Services\User\UserScoreService::class),
        $c->make(\App\Services\InfluencerReputationService::class)
    );
});

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
        $c->make(\App\Models\AdvancedAnalytics::class)
    );
});

$container->singleton(\App\Services\Shared\BulkService::class, function($c) {
    return new \App\Services\Shared\BulkService(
        $c->make(Database::class),
        $c->make(\Core\Logger::class)
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