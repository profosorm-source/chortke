<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Services\SettingService;
use App\Contracts\CurrencyServiceInterface;
use Core\Request;

class CurrencyService extends \App\Services\BaseService implements CurrencyServiceInterface
{
    private SettingService $settingService;
    private Request $request;

    public function __construct(SettingService $settingService, LoggerInterface $logger, Request $request)
    {
        parent::__construct($logger);
        $this->settingService = $settingService;
        $this->request = $request;
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
    public function formatAmount(float $amount, ?string $currency = null): string
    {
        $cur = $currency ? \strtolower(\trim($currency)) : $this->getCurrentMode();
        if ($cur === 'irt') {
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
            $uri = $this->request->uri() ?? '';
        }
        $uri = $uri ?? '';
        $uri = '/' . \ltrim($uri, '/');
        return $uri === '/investment' || \str_starts_with($uri, '/investment/');
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
