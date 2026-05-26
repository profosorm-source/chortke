<?php

namespace App\Validators;

/**
 * ================================================
 * DEPRECATED - Section 8.6 Refactor (فاز ۲)
 * ================================================
 *
 * این فایل دیگر استفاده نمی‌شود.
 * اعتبارسنجی ورودی → CreateCustomTaskRequest.php, SubmitCustomTaskProofRequest.php, RateCustomTaskRequest.php
 * اعتبارسنجی بیزینسی → CustomTaskService::guardCanCreateTask(), guardCanSubmitProof(), guardCanRateSubmission()
 *
 * این فایل فقط برای backward compatibility نگه داشته شده و در آینده حذف خواهد شد.
 */

class CustomTaskValidator
{
    public static function validateCreate(array $data): array
    {
        trigger_error('CustomTaskValidator::validateCreate is deprecated. Use CreateCustomTaskRequest instead.', E_USER_DEPRECATED);
        return [];
    }

    public static function validateProof(array $data, string $proofType): array
    {
        trigger_error('CustomTaskValidator::validateProof is deprecated. Use SubmitCustomTaskProofRequest instead.', E_USER_DEPRECATED);
        return [];
    }

    public static function validateRating(array $data): array
    {
        trigger_error('CustomTaskValidator::validateRating is deprecated. Use RateCustomTaskRequest instead.', E_USER_DEPRECATED);
        return [];
    }

    // سایر متدها هم deprecated هستند
}
