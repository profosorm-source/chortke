<?php

declare(strict_types=1);

namespace Core\ValueObjects;

use InvalidArgumentException;

/**
 * Money Value Object
 * 
 * مدیریت امن مبالغ برای جلوگیری از خطای اعشار و تداخل محاسباتی.
 * به صورت پیش‌فرض تمام مبالغ در سیستم به صورت string (سازگار با bcmath) نگهداری می‌شوند.
 */
readonly class Money
{
    private string $amount;
    private string $currency;

    public function __construct(string|int|float $amount, string $currency = 'IRR')
    {
        if (is_float($amount)) {
            // جلوگیری از خطای Float precision با تبدیل دقیق
            $this->amount = number_format($amount, 4, '.', '');
        } else {
            $this->amount = (string) $amount;
        }
        $this->currency = strtoupper($currency);
    }

    public static function fromString(string $amount, string $currency = 'IRR'): self
    {
        return new self($amount, $currency);
    }

    public static function fromInt(int $amount, string $currency = 'IRR'): self
    {
        return new self((string) $amount, $currency);
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);
        $result = bcadd($this->amount, $other->getAmount(), 4);
        return new self($this->stripTrailingZeroes($result), $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);
        $result = bcsub($this->amount, $other->getAmount(), 4);
        return new self($this->stripTrailingZeroes($result), $this->currency);
    }

    public function multiply(string|int|float $multiplier): self
    {
        $multiplierStr = is_float($multiplier) ? number_format($multiplier, 4, '.', '') : (string) $multiplier;
        $result = bcmul($this->amount, $multiplierStr, 4);
        return new self($this->stripTrailingZeroes($result), $this->currency);
    }

    public function isGreaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return bccomp($this->amount, $other->getAmount(), 4) === 1;
    }

    public function isGreaterThanOrEqual(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return bccomp($this->amount, $other->getAmount(), 4) >= 0;
    }

    public function equals(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return bccomp($this->amount, $other->getAmount(), 4) === 0;
    }

    public function format(): string
    {
        $formattedAmount = number_format((float) $this->amount, 0);
        $currencyName = $this->currency === 'IRR' ? 'تومان' : $this->currency;
        return "{$formattedAmount} {$currencyName}";
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->getCurrency()) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->getCurrency()}");
        }
    }

    private function stripTrailingZeroes(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value === '' ? '0' : $value;
    }
}
