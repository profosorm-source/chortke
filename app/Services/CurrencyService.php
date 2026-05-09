<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Services\SettingService;

class CurrencyService extends \App\Services\BaseService
{
    private SettingService $settingService;

    public function __construct(SettingService $settingService, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->settingService = $settingService;
    }

    /**
     * دریافت حالت ارز فعال سیستم
     */
    public function getCurrentMode(): string
    {
        $mode = (string) $this->settingService->get('currency_mode', 'irt');
        $mode = \strtolower(\trim($mode));
        return \in_array($mode, ['irt','usdt'], true) ? $mode : 'irt';
    }

    /**
     * آیا حالت فعال تومان است؟
     */
    public function isIRT(): bool
    {
        return $this->getCurrentMode() === 'irt';
    }

    /**
     * آیا حالت فعال تتر است؟
     */
    public function isUSDT(): bool
    {
        return $this->getCurrentMode() === 'usdt';
    }
    
    /**
     * دریافت نماد ارز
     */
    public function getCurrencySymbol(): string
    {
        return $this->isIRT() ? 'تومان' : 'USDT';
    }
    
    /**
     * فرمت کردن مبلغ
     */
    public function formatAmount(float $amount): string
    {
        if ($this->isIRT()) {
            return number_format($amount, 0, '.', ',') . ' تومان';
        } else {
            return number_format($amount, 2, '.', ',') . ' USDT';
        }
    }
    
    /**
     * آیا این قسمت باید USDT باشد؟
     */
    public function isInvestmentSection(?string $uri = null): bool
    {
        if ($uri === null) {
            try {
                if (\class_exists('\Core\Container')) {
                    $container = \Core\Container::getInstance();
                    if ($container->has(\Core\Request::class)) {
                        $request = $container->get(\Core\Request::class);
                        $uri = $request ? $request->uri() : '';
                    }
                }
            } catch (\Throwable $e) {
                $uri = '';
            }
        }
        $uri = $uri ?? '';
        return \strpos($uri, '/investment') !== false;
    }
    
    /**
     * دریافت ارز برای قسمت فعلی
     */
    public function getSectionCurrency(): string
    {
        if ($this->isInvestmentSection()) {
            return 'USDT';
        }
        
        return $this->getCurrentMode();
    }
}
