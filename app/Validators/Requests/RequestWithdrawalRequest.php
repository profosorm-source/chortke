<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;
use App\Models\InvestmentWithdrawal;

class RequestWithdrawalRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $profitOnly = InvestmentWithdrawal::TYPE_PROFIT_ONLY;
        $full = InvestmentWithdrawal::TYPE_FULL;

        return [
            'withdrawal_type' => "nullable|in:{$profitOnly},{$full}",
        ];
    }

    public function messages(): array
    {
        return [
            'withdrawal_type.in' => 'نوع برداشت نامعتبر است.',
        ];
    }
}
