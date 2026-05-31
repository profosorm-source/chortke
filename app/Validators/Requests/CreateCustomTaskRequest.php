<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;
use App\Services\Settings\AppSettings;
use Core\Container;

/**
 * درخواست ایجاد تسک سفارشی
 *
 * لایه Input Validation برای CustomTask
 * مطابق الگوی استاندارد فاز ۱ و ۲ (Section 8.6)
 */
class CreateCustomTaskRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title'              => 'required|string|min:5|max:200',
            'description'        => 'required|string|min:20|max:2000',
            'price_per_task'     => 'required|numeric|min:1000',
            'total_quantity'     => 'required|integer|min:1|max:10000',
            'currency'           => 'required|in:IRT,USDT',
            'task_type'          => 'required|in:signup,install,review,vote,follow,join,custom',
            'proof_type'         => 'required|in:screenshot,text,video,code,file',
            'deadline_hours'     => 'required|integer|min:1|max:168',
            'daily_limit_per_user' => 'nullable|integer|min:1|max:50',
            'device_restriction' => 'nullable|in:all,mobile,desktop',
            'link'               => 'nullable|url',
            'idempotency_key'    => 'required|string|min:10|max:128',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'                => 'عنوان تسک الزامی است',
            'title.min'                     => 'عنوان باید حداقل ۵ کاراکتر باشد',
            'title.max'                     => 'عنوان نمی‌تواند بیشتر از ۲۰۰ کاراکتر باشد',
            'description.required'          => 'توضیحات تسک الزامی است',
            'description.min'               => 'توضیحات باید حداقل ۲۰ کاراکتر باشد',
            'description.max'               => 'توضیحات نمی‌تواند بیشتر از ۲۰۰۰ کاراکتر باشد',
            'price_per_task.required'       => 'قیمت هر اجرا الزامی است',
            'price_per_task.numeric'        => 'قیمت باید عدد باشد',
            'total_quantity.required'       => 'تعداد کل تسک الزامی است',
            'total_quantity.min'            => 'تعداد باید حداقل ۱ باشد',
            'total_quantity.max'            => 'حداکثر تعداد مجاز ۱۰۰۰۰ است',
            'currency.in'                   => 'ارز انتخاب شده معتبر نیست',
            'task_type.required'            => 'نوع تسک الزامی است',
            'proof_type.required'           => 'نوع مدرک الزامی است',
            'deadline_hours.required'       => 'مهلت انجام الزامی است',
            'deadline_hours.min'            => 'مهلت حداقل ۱ ساعت است',
            'deadline_hours.max'            => 'مهلت حداکثر ۷ روز (۱۶۸ ساعت) است',
            'idempotency_key.required'      => 'کلید یکتای درخواست الزامی است',
            'link.url'                      => 'لینک وارد شده معتبر نیست',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        $validated = $this->validated();
        $currency = strtoupper($validated['currency'] ?? 'IRT');
        $price = (float)($validated['price_per_task'] ?? 0);

        try {
            $settings = Container::getInstance()->make(AppSettings::class);
            $minKey = $currency === 'IRT' 
                ? 'custom_task_min_price_irt' 
                : 'custom_task_min_price_usdt';
            
            $minPrice = (float)$settings->get($minKey, $currency === 'IRT' ? 5000 : 0.5);

            if ($price < $minPrice) {
                $label = $currency === 'IRT' 
                    ? number_format($minPrice) . ' تومان' 
                    : number_format($minPrice, 2) . ' USDT';
                $this->errors['price_per_task'][] = "حداقل قیمت هر تسک {$label} است";
                return false;
            }
        } catch (\Throwable $e) {
            // fallback
            if ($price < 5000) {
                $this->errors['price_per_task'][] = 'حداقل قیمت هر تسک ۵۰۰۰ تومان است';
                return false;
            }
        }

        return true;
    }
}
