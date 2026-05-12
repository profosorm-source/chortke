<?php

namespace Core;

class Route
{
    private string $uri;
    private $action; // ✅ بدون Type Declaration
    private array $middleware = [];
    private ?string $name = null;

    public function __construct(string $uri, $action)
    {
        $this->uri = $uri;
        $this->action = $action;
    }

    /**
     * اختصاص نام به Route
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * دریافت نام Route
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * افزودن Middleware به Route
     */
    public function middleware(string|array $middleware): self
    {
        if (is_string($middleware)) {
            $this->middleware[] = $middleware;
        } elseif (is_array($middleware)) {
            $this->middleware = array_merge($this->middleware, $middleware);
        }

        return $this;
    }

    /**
     * دریافت Middleware‌ها
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * دریافت URI
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * دریافت Action
     */
    public function getAction()
    {
        return $this->action;
    }
}