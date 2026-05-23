<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CreateBannerRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|min:3|max:255',
            'placement' => 'required|string',
            'link' => 'nullable|url',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان بنر الزامی است.',
            'title.min' => 'عنوان بنر باید حداقل ۳ کاراکتر باشد.',
            'placement.required' => 'جایگاه بنر الزامی است.',
            'link.url' => 'لینک بنر نامعتبر است.',
        ];
    }
}
