<?php

namespace App\Controllers;

use App\Services\SitemapService;
use App\Controllers\BaseController;

class SitemapController extends BaseController
{
    private SitemapService $sitemapService;
    
    public function __construct(SitemapService $sitemapService)
    {
        parent::__construct();
        $this->sitemapService = $sitemapService;
    }
    
    /**
     * نمایش Sitemap
     */
    public function index()
    {
        $this->response->header('Content-Type', 'application/xml; charset=utf-8');
        echo $this->sitemapService->getXml();
    }
}