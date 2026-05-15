<?php

namespace App\Services;

use App\Models\Page;
use Core\Cache;
use App\Contracts\LoggerInterface;
use App\Services\SettingService;

class SitemapService extends \App\Services\BaseService
{
    private Page $pageModel;
    private Cache $cache;
    private SettingService $settingService;
    private const CACHE_KEY = 'sitemap_xml_content';

    public function __construct(
        LoggerInterface $logger,
        \App\Models\Page $pageModel,
        Cache $cache,
        SettingService $settingService
    ) {
        parent::__construct($logger);
        $this->pageModel = $pageModel;
        $this->cache = $cache;
        $this->settingService = $settingService;
    }
    
    /**
     * تولید Sitemap
     */
    public function generate(): string
    {
        $baseUrl = config('app.url') ?: $this->settingService->get('site_url');
        
        if (empty($baseUrl) || $baseUrl === 'http://localhost') {
            if (config('app.env') === 'production') {
                throw new \RuntimeException("APP_URL must be set in production for sitemap generation.");
            }
            $baseUrl = 'http://localhost';
        }
        $baseUrl = rtrim($baseUrl, '/');
        $pages = $this->pageModel->getAll();
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        // صفحه اصلی (آخرین تغییر یکی از صفحات را به عنوان تاریخ اصلی در نظر می‌گیریم)
        $lastUpdate = date('Y-m-d');
        if (!empty($pages)) {
            $updates = array_map(fn($p) => strtotime($p->updated_at), $pages);
            $lastUpdate = date('Y-m-d', max($updates));
        }
        $xml .= $this->addUrl($baseUrl, '1.0', 'daily', $lastUpdate);
        
        // صفحات استاتیک
        foreach ($pages as $page) {
            if ($page->is_active) {
                $url = $baseUrl . '/pages/' . $page->slug;
                $xml .= $this->addUrl($url, '0.8', 'weekly', date('Y-m-d', strtotime($page->updated_at)));
            }
        }
        
        // سایر صفحات عمومی
        $publicPages = [
            '/login' => ['priority' => '0.7', 'changefreq' => 'monthly'],
            '/register' => ['priority' => '0.7', 'changefreq' => 'monthly']
        ];
        
        foreach ($publicPages as $path => $config) {
            $xml .= $this->addUrl(
                $baseUrl . $path,
                $config['priority'],
                $config['changefreq'],
                date('Y-m-d')
            );
        }
        
        $xml .= '</urlset>';
        
        return $xml;
    }

    public function getXml(): string
    {
        // H-05 Fix: پیاده‌سازی Mutex Lock اختصاصی برای جلوگیری از Cache Stampede
        // برخلاف remember پیش‌فرض، اینجا در صورت قفل بودن منتظر می‌مانیم یا کش قدیمی را برمی‌گردانیم
        
        $xml = $this->cache->get(self::CACHE_KEY);
        if ($xml !== null) {
            return $xml;
        }

        // تلاش برای گرفتن قفل به مدت ۱۰ ثانیه
        $lockKey = 'sitemap_gen_mutex';
        if ($this->cache->lock($lockKey, 60, 10)) {
            try {
                // Double check
                $xml = $this->cache->get(self::CACHE_KEY);
                if ($xml !== null) {
                    return $xml;
                }

                $xml = $this->generate();
                $this->cache->put(self::CACHE_KEY, $xml, 30); // ۳۰ دقیقه کش
                return $xml;
            } finally {
                $this->cache->unlock($lockKey);
            }
        }

        // اگر بعد از ۱۰ ثانیه قفل آزاد نشد، آخرین مقدار موجود را برگردان (حتی اگر خالی باشد)
        return (string)($this->cache->get(self::CACHE_KEY) ?? '');
    }
    
    /**
     * افزودن URL
     */
    private function addUrl(string $loc, string $priority, string $changefreq, string $lastmod): string
    {
        $xml = "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . "</loc>\n";
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
        $xml .= "    <priority>{$priority}</priority>\n";
        $xml .= "  </url>\n";
        
        return $xml;
    }
    
    /**
     * ذخیره فایل
     */
    public function save(): bool
    {
        $xml = $this->generate();
        $path = __DIR__ . '/../../public/sitemap.xml';
        
        return file_put_contents($path, $xml) !== false;
    }
}
