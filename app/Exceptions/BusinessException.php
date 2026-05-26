<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * BusinessException - برای خطاهای قوانین بیزینسی (Section 8.6)
 *
 * این exception برای اعتبارسنجی لایه Business Guard استفاده می‌شود
 * و باید در Controller به صورت کاربرپسند (422) نمایش داده شود.
 */
class BusinessException extends \Core\Exceptions\BusinessException
{
    public function __construct(string $message = 'خطای بیزینسی رخ داد', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
