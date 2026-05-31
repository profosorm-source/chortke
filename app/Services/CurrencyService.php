<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;
use App\Contracts\CurrencyServiceInterface;
use Core\Request;

class CurrencyService implements CurrencyServiceInterface
{
    private AppSettings $appSettings;
    private Request $request;

    public function __construct(
        AppSettings $appSettings,
        Request $request
    )
    {
                $this->appSettings = $appSettings;
        $this->request = $request;
    }

    /**
     * دریافت حالت ارز فعال سیستم
     */
    public function getCurrentMode(): string
    {
        $mode = (string) $this->appSettings->get('currency_mode', 'irt');
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
    public function formatAmount(float|string $amount, ?string $currency = null): string
    {
        $cur = $currency ? \strtolower(\trim($currency)) : $this->getCurrentMode();
        $num = (float)$amount;
        if ($cur === 'irt') {
            return number_format($num, 0, '.', ',') . ' تومان';
        } else {
            $strAmount = (string)$amount;
            if (strpos($strAmount, '.') !== false) {
                $decimals = strlen(substr($strAmount, strpos($strAmount, '.') + 1));
                $decimals = max(2, min(8, $decimals));
            } else {
                $decimals = 2;
            }
            return number_format($num, $decimals, '.', ',') . ' USDT';
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
