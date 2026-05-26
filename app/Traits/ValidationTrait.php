<?php

declare(strict_types=1);

namespace App\Traits;

use Core\Validator;
use App\Exceptions\BusinessException;

/**
 * ValidationTrait - Centralized Validation for Services
 * 
 * Provides centralized validation helpers for service layer.
 * Services using this trait have consistent validation across the codebase.
 * 
 * Usage in Service:
 * ```
 * class UserService extends BaseService {
 *     use ValidationTrait;
 *     
 *     public function createUser(array $data): array {
 *         $validated = $this->validate($data, [
 *             'email' => 'required|email',
 *             'password' => 'required|min:8',
 *         ]);
 *         // Use $validated data
 *     }
 * }
 * ```
 */
trait ValidationTrait
{
    /**
     * Quick validation with fluent builder
     * 
     * @param array $data Input data
     * @param array $rules Validation rules
     * @return array Validated data
     * @throws BusinessException If validation fails
     */
    protected function validate(array $data, array $rules): array
    {
        $validator = Validator::create($data, $rules);
        return $validator->validateOrFail();
    }

    /**
     * Validate with custom rules and authorization
     * 
     * @param array $data Input data
     * @param array $rules Validation rules
     * @param callable|null $authCallback Authorization check
     * @param array $customRules Custom validation callbacks
     * @return array Validated data
     * @throws BusinessException If validation fails
     */
    protected function validateWith(
        array $data,
        array $rules,
        ?callable $authCallback = null,
        array $customRules = []
    ): array {
        $validator = Validator::create($data, $rules);

        // Set authorization
        if ($authCallback !== null) {
            $validator->authorize($authCallback);
        }

        // Add custom validations
        foreach ($customRules as $field => $rule) {
            $validator->custom(
                $field,
                $rule['callback'],
                $rule['message'] ?? "فیلد {$field} نامعتبر است"
            );
        }

        return $validator->validateOrFail();
    }

    /**
     * Validate and return result array (no exception)
     * 
     * @param array $data Input data
     * @param array $rules Validation rules
     * @return array ['valid' => bool, 'data' => array|null, 'errors' => array, 'message' => string]
     */
    protected function validateSafely(array $data, array $rules): array
    {
        $validator = Validator::create($data, $rules);
        return $validator->result();
    }

    /**
     * Validate with custom rules and return result array
     * 
     * @param array $data Input data
     * @param array $rules Validation rules
     * @param callable|null $authCallback Authorization check
     * @param array $customRules Custom validation callbacks
     * @return array ['valid' => bool, 'data' => array|null, 'errors' => array, 'message' => string]
     */
    protected function validateWithSafely(
        array $data,
        array $rules,
        ?callable $authCallback = null,
        array $customRules = []
    ): array {
        $validator = Validator::create($data, $rules);

        // Set authorization
        if ($authCallback !== null) {
            $validator->authorize($authCallback);
        }

        // Add custom validations
        foreach ($customRules as $field => $rule) {
            $validator->custom(
                $field,
                $rule['callback'],
                $rule['message'] ?? "فیلد {$field} نامعتبر است"
            );
        }

        return $validator->result();
    }

    /**
     * Build a validator fluently
     * 
     * @param array $data Input data
     * @return Validator
     */
    protected function validator(array $data): Validator
    {
        return Validator::create($data);
    }

    /**
     * Validate authorization
     * 
     * @param callable $callback Authorization check
     * @return bool
     * @throws BusinessException If not authorized
     */
    protected function authorize(callable $callback): bool
    {
        if (!$callback()) {
            throw new BusinessException('شما دسترسی لازم برای این عملیات را ندارید');
        }
        return true;
    }

    /**
     * Validate a single field
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @param string $rule Validation rule (e.g., 'required|email')
     * @return bool
     */
    protected function validateField(string $field, mixed $value, string $rule): bool
    {
        $validator = Validator::create([$field => $value], [$field => $rule]);
        return $validator->passes();
    }

    /**
     * Get validation errors for a single field
     * 
     * @param string $field Field name
     * @param mixed $value Field value
     * @param string $rule Validation rule
     * @return array Errors
     */
    protected function fieldErrors(string $field, mixed $value, string $rule): array
    {
        $validator = Validator::create([$field => $value], [$field => $rule]);
        $validator->validate();
        return $validator->errors()[$field] ?? [];
    }
}
