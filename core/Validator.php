<?php
declare(strict_types=1);

namespace Core;

class Validator
{
    private array $data = [];
    private array $rules = [];
    private array $errors = [];

    public function __construct(array $data, array $rules = [])
    {
        $this->data = $data;
        $this->rules = [];

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

    // اگر هنوز rules نداریم، به جای Fatal Error، خطای validator ثبت کن
    if (empty($this->rules)) {
        $this->addError('__validator', 'قوانین اعتبارسنجی ارسال نشده است.');
        return;
    }

    foreach ($this->rules as $field => $ruleString) {
        $fieldRules = \explode('|', (string)$ruleString);
        $value = $this->data[$field] ?? null;

        foreach ($fieldRules as $rule) {
            $this->applyRule($field, $value, $rule);
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
                    $min = (int)$param;
                    if (\mb_strlen((string)$value) < $min) {
                        $this->addError($field, "حداقل {$min} کاراکتر مجاز است");
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
                if ($value !== null && $value !== '' && !\preg_match('/^[\u0600-\u06FF\s]+$/u', (string)$value)) {
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

    private function isUnique(?string $param, mixed $value, string $field): bool
    {
        if ($param === null) return true;

        try {
            [$table, $column] = \explode('.', $param, 2);
            $db = Database::getInstance();
            $result = $db->query(
                "SELECT COUNT(*) as count FROM `{$table}` WHERE `{$column}` = ?",
                [$value]
            )->fetch();

            return ($result->count ?? 0) === 0;
        } catch (\Throwable $e) {
            return true; // Assume valid if DB check fails
        }
    }

    private function valueExists(?string $param, mixed $value): bool
    {
        if ($param === null) return true;

        try {
            [$table, $column] = \explode('.', $param, 2);
            $db = Database::getInstance();
            $result = $db->query(
                "SELECT COUNT(*) as count FROM `{$table}` WHERE `{$column}` = ?",
                [$value]
            )->fetch();

            return ($result->count ?? 0) > 0;
        } catch (\Throwable $e) {
            return false; // Assume invalid if DB check fails
        }
    }

    private function addError(string $field, string $message): void
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
}