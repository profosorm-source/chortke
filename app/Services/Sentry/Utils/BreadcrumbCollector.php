<?php

namespace App\Services\Sentry\Utils;

/**
 * 🍞 BreadcrumbCollector - جمع‌آوری Breadcrumbs
 * 
 * Breadcrumb = مسیر کاربر قبل از وقوع خطا
 * 
 * انواع breadcrumb:
 * - navigation: تغییر صفحه
 * - http: درخواست API
 * - user: اکشن کاربر (کلیک، submit)
 * - console: لاگ console
 * - query: کوئری دیتابیس
 * - transaction: تراکنش مالی
 */
class BreadcrumbCollector
{
    private array $breadcrumbs = [];
    private int $maxBreadcrumbs = 50;

    /**
     * ➕ Add Breadcrumb
     */
    public function add(
        string $message,
        string $category = 'default',
        string $level = 'info',
        array $data = []
    ): void {
        $breadcrumb = [
            'timestamp' => microtime(true),
            'message' => $message,
            'category' => $category,
            'level' => $level,
            'data' => $data,
        ];

        $this->breadcrumbs[] = $breadcrumb;

        // حذف قدیمی‌ها اگر بیش از حد باشد
        if (count($this->breadcrumbs) > $this->maxBreadcrumbs) {
            array_shift($this->breadcrumbs);
        }
    }

    /**
     * 🌐 Add Navigation
     */
    public function addNavigation(string $from, string $to): void
    {
        $this->add(
            "Navigated from {$from} to {$to}",
            'navigation',
            'info',
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * 🔗 Add HTTP Request
     */
    public function addHttpRequest(
        string $method,
        string $url,
        ?int $statusCode = null,
        ?float $duration = null
    ): void {
        $this->add(
            "{$method} {$url}",
            'http',
            'info',
            array_filter([
                'method' => $method,
                'url' => $url,
                'status_code' => $statusCode,
                'duration' => $duration ? round($duration, 2) . 'ms' : null,
            ])
        );
    }

    /**
     * 👤 Add User Action
     */
    public function addUserAction(string $action, array $data = []): void
    {
        $this->add(
            $action,
            'user',
            'info',
            $data
        );
    }

    /**
     * 📊 Add Database Query
     */
    public function addQuery(string $query, ?float $duration = null): void
    {
        // حذف parametersها برای امنیت
        $sanitizedQuery = preg_replace('/\bVALUES\s*\([^)]+\)/i', 'VALUES (?)', $query);
        
        $this->add(
            substr($sanitizedQuery, 0, 100),
            'query',
            'info',
            array_filter([
                'duration' => $duration ? round($duration, 2) . 'ms' : null,
            ])
        );
    }

    /**
     * 💰 Add Transaction
     */
    public function addTransaction(string $type, float $amount, int $userId): void
    {
        $this->add(
            "Transaction: {$type}",
            'transaction',
            'info',
            [
                'type' => $type,
                'amount' => $amount,
                'user_id' => $userId,
            ]
        );
    }

    /**
     * 🖥️ Add Console Log
     */
    public function addConsole(string $message, string $level = 'log'): void
    {
        $this->add(
            $message,
            'console',
            $level,
            []
        );
    }

    /**
     * ⚠️ Add Error
     */
    public function addError(string $message, array $data = []): void
    {
        $this->add(
            $message,
            'error',
            'error',
            $data
        );
    }

    /**
     * 📝 Get All Breadcrumbs
     */
    public function getAll(): array
    {
        return $this->breadcrumbs;
    }

    /**
     * 🧹 Clear
     */
    public function clear(): void
    {
        $this->breadcrumbs = [];
    }

    /**
     * 📊 Get Summary
     */
    public function getSummary(): array
    {
        $categories = [];
        foreach ($this->breadcrumbs as $crumb) {
            $cat = $crumb['category'];
            $categories[$cat] = ($categories[$cat] ?? 0) + 1;
        }

        return [
            'total' => count($this->breadcrumbs),
            'by_category' => $categories,
        ];
    }

    /**
     * ⏱️ Get Recent (آخرین N تا)
     */
    public function getRecent(int $count = 10): array
    {
        return array_slice($this->breadcrumbs, -$count);
    }

    /**
     * 🔍 Filter by Category
     */
    public function filterByCategory(string $category): array
    {
        return array_filter(
            $this->breadcrumbs,
            fn($crumb) => $crumb['category'] === $category
        );
    }

    /**
     * ⚙️ Set Max Breadcrumbs
     */
    public function setMaxBreadcrumbs(int $max): void
    {
        $this->maxBreadcrumbs = $max;
    }
}
