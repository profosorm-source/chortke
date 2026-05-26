<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class CreateVitrineListingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'listing_type' => 'required|in:sell,buy',
            'category' => 'required|string',
            'platform' => 'nullable|string',
            'title' => 'required|string|min:5|max:200',
            'description' => 'required|string|min:20|max:5000',
            'price_usdt' => 'required|numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'listing_type.required' => 'نوع آگهی الزامی است.',
            'listing_type.in' => 'نوع آگهی نامعتبر است.',
            'category.required' => 'دسته‌بندی الزامی است.',
            'title.required' => 'عنوان آگهی الزامی است.',
            'title.min' => 'عنوان آگهی باید حداقل ۵ کاراکتر باشد.',
            'title.max' => 'عنوان آگهی باید حداکثر ۲۰۰ کاراکتر باشد.',
            'description.required' => 'توضیحات آگهی الزامی است.',
            'description.min' => 'توضیحات آگهی باید حداقل ۲۰ کاراکتر باشد.',
            'description.max' => 'توضیحات آگهی باید حداکثر ۵۰۰۰ کاراکتر باشد.',
            'price_usdt.required' => 'قیمت الزامی است.',
            'price_usdt.numeric' => 'قیمت باید عددی باشد.',
        ];
    }
}
