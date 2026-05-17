<?php

declare(strict_types=1);

namespace App\Contracts;

interface CurrencyServiceInterface
{
    public function getCurrentMode(): string;
    public function isIRT(): bool;
    public function isUSDT(): bool;
    public function getCurrencySymbol(): string;
    public function formatAmount(float|string $amount, ?string $currency = null): string;
    public function getSectionCurrency(): string;
}
