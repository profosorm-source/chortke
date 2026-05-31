<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ValidatorFactoryInterface;
use Core\Database;

class ValidatorFactory implements ValidatorFactoryInterface
{
    /**
     * ساختن یک Validator با پشتیبانی پیام‌های سفارشی
     */
    public function make(array $data, array $rules = [], array $messages = [], ?Database $db = null): \Core\Validator
    {
        $validator = new \Core\Validator($data, $rules, $db, $messages);
        return $validator;
    }
}

