<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class RobotsController extends BaseController
{
    /**
     * نمایش robots.txt داینامیک
     */
    public function index()
    {
        $baseUrl = config('app.url') ?: setting('site_url', 'https://chortke.com');
        $baseUrl = rtrim($baseUrl, '/');

        $content = "# robots.txt for Chortke\n\n";
        $content .= "User-agent: *\n\n";
        
        $content .= "# Allow\n";
        $content .= "Allow: /\n";
        $content .= "Allow: /pages/\n";
        $content .= "Allow: /assets/\n\n";
        
        $content .= "# Disallow\n";
        $content .= "Disallow: /admin/\n";
        $content .= "Disallow: /user/\n";
        $content .= "Disallow: /storage/\n";
        $content .= "Disallow: /config/\n";
        $content .= "Disallow: /database/\n";
        $content .= "Disallow: /vendor/\n\n";
        
        $content .= "# Sitemap\n";
        $content .= "Sitemap: {$baseUrl}/sitemap.xml\n";

        $this->response->header('Content-Type', 'text/plain; charset=utf-8');
        echo $content;
    }
}
