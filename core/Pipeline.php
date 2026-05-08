<?php

declare(strict_types=1);

namespace Core;

use Closure;

/**
 * Pipeline — مدیریت زنجیره‌ی Middlewareها
 * 
 * این کلاس درخواست (Request) را از بین چندین Middleware عبور می‌دهد.
 * هر Middleware می‌تواند پاسخ را تغییر دهد یا جلوی ادامه‌ی مسیر را بگیرد.
 */
class Pipeline
{
    protected array $pipes = [];
    protected mixed $passable;
    protected Container $container;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? Container::getInstance();
    }

    /**
     * تنظیم آبجکتی که باید از لوله‌ها عبور کند (معمولاً Request)
     */
    public function send(mixed $passable): self
    {
        $this->passable = $passable;
        return $this;
    }

    /**
     * تنظیم لیست Middlewareها
     */
    public function through(array $pipes): self
    {
        $this->pipes = $pipes;
        return $this;
    }

    /**
     * اجرای زنجیره و در نهایت اجرای Callback مقصد
     */
    public function then(Closure $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            $this->prepareDestination($destination)
        );

        return $pipeline($this->passable);
    }

    /**
     * آماده‌سازی مقصد نهایی (Controller Action)
     */
    protected function prepareDestination(Closure $destination): Closure
    {
        return function ($passable) use ($destination) {
            return $destination($passable);
        };
    }

    /**
     * ایجاد حلقه‌ی اتصال بین Middlewareها
     */
    protected function carry(): Closure
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                $parameters = [];

                if (is_string($pipe)) {
                    if (str_contains($pipe, ':')) {
                        [$pipe, $parameterString] = explode(':', $pipe, 2);
                        $parameters = explode(',', $parameterString);
                    }
                    // ساخت Middleware از Container برای حل وابستگی‌ها
                    $pipe = $this->container->make($pipe);
                }

                if (is_callable($pipe)) {
                    return $pipe($passable, $stack, ...$parameters);
                }

                if (!method_exists($pipe, 'handle')) {
                    throw new \RuntimeException("Middleware " . get_class($pipe) . " must have a handle() method.");
                }

                return $pipe->handle($passable, $stack, ...$parameters);
            };
        };
    }
}
