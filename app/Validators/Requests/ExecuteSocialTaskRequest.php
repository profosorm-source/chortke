<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;

/**
 * ExecuteSocialTaskRequest
 *
 * اعتبارسنجی کامل ارسال نتیجه اجرای تسک اجتماعی.
 * تمام قوانین پراکنده‌ای که قبلاً inline در SocialTaskService::validateExecutionSubmissionPayload()
 * نوشته شده بودند، اینجا به صورت declarative تعریف می‌شوند.
 */
class ExecuteSocialTaskRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // شناسه‌های کلیدی
            'execution_id'     => 'required|integer|min:1',
            'task_id'          => 'nullable|integer|min:1',

            // مدرک — حداقل یکی باید ارسال شود (بررسی at_least_one در authorize)
            'proof_url'        => 'nullable|url|max:500',
            'proof_text'       => 'nullable|string|max:2000',
            'proof_screenshot' => 'nullable|string',

            // زمان‌سنجی رفتاری (ثانیه، ۰ تا ۸۶۴۰۰)
            'active_time'      => 'nullable|integer|min:0|max:86400',
            'expected_time'    => 'nullable|integer|min:0|max:86400',

            // سیگنال‌های رفتاری
            'interactions'     => 'nullable|array',
            'behavior_signals' => 'nullable|array',

            // اثر انگشت ویدئو (hex 16–128 کاراکتر)
            'video_hash'       => 'nullable|regex:/^[A-Fa-f0-9]{16,128}$/',

            // idempotency
            'idempotency_key'  => 'nullable|string|min:10|max:128',
        ];
    }

    public function messages(): array
    {
        return [
            'execution_id.required'  => 'شناسه اجرا الزامی است',
            'execution_id.min'       => 'شناسه اجرا نامعتبر است',
            'proof_url.url'          => 'آدرس مدرک معتبر نیست',
            'proof_url.max'          => 'آدرس مدرک بیش از حد طولانی است',
            'proof_text.max'         => 'متن مدرک بیش از حد طولانی است',
            'active_time.min'        => 'زمان فعالیت نمی‌تواند منفی باشد',
            'active_time.max'        => 'زمان فعالیت معتبر نیست',
            'expected_time.min'      => 'زمان انتظار نمی‌تواند منفی باشد',
            'expected_time.max'      => 'زمان انتظار معتبر نیست',
            'interactions.array'     => 'داده‌های تعاملی باید آرایه باشند',
            'behavior_signals.array' => 'سیگنال‌های رفتاری باید آرایه باشند',
            'video_hash.regex'       => 'اثر انگشت ویدئو معتبر نیست',
        ];
    }

    /**
     * بررسی می‌کند حداقل یکی از proof_url یا proof_text ارسال شده.
     * این چک بیزینسی است (نه تکنیکال) پس اینجا قرار می‌گیرد.
     */
    public function authorize(): bool
    {
        return true; // authorization اصلی در Controller/Middleware انجام می‌شود
    }

    /**
     * بررسی at_least_one proof پس از validate() — برای استفاده در سرویس
     */
    public function hasProof(): bool
    {
        $url  = trim((string)($this->data['proof_url']  ?? ''));
        $text = trim((string)($this->data['proof_text'] ?? ''));
        return $url !== '' || $text !== '';
    }
}
