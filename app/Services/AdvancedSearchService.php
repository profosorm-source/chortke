<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdvancedSearch;
use Core\Cache;
use App\Contracts\LoggerInterface;
use App\Services\Search\SearchOrchestrator;
use App\Services\Search\AdminSearchProvider;
use App\Services\Search\UserSearchProvider;
use App\Services\Search\ModuleSearchProvider;

/**
 * 🚀 UPG-01: AdvancedSearchService - نسخه ارتقایافته و سبک شده
 * 
 * این کلاس اکنون صرفاً نقش یک نماساز/هماهنگ‌کننده (Facade/Orchestrator) را ایفا می‌کند و تمامی لاجیک‌های 
 * سنگین و پراکنده جستجو را به تأمین‌کنندگان تخصصی محول کرده است تا از ضدالگوی God-Service و ازدحام 
 * دپندنسی‌ها جلوگیری شود.
 * 
 * 💡 به دلیل وراثت کامل از ارکستریتور، این کلاس با تمامی کنترلرها و کلاس‌های فراخواننده سیستم ۱۰۰٪ 
 * سازگاری عقب‌گرد داشته و هیچ تغییری در کدهای بیرونی لازم نیست.
 */
class AdvancedSearchService extends SearchOrchestrator
{
    /**
     * سازنده سازگار با نسخه‌های پیشین جهت جلوگیری از بروز خطاهای استارتاپ
     */
    public function __construct(
        AdvancedSearch $searchModel,
        LoggerInterface $logger,
        Cache $cache
    ) {
        // ایجاد تأمین‌کنندگان زیرمجموعه به صورت ماژولار و تزریق آن‌ها به ارکستریتور پایه
        $adminProvider  = new AdminSearchProvider($searchModel, $cache, $logger);
        $userProvider   = new UserSearchProvider($searchModel, $cache, $logger);
        $moduleProvider = new ModuleSearchProvider($searchModel, $cache, $logger);

        // فراخوانی سازنده والد ارکستریتور جهت سازماندهی درخواست‌ها
        parent::__construct($adminProvider, $userProvider, $moduleProvider, $logger);
    }
}
