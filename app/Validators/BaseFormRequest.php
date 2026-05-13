<?php

declare(strict_types=1);

namespace App\Validators;

use Core\Validator;

abstract class BaseFormRequest
{
    protected array $data;
    protected array $errors = [];
    protected ?array $validated = null;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    abstract public function rules(): array;

    public function messages(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function validate(): bool
    {
        if (!$this->authorize()) {
            $this->errors = ['authorization' => 'Unauthorized request.'];
            return false;
        }

        $validator = new Validator($this->data, $this->rules(), $this->messages());
        if ($validator->fails()) {
            $this->errors = $validator->errors();
            return false;
        }

        $this->validated = $validator->data();
        return true;
    }

    public function validated(): array
    {
        return $this->validated ?? [];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
