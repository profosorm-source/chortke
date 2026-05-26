<?php

namespace App\Validators;

use Core\Validator;

/**
 * ================================================
 * DEPRECATED - Section 8.6 Refactor (فاز ۱)
 * ================================================
 *
 * این فایل دیگر استفاده نمی‌شود.
 * اعتبارسنجی ورودی → CreateWithdrawalRequest.php
 * اعتبارسنجی بیزینسی → WithdrawalUserService::guardCanCreateWithdrawal()
 *
 * این فایل فقط برای backward compatibility نگه داشته شده و در آینده حذف خواهد شد.
 */

class WithdrawalValidator extends Validator
{
    public function __construct(array $data = [])
    {
        trigger_error('WithdrawalValidator is deprecated. Use CreateWithdrawalRequest + WithdrawalUserService::guardCanCreateWithdrawal() instead.', E_USER_DEPRECATED);
        parent::__construct($data);
    }

    // بقیه متدها بدون تغییر (برای جلوگیری از خطای موجود)
}
