<?php

namespace Core;

class Application
{
    private static ?Application $instance = null;

    public Container $container;
    public Database  $db;
    public Router    $router;
    public Request   $request;
    public Response  $response;
    public Session   $session;
    public array $config;

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
        ExceptionHandler::register();

        // ── ۴. Core Objects ──────────────────────────────────────
        $this->request  = new Request();
        $this->response = new Response();
        $this->container = Container::getInstance();
        $this->router   = new Router($this->request, $this->response, $this->container);

        // ── ۵. Database ──────────────────────────────────────────
       try {
    $this->db = Database::getInstance();
} catch (\Throwable $e) {
    try {
        // M2 Fix: استفاده از هلپر داینامیک base_path برای دسترسی پویا به دایرکتوری لاگ‌ها
        $emergencyFile = base_path('storage/logs/sentry_emergency.jsonl');
        $logData = [
            'timestamp' => time(),
            'message' => $e->getMessage(),
            'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        @file_put_contents($emergencyFile, json_encode($logData) . "\n", FILE_APPEND);
    } catch (\Throwable $ignore) {}

    // خطای عمومی به ExceptionHandler منتقل می‌شود (بدون نشت جزئیات به کاربر)
    throw new \RuntimeException('System bootstrap failed', 0, $e);
}

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
        $c->instance(Database::class,    $this->db);
        $c->instance(Router::class,      $this->router);

        // ── Cache bound to singleton ──
        $c->singleton(\Core\Cache::class, function() {
            return \Core\Cache::getInstance();
        });

        // ── Core fallback logger — available during early bootstrap
        $c->singleton(\App\Contracts\LoggerInterface::class, function($c) {
            $db = $c->make(Database::class);

            return new \Core\Logger(
                new \App\Services\LogService(
                    $db,
                    new \App\Models\ActivityLog($db),
                    new \App\Models\SystemLog($db),
                    new \App\Models\SecurityLog($db),
                    new \App\Models\PerformanceLog($db)
                )
            );
        });

        // ── App-level singletons — یک بار در طول request ────────
        // هر Controller که AuthService یا User نیاز دارد،
        // همین instance را دریافت می\u200cکند (نه instance جدید)
        $c->singleton(\App\Services\Auth\AuthService::class);
        $c->bind(\App\Models\User::class);
    }
    /**
     * دریافت کاربر لاگین‌شده
     *
     * از Container → User Model می‌خواند (نه مستقیم از DB)
     */
    public function user(): ?object
    {
        $userId = $this->session->get('user_id');
        if (!$userId) {
            return null;
        }
        try {
            $userModel = $this->container->make(\App\Models\User::class);
            return $userModel->find((int) $userId);
        } catch (\Throwable $e) {
    return null;
}
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
        $this->router->dispatch();
    }
}