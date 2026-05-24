<?php

namespace Core;

class Application
{
    private static ?Application $instance = null;
    private static bool $exceptionHandlerRegistered = false;

    public Container $container;
    private ?Database $dbInstance = null;
    public Router    $router;
    public Request   $request;
    public Response  $response;
    public Session   $session;
    public array $config;
    private ?object $cachedUser = null;
    private bool $userResolved = false;

    private function __construct()
    {
        // ── ۱. Config ────────────────────────────────────────────
        $this->config = config();

        // ── ۲. Session — getInstance + start (یک‌جا، یک‌بار) ──────
        // DISABLED FOR NOW - causing memory exhaustion
        // $this->session = Session::getInstance();
        // $this->session->start();
        $this->session = Session::getInstance();
        // Don't call start() here - it will be called on-demand

        // ── ۳. ExceptionHandler — فقط یک‌بار در کل lifecycle ────
        //    index.php دیگر ExceptionHandler::register() صدا نمی‌زند
        if (!self::$exceptionHandlerRegistered) {
            ExceptionHandler::register();
            self::$exceptionHandlerRegistered = true;
        }

        // ── ۴. Core Objects ──────────────────────────────────────
        $this->request  = new Request();
        $this->response = new Response();
        $this->container = Container::getInstance();
        $this->router   = new Router($this->request, $this->response, $this->container);

        // ── ۵. Database ──────────────────────────────────────────
        // Moved to Lazy Loading via db() getter to prevent early connection failure 
        // and allow DB connection on-demand.

        // ── ۶. Container — ثبت singletonهای هسته ────────────────
        $this->registerCoreBindings();

        // ── ۷. Maintenance Mode ──────────────────────────────────
        // انتقال به لایه میدلور برای مدیریت هوشمند و داینامیک
    }

    /**
     * ثبت singleton‌های هسته در Container
     * هر کدی که Container::make() می‌زند،
     * همین instance‌ها را دریافت می‌کند.
     */
    private function registerCoreBindings(): void
    {
        $c = $this->container;

        // ── Core singletons — instance\u200cهای آماده ─────────────────
        $c->instance(Application::class, $this);
        $c->instance(Container::class,   $c);
        $c->instance(Request::class,     $this->request);
        $c->instance(Response::class,    $this->response);
        $c->instance(Session::class,     $this->session);
        $c->instance(Router::class,      $this->router);

        // ── Cache bound to singleton ──
        $c->singleton(\Core\Cache::class, function() {
            return \Core\Cache::getInstance();
        });

        // ── Core fallback logger — available during early bootstrap
        $c->singleton(\App\Contracts\LoggerInterface::class, \Core\Logger::class);

        // ── Metrics Collector binding ──
        $c->singleton(\App\Contracts\MetricsCollectorInterface::class, \App\Services\Metrics\MetricsCollector::class);

        // ── App-level singletons — یک بار در طول request ────────
        // هر Controller که AuthService یا User نیاز دارد،
        // همین instance را دریافت می‌کند (نه instance جدید)
        $c->singleton(\App\Services\Auth\AuthService::class);
    }

    /**
     * Lazy loaded helper for database connection instance
     */
    public function db(): Database
    {
        if ($this->dbInstance === null) {
            try {
                $this->dbInstance = $this->container->make(Database::class);
            } catch (\Throwable $e) {
                try {
                    $emergencyFile = base_path('storage/logs/sentry_emergency.jsonl');
                    $logData = [
                        'timestamp' => time(),
                        'message' => 'Lazy DB resolution failed: ' . $e->getMessage(),
                        'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ];
                    @file_put_contents($emergencyFile, json_encode($logData) . "\n", FILE_APPEND);
                } catch (\Throwable $ignore) {}

                throw new \RuntimeException('System database resolution failed', 0, $e);
            }
        }
        return $this->dbInstance;
    }

    /**
     * Magic getter to support backward compatibility for accessing typed properties dynamically
     */
    public function __get(string $name)
    {
        if ($name === 'db') {
            return $this->db();
        }
        throw new \RuntimeException("Property $name does not exist");
    }
    /**
     * دریافت کاربر لاگین‌شده (کش‌شده در هر request)
     *
     * ✅ Fix M1: کش کردن شیء کاربر پس از اولین بازیابی
     * - اولین فراخوانی: کوئری دیتابیس
     * - فراخوانی‌های بعدی: از حافظه موضعی
     * - این عملکرد را در صفحات پیچیده بهبود می‌بخشد
     */
    public function user(): ?object
    {
        // اگر قبلاً بررسی شده، همان نتیجه‌ی ذخیره‌شده را برگردان
        if ($this->userResolved) {
            return $this->cachedUser;
        }

        $userId = $this->session->get('user_id');
        if (!$userId) {
            $this->userResolved = true;
            $this->cachedUser = null;
            return null;
        }

        try {
            $userModel = $this->container->make(\App\Models\User::class);
            $this->cachedUser = $userModel->find((int) $userId);
        } catch (\Throwable $e) {
            $this->cachedUser = null;
        }

        $this->userResolved = true;
        return $this->cachedUser;
    }

    /**
     * ابطال حافظه کش محلی کاربر لاگین شده در این ریکوئست
     */
    public function forgetUser(): void
    {
        $this->cachedUser = null;
        $this->userResolved = false;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __clone() {}
    
    public function __wakeup()
    {
        throw new \RuntimeException("Cannot unserialize singleton");
    }

    public function run(): void
    {
        $startTime = microtime(true);
        
        $this->router->dispatch();

        // ✅ SLA Monitor
        $durationMs = (microtime(true) - $startTime) * 1000;
        $slaThresholdMs = (float) config('app.sla_threshold_ms', 1000);

        if ($durationMs > $slaThresholdMs) {
            try {
                $logger = $this->container->make(\App\Contracts\LoggerInterface::class);
                $logger->warning('sla_breach_detected', [
                    'duration_ms' => round($durationMs, 2),
                    'threshold_ms' => $slaThresholdMs,
                    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                ]);
            } catch (\Throwable $ignore) {}
        }
    }
}