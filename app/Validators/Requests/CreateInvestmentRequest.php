<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CreateInvestmentRequest extends BaseFormRequest
{
    private float $minAmount;
    private float $maxAmount;
    private string $minAmountFormatted;
    private string $maxAmountFormatted;

    public function __construct(array $data, float $minAmount, float $maxAmount, string $minAmountFormatted = '', string $maxAmountFormatted = '')
    {
        parent::__construct($data);
        $this->minAmount = $minAmount;
        $this->maxAmount = $maxAmount;
        $this->minAmountFormatted = $minAmountFormatted ?: (string)$minAmount;
        $this->maxAmountFormatted = $maxAmountFormatted ?: (string)$maxAmount;
    }

    public function rules(): array
    {
        return [
            'amount' => "required|numeric|min:{$this->minAmount}|max:{$this->maxAmount}",
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'مبلغ سرمایه‌گذاری الزامی است',
            'amount.numeric'  => 'مبلغ سرمایه‌گذاری نامعتبر است',
            'amount.min'      => "حداقل مبلغ سرمایه‌گذاری {$this->minAmountFormatted} است.",
            'amount.max'      => "حداکثر مبلغ سرمایه‌گذاری {$this->maxAmountFormatted} است.",
        ];
    }
}
