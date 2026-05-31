<?php

declare(strict_types=1);

namespace App\Validators;

use Core\Validator;
use Core\Exceptions\ValidationException;

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

        $validator = new Validator($this->data, $this->rules());
        if ($validator->fails()) {
            $this->errors = $validator->errors();
            return false;
        }

        $this->validated = $validator->data();
        return true;
    }

    /**
     * Validate and throw ValidationException on failure.
     * Simplifies controller code: one call instead of validate()+if(fails()).
     *
     * @return array Validated data
     * @throws ValidationException
     */
    public function validateOrFail(): array
    {
        if (!$this->validate()) {
            throw new ValidationException(
                $this->errors,
                'اطلاعات ورودی نامعتبر است'
            );
        }
        return $this->validated ?? [];
    }

    public function validated(): array
    {
        return $this->validated ?? [];
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}

