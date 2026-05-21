<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;
use App\Services\SettingService;
use Core\Container;

/**
 * درخواست ثبت امتیاز و نظر برای تسک سفارشی
 */
class RateCustomTaskRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'execution_id' => 'required|integer|min:1',
            'rating'       => 'required|integer|min:1|max:5',
            'review_text'  => 'nullable|string|min:10|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'execution_id.required' => 'شناسه اجرای تسک الزامی است',
            'rating.required'       => 'امتیاز الزامی است',
            'rating.min'            => 'امتیاز حداقل ۱ است',
            'rating.max'            => 'امتیاز حداکثر ۵ است',
            'review_text.min'       => 'نظر باید حداقل ۱۰ کاراکتر باشد',
            'review_text.max'       => 'نظر نمی‌تواند بیشتر از ۱۰۰۰ کاراکتر باشد',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        $validated = $this->validated();
        $requireReview = false;

        try {
            $settings = Container::getInstance()->make(SettingService::class);
            $requireReview = (bool)$settings->get('custom_task_require_review', 0);
        } catch (\Throwable $e) {
            $requireReview = false;
        }

        if ($requireReview && empty(trim($validated['review_text'] ?? ''))) {
            $this->errors['review_text'][] = 'ثبت نظر الزامی است';
            return false;
        }

        return true;
    }
}
