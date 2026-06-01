<?php

namespace Core;

/**
 * Dependency Injection Container
 *
 * ویژگی‌ها:
 *  - Auto-wiring کامل با Reflection (type-hint → خودکار resolve)
 *  - Manual binding با Closure یا class string
 *  - Singleton binding (یک بار ساخته، بعد cache)
 *  - تشخیص Circular Dependency (جلوگیری از حلقه بی‌نهایت)
 *  - Contextual override: bind() همیشه auto-wiring را override می‌کند
 *
 * جریان صحیح:
 *   Router::dispatch()
 *     → Container::make(ControllerClass)
 *         → Container::make(ServiceClass)        [از type-hint constructor]
 *             → Container::make(ModelClass)       [از type-hint constructor]
 *         → Controller::__construct(Service)
 */
class Container
{
    private static ?Container $instance = null;

    /** @var array<string, \Closure|string> */
    private array $bindings = [];

    /** @var array<string, object|null>  null = ثبت‌شده ولی هنوز build نشده */
    private array $singletons = [];

    private array $reflectionCache = [];
    private array $reflectionCacheUsage = [];
    private const MAX_REFLECTION_CACHE = 500;
    private bool $isLoggingMissing = false;
    private bool $ejectingReflection = false;

    /** @var array<string, array<string>> */
    private array $tags = [];

    /** @var array<string, array<\Closure>> */
    private array $extenders = [];

    // CORE-030: Scoped dependencies for long-running workers
    private array $scopedBindings = [];
    private array $scopedInstances = [];
    // ─────────────────────────────────────────────────────────────
    // Singleton Access
    // ─────────────────────────────────────────────────────────────

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}

    public function __wakeup(): void
    {
        throw new \LogicException('Container cannot be unserialized.');
    }

    // ─────────────────────────────────────────────────────────────
    // Registration
    // ─────────────────────────────────────────────────────────────

    /**
     * ثبت binding ساده — هر بار instance جدید
     */
    public function bind(string $abstract, $concrete = null): void
    {
        $this->bindings[$abstract] = $concrete ?? $abstract;
        unset($this->singletons[$abstract]);
    }

    /**
     * ثبت Singleton — فقط یک بار ساخته، بعد cache
     */
    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bindings[$abstract]  = $concrete ?? $abstract;
        $this->singletons[$abstract] = null;
    }

    /**
     * ثبت یک instance آماده به‌عنوان singleton
     */
    public function instance(string $abstract, object $object): void
    {
        $this->bindings[$abstract]   = $abstract;
        $this->singletons[$abstract] = $object;
    }

    /**
     * CORE-030: ثبت وابستگی محدود به حوزه ریکوئست یا کار (Scoped)
     */
    public function scoped(string $abstract, $concrete = null): void
    {
        $this->bindings[$abstract] = $concrete ?? $abstract;
        $this->scopedBindings[$abstract] = true;
        unset($this->singletons[$abstract], $this->scopedInstances[$abstract]);
    }

    /**
     * CORE-030: پاکسازی تمامی اشیاء Scoped برای شروع پردازش کار/ریکوئست جدید
     */
    public function flushScoped(): void
    {
        $this->scopedInstances = [];
    }

    // ─────────────────────────────────────────────────────────────
    // Resolution
    // ─────────────────────────────────────────────────────────────
    
    private array $traceStack = [];

    /**
     * H15 Fix: پاکسازی استک دیباگ چرخه‌ای برای جلوگیری از false-positive در فرآیندهای طولانی
     */
    public static function resetTraceStack(): void
    {
        if (self::$instance !== null) {
            self::$instance->traceStack = [];
        }
    }

    /**
     * ساخت / دریافت instance
     *
     * @throws \RuntimeException
     */
    public function make(string $abstract): object
    {
        if (in_array($abstract, $this->traceStack, true)) {
            throw new \RuntimeException("Circular dependency detected: " . implode(" -> ", $this->traceStack) . " -> " . $abstract);
        }
        $this->traceStack[] = $abstract;
        try {
            // Scoped cache (CORE-030)
            if (isset($this->scopedBindings[$abstract])) {
                if (!isset($this->scopedInstances[$abstract])) {
                    $instance = $this->resolve($abstract);
                    $this->scopedInstances[$abstract] = $this->applyExtenders($abstract, $instance);
                }
                return $this->scopedInstances[$abstract];
            }

            // Singleton cache
            if (array_key_exists($abstract, $this->singletons)) {
                if ($this->singletons[$abstract] === null) {
                    $instance = $this->resolve($abstract);
                    $this->singletons[$abstract] = $this->applyExtenders($abstract, $instance);
                }
                return $this->singletons[$abstract];
            }

            $instance = $this->resolve($abstract);
            return $this->applyExtenders($abstract, $instance);
        } finally {
            array_pop($this->traceStack);
        }
    }

    /**
     * اعمال توابع گسترش‌دهنده (Extenders) روی شیء ساخته‌شده
     */
    private function applyExtenders(string $abstract, object $instance): object
    {
        if (isset($this->extenders[$abstract])) {
            foreach ($this->extenders[$abstract] as $extender) {
                $instance = $extender($instance, $this);
            }
        }
        return $instance;
    }

    private function resolve(string $abstract): object
{
    $concrete = $this->bindings[$abstract] ?? $abstract;

    // closure binding
    if ($concrete instanceof \Closure) {
        $object = $concrete($this);
        if (!is_object($object)) {
            throw new \RuntimeException("[Container] Binding '{$abstract}' did not return an object.");
        }
        
        // H14 Fix: بررسی انطباق نوع شیء ساخته شده با اینترفیس/کلاس درخواستی
        if (class_exists($abstract) || interface_exists($abstract)) {
            if (!($object instanceof $abstract)) {
                throw new \RuntimeException("[Container] Container binding for '{$abstract}' returned incompatible type (" . get_class($object) . ").");
            }
        }
        
        return $object;
    }

    // pre-built object binding
    if (is_object($concrete) && !($concrete instanceof \Closure)) {
        return $concrete;
    }

    // alias binding (string)
    if (is_string($concrete) && $concrete !== $abstract) {
        return $this->make($concrete);
    }

    // class instantiation
    if (!is_string($concrete) || !class_exists($concrete)) {
        throw new \RuntimeException("[Container] Cannot resolve '{$abstract}'.");
    }

    if (!isset($this->reflectionCache[$concrete])) {
        if (count($this->reflectionCache) >= self::MAX_REFLECTION_CACHE) {
            if (!$this->ejectingReflection) {
                $this->ejectingReflection = true;
                try {
                    // Remove least recently used reflection class
                    asort($this->reflectionCacheUsage);
                    $leastUsed = array_key_first($this->reflectionCacheUsage);
                    if ($leastUsed !== null) {
                        unset(
                            $this->reflectionCache[$leastUsed],
                            $this->reflectionCacheUsage[$leastUsed]
                        );
                    }
                } finally {
                    $this->ejectingReflection = false;
                }
            }
        }
        $this->reflectionCache[$concrete] = new \ReflectionClass($concrete);
    }

    $this->reflectionCacheUsage[$concrete] = microtime(true);
    $reflector = $this->reflectionCache[$concrete];

    if (!$reflector->isInstantiable()) {
        throw new \RuntimeException("[Container] کلاس {$concrete} قابل نمونه‌سازی نیست");
    }

    $constructor = $reflector->getConstructor();
    if ($constructor === null) {
        return new $concrete();
    }

    $dependencies = $this->resolveDependencies($constructor->getParameters(), $concrete);
    return $reflector->newInstanceArgs($dependencies);
}



    /**
     * حل کردن پارامترهای constructor به‌صورت خودکار
     *
     * @param  \ReflectionParameter[] $parameters
     */
    private function resolveDependencies(array $parameters, string $forClass): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            // بدون type-hint
            if ($type === null || !($type instanceof \ReflectionNamedType)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }
                if ($parameter->allowsNull()) {
                    $dependencies[] = null;
                    continue;
                }
                throw new \RuntimeException(
                    "[Container] Cannot resolve '\${$parameter->getName()}'" .
                    " in {$forClass}::__construct() — no type-hint, no default."
                );
            }

            // Primitive type (int, string, bool, ...)
            if ($type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }
                if ($parameter->allowsNull()) {
                    $dependencies[] = null;
                    continue;
                }
                throw new \RuntimeException(
                    "[Container] Cannot resolve primitive '\${$parameter->getName()}'" .
                    " ({$type->getName()}) in {$forClass}::__construct() — add a default value."
                );
            }

            // Class / Interface type-hint
            $typeName = $type->getName();

            if ($parameter->allowsNull()) {
                try {
                    $dependencies[] = $this->make($typeName);
                } catch (\RuntimeException $e) {
                    // CORE-031: Do not hide misconfiguration on production
                    if (config('app.env') === 'production') {
                        throw $e;
                    }
                    
                    // M7 Fix: ثبت در لاگ سیستمی جهت سهولت در دیباگ زمانی که سیستم قادر به حل یک وابستگی Nullable نیست
                    if (function_exists('logger')) {
                        try {
                            logger()->debug("[Container] Resolved nullable '\${$parameter->getName()}' as null due to: " . $e->getMessage());
                        } catch (\Throwable) {
                            // Fallback silent
                        }
                    }
                    $dependencies[] = null;
                }
                continue;
            }

            $dependencies[] = $this->make($typeName);
        }

        return $dependencies;
    }

    // ─────────────────────────────────────────────────────────────
    // Utility
    // ─────────────────────────────────────────────────────────────

    /**
     * گسترش دادن یا تغییر نحوه ساخت نهایی یک شیء (Decoration)
     */
    public function extend(string $abstract, \Closure $closure): void
    {
        // اگر قبلاً در کش سینگلتون‌ها مقداردهی اولیه شده است، بلافاصله آن را تغییر بده
        if (array_key_exists($abstract, $this->singletons) && $this->singletons[$abstract] !== null) {
            $this->singletons[$abstract] = $closure($this->singletons[$abstract], $this);
        } else {
            $this->extenders[$abstract][] = $closure;
        }
    }

    /**
     * اختصاص تگ به چندین کلاس/آبسترکت جهت ارجاع گروهی
     */
    public function tag(string|array $abstracts, string ...$tags): void
    {
        $abstracts = (array)$abstracts;

        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            foreach ($abstracts as $abstract) {
                if (!\in_array($abstract, $this->tags[$tag], true)) {
                    $this->tags[$tag][] = $abstract;
                }
            }
        }
    }

    /**
     * دریافت تمامی اشیائی که با تگ خاصی ثبت شده‌اند
     */
    public function tagged(string $tag): iterable
    {
        if (!isset($this->tags[$tag])) {
            return [];
        }

        $instances = [];
        foreach ($this->tags[$tag] as $abstract) {
            $instances[] = $this->make($abstract);
        }

        return $instances;
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || array_key_exists($abstract, $this->singletons);
    }

    /**
     * اجرای یک Closure یا Callable با DI خودکار
     * 
     * ✅ Fix L2: پشتیبانی DI برای Closureها در روت‌ها
     * 
     * @param callable $callback
     * @param array $params پارامترهای اضافی برای پاس کردن
     * @return mixed
     */
    public function call(callable $callback, array $params = []): mixed
    {
        if (!($callback instanceof \Closure)) {
            // اگر غیر Closure است، فقط اجرا کن
            return $callback(...$params);
        }

        // برای Closure: reflection برای دریافت پارامترهای type-hint شده
        $reflectionFunc = new \ReflectionFunction($callback);
        $parameters = $reflectionFunc->getParameters();
        
        $resolvedArgs = [];
        $remainingParams = $params;

        foreach ($parameters as $param) {
            $type = $param->getType();
            $paramName = $param->getName();

            // ۱. اگر مطابقت دقیق با نام روت پارام دارد
            if (array_key_exists($paramName, $remainingParams)) {
                $resolvedArgs[] = $remainingParams[$paramName];
                unset($remainingParams[$paramName]);
                continue;
            }

            // ۲. اگر type-hint کلاس غیرBuiltin دارد، از کانتینر بساز
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                try {
                    $resolvedArgs[] = $this->make($type->getName());
                    continue;
                } catch (\RuntimeException $e) {
                    // اگر نتوانست resolve کند
                    if ($param->allowsNull()) {
                        $resolvedArgs[] = null;
                        continue;
                    }
                    throw $e;
                }
            }

            // ۳. اگر نام مطابقت نداشت، اولین پارامتر باقی‌مانده و مصرف‌نشده روت را بردار (پوزیشنال)
            if (!empty($remainingParams)) {
                $firstKey = array_key_first($remainingParams);
                $resolvedArgs[] = $remainingParams[$firstKey];
                unset($remainingParams[$firstKey]);
            } elseif ($param->isDefaultValueAvailable()) {
                $resolvedArgs[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $resolvedArgs[] = null;
            } else {
                throw new \RuntimeException(
                    "[Container] Cannot resolve parameter '\${$paramName}' in closure"
                );
            }
        }

        return $callback(...$resolvedArgs);
    }

    public function forget(string $abstract): void
    {
        unset($this->bindings[$abstract], $this->singletons[$abstract]);
    }

    /**
     * CORE-030: پاکسازی تمامی نمونه‌های سینگلتون برای آزادکردن حافظه در پروسه‌های طولانی‌مدت
     * به استثنای نمونه‌های هسته سیستم که نباید دوباره مقداردهی یا تخریب شوند.
     */
    public function flushSingletonInstances(array $keep = []): void
    {
        $defaultKeep = [
            self::class,
            \Core\Database::class,
            \Core\Queue::class,
            \Core\Cache::class,
            \Core\EventDispatcher::class,
            \App\Contracts\LoggerInterface::class,
        ];
        $keep = array_merge($defaultKeep, $keep);

        foreach ($this->singletons as $abstract => $instance) {
            if ($instance === null) {
                continue;
            }

            $shouldKeep = false;
            foreach ($keep as $keepClass) {
                if ($abstract === $keepClass || $instance instanceof $keepClass) {
                    $shouldKeep = true;
                    break;
                }
            }

            if (!$shouldKeep) {
                $this->singletons[$abstract] = null;
            }
        }
    }

    /**
     * پاکسازی کامل کش رفلکشن کانتینر
     */
    public function flushReflectionCache(): void
    {
        $this->reflectionCache = [];
        $this->reflectionCacheUsage = [];
    }

    /**
     * Periodic cleanup for reflection cache to prevent memory leaks in long-running processes
     */
    public function cleanupReflectionCache(): void
    {
        $cutoff = microtime(true) - 3600; // 1 hour
        foreach ($this->reflectionCacheUsage as $key => $time) {
            if ($time < $cutoff) {
                unset(
                    $this->reflectionCache[$key],
                    $this->reflectionCacheUsage[$key]
                );
            }
        }
    }

    /**
     * Validate registered container bindings for integrity.
     *
     * This method verifies that string bindings point to existing classes,
     * and that closure bindings with declared return types are compatible
     * with the requested abstract.
     *
     * @throws \RuntimeException
     */
    public function validateBindings(): void
    {
        foreach ($this->bindings as $abstract => $concrete) {
            if ($concrete instanceof \Closure) {
                $reflection = new \ReflectionFunction($concrete);
                $returnType = $reflection->getReturnType();
                if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()) {
                    $returnTypeName = $returnType->getName();
                    if (class_exists($abstract) || interface_exists($abstract)) {
                        if (!is_a($returnTypeName, $abstract, true)) {
                            throw new \RuntimeException("[Container] Closure binding for '{$abstract}' declares return type '{$returnTypeName}' incompatible with '{$abstract}'.");
                        }
                    }
                }
                continue;
            }

            if (is_string($concrete) && $concrete !== $abstract) {
                if (!class_exists($concrete) && !interface_exists($concrete)) {
                    throw new \RuntimeException("[Container] Container binding for '{$abstract}' points to missing class or interface '{$concrete}'.");
                }
                continue;
            }

            if (is_string($concrete) && $concrete === $abstract) {
                if (!class_exists($abstract) && !interface_exists($abstract)) {
                    throw new \RuntimeException("[Container] Container binding for '{$abstract}' references missing class or interface.");
                }
            }
        }
    }

    /** فهرست binding‌های ثبت‌شده — فقط برای Debug */
    public function getBindings(): array
    {
        return array_keys($this->bindings);
    }
}
