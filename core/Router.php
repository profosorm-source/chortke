<?php

namespace Core;

/**
 * Router
 *
 * جریان صحیح dispatch:
 *
 *   User Request
 *       ↓
 *   Router::dispatch()
 *       ↓
 *   matchRoute()  →  پیدا کردن route
 *       ↓
 *   Container::make(Middleware)  →  اجرای middleware‌ها
 *       ↓
 *   Container::make(ControllerClass)
 *       ├─→ Container::make(ServiceClass)   [auto-wire از constructor]
 *       │       └─→ Container::make(Model)  [auto-wire از constructor]
 *       └─→ Controller::__construct(Service, ...)
 *       ↓
 *   Controller::method($routeParams)
 *       ↓
 *   Response::send()
 */
class Router
{
    protected array $groupAttributes = [];
    private Request   $request;
    private Response  $response;
    private Container $container;

    private array $routes = [
        'GET'    => [],
        'POST'   => [],
        'PUT'    => [],
        'DELETE' => [],
        'PATCH'  => [],
    ];

    public function __construct(Request $request, Response $response, Container $container)
    {
        $this->request   = $request;
        $this->response  = $response;
        $this->container = $container;
    }

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    // ─────────────────────────────────────────────────────────────
    // Route Registration
    // ─────────────────────────────────────────────────────────────

    public function get(string $uri, $action, array $middleware = []): Route
    {
        return $this->addRoute('GET', $uri, $action, $middleware);
    }

    public function post(string $uri, $action, array $middleware = []): Route
    {
        return $this->addRoute('POST', $uri, $action, $middleware);
    }

    public function put(string $uri, $action, array $middleware = []): Route
    {
        return $this->addRoute('PUT', $uri, $action, $middleware);
    }

    public function delete(string $uri, $action, array $middleware = []): Route
    {
        return $this->addRoute('DELETE', $uri, $action, $middleware);
    }

    public function patch(string $uri, $action, array $middleware = []): Route
    {
        return $this->addRoute('PATCH', $uri, $action, $middleware);
    }

    private function addRoute(string $method, string $uri, $action, array $routeMiddleware = []): Route
    {
        // اعمال group prefix
        $prefix  = $this->groupAttributes['prefix'] ?? '';
        $fullUri = $prefix . '/' . ltrim($uri, '/');
        $fullUri = '/' . trim($fullUri, '/') ?: '/';

        $route = new Route($fullUri, $action);

        // اعمال group middleware
        if (!empty($this->groupAttributes['middleware'])) {
            foreach ((array)$this->groupAttributes['middleware'] as $mw) {
                $route->middleware($mw);
            }
        }

        // اعمال inline middleware (پارامتر سوم مستقیم)
        foreach ($routeMiddleware as $mw) {
            $route->middleware($mw);
        }

        $this->routes[$method][] = [
            'uri'   => $fullUri,
            'route' => $route,
        ];

        return $route;
    }

    // ─────────────────────────────────────────────────────────────
    // Group
    // ─────────────────────────────────────────────────────────────

    public function group(array $attributes, callable $callback): void
    {
        $previous = $this->groupAttributes;

        if (isset($attributes['middleware'])) {
            $mw = is_array($attributes['middleware'])
                ? $attributes['middleware']
                : [$attributes['middleware']];

            $this->groupAttributes['middleware'] = array_merge(
                $this->groupAttributes['middleware'] ?? [],
                $mw
            );
        }

        if (isset($attributes['prefix'])) {
            $this->groupAttributes['prefix'] =
                ($this->groupAttributes['prefix'] ?? '') . '/' . ltrim($attributes['prefix'], '/');
        }

        $callback($this);

        $this->groupAttributes = $previous;
    }

    // ─────────────────────────────────────────────────────────────
    // Dispatch — قلب Router
    // ─────────────────────────────────────────────────────────────

    public function dispatch(): void
    {
        // Ensure session is started at the beginning of request handling
        try {
            $this->container->make(\Core\Session::class)->ensureStarted();
        } catch (\Throwable $e) {
            // برای متدهایی که تغییر دهنده حالت هستند، اگر سشن لود نشد عملیات را به سرعت و با امنیت متوقف می‌کنیم (Fail-Closed)
            if (in_array(strtoupper($this->request->method()), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
                throw new \RuntimeException('Session failed to initialize. Request aborted for security reasons.', 500, $e);
            }
        }

        $method = $this->request->method();
        $uri    = $this->normalizeUri($_SERVER['REQUEST_URI'] ?? '/');

        foreach ($this->routes[$method] ?? [] as $routeData) {
            $params = $this->matchRoute($routeData['uri'], $uri);

            if ($params === false) {
                continue;
            }

            // ① تزریق route params به Request
            $this->request->setParams($params);
            $GLOBALS['_route_params'] = $params;

            // ② آماده‌سازی Middlewareها
            $middlewares = $routeData['route']->getMiddleware();

            // ③ اجرای Pipeline
            $response = (new Pipeline($this->container))
                ->send($this->request)
                ->through($middlewares)
                ->then(function ($request) use ($routeData, $params) {
                    // مقصد نهایی: اجرای Controller Action
                    return $this->executeAction($routeData['route']->getAction(), $params);
                });

            // ④ ارسال پاسخ نهایی (اگر Response باشد)
            $this->handleResult($response);
            return;
        }

        $this->handleNotFound($uri, $method);
    }

    // ─────────────────────────────────────────────────────────────
    // Middleware Execution — از Container ساخته می‌شه
    // ─────────────────────────────────────────────────────────────

    // runMiddleware removed - handled by Pipeline class

    // ─────────────────────────────────────────────────────────────
    // Controller Execution — از Container ساخته می‌شه
    // ─────────────────────────────────────────────────────────────

    protected function executeAction($action, array $params = []): mixed
    {
        // ── Closure action ───────────────────────────────────────
        if ($action instanceof \Closure) {
            $args = array_values($params);
            return call_user_func_array($action, $args);
        }

        // ── [ControllerClass, 'method'] ──────────────────────────
        if (!is_array($action) || count($action) !== 2) {
            throw new \RuntimeException('[Router] Invalid route action format.');
        }

        [$controllerClass, $method] = $action;

        // ساخت Controller از Container — همه وابستگی‌ها auto-wire می‌شن
        try {
            $controller = $this->container->make($controllerClass);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                "[Router] Cannot resolve Controller '{$controllerClass}': " . $e->getMessage()
            );
        }

        if (!method_exists($controller, $method)) {
            throw new \RuntimeException(
                "[Router] Method '{$method}' not found in '{$controllerClass}'."
            );
        }

        // اجرای action method با route params
        try {
            $ref           = new \ReflectionMethod($controller, $method);
            $expectedCount = $ref->getNumberOfParameters();
            $args          = $expectedCount > 0
                ? array_slice(array_values($params), 0, $expectedCount)
                : [];

            return $ref->invokeArgs($controller, $args);

        } catch (\ReflectionException $e) {
            throw new \RuntimeException(
                "[Router] Reflection error for {$controllerClass}::{$method}: " . $e->getMessage()
            );
        }
    }

    /**
     * مدیریت نتیجه‌ی action
     */
    private function handleResult(mixed $result): void
    {
        if ($result instanceof Response) {
            $result->send();
            return;
        }
        if (is_string($result)) {
            echo $result;
            return;
        }
        if (is_array($result) || is_object($result)) {
            $response = new Response();
            $response->json((array)$result);
            return;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // URI Matching
    // ─────────────────────────────────────────────────────────────

    private function normalizeUri(string $rawUri): string
    {
        $uri = strtok($rawUri, '?');

        // حذف base path (برای نصب در subdirectory)
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $basePath   = str_replace('/public', '', dirname($scriptName));

        if ($basePath !== '/' && $basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }

        $uri = '/' . trim($uri, '/');
        return $uri === '//' ? '/' : $uri;
    }

    private function matchRoute(string $routeUri, string $currentUri): array|false
    {
        $routeUri   = trim($routeUri, '/');
        $currentUri = trim($currentUri, '/');

        $paramNames = [];

        $pattern = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^\/]+)';
        }, $routeUri);

        $pattern = '#^' . $pattern . '$#u';

        if (!preg_match($pattern, $currentUri, $matches)) {
            return false;
        }

        $params = [];
        foreach ($paramNames as $i => $name) {
            $params[$name] = urldecode($matches[$i + 1] ?? '');
        }

        return $params;
    }

    // ─────────────────────────────────────────────────────────────
    // 404 Handler
    // ─────────────────────────────────────────────────────────────

    private function handleNotFound(string $uri, string $method): void
    {
        http_response_code(404);

        if (config('app.debug') && config('app.env') === 'local') {
            echo "<!DOCTYPE html><html lang='fa' dir='rtl'><head><meta charset='UTF-8'>";
            echo "<title>404 - صفحه یافت نشد</title>";
            echo "<style>body{font-family:Tahoma,Arial;padding:40px;background:#f5f5f5;}";
            echo ".box{background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.1);}";
            echo "h1{color:#e74c3c;}code{background:#ecf0f1;padding:2px 6px;border-radius:3px;}</style>";
            echo "</head><body><div class='box'>";
            echo "<h1>404 — صفحه یافت نشد</h1>";
            echo "<p><strong>Method:</strong> <code>{$method}</code></p>";
            echo "<p><strong>URI:</strong> <code>{$uri}</code></p>";
            echo "<h3>مسیرهای ثبت‌شده ({$method}):</h3><ul>";
            foreach ($this->routes[$method] ?? [] as $r) {
                echo "<li><code>{$r['uri']}</code></li>";
            }
            echo "</ul></div></body></html>";
        } else {
            $view = __DIR__ . '/../views/errors/404.php';
            file_exists($view) ? require $view : print '404 - Not Found';
        }

        exit;
    }

    // ─────────────────────────────────────────────────────────────
    // Debug
    // ─────────────────────────────────────────────────────────────

    public function getRoutes(): array
    {
        return $this->routes;
    }
}
