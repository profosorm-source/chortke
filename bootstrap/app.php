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
use App\Contracts\WalletServiceInterface;
use App\Services\Notification\NotificationService;
use App\Services\UploadService;
use App\Services\FileAccessService;
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

// â”€â”€ Tracing Context (Correlation ID) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!isset($_SERVER['REQUEST_ID'])) {
    $_SERVER['REQUEST_ID'] = $_SERVER['HTTP_X_REQUEST_ID']
        ?? bin2hex(random_bytes(16));
}

// Load critical non-PSR4 compliant constants from legacy ecosystem
require_once BASE_PATH . '/app/Constants/MagicNumbers.php';

// Composer Autoloader â€” Loads vendor + PSR-4 (Core, App) + Helpers
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
        '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>Ø®Ø·Ø§ÛŒ Ø¨Ø³ØªÙ‡â€ŒÙ‡Ø§ÛŒ Ø³ÛŒØ³ØªÙ…ÛŒ</title>' .
        '<style>body{font-family:Tahoma,sans-serif;padding:50px;background:#fcfcfc;} .box{background:#fff;border-right:5px solid #e74c3c;padding:30px;box-shadow:0 5px 20px rgba(0,0,0,0.05);border-radius:4px;}</style></head>' .
        '<body><div class="box"><h2>Ø®Ø·Ø§ÛŒ Ø±Ø§Ù‡â€ŒØ§Ù†Ø¯Ø§Ø²ÛŒ: Composer autoload ÛŒØ§ÙØª Ù†Ø´Ø¯</h2>' .
        '<p>Ø¨Ø³ØªÙ‡â€ŒÙ‡Ø§ÛŒ PHP Ø³ÛŒØ³ØªÙ… Ù†ØµØ¨ Ù†Ø´Ø¯Ù‡â€ŒØ§Ù†Ø¯. Ù„Ø·ÙØ§Ù‹ Ø¯Ø³ØªÙˆØ± Ø²ÛŒØ± Ø±Ø§ Ø§Ø¬Ø±Ø§ Ù†Ù…Ø§ÛŒÛŒØ¯:</p>' .
        '<pre style="background:#f5f5f5;padding:15px;border-radius:4px;color:#c0392b;font-weight:bold;">composer install</pre></div></body></html>'
    );
}
require_once $vendorAutoload;

// â”€â”€ Hardened Security Defaults (Entry Safeguard) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Helpers Ø§Ø² Ø·Ø±ÛŒÙ‚ composer autoload (files section) Ù„ÙˆØ¯ Ù…ÛŒâ€ŒØ´ÙˆÙ†Ø¯
// Ù†ÛŒØ§Ø²ÛŒ Ø¨Ù‡ require_once Ø¯Ø³ØªÛŒ Ù†ÛŒØ³Øª

// â”€â”€ Ø¨Ø§Ø±Ú¯Ø°Ø§Ø±ÛŒ .env â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
global $env;
if (empty($env)) {
    $envPath = BASE_PATH . '/.env';
    $env = []; // Ù…Ù‚Ø¯Ø§Ø±Ø¯Ù‡ÛŒ Ø§ÙˆÙ„ÛŒÙ‡
    if (file_exists($envPath)) {
        $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
        if ($env === false) {
            $env = [];
            error_log('[Chortke] .env file is invalid or unreadable');
        }
    } else {
        // Ø¨Ø¯ÙˆÙ† .env: Ø§Ù…Ù†ÛŒØª Ø­Ø¯Ø§Ú©Ø«Ø±ÛŒ Ø§Ø² Ù…Ù‚Ø§Ø¯ÛŒØ± Ù¾ÛŒØ´â€ŒÙØ±Ø¶ Ø§Ø³ØªÙØ§Ø¯Ù‡ Ù…ÛŒâ€ŒØ´ÙˆØ¯
    }
}

// Define SECURITY_API_TOKEN_SECRET constant from .env if available
if (!defined('SECURITY_API_TOKEN_SECRET')) {
    $secret = $env['SECURITY_API_TOKEN_SECRET'] ?? getenv('SECURITY_API_TOKEN_SECRET') ?? $_ENV['SECURITY_API_TOKEN_SECRET'] ?? null;
    if ($secret) {
        define('SECURITY_API_TOKEN_SECRET', (string)$secret);
    }
}


// ðŸ›¡ï¸ CRITICAL Infrastructure Integrity Checks
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
            '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>Ø®Ø·Ø§ÛŒ Ø§Ù…Ù†ÛŒØªÛŒ Ø³ÛŒØ³ØªÙ…</title>' .
            '<style>body{font-family:Tahoma,sans-serif;padding:50px;background:#fcfcfc;} .box{background:#fff;border-right:5px solid #e74c3c;padding:30px;box-shadow:0 5px 20px rgba(0,0,0,0.05);border-radius:4px;}</style></head>' .
            '<body><div class="box"><h2>Ø®Ø·Ø§ÛŒ Ø¨Ø­Ø±Ø§Ù†ÛŒ Ø§Ù…Ù†ÛŒØª: Ú©Ù„ÛŒØ¯ Ø±Ù…Ø²Ù†Ú¯Ø§Ø±ÛŒ Ø³ÛŒØ³ØªÙ… ØªÙ†Ø¸ÛŒÙ… Ù†Ø´Ø¯Ù‡ ÛŒØ§ Ù†Ø§Ø§Ù…Ù† Ø§Ø³Øª</h2>' .
            '<p>Ø³ÛŒØ³ØªÙ… Ø¯Ø± Ø­Ø§Ù„Øª Ø¹Ù…Ù„ÛŒØ§ØªÛŒ (Production) Ø§Ø¬Ø§Ø²Ù‡ Ø±Ø§Ù‡â€ŒØ§Ù†Ø¯Ø§Ø²ÛŒ Ø¨Ø§ Ú©Ù„ÛŒØ¯ Ø§Ù…Ù†ÛŒØªÛŒ Ù¾ÛŒØ´â€ŒÙØ±Ø¶ØŒ Ù†Ø§Ù…ÙˆØ¬ÙˆØ¯ ÛŒØ§ Ø¶Ø¹ÛŒÙ Ø±Ø§ Ù†Ù…ÛŒâ€ŒØ¯Ù‡Ø¯. Ù„Ø·ÙØ§ ÙØ§ÛŒÙ„ Ù¾ÛŒÚ©Ø±Ø¨Ù†Ø¯ÛŒ <code>.env</code> Ø±Ø§ Ø¨Ø±Ø±Ø³ÛŒ Ùˆ Ú©Ù„ÛŒØ¯ Ø§Ù…Ù†ÛŒØªÛŒ Ù…Ø¹ØªØ¨Ø±ÛŒ (Ø­Ø¯Ø§Ù‚Ù„ Û³Û² Ú©Ø§Ø±Ø§Ú©ØªØ±) ØªÙ†Ø¸ÛŒÙ… Ù†Ù…Ø§ÛŒÛŒØ¯.</p></div></body></html>'
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

// Set the default timezone globally for both HTTP and CLI/tests execution paths
$timezone = $config['app']['timezone'] ?? 'Asia/Tehran';
date_default_timezone_set($timezone);

// â”€â”€ Unified & Config-Driven PHP Error Configuration â”€â”€
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

// â”€â”€â”€ Logger â€” Singleton Ù…Ø±Ú©Ø²ÛŒ Ù„Ø§Ú¯ â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// PSR-3 Compatible Logging System
$container->singleton(\App\Services\LogService::class);
$container->singleton(\App\Contracts\LoggerInterface::class, function($c) {
    $logger = new \Core\Logger();
    // âœ… Ø§Ù†ØªØ´Ø§Ø± Ø³Ø±Ø§Ø³Ø±ÛŒ Trace ID Ø¯Ø± ØªÙ…Ø§Ù…ÛŒ Ù„Ø§Ú¯â€ŒÙ‡Ø§ÛŒ Ø§ÛŒØ¬Ø§Ø¯ Ø´Ø¯Ù‡ Ø¯Ø± Ú†Ø±Ø®Ù‡ Ø±ÛŒÚ©ÙˆØ¦Ø³Øª HTTP
    if (isset($_SERVER['REQUEST_ID']) && method_exists($logger, 'setExtraContext')) {
        $logger->setExtraContext(['trace_id' => $_SERVER['REQUEST_ID']]);
    }
    return $logger;
});

// Ensure CacheInterface is available early to avoid resolution order issues
$container->singleton(\App\Contracts\CacheInterface::class, function($c) {
    return new \App\Services\Cache\CacheManager(
        \Core\Cache::getInstance(),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

// =========================
// Sentry-like Services
// =========================

$container->singleton(\App\Models\SentryModel::class);

$container->singleton(\App\Services\Sentry\Alerting\AlertDispatcher::class);



$container->singleton(\App\Services\CryptoDeposit\CryptoDepositService::class);

// CryptoDeposit Adapters
$container->singleton(\App\Adapters\CryptoVerificationAdapter::class, \App\Adapters\CryptoExplorerAdapter::class);

$container->singleton(\App\Adapters\CryptoApiAdapter::class, function($c) {
    return new \App\Adapters\CryptoApiAdapter(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Settings\AppSettings::class),
        $c->make(\Core\CircuitBreaker::class)
    );
});

// Bank Inquiry Adapter (Automatic Fallback enabled)
$container->bind(\App\Adapters\BankInquiryAdapter::class, function($c) {
    $logger = $c->make(\App\Contracts\LoggerInterface::class);
    return new \App\Adapters\BankInquiryManager($logger, [
        $c->make(\App\Adapters\JibitInquiryAdapter::class),
        $c->make(\App\Adapters\VandarInquiryAdapter::class),
    ]);
});

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
\App\Services\Sentry\SentryExceptionHandler::setInstance($container->make(\App\Services\Sentry\SentryExceptionHandler::class));

$container->singleton(App\Services\AuditTrail::class);



// Ø«Ø¨Øª Ø³Ø±ÙˆÛŒØ³â€ŒÙ‡Ø§ Ùˆ Ù…Ø¯Ù„â€ŒÙ‡Ø§
$container->singleton(Session::class, function() {
    return Session::getInstance();
});






$container->singleton(\App\Services\EscrowService::class);

$container->singleton(\App\Domain\Financial\Services\FinancialEscrowService::class);

$container->singleton(\App\Services\StateMachineService::class);





$container->bind(App\Models\AdvancedAnalytics::class);


// ========== Analytics Services (Consolidated) ==========
$container->singleton(\App\Services\Analytics\AnalyticsService::class);
$container->singleton(\App\Services\Shared\DashboardStatsService::class);


$container->singleton(\App\Models\SecurityModel::class);

$container->singleton(\App\Models\User::class);



$container->singleton(\App\Services\User\ProfileService::class);



$container->singleton(\App\Services\Auth\TwoFactorService::class);

$container->singleton(\App\Services\Auth\AuthService::class);



// User Model is already registered above as \App\Models\User::class

$container->singleton(\App\Services\Settings\AppSettings::class);

$container->singleton(\App\Models\Setting::class);


// â”€â”€â”€ Singletons: Simple Services â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

$container->singleton(\App\Contracts\WalletServiceInterface::class, function($c) {
    return $c->make(\App\Services\Wallet\WalletService::class);
});
$container->singleton(\App\Contracts\ValidatorFactoryInterface::class, App\Services\ValidatorFactory::class);
$container->singleton(\App\Services\WalletLockManager::class);

// AntiFraud services use \App\Services\AntiFraud\GeoIPService for consistent geolocation checks

// â”€â”€â”€ Distributed Lock Service â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$container->singleton(\App\Services\DistributedLockService::class);

$container->singleton(\App\Services\RedisEmailQueueService::class);



$container->singleton(\App\Services\EmailService::class);

$container->singleton(\App\Contracts\EmailServiceInterface::class, function($c) {
    return $c->make(\App\Services\EmailService::class);
});

// Core Infrastructure Context removed

$container->singleton(\App\Services\Notification\NotificationService::class);
$container->singleton(\App\Contracts\NotificationServiceInterface::class, function($c) {
    return $c->make(\App\Services\Notification\NotificationService::class);
});

$container->singleton(\App\Services\Notification\NotificationRetryPolicy::class);

$container->singleton(\App\Services\Notification\NotificationDispatcher::class);

// â”€â”€â”€ AdNotificationDispatcher (with batch optimization) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$container->singleton(\App\Services\AdNotificationDispatcher::class);

$container->singleton(\App\Adapters\Notification\PushNotificationAdapter::class, function($c) {
    return new \App\Adapters\Notification\PushNotificationAdapter(
        $c->make(\App\Adapters\Notification\FcmNotificationAdapter::class),
        $c->make(\Core\Logger::class)
    );
});

$container->singleton(\App\Adapters\Notification\SmsNotificationAdapter::class, function($c) {
    return new \App\Adapters\Notification\SmsNotificationAdapter(
        $c->make(\App\Models\User::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\CircuitBreaker::class)
    );
});

$container->singleton(\App\Adapters\Notification\FcmNotificationAdapter::class, function($c) {
    return new \App\Adapters\Notification\FcmNotificationAdapter(
        $c->make(\Core\Logger::class),
        $c->make(\Core\Cache::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\MetricsCollectorInterface::class),
        $c->make(\Core\CircuitBreaker::class)
    );
});

$container->singleton(\App\Adapters\Notification\LogNotificationAdapter::class, function($c) {
    return new \App\Adapters\Notification\LogNotificationAdapter(
        $c->make(\App\Models\Notification::class),
        $c->make(\App\Models\SystemTelemetryModel::class),
        $c->make(\Core\Logger::class),
        $c->make(\Core\CircuitBreaker::class)
    );
});

$container->singleton(\App\Services\Notification\FcmService::class);






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





$container->singleton(\App\Services\AntiFraud\GeoIPService::class);



$container->singleton('oauth_config', function() {
    return [
        'google_client_id' => (string)config('oauth.google.client_id', ''),
        'google_client_secret' => (string)config('oauth.google.client_secret', ''),
        'facebook_app_id' => (string)config('oauth.facebook.app_id', ''),
        'facebook_app_secret' => (string)config('oauth.facebook.app_secret', ''),
        'app_url' => (string)config('app.url', 'http://localhost'),
    ];
});

$container->singleton(\App\Services\Auth\GoogleJwtVerifier::class);

$container->singleton(\App\Services\Auth\OAuthService::class);


$container->bind(App\Models\CustomTaskSubmissionModel::class, function($c) {
    return new App\Models\CustomTaskSubmissionModel($c->make(Database::class));
});

$container->bind(App\Models\CustomTaskAnalyticsModel::class, function($c) {
    return new App\Models\CustomTaskAnalyticsModel($c->make(Database::class));
});

$container->bind(App\Models\UserVacation::class, function($c) {
    return new App\Models\UserVacation($c->make(Database::class));
});

// Service - CustomTaskService (Sprint 4 auto-wired singleton)
$container->singleton(\App\Services\CustomTask\CustomTaskService::class);
$container->singleton(App\Services\UnifiedTaskService::class);

// Binding for non-existent XPEngine removed
$container->singleton(\App\Services\User\UserLevelService::class);

$container->singleton(\App\Services\Cron\CronService::class);

// Controllers


// CryptoDepositService singleton is already registered above with full dependencies

$container->singleton(\App\Services\Payment\PaymentGatewayFactory::class);

$container->singleton(\App\Services\Payment\PaymentService::class);











// Binding for non-existent InfluencerReputationService removed









// Legacy SEOTaskService binding removed: class does not exist; use App\Services\SeoService instead.


$container->singleton(\App\Services\User\UserDashboardService::class);


// â”€â”€â”€ Auto-generated Model Bindings â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€


$container->bind(App\Models\BankCard::class);
$container->bind(App\Models\BannerPlacement::class);
$container->bind(App\Models\ContentAgreement::class);
$container->bind(App\Models\ContentRevenue::class);
$container->bind(App\Models\ContentSubmission::class);
$container->bind(App\Models\CryptoDeposit::class);
$container->bind(App\Models\CryptoDepositIntent::class);
$container->bind(App\Models\EmailQueue::class);
$container->bind(App\Models\BackupLog::class);
$container->bind(App\Models\ContactMessage::class);
$container->bind(App\Models\CaptchaLog::class);
$container->bind(App\Models\BulkOperation::class);
$container->bind(App\Models\Ads::class);
$container->bind(App\Models\CronJob::class);
$container->bind(App\Models\InvestmentProfit::class);
$container->bind(App\Models\InvestmentWithdrawal::class);
$container->bind(App\Models\KYCVerification::class);
$container->bind(App\Models\DirectMessage::class);
$container->bind(App\Models\FileAccess::class);
$container->bind(App\Models\LotteryDailyNumber::class);
$container->bind(App\Models\LotteryParticipation::class);
$container->bind(App\Models\LotteryVote::class);
$container->bind(App\Models\ManualDeposit::class);
$container->bind(App\Models\NotificationPreference::class);
$container->bind(App\Models\Page::class);
$container->bind(App\Models\SeoExecution::class);
$container->bind(App\Models\StoryOrder::class);
$container->bind(App\Models\Ticket::class);
$container->bind(App\Models\TicketCategory::class);
$container->bind(App\Models\TicketMessage::class);
$container->bind(App\Models\TradingRecord::class);
$container->bind(App\Models\Withdrawal::class);
$container->bind(App\Models\WithdrawalLimit::class);
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




$container->singleton(\App\Services\ExportService::class);

// ─── Search CQRS Read-Model infrastructure (C1 fix) ───────────────────────
$container->singleton(\App\Services\Search\SchemaInspector::class);

$container->singleton(\App\Services\Search\SearchIndexer::class);

$container->singleton(\App\Services\Search\SearchProjectionRepository::class);

$container->singleton(\App\Services\Search\SearchProjectionListener::class);

$container->singleton(\App\Services\Search\AdminSearchGateway::class);

$container->singleton(\App\Services\Search\UserSearchGateway::class);

$container->singleton(\App\Services\Search\ModuleSearchGateway::class);

$container->singleton(\App\Services\Search\AdminSearchProvider::class);
$container->singleton(\App\Services\Search\UserSearchProvider::class);
$container->singleton(\App\Services\Search\ModuleSearchProvider::class);

$container->singleton(\App\Services\Search\SearchOrchestrator::class, function($c) {
    $orchestrator = new \App\Services\Search\SearchOrchestrator(
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\Core\RateLimiter::class)
    );
    
    $orchestrator->registerProvider($c->make(\App\Services\Search\AdminSearchProvider::class));
    $orchestrator->registerProvider($c->make(\App\Services\Search\UserSearchProvider::class));
    $orchestrator->registerProvider($c->make(\App\Services\Search\ModuleSearchProvider::class));
    
    return $orchestrator;
});


$container->singleton(\App\Services\AntiFraud\FraudDetectionService::class);





$container->singleton(\App\Services\DirectMessageService::class);







$container->singleton(\App\Services\Auth\SessionService::class);

$container->singleton(\App\Services\SitemapService::class);

$container->singleton(\App\Services\SocialAccountService::class);

$container->singleton(\App\Services\User\UserService::class);






$container->singleton(\App\Services\AntiFraud\IPQualityService::class);

$container->singleton(\App\Services\AntiFraud\FraudManagementService::class);

$container->singleton(\App\Services\AntiFraud\SeoFraudDetector::class);

$container->singleton(\App\Services\AntiFraud\MLFraudDetectionService::class);

$container->singleton(\App\Services\AntiFraud\BrowserFingerprintService::class);


$container->singleton(\App\Services\AntiFraud\BehavioralBiometricsService::class);



$container->singleton(\App\Services\AntiFraud\GraphAnalysisService::class);

$container->singleton(\App\Services\AntiFraud\GeolocationIntelligenceService::class);

$container->singleton(\App\Services\AntiFraud\FraudDashboardService::class);

$container->singleton(\App\Services\AntiFraud\EmailPhoneIntelligenceService::class);

$container->singleton(\App\Services\AntiFraud\DeviceIntelligenceService::class);

$container->singleton(\App\Services\AntiFraud\AccountTakeoverService::class);


// â”€â”€â”€ FeatureFlagService â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$container->singleton(\App\Services\FeatureFlagService::class);

// â”€â”€â”€ Core Services â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$container->singleton(\Core\RateLimiter::class, function($c) {
    return new \Core\RateLimiter(
        $c->make(\Core\Cache::class),
        $c->make(\Core\EventDispatcher::class),
        $c->make(\App\Services\AntiFraud\RateLimitingService::class),
        $c->make(\Core\Logger::class)
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
        $c->make(\Core\Cache::class),
        $c->make(\App\Contracts\LoggerInterface::class)
    );
});

$container->singleton(\App\Contracts\CircuitBreakerInterface::class, \Core\CircuitBreaker::class);

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


$container->singleton(\App\Services\QueueWorker::class);
$container->singleton(\App\Services\DlqWorker::class);

$container->singleton(\App\Services\Cache\CacheInvalidationService::class);

$container->singleton(\Core\EventDispatcher::class, function($c) {
    return new \Core\EventDispatcher(
        $c->make(\Core\Queue::class)
    );
});


$container->singleton(\App\Services\OutboxService::class);

$container->singleton(\App\Contracts\OutboxServiceInterface::class, function($c) {
    return $c->make(\App\Services\OutboxService::class);
});

$container->singleton(\App\Services\OutboxPublisher::class);


$container->singleton(\Core\IdempotencyKey::class, function($c) {
    return new \Core\IdempotencyKey(
        $c->make(\Core\Database::class),
        $c->make(\Core\Cache::class)
    );
});

// â”€â”€â”€ CLI Core framework â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$container->singleton(\Core\Console\CliDispatcher::class, function($c) {
    // M40 Fix: Ø§Ø±Ø³Ø§Ù„ ØµØ±ÛŒØ­ Ù¾Ø§Ø±Ø§Ù…ØªØ± Ú©Ø§Ù†ØªÛŒÙ†Ø± Ø¨Ù‡ Ø³Ø§Ø²Ù†Ø¯Ù‡ Ø¯ÛŒØ³Ù¾Ú†Ø± Ø¬Ù‡Øª Ø¬Ù„ÙˆÚ¯ÛŒØ±ÛŒ Ø§Ø² Ø®Ø·Ø§ÛŒ Ù¾Ø§Ø±Ø§Ù…ØªØ± Ø¯Ø± Ù…Ø­ÛŒØ· CLI
    $dispatcher = new \Core\Console\CliDispatcher($c);

    // âœ… Ø«Ø¨Øª Ù…Ø±Ú©Ø²ÛŒ Ø¯Ø³ØªÙˆØ±Ø§Øª Ø®Ø· ÙØ±Ù…Ø§Ù† Ø¨Ù‡ Ø¬Ø§ÛŒ Switch-Case Ù‡Ø§ÛŒ Ù¾Ø±Ø§Ú©Ù†Ø¯Ù‡
    $dispatcher->register('feature:*', \App\Commands\FeatureFlagCommand::class, 'Feature Flag Management');

    // ðŸš€ UPG-04: Ø«Ø¨Øª Ø¯Ø³ØªÙˆØ± Ù¾ÛŒØ´â€ŒÚ¯Ø±Ù…Ø§ÛŒØ´ Ú©Ø´â€ŒÙ‡Ø§ÛŒ Ø³Ù†Ú¯ÛŒÙ† Ø¯Ø§Ø´Ø¨ÙˆØ±Ø¯ Ø¢Ù…Ø§Ø±ÛŒ
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
    $dispatcher->register('queue:failed:retry-batch', \App\Commands\QueueFailedCommand::class, 'Re-queue a batch of failed jobs (optionally filtered by --queue)');
    $dispatcher->register('queue:failed:purge',       \App\Commands\QueueFailedCommand::class, 'Purge failed_jobs older than --days');
    $dispatcher->register('queue:failed:stats',       \App\Commands\QueueFailedCommand::class, 'Show DLQ size grouped by queue');

    $dispatcher->register('alert:bootstrap-dlq', \App\Commands\AlertRulesBootstrapCommand::class, 'Register DLQ alert rules (failed_jobs > 20/50)');

    $dispatcher->register('idempotency:stats',   \App\Commands\IdempotencyCommand::class, 'Show idempotency_keys totals grouped by status');
    $dispatcher->register('idempotency:cleanup', \App\Commands\IdempotencyCommand::class, 'Delete expired idempotency_keys (use --dry-run to preview)');

    $dispatcher->register('ratelimit:audit', \App\Commands\RateLimitAuditCommand::class, 'Audit unified rate-limit policy (config/rate_limits.php)');

    $dispatcher->register('withdrawals:review:scan',     \App\Commands\StuckWithdrawalReviewCommand::class, 'Detect & flag stuck withdrawals for admin review (safe)');
    $dispatcher->register('withdrawals:review:auto-fix', \App\Commands\StuckWithdrawalReviewCommand::class, 'Auto-resolve only deterministic stuck withdrawals (tx failed/cancelled)');
    $dispatcher->register('withdrawals:review:list',     \App\Commands\StuckWithdrawalReviewCommand::class, 'List open stuck-withdrawal reviews');
    $dispatcher->register('withdrawals:review:resolve',  \App\Commands\StuckWithdrawalReviewCommand::class, 'Admin: mark a stuck-withdrawal review as resolved');
    $dispatcher->register('withdrawals:review:dismiss',  \App\Commands\StuckWithdrawalReviewCommand::class, 'Admin: dismiss a stuck-withdrawal review');

    $dispatcher->register('outbox:publish',              \App\Commands\OutboxPublishCommand::class, 'Publish pending outbox_events (transactional outbox worker)');
    $dispatcher->register('queue:work',                  \App\Commands\QueueWorkCommand::class, 'Run the queue worker daemon to process queued jobs');
    $dispatcher->register('dlq:work',                    \App\Commands\DlqWorkCommand::class, 'Run the dead letter queue worker to process poison messages');

    $dispatcher->register('search:backfill',             \App\Commands\BackfillSearchProjectionCommand::class, 'Backfill the search_projections read-model from live tables (CQRS)');
    $dispatcher->register('system:cleanup',              \App\Commands\SystemCleanupCommand::class, 'Run system cleanup jobs (logs, outbox, audit, etc.)');

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

    // rate_limit.exceeded and fraud.score_updated handled by typed listeners registered elsewhere

    // Event Subscriber for Cache Invalidation
    $c->make(\App\Services\Cache\CacheInvalidationService::class)->subscribe($dispatcher);

    return $dispatcher;
});

// Boot event listeners immediately so closures are registered.
try {
    $container->make('event.bootstrap');
} catch (\Throwable $e) {
    if (PHP_SAPI !== 'cli') {
        throw $e;
    }
    error_log('[Chortke] Event bootstrap failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

// â”€â”€â”€ DashboardQueryService (with Performance tracking) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$container->singleton(\App\Services\AdminDashboard\DashboardQueryService::class);

// â”€â”€â”€ AdminDashboardService â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€


// â”€â”€â”€ BulkOperationsService â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$container->singleton(\App\Services\BulkOperationsService::class);

// â”€â”€â”€ VitrineService â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$container->singleton(\App\Services\VitrineService::class);

// UserScoreService removed

// â”€â”€â”€ Anti-Fraud Domain â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

$container->singleton(\App\Services\AntiFraud\RiskPolicyService::class);
$container->singleton(\App\Services\AntiFraud\RiskDecisionService::class);
$container->singleton(\App\Services\AntiFraud\FraudGuardService::class);
$container->singleton(\App\Services\AntiFraud\VelocityCheckService::class);
// SocialTaskScoringService removed
$container->singleton(\App\Services\AntiFraud\TorListUpdater::class);

$container->singleton(\App\Services\AntiFraud\SessionAnomalyService::class);

// GeoIPService already registered above

$container->singleton(\App\Services\AntiFraud\VideoFingerprintService::class);

$container->singleton(\App\Services\ApiRateLimiter::class);

// App\Services\SocialTask\TrustScoreService removed

$container->singleton(\App\Services\SocialTask\SilentAntiFraudService::class);

// Duplicate SocialTaskService binding removed. Replaced by singleton registration earlier.

// App\Services\SocialTask\RatingService removed





// â”€â”€â”€ Phase 3 Services â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// âœ… Real-time, Caching, Verification, Performance Optimization

$container->singleton(\App\Services\WebSocketService::class);

$container->singleton(\App\Services\VerificationService::class);

$container->singleton(\App\Services\PerformanceOptimizationService::class);



// â”€â”€â”€ Phase 5e: Advanced Settings & Management â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

$container->singleton(\App\Services\DataExportService::class);





// â”€â”€â”€ BackupService (Phase 5e) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$container->singleton(\App\Services\BackupService::class);



// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// â”€â”€â”€ Contracts Bindings Ø¨Ø±Ø§ÛŒ DI Ùˆ ØªØ³Øªâ€ŒÙ¾Ø°ÛŒØ±ÛŒ â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

// Cache Interface (Moved to top)

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
    return $c->make(\App\Services\Search\SearchOrchestrator::class);
});

// â”€â”€â”€ Policies â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
        $c->make(\App\Services\Settings\AppSettings::class),
        $c->make(\App\Contracts\ValidatorFactoryInterface::class)
    );
});

$container->singleton(\App\Adapters\SeoAdAdapter::class, function($c) {
    return new \App\Adapters\SeoAdAdapter(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Settings\AppSettings::class),
        $c->make(\App\Contracts\ValidatorFactoryInterface::class)
    );
});

$container->singleton(\App\Adapters\BannerAdapter::class, function($c) {
    return new \App\Adapters\BannerAdapter(
        $c->make(\App\Models\Ads::class), // Ø§Ø±ØªÙ‚Ø§ Ø¨Ù‡ Ù…Ø¯Ù„ Ù…ØªÙ…Ø±Ú©Ø²
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class), // Ø¢Ø±Ú¯ÙˆÙ…Ø§Ù† Ú¯Ù…Ø´Ø¯Ù‡ Û±
        $c->make(\App\Services\Settings\AppSettings::class),   // Ø¢Ø±Ú¯ÙˆÙ…Ø§Ù† Ú¯Ù…Ø´Ø¯Ù‡ Û²
        $c->make(\App\Contracts\ValidatorFactoryInterface::class)
    );
});

// Vitrine adapter binding removed: Vitrine has its own bounded context via VitrineService.



$container->singleton(\App\Adapters\AdTubeAdapter::class, function($c) {
    return new \App\Adapters\AdTubeAdapter(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Settings\AppSettings::class),
        $c->make(\App\Contracts\ValidatorFactoryInterface::class)
    );
});

$container->singleton(\App\Adapters\AdSocialAdapter::class, function($c) {
    return new \App\Adapters\AdSocialAdapter(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Settings\AppSettings::class),
        $c->make(\App\Contracts\ValidatorFactoryInterface::class)
    );
});

$container->singleton(\App\Adapters\NotificationAdAdapter::class, function($c) {
    return new \App\Adapters\NotificationAdAdapter(
        $c->make(\App\Models\Ads::class),
        $c->make(\App\Contracts\WalletServiceInterface::class),
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        $c->make(\App\Services\Settings\AppSettings::class),
        $c->make(\App\Contracts\ValidatorFactoryInterface::class)
    );
});

// AdSystemManager
$container->singleton(\App\Contracts\AdsRepositoryInterface::class, \App\Models\Ads::class);

$container->singleton(\App\Services\AdSystemManager::class, function($c) {
    return new \App\Services\AdSystemManager(
        $c->make(\Core\Database::class),
        $c->make(\App\Contracts\LoggerInterface::class),
        [
            'custom_task' => $c->make(\App\Adapters\CustomTaskAdapter::class),
            'seo' => $c->make(\App\Adapters\SeoAdAdapter::class),
            'banner' => $c->make(\App\Adapters\BannerAdapter::class),
            'youtube' => $c->make(\App\Adapters\AdTubeAdapter::class),
            'social' => $c->make(\App\Adapters\AdSocialAdapter::class),
            'notification' => $c->make(\App\Adapters\NotificationAdAdapter::class),
        ],
        $c->make(\App\Contracts\AdsRepositoryInterface::class)
    );
});

// ---------------------------------------------------------------------
// Transaction Reversal & Reconciliation Services (Sprint 2-3)
// ---------------------------------------------------------------------


$container->singleton(\App\Services\ReconciliationService::class);

// Old ReferralService (@App\Services) is deprecated
// Use Shared\ReferralService instead

// PolicyService has been moved to Shared\PolicyService

// Legacy binding removed - see Shared\PolicyService instead


// ---------------------------------------------------------------------
// Sprint 6: Upload Enforcement (already exists in app.php)
// ---------------------------------------------------------------------


// â”€â”€â”€ Shared Services â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$container->singleton(\App\Services\Shared\DisputeService::class);

$container->singleton(\App\Services\ScoreService::class);

  // Register Score Projection Listener for CQRS
  $dispatcher = $container->make(\Core\EventDispatcher::class);
  $dispatcher->listen(\App\Events\ScoreDeltaAppendedEvent::class, [\App\Listeners\ScoreProjectionListener::class, 'handle']);

// Register Global Score Listener
$dispatcher = $container->make(\Core\EventDispatcher::class);
$dispatcher->listen('notification.sent', [\App\Listeners\DomainActivityListener::class, 'handle']);
$dispatcher->listen('influencer.order_completed', [\App\Listeners\DomainActivityListener::class, 'handle']);
$dispatcher->listen('influencer.order_rejected', [\App\Listeners\DomainActivityListener::class, 'handle']);
$dispatcher->listen('content.rated', [\App\Listeners\DomainActivityListener::class, 'handle']);
$dispatcher->listen('kyc.status_changed', [\App\Listeners\DomainActivityListener::class, 'handle']);
$dispatcher->listen('queue.failed', [\App\Listeners\DomainActivityListener::class, 'handle']);
$dispatcher->listen('reconciliation.failed', [\App\Listeners\DomainActivityListener::class, 'handle']);

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// Event Sourcing: Events as Source of Truth for Audit Trail
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// Event audit is now handled centrally by Core\EventDispatcher to cover all dispatched events.

// App\Services\Shared\RatingService removed



$container->singleton(\App\Services\Shared\ReferralService::class);

$container->singleton(\App\Services\Shared\CouponService::class);


$container->singleton(\App\Services\Shared\PolicyService::class);


// See: SERVICES_AUDIT_INCOMPLETE.md for details

// --- MISSING SERVICES AUTO-REGISTERED ---
// Missing bindings to append to bootstrap/app.php

$container->singleton(\App\Services\ApiTokenService::class);

$container->singleton(\App\Services\BannerService::class);

$container->singleton(\App\Services\CacheAdminService::class);

$container->singleton(\App\Services\CaptchaService::class);

$container->singleton(\App\Services\ContactService::class);

$container->singleton(\App\Services\ContentService::class);

$container->singleton(\App\Services\CurrencyService::class);

$container->singleton(\App\Services\FileAccessService::class);

$container->singleton(\App\Services\InfluencerService::class);

$container->singleton(\App\Services\KYCService::class);

$container->singleton(\App\Domain\Financial\Services\LedgerService::class);

$container->singleton(\App\Services\InvestmentService::class);

// Ø«Ø¨Øª Ø±ÙˆÛŒØ¯Ø§Ø¯Ù‡Ø§ÛŒ Ø³Ø±Ù…Ø§ÛŒÙ‡â€ŒÚ¯Ø°Ø§Ø±ÛŒ Ø¯Ø± Ù„ÛŒØ³Ù†Ø± Ù…Ø´ØªØ±Ú©
$dispatcher = $container->make(\Core\EventDispatcher::class);

// Ú©Ù„Ø§Ø³â€ŒÙ…Ø­ÙˆØ±: Ø«Ø¨Øª listener Ø§Ø®ØªØµØ§ØµÛŒ Ø³Ø±Ù…Ø§ÛŒÙ‡â€ŒÚ¯Ø°Ø§Ø±ÛŒ Ø¨Ø±Ø§ÛŒ Ø³Ø§Ø²Ú¯Ø§Ø±ÛŒ Ø¨Ø§ Event classes
$investmentEventListeners = $container->make(\App\Listeners\InvestmentEventListeners::class);
$dispatcher->listen(\App\Events\InvestmentCreatedEvent::class, [$investmentEventListeners, 'handleInvestmentCreated']);

$container->singleton(\App\Services\Lottery\LotteryService::class);

$container->singleton(\App\Services\ManualDepositService::class);

$container->singleton(\App\Services\MessageModerationService::class);

$container->singleton(\App\Services\MigrationService::class);

$container->singleton(\App\Services\DatabaseService::class);

$container->singleton(\App\Services\PredictionService::class);

$container->singleton(\App\Services\ReferralManagementService::class);

$container->singleton(\App\Policies\RolePolicy::class, function($c) {
    return new \App\Policies\RolePolicy(
    );
});

$container->singleton(\App\Services\ScheduledPaymentService::class);

$container->singleton(\App\Services\SeoPayoutService::class);

$container->singleton(\App\Services\Seo\SeoService::class);

$container->singleton(\App\Services\TicketService::class);

$container->singleton(\App\Services\UploadService::class);

$container->singleton(\App\Contracts\UploadServiceInterface::class, function($c) {
    return $c->make(\App\Services\UploadService::class);
});

$container->singleton(\App\Services\VitrineSettingsService::class);


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

$container->singleton(\App\Services\AdminDashboard\AdminDashboardService::class);

$container->singleton(\App\Services\AdminDashboard\SystemMonitoringService::class);

$container->singleton(\App\Services\Analytics\AnalyticsQueryService::class);

$container->singleton(\App\Services\Analytics\AnalyticsExporter::class);

$container->singleton(\App\Services\AntiFraud\RateLimitingService::class);

$container->singleton(\App\Services\Auth\LoginRiskService::class);

$container->singleton(\App\Services\Cache\CacheManager::class, function($c) {
    return $c->make(\App\Contracts\CacheInterface::class);
});

$container->singleton(\App\Services\Notification\NotificationAnalyticsService::class);

$container->singleton(\App\Services\Notification\NotificationPreferenceService::class);

$container->singleton(\App\Services\Notification\NotificationTemplateService::class);

$container->singleton(\App\Services\Notification\NotificationTracker::class);

$container->singleton(\App\Services\Notification\SmsNotificationService::class);

$container->singleton(\App\Services\Payment\DgPayGateway::class);

$container->singleton(\App\Services\Payment\IDPayGateway::class);

$container->singleton(\App\Services\Payment\NextPayGateway::class);

$container->singleton(\App\Services\Payment\ZarinPalGateway::class);

$container->singleton(\App\Services\Sentry\Alerting\AlertRulesEngine::class);

$container->singleton(\App\Services\Sentry\Alerting\EscalationManager::class);

$container->singleton(\App\Services\Sentry\Analytics\DashboardService::class);

$container->singleton(\App\Services\Sentry\Analytics\TrendAnalyzer::class);

$container->singleton(\App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor::class);

$container->singleton(\App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor::class);

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

// ScoreEventService removed

// TrustScoreService removed

$container->singleton(\App\Services\User\AccountDeletionService::class);

$container->singleton(\App\Services\User\UserSettingsService::class);

if (config('app.env') !== 'production' || getenv('DI_VALIDATE_BINDINGS') === '1') {
    $container->validateBindings();
}

// ðŸ›¡ï¸ Register global exception tracking and internal performance monitors
try {
    $container = \Core\Container::getInstance();
    if ($container->has(\App\Services\Sentry\SentryExceptionHandler::class)) {
        $container->make(\App\Services\Sentry\SentryExceptionHandler::class)->register();
    }
} catch (\Throwable $e) {
    // fallback
}

// =========================================================================
// ðŸ”„ Event-Driven Configuration: Ø«Ø¨Øª Ø´Ù†ÙˆÙ†Ø¯Ú¯Ø§Ù† Ø±ÙˆÛŒØ¯Ø§Ø¯Ù‡Ø§ÛŒ Ø§ØµÙ„ÛŒ Ø³ÛŒØ³ØªÙ… Ú†ÙˆØ±ØªÚ©Ù‡
// =========================================================================
try {
    $dispatcher = $container->make(\Core\EventDispatcher::class);

    // Ø´Ù†ÙˆÙ†Ø¯Ù‡â€ŒÙ‡Ø§ÛŒ Ù…Ø§Ú˜ÙˆÙ„ Ø§Ø­Ø±Ø§Ø² Ù‡ÙˆÛŒØª (Ú©Ù„Ø§Ø³â€ŒÙ…Ø­ÙˆØ± Ø¨Ø±Ø§ÛŒ DI)
    $loginHandler = $container->make(\App\Listeners\LogUserLoggedInActivity::class);
    $dispatcher->listen(\App\Events\UserLoggedInEvent::class, [$loginHandler, 'handle']);

    $registerHandler = $container->make(\App\Listeners\LogUserRegisteredActivity::class);
    $dispatcher->listen(\App\Events\UserRegisteredEvent::class, [$registerHandler, 'handle']);

    // ÙØ¹Ø§Ù„â€ŒØ³Ø§Ø²ÛŒ Ø´Ù†ÙˆÙ†Ø¯Ù‡ ØªØ§Ø±ÛŒØ®Ú†Ù‡ ØªØºÛŒÛŒØ± ÙÛŒÚ†Ø±ÙÙ„Ú¯â€ŒÙ‡Ø§ (Ú©Ù„Ø§Ø³â€ŒÙ…Ø­ÙˆØ±)
    $featureFlagHandler = $container->make(\App\Listeners\LogFeatureFlagChange::class);
    $dispatcher->listen(\App\Events\FeatureFlagChanged::class, [$featureFlagHandler, 'handle']);

    // Ø«Ø¨Øª Ø´Ù†ÙˆÙ†Ø¯Ù‡ Ù‡ÙˆØ´Ù…Ù†Ø¯ Ù¾Ø±Ø¯Ø§Ø²Ø´ Ø§Ù…ØªÛŒØ§Ø²Ù‡Ø§ÛŒ Ø¨Ø­Ø±Ø§Ù†ÛŒ ÙÙØ±Ø§Ø¯ Ø¨Ù‡ ØµÙˆØ±Øª Ù¾Ø³â€ŒØ²Ù…ÛŒÙ†Ù‡ (Ú©Ù„Ø§Ø³â€ŒÙ…Ø­ÙˆØ±)
    $fraudHandler = $container->make(\App\Listeners\ProcessFraudAlert::class);
    $dispatcher->listen(\App\Events\FraudScoreUpdatedEvent::class, [$fraudHandler, 'handle']);

    // ðŸš€ Ø«Ø¨Øª Ø´Ù†ÙˆÙ†Ø¯Ù‡ ÛŒÚ©Ù¾Ø§Ø±Ú†Ù‡ Ø¨Ø±Ø§ÛŒ Ø¨Ø§Ø·Ù„â€ŒØ³Ø§Ø²ÛŒ Ø±ÙˆÛŒØ¯Ø§Ø¯Ù…Ø­ÙˆØ± Ú©Ø´â€ŒÙ‡Ø§ÛŒ Ø¬Ø³ØªØ¬Ùˆ (Event-Driven Search Invalidation)
    $searchEvents = [
        'ad.created', 'ad.updated', 'ad.status_changed',
        'seo_ad.approved', 'seo_ad.rejected', 'seo_ad.paused',
        \App\Events\TaskCompletedEvent::class,
        'prediction.created', 'lottery.created', 'coupon.created',
        'ticket.created', 'ticket.updated', 'content.created',
        'direct_message.created',
        'investment.created', 'investment.updated',
        'bug_report.created',
        'escrow.created', 'escrow.updated'
    ];
    foreach ($searchEvents as $ev) {
        $dispatcher->listen($ev, \App\Listeners\InvalidateSearchCacheListener::class);
    }

    // 🔎 CQRS Read-Model sync: همگام‌سازی projection جستجو با رویدادهای دامنه (C1)
    $searchProjectionListener = $container->make(\App\Services\Search\SearchProjectionListener::class);
    $projectionSyncEvents = [
        'wallet.deposit.completed', 'wallet.withdraw.completed', 'wallet.pay.completed',
        'withdrawal.created', 'withdrawal.approved',
        'deposit.manual_created', 'deposit.manual_approved',
        'content.created', 'content.approved',
        'vitrine.listing_created', 'vitrine.listing_updated',
        'vitrine.listing_removed', 'vitrine.listing_expired',
        'ticket.created', 'ticket.updated',
        'direct_message.sent', 'direct_message.created',
        'influencer.profile_updated',
        'account.deleted',
    ];
    foreach ($projectionSyncEvents as $ev) {
        $dispatcher->listen($ev, [$searchProjectionListener, 'handle']);
    }

    // ðŸ’³ Ù¾Ø±Ø¯Ø§Ø®Øª Ù…ÙˆÙÙ‚: XP Ø§Ø¹Ø·Ø§ØŒ Ú©Ù…ÛŒØ³ÛŒÙˆÙ† Ø±ÛŒÙØ±Ø§Ù„ØŒ Ø§Ø·Ù„Ø§Ø¹â€ŒØ±Ø³Ø§Ù†ÛŒ
    // Ù¾Ø±Ø¯Ø§Ø®Øª Ù…ÙˆÙÙ‚: Ú©Ù„Ø§Ø³â€ŒÙ…Ø­ÙˆØ± (DI)
    $paymentHandler = $container->make(\App\Listeners\HandlePaymentCompleted::class);
    $dispatcher->listen(\App\Events\PaymentCompletedEvent::class, [$paymentHandler, 'handle']);

    // ðŸ† Ø§Ø±ØªÙ‚Ø§Ø¡ Ø³Ø·Ø­: NotificationØŒ AuditØŒ Badge Ø§Ø¹Ø·Ø§
    $levelHandler = $container->make(\App\Listeners\HandleLevelUpgraded::class);
    $dispatcher->listen(\App\Events\LevelUpgradedEvent::class, [$levelHandler, 'handle']);

    // ðŸš« ØªØ¬Ø§ÙˆØ² Ø§Ø² Rate Limit: Alert Ø§Ø¯Ù…ÛŒÙ†ØŒ Flag IP
    $rateHandler = $container->make(\App\Listeners\HandleRateLimitExceeded::class);
    $dispatcher->listen(\App\Events\RateLimitExceededEvent::class, [$rateHandler, 'handle']);

    // ðŸ—‘ï¸ Ø­Ø°Ù Ø­Ø³Ø§Ø¨: Ø¨Ø±Ø±Ø³ÛŒ Ù…ÙˆØ¬ÙˆØ¯ÛŒØŒ Ø¨Ø³ØªÙ† EscrowÙ‡Ø§ØŒ Audit Ù†Ù‡Ø§ÛŒÛŒ
    $accountDeletedHandler = $container->make(\App\Listeners\HandleAccountDeleted::class);
    $dispatcher->listen(\App\Events\AccountDeletedEvent::class, [$accountDeletedHandler, 'handle']);

    // âš™ï¸ ØªØºÛŒÛŒØ± Feature Flag Ø¨Ø­Ø±Ø§Ù†ÛŒ: Alert ÙÙˆØ±ÛŒ Ø§Ø¯Ù…ÛŒÙ† (ØªÚ©Ù…ÛŒÙ„ Listener Ù†Ø§Ù‚Øµ)
    $criticalFeatureHandler = $container->make(\App\Listeners\AlertAdminOnCriticalFeatureChange::class);
    $dispatcher->listen(\App\Events\CriticalFeatureChangedEvent::class, [$criticalFeatureHandler, 'handle']);

    // ðŸ“ Content Event Listeners - Event-Driven Decoupling Ø¨Ø±Ø§ÛŒ Ù…Ø­ØªÙˆØ§
    // Ø¬Ø§ÛŒÚ¯Ø²ÛŒÙ† Ú©Ø±Ø¯Ù† direct service calls Ø¨Ø§ event-driven architecture
    $contentEventListeners = $container->make(\App\Listeners\ContentEventListeners::class);
    $dispatcher->listen('content.approved', [$contentEventListeners, 'handleContentApproved']);
    $dispatcher->listen('content.rejected', [$contentEventListeners, 'handleContentRejected']);
    $dispatcher->listen('content.published', [$contentEventListeners, 'handleContentPublished']);
    $dispatcher->listen('content.revenue_recorded', [$contentEventListeners, 'handleContentRevenueRecorded']);
    $dispatcher->listen('content.revenue_paid', [$contentEventListeners, 'handleContentRevenuePaid']);

    $socialTaskEventListeners = $container->make(\App\Listeners\SocialTaskEventListeners::class);
    $dispatcher->listen('social_task.reward_approved', [$socialTaskEventListeners, 'handleRewardApproved']);
    $dispatcher->listen('social_task.rejected', [$socialTaskEventListeners, 'handleTaskRejected']);
    $dispatcher->listen('social_task.ad_cancelled_refund', [$socialTaskEventListeners, 'handleAdCancelledRefund']);
    $dispatcher->listen('social_task.execution.completed', [$socialTaskEventListeners, 'handleExecutionCompleted']);

    $vitrineEventListeners = $container->make(\App\Listeners\VitrineEventListeners::class);
    $dispatcher->listen('vitrine.escrow_payment_requested', [$vitrineEventListeners, 'handleEscrowPaymentRequested']);
    $dispatcher->listen('vitrine.refund_requested', [$vitrineEventListeners, 'handleRefundRequested']);
    $dispatcher->listen('vitrine.listing_approved', [$vitrineEventListeners, 'handleListingApproved']);

    $influencerEventListeners = $container->make(\App\Listeners\InfluencerEventListeners::class);
    $dispatcher->listen('influencer.order_created', [$influencerEventListeners, 'handleInfluencerOrderCreated']);
    $dispatcher->listen('influencer.order_completed', [$influencerEventListeners, 'handleInfluencerOrderCompleted']);
    $dispatcher->listen('influencer.order_rejected', [$influencerEventListeners, 'handleInfluencerOrderRejected']);
    $dispatcher->listen('influencer.order_refunded', [$influencerEventListeners, 'handleInfluencerOrderRefunded']);

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // ðŸŽ¯ Event-Driven Architecture: Listeners Registration
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // ðŸ“‹ Event Sourcing: Events as Source of Truth for Audit Trail
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•ââ•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // Event audit is now handled centrally by Core\EventDispatcher for all dispatched events.
    
    // ðŸ’³ Withdrawal Events - Decouples withdrawal domain from notifications & auditing
    $withdrawalListener = $container->make(\App\Listeners\WithdrawalListener::class);
    $dispatcher->listen(\App\Events\WithdrawalCreatedEvent::class, [$withdrawalListener, 'handleWithdrawalCreated']);
    $dispatcher->listen(\App\Events\WithdrawalApprovedEvent::class, [$withdrawalListener, 'handleWithdrawalApproved']);
    $dispatcher->listen(\App\Events\WithdrawalEvent::class, [$withdrawalListener, 'handleWithdrawalEvent']);

    // âœ… Task Completion - XP awards, trust score, notifications
    $taskListener = $container->make(\App\Listeners\TaskCompletedListener::class);
    $dispatcher->listen(\App\Events\TaskCompletedEvent::class, [$taskListener, 'handle']);

    // âš–ï¸ Dispute Events - Escrow freeze, admin alerts, escalation
    $disputeListener = $container->make(\App\Listeners\DisputeListener::class);
    $dispatcher->listen(\App\Events\DisputeOpenedEvent::class, [$disputeListener, 'handle']);

    // ðŸ“Š Score Updates - Level checks, threshold alerts, audit logging
    $scoreListener = $container->make(\App\Listeners\ScoreUpdateListener::class);
    $dispatcher->listen(\App\Events\ScoreUpdatedEvent::class, [$scoreListener, 'handle']);

    // ðŸ’° Escrow Release - Wallet deposit, ledger updates, notifications
    $escrowListener = $container->make(\App\Listeners\EscrowListener::class);
    $dispatcher->listen(\App\Events\EscrowReleasedEvent::class, [$escrowListener, 'handle']);

    // ðŸ” KYC Approval - Feature unlock, withdrawal limits, notifications
    $kycListener = $container->make(\App\Listeners\KYCListener::class);
    $dispatcher->listen(\App\Events\KYCApprovedEvent::class, [$kycListener, 'handle']);

    // ✅ Domain-specific listeners now handle their own events exclusively
    // to prevent duplicate side effects (notifications, score updates, wallet operations).
    // DomainActivityListener is deprecated for these events.

    // Notification request listener - routes notification requests through NotificationService
    $notificationRequestListener = $container->make(\App\Listeners\NotificationRequestListener::class);
    $dispatcher->listen('notification.requested', [$notificationRequestListener, 'handle']);
    $dispatcher->listen('cache.invalidate', [\App\Listeners\CacheInvalidateListener::class, 'handle']);

    // Channel-level notification dispatch listener - routes channel events through NotificationDispatcher
    $notificationChannelDispatchListener = $container->make(\App\Listeners\NotificationChannelDispatchListener::class);
    $dispatcher->listen('notification.channel.requested', [$notificationChannelDispatchListener, 'handle']);

    // Alert request listener - routes alert events through AlertDispatcher
    $alertRequestListener = $container->make(\App\Listeners\AlertRequestListener::class);
    $dispatcher->listen('alert.requested', [$alertRequestListener, 'handle']);

    // Referral commission listener - processes commission for referrals
    $referralCommissionListener = $container->make(\App\Listeners\ReferralCommissionListener::class);
    $dispatcher->listen('referral.commission.process', [$referralCommissionListener, 'handle']);

    // Wallet deposit request listener - handles async wallet deposit events
    $walletDepositRequestListener = $container->make(\App\Listeners\WalletDepositRequestListener::class);
    $dispatcher->listen('wallet.deposit.requested', [$walletDepositRequestListener, 'handle']);
    
    // Map EDA Financial Events to WalletDepositRequestListener
    // استفاده از الگوهای wildcard برای forward-compatibility
    $walletDepositRequestListener = $container->make(\App\Listeners\WalletDepositRequestListener::class);
    
    // ثبت دقیق listeners برای رویدادهای موجود
    $financialEvents = \App\Events\Registry\EventRegistry::getDepositTriggerEvents();
    foreach ($financialEvents as $fEvent) {
        if ($fEvent !== 'wallet.deposit.requested') {
            $dispatcher->listen($fEvent, [$walletDepositRequestListener, 'handle']);
        }
    }

    // ثبت pattern listeners برای جذب خودکار رویدادهای جدید تحت namespaces عمومی
    $patterns = \App\Events\Registry\EventRegistry::getDepositTriggerPatterns();
    foreach ($patterns as $pattern) {
        $dispatcher->listenPattern($pattern, [$walletDepositRequestListener, 'handle']);
    }

    // Persist audit records listener - centralizes audit persistence for audit events
    $persistAuditListener = $container->make(\App\Listeners\PersistAuditRecordListener::class);
    $dispatcher->listen(\App\Events\AuditRecordedEvent::class, [$persistAuditListener, 'handle']);

    // Ø«Ø¨Øª ØªØµÙˆÛŒØ± ÙˆØ¶Ø¹ÛŒØª Ø´Ù†ÙˆÙ†Ø¯Ù‡â€ŒÙ‡Ø§ÛŒ Ø§ÙˆÙ„ÛŒÙ‡ Ø¨Ø±Ø§ÛŒ Ù¾Ø§Ú©Ø³Ø§Ø²ÛŒ Ø­Ø§ÙØ¸Ù‡ Ø¯Ø± ÙØ±Ø¢ÛŒÙ†Ø¯Ù‡Ø§ÛŒ Ø·ÙˆÙ„Ø§Ù†ÛŒ
    if (method_exists($dispatcher, 'snapshotBootstrapState')) {
        $dispatcher->snapshotBootstrapState();
    }

} catch (\Throwable $e) {
    if (PHP_SAPI !== 'cli') {
        throw $e;
    }
    $msg = '[Chortke] Events registration failed: ' . $e->getMessage()
         . " in " . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
    error_log($msg);
}

// Application â€” Ø¨Ø§ÛŒØ¯ Ø¢Ø®Ø±ÛŒÙ† Ø®Ø· Ø¨Ø§Ø´Ø¯
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


