<?php

declare(strict_types=1);

namespace App\Traits;

use App\Contracts\ValidatorFactoryInterface;
use Core\Container;

/**
 * UsesValidatorFactory — Trait برای استفاده تزریق‌شده از ValidatorFactory در کنترلرها و سرویس‌ها
 * 
 * این trait امکان دسترسی آسان به کارخانه validator را بدون نیاز به تزریق مستقیم فراهم می‌کند.
 * ValidatorFactory از Container واکشی می‌شود و نتیجه کش می‌شود.
 */
trait UsesValidatorFactory
{
    private ?ValidatorFactoryInterface $validatorFactory = null;

    /**
     * دریافت نمونه ValidatorFactoryInterface
     * بصورت تنبل (lazy) از Container واکشی و کش می‌شود
     */
    protected function validatorFactory(): ValidatorFactoryInterface
    {
        if ($this->validatorFactory === null) {
            $this->validatorFactory = Container::getInstance()->make(ValidatorFactoryInterface::class);
        }
        return $this->validatorFactory;
    }

    /**
     * شناور برای ساختن validator
     * استفاده: $this->validate($data, $rules)
     */
    protected function makeValidator(array $data, array $rules = [])
    {
        return $this->validatorFactory()->make($data, $rules);
    }
}
