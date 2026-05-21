<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class ContactMessageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name'              => 'required|string|min:3|max:100',
            'email'             => 'required|email',
            'subject'           => 'required|string|min:5|max:200',
            'message'           => 'required|string|min:10|max:5000',
            'captcha_token'     => 'required|string|min:1',
            'captcha_response'  => 'required|string|min:1',
            'challenge_answer'  => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'             => 'نام الزامی است',
            'name.min'                  => 'نام باید حداقل ۳ کاراکتر باشد',
            'email.required'            => 'ایمیل الزامی است',
            'email.email'               => 'ایمیل معتبر نیست',
            'subject.required'          => 'موضوع الزامی است',
            'subject.min'               => 'موضوع باید حداقل ۵ کاراکتر باشد',
            'message.required'          => 'متن پیام الزامی است',
            'message.min'               => 'متن پیام باید حداقل ۱۰ کاراکتر باشد',
            'message.max'               => 'متن پیام نمی‌تواند بیش از ۵۰۰۰ کاراکتر باشد',
            'captcha_token.required'    => 'توکن کپچا الزامی است',
            'captcha_response.required' => 'پاسخ کپچا الزامی است',
        ];
    }
}
