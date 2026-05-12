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

    private $reflectionCache = [];
    private bool $isLoggingMissing = false;

    /** @var array<string, array<string>> */
    private array $tags = [];

    /** @var array<string, array<\Closure>> */
    private array $extenders = [];
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

    // ─────────────────────────────────────────────────────────────
    // Resolution
    // ─────────────────────────────────────────────────────────────
    
    private static array $traceStack = [];

    /**
     * H15 Fix: پاکسازی استک دیباگ چرخه‌ای برای جلوگیری از false-positive در فرآیندهای طولانی
     */
    public static function resetTraceStack(): void
    {
        self::$traceStack = [];
    }

    /**
     * ساخت / دریافت instance
     *
     * @throws \RuntimeException
     */
    public function make(string $abstract): object
    {
        if (in_array($abstract, self::$traceStack, true)) {
            throw new \RuntimeException("Circular dependency detected: " . implode(" -> ", self::$traceStack) . " -> " . $abstract);
        }
        self::$traceStack[] = $abstract;
        try {
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
            array_pop(self::$traceStack);
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
        // M6 Fix: جلوگیری از رشد نامحدود حافظه با تعیین سقف ۵۰۰ آیتم برای کش رفلکشن
        if (count($this->reflectionCache) >= 500) {
            array_shift($this->reflectionCache);
        }
        $this->reflectionCache[$concrete] = new \ReflectionClass($concrete);
    }

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

    public function forget(string $abstract): void
    {
        unset($this->bindings[$abstract], $this->singletons[$abstract]);
    }

    /** فهرست binding‌های ثبت‌شده — فقط برای Debug */
    public function getBindings(): array
    {
        return array_keys($this->bindings);
    }
}
