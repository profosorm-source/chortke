<?php

declare(strict_types=1);

namespace App\Contracts;

use Core\Database;

interface ValidatorFactoryInterface
{
    public function make(array $data, array $rules = [], array $messages = [], ?Database $db = null): \Core\Validator;
}
