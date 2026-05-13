<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

/**
 * فرم درخواست برای ارسال محتوا
 * 
 * استفاده در User/ContentController::store()
 */
class ContentSubmissionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'platform' => 'required|in:aparat,youtube',
            'video_url' => 'required|url|max:500',
            'title' => 'required|min:5|max:255',
            'description' => 'max:2000',
            'category' => 'max:100',
            'agreement_accepted' => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'platform.required' => 'انتخاب پلتفرم الزامی است.',
            'platform.in' => 'پلتفرم انتخابی نامعتبر است.',
            'video_url.required' => 'لینک ویدیو الزامی است.',
            'video_url.url' => 'فرمت لینک ویدیو نامعتبر است.',
            'video_url.max' => 'لینک ویدیو بیش از حد طولانی است.',
            'title.required' => 'عنوان ویدیو الزامی است.',
            'title.min' => 'عنوان باید حداقل 5 کاراکتر باشد.',
            'title.max' => 'عنوان نباید بیشتر از 255 کاراکتر باشد.',
            'description.max' => 'توضیحات نباید بیشتر از 2000 کاراکتر باشد.',
            'agreement_accepted.required' => 'پذیرش تعهدنامه الزامی است.',
        ];
    }

    public function authorize(): bool
    {
        // همه کاربران احراز هویت شده می‌توانند محتوا ارسال کنند
        return true;
    }
}
