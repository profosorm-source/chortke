<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Services\Search\SearchOrchestrator;
use App\Services\Search\AdminSearchProvider;
use App\Services\Search\UserSearchProvider;
use App\Services\Search\ModuleSearchProvider;

/**
 * 🚀 UPG-01: AdvancedSearchService - نسخه ارتقایافته و سبک شده
 * 
 * Facade/Orchestrator جستجو.
 * Providerها اکنون از طریق DI تزریق می‌شوند و دیگر داخل این کلاس new نمی‌شوند.
 */
class AdvancedSearchService extends SearchOrchestrator
{
    public function __construct(
        AdminSearchProvider $adminProvider,
        UserSearchProvider $userProvider,
        ModuleSearchProvider $moduleProvider,
        LoggerInterface $logger
    ) {
        parent::__construct($adminProvider, $userProvider, $moduleProvider, $logger);
    }
}
