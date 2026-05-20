<?php

use Core\Container;
use Core\Application;
use Core\Session;
use Core\Database;
use Core\Logger;
use App\Models\User;
use App\Models\KpiStatistics;
use App\Models\ExportData;
use App\Services\CaptchaService;
use App\Models\SecurityModel;
use App\Services\AuditTrail;
use App\Services\WalletService;
use App\Services\Notification\NotificationService;
use App\Services\UploadService;
use App\Services\FileAccessService;
use App\Services\WithdrawalService;
use App\Services\ContentService;
use App\Services\InvestmentService;
use App\Services\LotteryService;
use App\Services\ManualDepositService;
use App\Services\CryptoDeposit\CryptoDepositService;
use App\Adapters\CryptoVerificationAdapter;
use App\Adapters\CryptoApiAdapter;
use App\Adapters\BankInquiryAdapter;
use App\Adapters\JibitInquiryAdapter;
use App\Adapters\KycFaceVerificationAdapter;
use App\Adapters\DeepFaceKycAdapter;
use App\Services\InfluencerService;
use App\Services\KYCService;
use App\Services\BannerService;
use App\Services\Auth\TwoFactorService;
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

// ── Tracing Context (Correlation ID) ──────────────────────────
if (!isset($_SERVER['REQUEST_ID'])) {
    $_SERVER['REQUEST_ID'] = $_SERVER['HTTP_X_REQUEST_ID']
        ?? bin2hex(random_bytes(16));
}

// Load critical non-PSR4 compliant constants from legacy ecosystem
require_once BASE_PATH . '/app/Constants/MagicNumbers.php';

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
        }
    } else {
        // بدون .env: امنیت حداکثری از مقادیر پیش‌فرض استفاده می‌شود
    }
}

// Define SECURITY_API_TOKEN_SECRET constant from .env if available
if (!defined('SECURITY_API_TOKEN_SECRET')) {
    $secret = $env['SECURITY_API_TOKEN_SECRET'] ?? getenv('SECURITY_API_TOKEN_SECRET') ?? $_ENV['SECURITY_API_TOKEN_SECRET'] ?? null;
    if ($secret) {
        define('SECURITY_API_TOKEN_SECRET', (string)$secret);
    }
}


// 🛡️ CRITICAL Infrastructure Integrity Checks
// These must run before any service starts to prevent insecure deployments

// 1. Check APP_KEY (Mandatory, min 32 chars for AES-256)
$appKey = secure_key();
if (empty($appKey) || strlen($appKey) < 32 || $appKey === 'default_key') {
    if (config('app.env') === 'production') {
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        die(
            '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>خطای امنیتی سیستم</title>' .
            '<style>body{font-family:Tahoma,sans-serif;padding:50px;background:#fcfcfc;} .box{background:#fff;border-right:5px solid #e74c3c;padding:30px;box-shadow:0 5px 20px rgba(0,0,0,0.05);border-radius:4px;}</style></head>' .
            '<body><div class="box"><h2>خطای بحرانی امنیت: کلید رمزنگاری سیستم تنظیم نشده یا ناامن است</h2>' .
            '<p>سیستم در حالت عملیاتی (Production) اجازه راه‌اندازی با کلید امنیتی پیش‌فرض، ناموجود یا ضعیف را نمی‌دهد. لطفا فایل پیکربندی <code>.env</code> را بررسی و کلید امنیتی معتبری (حداقل ۳۲ کاراکتر) تنظیم نمایید.</p></div></body></html>'
        );
    }
    throw new Exception('APP_KEY must be set, be at least 32 characters long, and must not be "default_key" for secure encryption.');
}

// 2. Check APP_URL (Mandatory for CSRF/OAuth integrity)
$appUrl = (string)config('app.url');
if (empty($appUrl) || !filter_var($appUrl, FILTER_VALIDATE_URL)) {
    throw new Exception('APP_URL is missing or invalid. It is required for security validations (CSRF/CORS/OAuth).');
}

// 3. Check SECURITY_API_TOKEN_SECRET (Mandatory in all environments)
if (!defined('SECURITY_API_TOKEN_SECRET') || strlen(SECURITY_API_TOKEN_SECRET) < 32) {
    throw new Exception('SECURITY_API_TOKEN_SECRET is missing or too weak (min 32 chars). All environments must be secure.');
}


// Load config early to avoid circular dependency
$config = config();

// ── Unified & Config-Driven PHP Error Configuration ──
$isDebug = (bool) config('app.debug', false);
$isProduction = config('app.env', 'production') === 'production';

if ($isDebug && !$isProduction) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// Container Singleton
$container = Container::getInstance();
$container->singleton(\Core\Container::class, function() use ($container) {
    return $container;
});

// Database Singleton - bind early
$container->singleton(\Core\Database::class, function($c) use ($config) {
    return \Core\Database::getInstance($config['database']);
});

// ─── Logger — Singleton مرکزی لاگ ────────────────────────────────────────────
// PSR-3 Compatible Logging System
$container->singleton(\App\Services\LogService::class);
$container->singleton(\App\Contracts\LoggerInterface::class, \Core\Logger::class);

// =========================
// Sentry-like Services
// =========================

$container->singleton(\App\Models\SentryModel::class);

$container->singleton(\App\Services\Sentry\Alerting\AlertDispatcher::class);


$container->singleton(\App\Services\CryptoDeposit\CryptoDepositService::class);

// CryptoDeposit Adapters
$container->singleton(\App\Adapters\CryptoVerificationAdapter::class, \App\Adapters\CryptoExplorerAdapter::class);

$container->singleton(\App\Adapters\CryptoApiAdapter::class);

// Bank Inquiry Adapter (Automatic Fallback enabled)
$container->singleton(\App\Adapters\BankInquiryAdapter::class, \App\Adapters\JibitInquiryAdapter::class);

// AI KYC Verification Adapter
$container->singleton(\App\Adapters\KycFaceVerificationAdapter::class, \App\Adapters\DeepFaceKycAdapter::class);







$container->singleton(\App\Models\SocialTaskModel::class);

$container->singleton(\App\Models\SocialTaskExecutionModel::class);

$container->singleton(\App\Models\SocialTaskAnalyticsModel::class);





$container->singleton(\App\Services\SocialTask\BehaviorAnalysisService::class);

$container->singleton(\App\Services\SocialTask\CameraVerificationService::class);



$container->singleton(\App\Services\SocialTask\SocialTaskService::class);

$container->singleton(\App\Services\Sentry\Audit\AdvancedAuditTrail::class);

$container->singleton(\App\Services\Sentry\SentryExceptionHandler::class);


$container->singleton(App\Services\AuditTrail::class);



// ثبت سرویس‌ها و مدل‌ها
$container->singleton(Session::class, function() {
    return Session::getInstance();
});






$container->singleton(\App\Services\EscrowService::class);

$container->singleton(\App\Services\FinancialEscrowService::class);

$container->singleton(\App\Services\StateMachineService::class);





$container->singleton(App\Models\AdvancedAnalytics::class);


// ========== Analytics Services (Consolidated) ==========
$container->singleton(\App\Services\Analytics\AnalyticsService::class);


$container->singleton(\App\Models\SecurityModel::class);

$container->singleton(\App\Models\User::class);



$container->singleton(\App\Services\User\ProfileService::class);



$container->singleton(\App\Services\Auth\TwoFactorService::class);

$container->singleton(\App\Services\Auth\AuthService::class);



// User Model is already registered above as \App\Models\User::class

$container->singleton(\App\Services\SettingService::class);

$container->singleton(\App\Models\Setting::class);


// ─── Singletons: Simple Services ─────────────────────────────────────────

$container->singleton(App\Services\WalletService::class);
$container->singleton(\App\Contracts\WalletServiceInterface::class, App\Services\WalletService::class);

// AntiFraud services use \App\Services\AntiFraud\GeoIPService for consistent geolocation checks

// ─── Distributed Lock Service ─────────────────────────────
$container->singleton(\App\Services\DistributedLockService::class);

$container->singleton(\App\Services\RedisEmailQueueService::class);



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
        $c->make(\Core\Queue::class), // 🚀 UPG-03: تزریق سیستم صف جهت پردازش نامتقارن نوتیفیکیشن‌های انبوه
        $c->make(\App\Services\EmailService::class),
        $c->make(\App\Services\Notification\SmsNotificationService::class)
    );
});
$container->singleton(\App\Contracts\NotificationServiceInterface::class, function($c) {
    return $c->make(\App\Services\Notification\NotificationService::class);
});

$container->singleton(\App\Services\Notification\NotificationRetryPolicy::class, function($c) {
    return new \App\Services\Notification\NotificationRetryPolicy(
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Notification\NotificationDispatcher::class, function($c) {
    return new \App\Services\Notification\NotificationDispatcher(
        $c->make(\App\Adapters\PushNotificationAdapter::class),
        $c->make(\App\Adapters\SmsNotificationAdapter::class),
        $c->make(\App\Adapters\FcmNotificationAdapter::class),
        $c->make(\App\Adapters\LogNotificationAdapter::class),
        $c->make(Logger::class),
        $c->make(\Core\Queue::class),
        $c->make(\App\Services\Notification\NotificationRetryPolicy::class)
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

$container->singleton(\App\Adapters\PushNotificationAdapter::class, function($c) {
    return new \App\Adapters\PushNotificationAdapter(
        $c->make(\App\Adapters\FcmNotificationAdapter::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Adapters\SmsNotificationAdapter::class, function($c) {
    return new \App\Adapters\SmsNotificationAdapter(
        $c->make(\App\Models\User::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Adapters\FcmNotificationAdapter::class, function($c) {
    return new \App\Adapters\FcmNotificationAdapter(
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\MetricsCollectorInterface::class)
    );
});

$container->singleton(\App\Adapters\LogNotificationAdapter::class, function($c) {
    return new \App\Adapters\LogNotificationAdapter(
        $c->make(\App\Models\Notification::class),
        $c->make(\App\Models\SystemTelemetryModel::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Services\Notification\FcmService::class, function($c) {
    return new \App\Services\Notification\FcmService(
        $c->make(\App\Adapters\FcmNotificationAdapter::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
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

$container->singleton(\App\Services\Auth\GoogleJwtVerifier::class, function($c) {
    return new \App\Services\Auth\GoogleJwtVerifier(
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
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
        $c->make(\App\Services\Auth\GoogleJwtVerifier::class),
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

$container->singleton(\App\Services\XPEngine::class);
$container->singleton(\App\Services\User\UserLevelService::class);

$container->singleton(\App\Services\CronService::class);

// Controllers


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

$container->singleton(\App\Services\Payment\PaymentService::class);











$container->singleton(\App\Services\InfluencerReputationService::class);









// Legacy SEOTaskService binding removed: class does not exist; use App\Services\SeoService instead.


$container->singleton(\App\Services\User\UserDashboardService::class);


// ─── Auto-generated Model Bindings ─────────────────────────────────────


$container->singleton(App\Models\BankCard::class);
$container->singleton(App\Models\BannerPlacement::class);
$container->singleton(App\Models\ContentAgreement::class);
$container->singleton(App\Models\ContentRevenue::class);
$container->singleton(App\Models\ContentSubmission::class);
$container->singleton(App\Models\CryptoDeposit::class);
$container->singleton(App\Models\CryptoDepositIntent::class);
$container->singleton(App\Models\EmailQueue::class);
$container->singleton(App\Models\BackupLog::class);
$container->singleton(App\Models\ContactMessage::class);
$container->singleton(App\Models\CaptchaLog::class);
$container->singleton(App\Models\BulkOperation::class);
$container->singleton(App\Models\Ads::class);
$container->singleton(App\Models\CronJob::class);
$container->singleton(App\Models\InvestmentProfit::class);
$container->singleton(App\Models\InvestmentWithdrawal::class);
$container->singleton(App\Models\KYCVerification::class);
$container->singleton(App\Models\DirectMessage::class);
$container->singleton(App\Models\FileAccess::class);
$container->singleton(App\Models\LotteryDailyNumber::class);
$container->singleton(App\Models\LotteryParticipation::class);
$container->singleton(App\Models\LotteryVote::class);
$container->singleton(App\Models\ManualDeposit::class);
$container->singleton(App\Models\NotificationPreference::class);
$container->singleton(App\Models\Page::class);
$container->singleton(App\Models\SeoExecution::class);
$container->singleton(App\Models\StoryOrder::class);
$container->singleton(App\Models\Ticket::class);
$container->singleton(App\Models\TicketCategory::class);
$container->singleton(App\Models\TicketMessage::class);
$container->singleton(App\Models\TradingRecord::class);
$container->singleton(App\Models\Withdrawal::class);
$container->singleton(App\Models\WithdrawalLimit::class);
$container->singleton(\App\Models\Dispute::class);
$container->singleton(\App\Models\InfluencerModel::class);
$container->singleton(\App\Models\InfluencerReputation::class);
$container->singleton(\App\Models\InfluencerVerification::class);
$container->singleton(\App\Models\Score::class);
$container->singleton(\App\Models\Rating::class);
$container->singleton(\App\Models\Coupon::class);
$container->singleton(\App\Models\CouponRedemption::class);
$container->singleton(\App\Models\ReferralCommission::class);
$container->singleton(\App\Models\Escrow::class);
$container->singleton(\App\Models\LedgerEntry::class);



$container->singleton(App\Services\BankCardService::class);




$container->singleton(App\Services\ExportService::class, function($c) {
    return new App\Services\ExportService(
        $c->make(\App\Models\ExportData::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Search\AdminSearchGateway::class);
$container->singleton(\App\Services\Search\UserSearchGateway::class);
$container->singleton(\App\Services\Search\ModuleSearchGateway::class);

$container->singleton(\App\Services\Search\AdminSearchProvider::class, function($c) {
    return new \App\Services\Search\AdminSearchProvider(
        $c->make(\App\Models\AdvancedSearch::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Search\AdminSearchGateway::class)
    );
});

$container->singleton(\App\Services\Search\UserSearchProvider::class, function($c) {
    return new \App\Services\Search\UserSearchProvider(
        $c->make(\App\Models\AdvancedSearch::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Search\UserSearchGateway::class)
    );
});

$container->singleton(\App\Services\Search\ModuleSearchProvider::class, function($c) {
    return new \App\Services\Search\ModuleSearchProvider(
        $c->make(\App\Models\AdvancedSearch::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Search\ModuleSearchGateway::class)
    );
});

$container->singleton(\App\Services\AdvancedSearchService::class, function($c) {
    return new \App\Services\AdvancedSearchService(
        $c->make(\App\Services\Search\AdminSearchProvider::class),
        $c->make(\App\Services\Search\UserSearchProvider::class),
        $c->make(\App\Services\Search\ModuleSearchProvider::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\RateLimiter::class)
    );
});

$container->singleton(\App\Services\AntiFraud\FraudDetectionService::class, function($c) {
    return new \App\Services\AntiFraud\FraudDetectionService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\Core\EventDispatcher::class), // 🚀 UPG-05: تزریق دیسپچر رویدادها
        $c->make(\Core\Logger::class)
    );
});





$container->singleton(\App\Services\DirectMessageService::class, function($c) {
    return new \App\Services\DirectMessageService(
        $c->make(\App\Models\DirectMessage::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\Redis::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\Core\Database::class)
    );
});







$container->singleton(\App\Services\Auth\SessionService::class, function($c) {
    return new \App\Services\Auth\SessionService(
        $c->make(\App\Models\SecurityModel::class),
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\App\Services\DistributedLockService::class),
        $c->make(\Core\Database::class),
        $c->make(\Core\Redis::class),
        $c->make(\App\Contracts\LoggerInterface::class)
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
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\AntiFraud\GeoIPService::class)
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
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});


$container->singleton(\App\Services\AntiFraud\BehavioralBiometricsService::class, function($c) {
    return new \App\Services\AntiFraud\BehavioralBiometricsService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
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
        $c->make(\Core\Session::class), // M34 Fix: تزریق مستقیم سشن منطبق با تغییر سازنده سرویس
        $c->make(\App\Services\AntiFraud\GeoIPService::class),
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
    return new \Core\RateLimiter(
        $c->make(\Core\Cache::class),
        $c->make(\Core\EventDispatcher::class)
    );
});

$container->singleton(\Core\Scheduler::class, function($c) {
    return new \Core\Scheduler(
        $c->make(\App\Models\ActivityLog::class)
    );
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


$container->singleton(\App\Services\QueueWorker::class, function($c) {
    return new \App\Services\QueueWorker(
        $c->make(\Core\Queue::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Cache\CacheInvalidationService::class, function($c) {
    return new \App\Services\Cache\CacheInvalidationService(
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\Core\EventDispatcher::class, function($c) {
    return new \Core\EventDispatcher(
        $c->make(\Core\Queue::class)
    );
});


$container->singleton(\App\Services\OutboxService::class, function($c) {
    return new \App\Services\OutboxService(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\OutboxPublisher::class, function($c) {
    return new \App\Services\OutboxPublisher(
        $c->make(\Core\Database::class),
        $c->make(\Core\Queue::class),
        $c->make(\Core\EventDispatcher::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\Core\IdempotencyKey::class, function($c) {
    return new \Core\IdempotencyKey(
        $c->make(\Core\Database::class),
        $c->make(\Core\Cache::class)
    );
});

// ─── CLI Core framework ────────────────────────────────────────────────────
$container->singleton(\Core\Console\CliDispatcher::class, function($c) {
    // M40 Fix: ارسال صریح پارامتر کانتینر به سازنده دیسپچر جهت جلوگیری از خطای پارامتر در محیط CLI
    $dispatcher = new \Core\Console\CliDispatcher($c);

    // ✅ ثبت مرکزی دستورات خط فرمان به جای Switch-Case های پراکنده
    $dispatcher->register('feature:*', \App\Commands\FeatureFlagCommand::class, 'Feature Flag Management');

    // 🚀 UPG-04: ثبت دستور پیش‌گرمایش کش‌های سنگین داشبورد آماری
    $dispatcher->register('analytics:warm', \App\Commands\AnalyticsCacheWarmupCommand::class, 'Warm up heavy analytics dashboards caches');

    // CORE-063: Formal CLI Command for Route auditing & integrity validation
    $dispatcher->register('route:audit', \App\Commands\RouteAuditCommand::class, 'Perform standard controller and integrity audit for routing tables');

    // Tor update exit nodes list command registration
    $dispatcher->register('tor:update-exit-nodes', \App\Commands\UpdateTorExitNodesCommand::class, 'Update the Tor Exit Nodes database list');

    // Register scheduled tasks processing command
    $dispatcher->register('process:scheduled-tasks', \App\Commands\ProcessScheduledTasksCommand::class, 'Run all system scheduled tasks including expired escrow cleanups');

    // Automatically cleanup and refund expired escrows
    $dispatcher->register('escrow:cleanup-expired', \App\Commands\EscrowCleanupCommand::class, 'Automatically cleanup and refund expired escrows');


    $dispatcher->register('queue:failed:list', \App\Commands\QueueFailedCommand::class, 'List failed queue jobs');
    $dispatcher->register('queue:failed:retry', \App\Commands\QueueFailedCommand::class, 'Retry a failed queue job by id');
    $dispatcher->register('queue:failed:forget', \App\Commands\QueueFailedCommand::class, 'Delete a failed queue job by id');

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
    // 🚀 UPG-05: شنود رویداد تغییر امتیاز فراد و به‌روزرسانی نهایی دیتابیس
    $dispatcher->listen('fraud.score_updated', function($event) use ($c) {
        $data = $event->getData();
        $userId = (int)($data['user_id'] ?? 0);
        $score = (int)($data['score'] ?? 0);
        if ($userId > 0) {
            try {
                $model = $c->make(\App\Models\VelocityAndScoreModel::class);
                $model->updateUserFraudScore($userId, $score);
            } catch (\Throwable $e) {
                if (function_exists('logger')) {
                    logger()->error('event.fraud_score_updated.listener_failed', [
                        'user_id' => $userId,
                        'score'   => $score,
                        'error'   => $e->getMessage()
                    ]);
                }
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
        $c->make(\App\Contracts\NotificationServiceInterface::class)
    );
});

// ─── VitrineService ───────────────────────────────────────────────────────────
$container->singleton(\App\Services\VitrineService::class);

$container->singleton(\App\Services\User\UserScoreService::class);

// ─── Anti-Fraud Domain ────────────────────────────────────────────────────

$container->singleton(\App\Services\AntiFraud\RiskPolicyService::class);
$container->singleton(\App\Services\AntiFraud\RiskDecisionService::class);
$container->singleton(\App\Services\AntiFraud\FraudGuardService::class);
$container->singleton(\App\Services\AntiFraud\VelocityCheckService::class, function($c) {
    return new \App\Services\AntiFraud\VelocityCheckService(
        $c->make(\App\Models\VelocityAndScoreModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Services\DistributedLockService::class)
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
        $c->make(\App\Services\AntiFraud\RiskPolicyService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

// GeoIPService already registered above

$container->singleton(\App\Services\AntiFraud\VideoFingerprintService::class, function($c) {
    return new \App\Services\AntiFraud\VideoFingerprintService(
        $c->make(\Core\Database::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\ApiRateLimiter::class, function($c) {
    return new \App\Services\ApiRateLimiter(
        $c->make(\App\Policies\RateLimitPolicy::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\SocialTask\TrustScoreService::class, function($c) {
    return new \App\Services\SocialTask\TrustScoreService(
        $c->make(\App\Services\Shared\TrustScoreService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
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
        $c->make(\App\Contracts\NotificationServiceInterface::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Contracts\LoggerInterface::class)  // ✅ Logger اضافه شد
    );
});

// Duplicate SocialTaskService binding removed. Replaced by singleton registration earlier.

$container->singleton(\App\Services\SocialTask\RatingService::class, function($c) {
    return new \App\Services\SocialTask\RatingService(
        $c->make(\App\Services\Shared\RatingService::class),
        $c->make(\App\Models\SocialTaskExecutionModel::class),
        $c->make(\App\Models\SocialTaskAnalyticsModel::class),
        $c->make(\App\Services\Shared\TrustScoreService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
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

// Metrics Collector Interface
$container->singleton(\App\Contracts\MetricsCollectorInterface::class, function($c) {
    return $c->make(\App\Services\Metrics\MetricsCollector::class);
});

// Currency Service Interface
$container->singleton(\App\Contracts\CurrencyServiceInterface::class, function($c) {
    return $c->make(\App\Services\CurrencyService::class);
});

// Rate Limiter Interface
$container->singleton(\App\Contracts\RateLimiterInterface::class, function($c) {
    return $c->make(\Core\RateLimiter::class);
});

// Feature Flag Repository Interface
$container->singleton(\App\Contracts\FeatureFlagRepositoryInterface::class, function($c) {
    return $c->make(\App\Services\FeatureFlagService::class);
});

// Search Service Interface
$container->singleton(\App\Contracts\SearchServiceInterface::class, function($c) {
    return $c->make(\App\Services\AdvancedSearchService::class);
});

// ─── Policies ─────────────────────────────────────────────────────
$container->singleton(\App\Policies\FeatureFlagPolicy::class, function($c) {
    return new \App\Policies\FeatureFlagPolicy();
});


// ---------------------------------------------------------------------
// AdSystemManager ? Adapter?? (Unified Ad Service - Sprint 1)
// ---------------------------------------------------------------------

// Adapter??
$container->singleton(\App\Adapters\CustomTaskAdapter::class, function($c) {
    return new \App\Adapters\CustomTaskAdapter(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Adapters\SeoAdAdapter::class, function($c) {
    return new \App\Adapters\SeoAdAdapter(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Adapters\BannerAdapter::class, function($c) {
    return new \App\Adapters\BannerAdapter(
        $c->make(\App\Models\Ads::class), // ارتقا به مدل متمرکز
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class), // آرگومان گمشده ۱
        $c->make(\App\Services\SettingService::class)   // آرگومان گمشده ۲
    );
});

// Vitrine adapter binding removed: Vitrine has its own bounded context via VitrineService.



$container->singleton(\App\Adapters\AdTubeAdapter::class, function($c) {
    return new \App\Adapters\AdTubeAdapter(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Adapters\AdSocialAdapter::class, function($c) {
    return new \App\Adapters\AdSocialAdapter(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class)
    );
});

$container->singleton(\App\Adapters\NotificationAdAdapter::class, function($c) {
    return new \App\Adapters\NotificationAdAdapter(
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
        'custom_task' => $c->make(\App\Adapters\CustomTaskAdapter::class),
        'seo' => $c->make(\App\Adapters\SeoAdAdapter::class),
        'banner' => $c->make(\App\Adapters\BannerAdapter::class),
        'adtube' => $c->make(\App\Adapters\AdTubeAdapter::class),
        'social_task' => $c->make(\App\Adapters\AdSocialAdapter::class),
        'notification' => $c->make(\App\Adapters\NotificationAdAdapter::class),
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
        $c->make(\App\Contracts\NotificationServiceInterface::class),
        $c->make(\App\Models\Dispute::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\App\Services\ReconciliationService::class),
        $c->make(\App\Models\Transaction::class)
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
        $c->make(\App\Models\CouponRedemption::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class)
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
        $c->make(\Core\RateLimiter::class),
        $c->make(\App\Services\Auth\TwoFactorService::class)
    );
});

$container->singleton(\App\Services\BannerService::class, function($c) {
    return new \App\Services\BannerService(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Models\BannerPlacement::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\App\Services\UploadService::class),
        $c->make(\Core\Database::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\CacheAdminService::class, function($c) {
    return new \App\Services\CacheAdminService(
        $c->make(\App\Contracts\CacheInterface::class),
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
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\RateLimiter::class),
        $c->make(\App\Services\CaptchaService::class)
    );
});

$container->singleton(\App\Services\ContentService::class, function($c) {
    return new \App\Services\ContentService(
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\App\Contracts\NotificationServiceInterface::class),
        $c->make(\App\Services\User\UserService::class),
        $c->make(\App\Services\Shared\ReferralService::class),
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

$container->singleton(\App\Services\FileAccessService::class, function($c) {
    return new \App\Services\FileAccessService(
        $c->make(\App\Models\FileAccess::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\InfluencerService::class, function($c) {
    return new \App\Services\InfluencerService(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\App\Contracts\NotificationServiceInterface::class),
        $c->make(\App\Services\Shared\ReferralService::class),
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
        $c->make(\App\Adapters\KycFaceVerificationAdapter::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\Encryption::class),
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

$container->singleton(\App\Services\InvestmentService::class, function($c) {
    return new \App\Services\InvestmentService(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\App\Contracts\NotificationServiceInterface::class),
        $c->make(\App\Services\User\UserService::class),
        $c->make(\App\Services\Shared\ReferralService::class),
        $c->make(\App\Models\Investment::class),
        $c->make(\App\Models\TradingRecord::class),
        $c->make(\App\Models\InvestmentProfit::class),
        $c->make(\App\Models\InvestmentWithdrawal::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\Queue::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Services\PerformanceOptimizationService::class),
        $c->make(\App\Contracts\CurrencyServiceInterface::class)
    );
});

$container->singleton(\App\Services\LotteryService::class, function($c) {
    return new \App\Services\LotteryService(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\App\Contracts\NotificationServiceInterface::class),
        $c->make(\App\Models\LotteryRound::class),
        $c->make(\App\Models\LotteryParticipation::class),
        $c->make(\App\Models\LotteryDailyNumber::class),
        $c->make(\App\Models\LotteryVote::class),
        $c->make(\App\Models\LotteryChanceLog::class),
        $c->make(\App\Services\FeatureFlagService::class),
        \Core\Cache::getInstance(),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\AuditTrail::class)
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
        $c->make(\App\Services\ReconciliationService::class),
        $c->make(\App\Services\UploadService::class),
        $c->make(\App\Services\CurrencyService::class)
    );
});

$container->singleton(\App\Services\MessageModerationService::class, function($c) {
    return new \App\Services\MessageModerationService(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\InteractionModel::class),
        $c->make(\App\Models\MessageModerationModel::class),
        $c->make(\Core\Cache::class)
    );
});

$container->singleton(\App\Commands\MigrationManager::class, function($c) {
    return new \App\Commands\MigrationManager(
        $c->make(\Core\Database::class)
    );
});

$container->singleton(\App\Services\PredictionService::class, function($c) {
    return new \App\Services\PredictionService(
        $c->make(\Core\Database::class),
        $c->make(\App\Models\PredictionGame::class),
        $c->make(\App\Models\PredictionBet::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\AuditTrail::class)
    );
});

$container->singleton(\App\Services\ReferralManagementService::class, function($c) {
    return new \App\Services\ReferralManagementService(
        $c->make(\App\Services\Shared\ReferralService::class),
        $c->make(\App\Models\ReferralCommission::class),
        $c->make(\App\Services\User\UserService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Policies\RolePolicy::class, function($c) {
    return new \App\Policies\RolePolicy(
    );
});

$container->singleton(\App\Services\ScheduledPaymentService::class, function($c) {
    return new \App\Services\ScheduledPaymentService(
        $c->make(\App\Models\ScheduledPayment::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\ReconciliationService::class)
    );
});

$container->singleton(\App\Services\SeoPayoutService::class, function($c) {
    return new \App\Services\SeoPayoutService(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\SeoService::class, function($c) {
    return new \App\Services\SeoService(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Models\SeoExecution::class),
        $c->make(\App\Services\User\UserScoreService::class),
        $c->make(\App\Services\SeoPayoutService::class),
        $c->make(\App\Services\AntiFraud\SeoFraudDetector::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\App\Services\Shared\ReferralService::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Services\Shared\RatingService::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Services\AntiFraud\FraudGuardService::class)
    );
});

$container->singleton(\App\Services\TicketService::class, function($c) {
    return new \App\Services\TicketService(
        $c->make(\App\Models\Ticket::class),
        $c->make(\App\Models\TicketMessage::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Contracts\NotificationServiceInterface::class),
        $c->make(\Core\RateLimiter::class), // 🛡️ مهار مقابله با سوءاستفاده: تزریق محدودیت نرخ ریکوئست
        $c->make(\Core\Redis::class)
    );
});

$container->singleton(\App\Services\UploadService::class, function($c) {
    return new \App\Services\UploadService(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\Core\Database::class)
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
        $c->make(\App\Services\ReconciliationService::class),
        $c->make(\App\Contracts\CurrencyServiceInterface::class),
        $c->make(\App\Services\AntiFraud\FraudGuardService::class),
        $c->make(\Core\Encryption::class)
    );
});

$container->singleton(\App\Adapters\CryptoExplorerAdapter::class, function($c) {
    return new \App\Adapters\CryptoExplorerAdapter(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\CircuitBreaker::class)
    );
});

$container->singleton(\App\Adapters\DeepFaceKycAdapter::class, function($c) {
    return new \App\Adapters\DeepFaceKycAdapter(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\Database::class),
        $c->make(\Core\CircuitBreaker::class)
    );
});

$container->singleton(\App\Adapters\JibitInquiryAdapter::class, function($c) {
    return new \App\Adapters\JibitInquiryAdapter(
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
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Analytics\AnalyticsQueryService::class, function($c) {
    return new \App\Services\Analytics\AnalyticsQueryService(
        $c->make(\Core\Database::class),
        $c->make(\App\Models\KpiStatistics::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Models\CustomTaskAnalyticsModel::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\KYCVerification::class),
        $c->make(\App\Models\Transaction::class),
        $c->make(\Core\Logger::class) // M35 Fix: اصلاح کامل نگاشت‌های اشتباه به private::class و رفع خطای تزریق وابستگی
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
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\Redis::class)
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
        $c->make(\App\Models\Notification::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Notification\NotificationPreferenceService::class, function($c) {
    return new \App\Services\Notification\NotificationPreferenceService(
        $c->make(\App\Models\NotificationPreference::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Notification\NotificationTemplateService::class, function($c) {
    return new \App\Services\Notification\NotificationTemplateService(
        $c->make(\App\Models\Notification::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Notification\NotificationTracker::class, function($c) {
    return new \App\Services\Notification\NotificationTracker(
        $c->make(\App\Models\Notification::class),
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\Notification\SmsNotificationService::class, function($c) {
    return new \App\Services\Notification\SmsNotificationService(
        $c->make(\App\Adapters\SmsNotificationAdapter::class),
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
        $c->make(\App\Models\SentryModel::class),
        $c->make(\App\Contracts\CacheInterface::class),
        $c->make(\Core\Database::class)
    );
});

$container->singleton(\App\Services\Sentry\Analytics\TrendAnalyzer::class, function($c) {
    return new \App\Services\Sentry\Analytics\TrendAnalyzer(
        $c->make(\App\Models\SentryModel::class)
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

$container->singleton(\App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor::class, function($c) {
    return new \App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor(
        $c->make(\App\Models\SentryModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\App\Services\Sentry\Alerting\AlertDispatcher::class),
        (array)config('sentry.performance', [])
    );
});

$container->singleton(\App\Utils\Sentry\BreadcrumbCollector::class, function($c) {
    return new \App\Utils\Sentry\BreadcrumbCollector(
    );
});

$container->singleton(\App\Utils\Sentry\ContextEnricher::class, function($c) {
    return new \App\Utils\Sentry\ContextEnricher(
    );
});

$container->singleton(\App\Utils\Sentry\StackTraceAnalyzer::class, function($c) {
    return new \App\Utils\Sentry\StackTraceAnalyzer(
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
        $c->make(\Core\Database::class),
        $c->make(\App\Models\User::class),
        $c->make(\App\Services\SettingService::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Services\User\AccountDeletionService::class, function($c) {
    return new \App\Services\User\AccountDeletionService(
        $c->make(\App\Models\User::class),
        $c->make(\App\Models\AccountDeletionLog::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\CustomTaskService::class),
        $c->make(\Core\EventDispatcher::class),
        $c->make(\App\Models\Wallet::class),
        $c->make(\App\Services\DistributedLockService::class),
        $c->make(\App\Services\EmailService::class)
    );
});

$container->singleton(\App\Services\User\UserSettingsService::class, function($c) {
    return new \App\Services\User\UserSettingsService(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Models\User::class),
        $c->make(\Core\Cache::class),
        $c->make(\Core\RateLimiter::class)
    );
});


// 🛡️ Register global exception tracking and internal performance monitors
try {
    $container = \Core\Container::getInstance();
    if ($container->has(\App\Services\Sentry\SentryExceptionHandler::class)) {
        $container->make(\App\Services\Sentry\SentryExceptionHandler::class)->register();
    }
} catch (\Throwable $e) {
    // fallback
}

// =========================================================================
// 🔄 Event-Driven Configuration: ثبت شنوندگان رویدادهای اصلی سیستم چورتکه
// =========================================================================
try {
    $dispatcher = $container->make(\Core\EventDispatcher::class);

    // شنونده‌های ماژول احراز هویت (احراز، لاگ، ردپا به صورت پس‌زمینه)
    $dispatcher->listen('auth.login', \App\Listeners\LogUserLoggedInActivity::class);
    $dispatcher->listen('auth.register', \App\Listeners\LogUserRegisteredActivity::class);

    // فعال‌سازی شنونده فراموش‌شده تاریخچه تغییر فیچرفلگ‌ها
    $dispatcher->listen('feature_flag.changed', \App\Listeners\LogFeatureFlagChange::class);

    // ثبت شنونده هوشمند پردازش امتیازهای بحرانی فِراد به صورت پس‌زمینه (🚀 UPG-06)
    $dispatcher->listen('fraud.score_updated', \App\Listeners\ProcessFraudAlert::class);

} catch (\Throwable $e) {
    if (function_exists('logger')) {
        logger()->error('bootstrap.events_registration_failed', [
            'channel' => 'event',
            'error' => $e->getMessage()
        ]);
    }
}

// Application — باید آخرین خط باشد
$app = Application::getInstance();

// Debug: Log registered bindings for troubleshooting
try {
    if (function_exists('logger')) {
        $container = \Core\Container::getInstance();
        $bindings = $container->getBindings();
        logger()->debug('bootstrap.bindings.registered', [
    'total_bindings' => count($bindings),
]);
    }
} catch (\Throwable $ignore) {
    // Ignore during bootstrap
}

return $app;



