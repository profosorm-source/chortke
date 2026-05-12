<?php

namespace Core;

class PathResolver
{
    private static ?PathResolver $instance = null;
    private string $baseUrl;
    private string $basePath;
    
    private function __construct()
    {
        // تشخیص Base URL از تنظیمات (Canonical)
        $this->baseUrl = rtrim(config('app.url', 'http://localhost'), '/');
        
        // تشخیص مسیر پروژه (فیزیکی)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = str_replace('\\', '/', dirname($scriptName));
        
        $this->basePath = ($scriptDir === '/' || $scriptDir === '') ? '' : $scriptDir;
    }
    
    public static function getInstance(): PathResolver
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * برگرداندن URL کامل
     */
    public function url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        return $this->baseUrl . ($path ? '/' . $path : '');
    }
    
    /**
     * برگرداندن مسیر Asset ها
     */
    public function asset(string $path = ''): string
    {
        $path = ltrim($path, '/');
        return $this->baseUrl . '/assets/' . $path;
    }
    
    /**
     * برگرداندن Base URL
     */
    public function base(): string
    {
        return $this->baseUrl;
    }
    
    /**
     * برگرداندن مسیر فیزیکی
     */
    public function path(string $path = ''): string
    {
        $rootPath = dirname(__DIR__);
        $path = ltrim($path, '/');
        return $rootPath . ($path ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path) : '');
    }
    
    /**
     * برگرداندن مسیر Storage
     */
    public function storage(string $path = ''): string
    {
        return $this->path('storage/' . ltrim($path, '/'));
    }
    
    /**
     * برگرداندن مسیر Public
     */
    public function public(string $path = ''): string
    {
        return $this->path('public/' . ltrim($path, '/'));
    }
}