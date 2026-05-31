<?php

declare(strict_types=1);

namespace Core\ValueObjects;

use DateTime;
use DateTimeZone;
use InvalidArgumentException;
// با فرض وجود کلاس JalaliDate در هسته یا Helpers
use App\Helpers\JalaliDate; 

/**
 * AppDate Value Object
 * 
 * مدیریت یکپارچه زمان در کل سیستم برای جلوگیری از مشکلات Timezone
 * در این سیستم هسته همیشه به وقت UTC است.
 */
readonly class AppDate
{
    private DateTime $dateTime;

    public function __construct(string $time = 'now', ?string $timezone = 'UTC')
    {
        try {
            $this->dateTime = new DateTime($time, new DateTimeZone($timezone ?? 'UTC'));
            // تبدیل به UTC به عنوان استاندارد ثابت دیتابیس
            if ($timezone !== 'UTC') {
                $this->dateTime->setTimezone(new DateTimeZone('UTC'));
            }
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Invalid date format: {$time}");
        }
    }

    public static function now(): self
    {
        return new self('now', 'UTC');
    }

    public static function fromTimestamp(int $timestamp): self
    {
        return new self('@' . $timestamp, 'UTC');
    }

    public static function fromFormat(string $format, string $time): self
    {
        $dt = DateTime::createFromFormat($format, $time, new DateTimeZone('UTC'));
        if (!$dt) {
            throw new InvalidArgumentException("Invalid date format or time: {$time}");
        }
        return new self($dt->format('Y-m-d H:i:s'), 'UTC');
    }

    public function getTimestamp(): int
    {
        return $this->dateTime->getTimestamp();
    }

    public function format(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->dateTime->format($format);
    }

    public function toJalaliString(string $format = 'Y/m/d H:i:s'): string
    {
        // در صورت عدم وجود کلاس JalaliDate به طور موقت از خروجی استاندارد استفاده می‌کند
        if (class_exists(JalaliDate::class)) {
            // فرض بر این است که JalaliDate تایم استمپ می‌گیرد
            return JalaliDate::fromTimestamp($this->getTimestamp())->format($format);
        }
        
        // Fallback
        return $this->dateTime->format($format) . ' (UTC)';
    }

    public function isExpired(int $minutes): bool
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $this->getTimestamp();
        
        return $diff > ($minutes * 60);
    }

    public function addMinutes(int $minutes): self
    {
        $clone = clone $this->dateTime;
        $clone->modify("+{$minutes} minutes");
        return new self($clone->format('Y-m-d H:i:s'), 'UTC');
    }
}
