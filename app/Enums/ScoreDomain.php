<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical score domains.
 *
 * دامنه‌های امتیازدهی عمداً از هم جدا هستند. مثلا social_trust فقط برای منطق
 * اعتماد تسک‌های اجتماعی است و نباید به عنوان trust عمومی همه ماژول‌ها تفسیر شود.
 */
enum ScoreDomain: string
{
    case Fraud = 'fraud';
    case Task = 'task';
    case SocialTrust = 'social_trust';
    case Referral = 'referral';
    case Activity = 'activity';
    case Loyalty = 'loyalty';
    case Reputation = 'reputation';

    public static function normalize(string $domain): string
    {
        $domain = strtolower(trim($domain));

        // Legacy compatibility only: do not persist new generic "trust" buckets.
        if ($domain === 'trust') {
            return self::SocialTrust->value;
        }

        return $domain;
    }

    public static function tryFromNormalized(string $domain): ?self
    {
        return self::tryFrom(self::normalize($domain));
    }

    public static function values(): array
    {
        return array_map(static fn(self $case) => $case->value, self::cases());
    }

    public static function isValid(string $domain): bool
    {
        return self::tryFromNormalized($domain) !== null;
    }
}
