<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CreatePredictionGameRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'team_home' => 'required|string',
            'team_away' => 'required|string',
            'match_date' => 'required|date',
            'bet_deadline' => 'required|date',
            'min_bet_usdt' => 'required|numeric|min:0.01',
            'max_bet_usdt' => 'required|numeric',
            'commission_percent' => 'required|numeric|min:0|max:30',
            'sport_type' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان بازی الزامی است.',
            'team_home.required' => 'نام تیم خانه الزامی است.',
            'team_away.required' => 'نام تیم مهمان الزامی است.',
            'match_date.required' => 'تاریخ بازی الزامی است.',
            'bet_deadline.required' => 'ددلاین شرط‌بندی الزامی است.',
            'min_bet_usdt.required' => 'حداقل مبلغ شرط الزامی است.',
            'min_bet_usdt.min' => 'حداقل مبلغ شرط باید بیشتر از صفر باشد.',
            'max_bet_usdt.required' => 'حداکثر مبلغ شرط الزامی است.',
            'commission_percent.required' => 'درصد کمیسیون الزامی است.',
            'commission_percent.min' => 'درصد کمیسیون باید بین ۰ و ۳۰ باشد.',
            'commission_percent.max' => 'درصد کمیسیون باید بین ۰ و ۳۰ باشد.',
            'sport_type.required' => 'نوع ورزش الزامی است.',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        // Custom time validations
        $matchTime = strtotime((string)($this->data['match_date'] ?? ''));
        $deadlineTime = strtotime((string)($this->data['bet_deadline'] ?? ''));

        if ($deadlineTime >= $matchTime) {
            $this->errors['bet_deadline'] = ['ددلاین شرط‌بندی باید قبل از زمان بازی باشد.'];
            return false;
        }

        if ($deadlineTime <= time()) {
            $this->errors['bet_deadline'] = ['ددلاین شرط‌بندی باید در آینده باشد.'];
            return false;
        }

        $minBet = (float)($this->data['min_bet_usdt'] ?? 0);
        $maxBet = (float)($this->data['max_bet_usdt'] ?? 0);

        if ($maxBet < $minBet) {
            $this->errors['max_bet_usdt'] = ['حداکثر مبلغ شرط باید بیشتر از حداقل باشد.'];
            return false;
        }

        return true;
    }
}
