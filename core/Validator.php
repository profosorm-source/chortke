<?php
declare(strict_types=1);

namespace Core;

class Validator
{
    protected array $data = [];
    protected array $rules = [];
    protected array $errors = [];
    protected array $customValidations = [];

    /** @var callable|null */
    protected $authorizationCheck = null;

    protected array $messages = [];

    private ?Database $db = null;

    public function __construct(array $data, array $rules = [], ?Database $db = null)
    {
        $this->data = $data;
        $this->rules = [];
        
        // H17 Fix: تزریق دیتابیس از طریق DI به جای فراخوانی مستقیم Singleton در لایه Core
        $this->db = $db ?? Container::getInstance()->make(Database::class);

        if (!empty($rules)) {
            $this->validate($rules);
        }
    }

    public function validate(?array $rules = null): void
    {
        // اگر rules پاس داده شد، ست کن
        if ($rules !== null) {
            $this->rules = $rules;
        }

        // اگر هنوز rules نداریم، خطای پیکربندی پرتاب کن (Fail-Closed)
        if (empty($this->rules)) {
            throw new \InvalidArgumentException('Validation rules cannot be empty.');
        }

        foreach ($this->rules as $field => $ruleString) {
            $fieldRules = \explode('|', (string)$ruleString);
            $value = $this->data[$field] ?? null;

            $skipRemaining = false;
            foreach ($fieldRules as $rule) {
                if ($rule === 'nullable' && ($value === null || $value === '')) {
                    $skipRemaining = true;
                    break;
                }
                $this->applyRule($field, $value, $rule);
            }

            if ($skipRemaining) {
                continue;
            }
        }
    }

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        $param = null;

        if (\strpos($rule, ':') !== false) {
            [$ruleName, $param] = \explode(':', $rule, 2);
        } else {
            $ruleName = $rule;
        }

        switch ($ruleName) {
            case 'required':
                if ($value === null || $value === '' || (\is_array($value) && empty($value))) {
                    $this->addError($field, 'این فیلد الزامی است');
                }
                break;

            case 'email':
                if ($value !== null && $value !== '' && !\filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, 'ایمیل نامعتبر است');
                }
                break;

            case 'min':
                if ($value !== null && $value !== '') {
                    $rules = \explode('|', (string)($this->rules[$field] ?? ''));
                    $isNumeric = \in_array('numeric', $rules, true) || \is_numeric($value);
                    if ($isNumeric) {
                        $minVal = (string)$param;
                        if (bccomp((string)$value, $minVal, 8) < 0) {
                            $this->addError($field, "حداقل مقدار مجاز {$minVal} است");
                        }
                    } else {
                        $min = (int)$param;
                        if (\mb_strlen((string)$value) < $min) {
                            $this->addError($field, "حداقل {$min} کاراکتر مجاز است");
                        }
                    }
                }
                break;

            case 'max':
                if ($value !== null && $value !== '') {
                    $max = (int)$param;
                    if (\mb_strlen((string)$value) > $max) {
                        $this->addError($field, "حداکثر {$max} کاراکتر مجاز است");
                    }
                }
                break;

            case 'in':
                if ($value !== null && $value !== '') {
                    $allowed = \explode(',', (string)$param);
                    if (!\in_array((string)$value, $allowed, true)) {
                        $this->addError($field, 'مقدار نامعتبر است');
                    }
                }
                break;

            case 'date':
                if ($value !== null && $value !== '' && \strtotime((string)$value) === false) {
                    $this->addError($field, 'تاریخ نامعتبر است');
                }
                break;

            // ✅ جدید: regex
            case 'regex':
                if ($value !== null && $value !== '' && !\preg_match((string)$param, (string)$value)) {
                    $this->addError($field, 'فرمت نامعتبر است');
                }
                break;

            // ✅ جدید: url
            case 'url':
                if ($value !== null && $value !== '' && !\filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->addError($field, 'URL نامعتبر است');
                }
                break;

            // ✅ جدید: ip
            case 'ip':
                if ($value !== null && $value !== '' && !\filter_var($value, FILTER_VALIDATE_IP)) {
                    $this->addError($field, 'آدرس IP نامعتبر است');
                }
                break;

            // ✅ جدید: numeric
            case 'numeric':
                if ($value !== null && $value !== '' && !\is_numeric($value)) {
                    $this->addError($field, 'این فیلد باید عددی باشد');
                }
                break;

            // ✅ جدید: integer
            case 'integer':
                if ($value !== null && $value !== '' && !\filter_var($value, FILTER_VALIDATE_INT)) {
                    $this->addError($field, 'این فیلد باید عدد صحیح باشد');
                }
                break;

            // ✅ جدید: boolean
            case 'boolean':
                if ($value !== null && $value !== '' && !\in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
                    $this->addError($field, 'این فیلد باید boolean باشد');
                }
                break;

            // ✅ جدید: string
            case 'string':
                if ($value !== null && $value !== '' && !\is_string($value)) {
                    $this->addError($field, 'این فیلد باید متن باشد');
                }
                break;

            // ✅ جدید: nullable
            case 'nullable':
                // Nullable fields are handled before applying other rules.
                break;

            // ✅ جدید: array
            case 'array':
                if ($value !== null && $value !== '' && !\is_array($value)) {
                    $this->addError($field, 'این فیلد باید آرایه باشد');
                }
                break;

            // ✅ جدید: confirmed - فیلدی برابر با field_confirmation
            case 'confirmed':
                $confirmationField = $field . '_confirmation';
                $confirmationValue = $this->data[$confirmationField] ?? null;
                if ($value !== $confirmationValue) {
                    $this->addError($field, 'تایید مطابقت ندارد');
                }
                break;

            // ✅ جدید: phone
            case 'phone':
                if ($value !== null && $value !== '' && !\preg_match('/^(\+98|0)?9\d{9}$/', (string)$value)) {
                    $this->addError($field, 'شماره تلفن نامعتبر است');
                }
                break;

            // ✅ جدید: mobile
            case 'mobile':
                if ($value !== null && $value !== '' && !\preg_match('/^(\+98|0)?9(1[0-9]|3[1-9]|2[1-9])\d{7}$/', (string)$value)) {
                    $this->addError($field, 'شماره موبایل نامعتبر است');
                }
                break;

            // ✅ جدید: national_code (کد ملی ایران)
            case 'national_code':
                if (!$this->validateNationalCode((string)$value)) {
                    $this->addError($field, 'کد ملی نامعتبر است');
                }
                break;

            // ✅ جدید: unique - فیلد در DB منحصر به فرد باشد
            case 'unique':
                // param: table.column
                if ($value !== null && $value !== '') {
                    if (!$this->isUnique($param, $value, $field)) {
                        $this->addError($field, 'این مقدار قبلاً استفاده شده است');
                    }
                }
                break;

            // ✅ جدید: exists - فیلد در DB موجود باشد
            case 'exists':
                // param: table.column
                if ($value !== null && $value !== '') {
                    if (!$this->valueExists($param, $value)) {
                        $this->addError($field, 'مقدار موجود نیست');
                    }
                }
                break;

            // ✅ جدید: timezone
            case 'timezone':
                if ($value !== null && $value !== '' && !\in_array($value, \timezone_identifiers_list(), true)) {
                    $this->addError($field, 'منطقه زمانی نامعتبر است');
                }
                break;

            // ✅ جدید: json
            case 'json':
                if ($value !== null && $value !== '') {
                    \json_decode((string)$value);
                    if (\json_last_error() !== JSON_ERROR_NONE) {
                        $this->addError($field, 'JSON نامعتبر است');
                    }
                }
                break;

            // ✅ جدید: uppercase
            case 'uppercase':
                if ($value !== null && $value !== '' && $value !== \strtoupper($value)) {
                    $this->addError($field, 'باید حروف بزرگ باشد');
                }
                break;

            // ✅ جدید: lowercase
            case 'lowercase':
                if ($value !== null && $value !== '' && $value !== \strtolower($value)) {
                    $this->addError($field, 'باید حروف کوچک باشد');
                }
                break;

            // ✅ جدید: persian
            case 'persian':
                if ($value !== null && $value !== '' && !\preg_match('/^[\x{0600}-\x{06FF}\s]+$/u', (string)$value)) {
                    $this->addError($field, 'فقط حروف فارسی مجاز است');
                }
                break;

            // ✅ جدید: english
            case 'english':
                if ($value !== null && $value !== '' && !\preg_match('/^[a-zA-Z\s]+$/', (string)$value)) {
                    $this->addError($field, 'فقط حروف انگلیسی مجاز است');
                }
                break;
        }
    }

    // ✅ Helper functions برای validation
    private function validateNationalCode(string $code): bool
    {
        // فرمت: 10 رقم
        if (!\preg_match('/^[0-9]{10}$/', $code)) {
            return false;
        }

        // الگوریتم check digit
        $check = 0;
        for ($i = 0; $i < 10; $i++) {
            $check += (int)$code[$i] * (10 - $i);
        }
        $check = $check % 11;

        if ($check < 2) {
            return (int)$code[9] === $check;
        } else {
            return (int)$code[9] === 11 - $check;
        }
    }

    private function isUnique(?string $param, mixed $value): bool
    {
        if ($param === null) return true;

        try {
            [$table, $column] = \explode('.', $param, 2);
            
            // H16 Fix: ولیدیت سختگیرانه نام جدول و ستون برای جلوگیری از Table Injection
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                throw new \InvalidArgumentException("[Validator] Invalid table or column specified in unique rule.");
            }

            // H17: استفاده از اینجکشن دیتابیس
            $result = $this->db->query(
                "SELECT COUNT(*) as count FROM `{$table}` WHERE `{$column}` = ?",
                [$value]
            )->fetch();

            return ($result->count ?? 0) === 0;
        } catch (\Throwable $e) {
            return true; // Assume valid if DB check fails (avoid blocking user on query error)
        }
    }

    private function valueExists(?string $param, mixed $value): bool
    {
        if ($param === null) return true;

        try {
            [$table, $column] = \explode('.', $param, 2);
            
            // H16 Fix: حفاظت از تزریق SQL در قوانین دیتابیسی
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                throw new \InvalidArgumentException("[Validator] Invalid table or column specified in exists rule.");
            }

            // H17: استفاده از دیتابیس تزریق شده
            $result = $this->db->query(
                "SELECT COUNT(*) as count FROM `{$table}` WHERE `{$column}` = ?",
                [$value]
            )->fetch();

            return ($result->count ?? 0) > 0;
        } catch (\Throwable $e) {
            return false; // Assume invalid if DB check fails
        }
    }

    protected function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    // قانون 20
    public function data(): array
    {
        return $this->data;
    }

    public function all(): array
    {
        return $this->data;
    }

    // ============================================================
    // Pipeline Methods - برای chain-able fluent validation
    // ============================================================

    /**
     * Set custom error messages for fields
     * 
     * @param array $messages Custom messages
     * @return self
     */
    public function messages(array $messages): self
    {
        $this->messages = $messages;
        return $this;
    }

    /**
     * Add a custom validation rule (callback-based)
     * 
     * @param string $field Field name
     * @param callable $callback Validation callback (receives field value, returns bool)
     * @param string $errorMessage Error message if validation fails
     * @return self
     */
    public function custom(string $field, callable $callback, string $errorMessage): self
    {
        $this->customValidations[$field] = [
            'callback' => $callback,
            'message' => $errorMessage
        ];
        return $this;
    }

    /**
     * Set an authorization check (runs before validation)
     * 
     * @param callable $callback Authorization check (returns bool)
     * @return self
     */
    public function authorize(callable $callback): self
    {
        $this->authorizationCheck = $callback;
        return $this;
    }

    /**
     * Run validation and return result array (no exception)
     * 
     * @return array ['valid' => bool, 'data' => array|null, 'errors' => array, 'message' => string]
     */
    public function result(): array
    {
        // Step 1: Authorization check
        if ($this->authorizationCheck !== null) {
            try {
                if (!($this->authorizationCheck)()) {
                    return [
                        'valid' => false,
                        'data' => null,
                        'errors' => ['authorization' => 'درخواست غیرمجاز'],
                        'message' => 'شما دسترسی لازم برای این عملیات را ندارید'
                    ];
                }
            } catch (\Throwable $e) {
                return [
                    'valid' => false,
                    'data' => null,
                    'errors' => ['authorization' => $e->getMessage()],
                    'message' => 'خطا در بررسی دسترسی: ' . $e->getMessage()
                ];
            }
        }

        // Step 2: Run standard rules validation if rules are set
        if (!empty($this->rules)) {
            try {
                $this->validate($this->rules);
            } catch (\InvalidArgumentException $e) {
                return [
                    'valid' => false,
                    'data' => null,
                    'errors' => [],
                    'message' => $e->getMessage()
                ];
            }
        }

        // If standard validation failed, return errors
        if (!empty($this->errors)) {
            return [
                'valid' => false,
                'data' => null,
                'errors' => $this->errors,
                'message' => 'اطلاعات ورودی نامعتبر است'
            ];
        }

        // Step 3: Run custom validations
        if (!empty($this->customValidations)) {
            foreach ($this->customValidations as $field => $validation) {
                try {
                    $value = $this->data[$field] ?? null;
                    if (!($validation['callback'])($value)) {
                        $this->addError($field, $validation['message']);
                    }
                } catch (\Throwable $e) {
                    $this->addError($field, 'خطا در بررسی: ' . $e->getMessage());
                }
            }

            if (!empty($this->errors)) {
                return [
                    'valid' => false,
                    'data' => null,
                    'errors' => $this->errors,
                    'message' => 'اطلاعات ورودی نامعتبر است'
                ];
            }
        }

        return [
            'valid' => true,
            'data' => $this->data,
            'errors' => [],
            'message' => ''
        ];
    }

    /**
     * Validate and throw ValidationException on failure
     *
     * @return array Validated data
     * @throws \Core\Exceptions\ValidationException If validation fails
     */
    public function validateOrFail(): array
    {
        $result = $this->result();
        if (!$result['valid']) {
            throw new \Core\Exceptions\ValidationException(
                $result['errors'],
                $result['message'] ?: 'اطلاعات ورودی نامعتبر است'
            );
        }
        return $result['data'];
    }

    /**
     * Check if validation passed (alias for !fails())
     * 
     * @return bool
     */
    public function passes(): bool
    {
        return !$this->fails();
    }

    /**
     * Static factory for fluent interface
     * 
     * @param array $data Input data
     * @param array $rules Validation rules
     * @return self
     */
    public static function create(array $data, array $rules = []): self
    {
        return new self($data, $rules);
    }
}