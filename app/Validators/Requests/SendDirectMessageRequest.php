<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

class SendDirectMessageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'sender_id'    => 'required|integer|min:1',
            'recipient_id' => 'required|integer|min:1',
            'message'      => 'required|string|min:1|max:5000',
            'is_encrypted' => 'nullable|boolean',
            'attachments'  => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'sender_id.required'    => 'شناسه فرستنده الزامی است',
            'sender_id.integer'     => 'شناسه فرستنده نامعتبر است',
            'recipient_id.required' => 'شناسه گیرنده الزامی است',
            'recipient_id.integer'  => 'شناسه گیرنده نامعتبر است',
            'message.required'      => 'متن پیام الزامی است',
            'message.min'           => 'متن پیام نمی‌تواند خالی باشد',
            'message.max'           => 'متن پیام طولانی‌تر از حد مجاز است',
            'attachments.array'     => 'پیوست‌ها باید به صورت آرایه ارسال شوند',
            'is_encrypted.boolean'  => 'مقدار رمزنگاری پیام نامعتبر است',
        ];
    }
}
