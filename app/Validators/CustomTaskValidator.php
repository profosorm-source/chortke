<?php

namespace App\Validators;

class CustomTaskValidator
{
    public static function validateCreate(array $data): array
    {
        $errors = [];

        // عنوان
        if (empty(trim((string)($data['title'] ?? '')))) {
            $errors['title'][] = 'عنوان الزامی است.';
        } elseif (mb_strlen(trim($data['title'])) < 5) {
            $errors['title'][] = 'عنوان باید حداقل 5 کاراکتر باشد.';
        } elseif (mb_strlen(trim($data['title'])) > 200) {
            $errors['title'][] = 'عنوان نباید بیشتر از 200 کاراکتر باشد.';
        }

        // توضیحات
        if (empty(trim((string)($data['description'] ?? '')))) {
            $errors['description'][] = 'توضیحات الزامی است.';
        } elseif (mb_strlen(trim($data['description'])) < 20) {
            $errors['description'][] = 'توضیحات باید حداقل 20 کاراکتر باشد.';
        } elseif (mb_strlen(trim($data['description'])) > 2000) {
            $errors['description'][] = 'توضیحات نباید بیشتر از 2000 کاراکتر باشد.';
        }

        // لینک (اختیاری)
        if (!empty($data['link']) && !filter_var($data['link'], FILTER_VALIDATE_URL)) {
            $errors['link'][] = 'لینک نامعتبر است.';
        }

        // قیمت
        $price = (float)($data['price_per_task'] ?? 0);
        if ($price <= 0) {
            $errors['price_per_task'][] = 'مبلغ هر تسک باید بیشتر از صفر باشد.';
        }

        $currency = $data['currency'] ?? 'irt';
        if (!in_array($currency, ['irt', 'usdt'])) {
            $errors['currency'][] = 'واحد پولی نامعتبر است.';
        }

        // بررسی حداقل قیمت
        $minPrice = $currency === 'usdt'
            ? (float) setting('custom_task_min_price_usdt', 0.50)
            : (float) setting('custom_task_min_price_irt', 5000);

        if ($price < $minPrice) {
            $label = $currency === 'usdt' 
                ? number_format($minPrice, 2) . ' USDT' 
                : number_format($minPrice) . ' تومان';
            $errors['price_per_task'][] = "حداقل قیمت هر تسک {$label} است.";
        }

        // تعداد
        $qty = (int)($data['total_quantity'] ?? 0);
        if ($qty <= 0) {
            $errors['total_quantity'][] = 'تعداد باید بیشتر از صفر باشد.';
        } elseif ($qty > 10000) {
            $errors['total_quantity'][] = 'حداکثر تعداد مجاز 10000 عدد است.';
        }

        // نوع تسک
        $validTaskTypes = ['signup', 'install', 'review', 'vote', 'follow', 'join', 'custom'];
        if (!empty($data['task_type']) && !in_array($data['task_type'], $validTaskTypes)) {
            $errors['task_type'][] = 'نوع وظیفه نامعتبر است.';
        }

        // نوع مدرک
        $validProofTypes = ['screenshot', 'text', 'video', 'code', 'file'];
        if (!empty($data['proof_type']) && !in_array($data['proof_type'], $validProofTypes)) {
            $errors['proof_type'][] = 'نوع مدرک نامعتبر است.';
        }

        // مهلت
        $deadline = (int)($data['deadline_hours'] ?? 24);
        if ($deadline < 1 || $deadline > 168) {
            $errors['deadline_hours'][] = 'مهلت باید بین 1 تا 168 ساعت باشد.';
        }

        // محدودیت دستگاه
        if (!empty($data['device_restriction']) && 
            !in_array($data['device_restriction'], ['all', 'mobile', 'desktop'])) {
            $errors['device_restriction'][] = 'محدودیت دستگاه نامعتبر است.';
        }

        // سقف روزانه
        $dailyLimit = (int)($data['daily_limit_per_user'] ?? 1);
        if ($dailyLimit < 1 || $dailyLimit > 50) {
            $errors['daily_limit_per_user'][] = 'سقف روزانه باید بین 1 تا 50 باشد.';
        }

        return $errors;
    }

    public static function validateProof(array $data, string $proofType): array
    {
        $errors = [];

        switch ($proofType) {
            case 'screenshot':
            case 'file':
            case 'video':
                if (empty($data['proof_file'])) {
                    $errors['proof_file'][] = 'آپلود فایل الزامی است.';
                }
                break;

            case 'text':
            case 'code':
                if (empty(trim((string)($data['proof_text'] ?? '')))) {
                    $errors['proof_text'][] = 'ارسال متن الزامی است.';
                } elseif (mb_strlen(trim($data['proof_text'])) < 10) {
                    $errors['proof_text'][] = 'متن باید حداقل 10 کاراکتر باشد.';
                }
                break;
        }

        // بررسی کلی
        if (empty($data['proof_text']) && empty($data['proof_file'])) {
            $errors['proof'][] = 'ارسال حداقل یک مدرک الزامی است.';
        }

        return $errors;
    }

    public static function validateDispute(array $data): array
    {
        $errors = [];

        if (empty(trim((string)($data['reason'] ?? '')))) {
            $errors['reason'][] = 'دلیل اختلاف الزامی است.';
        } elseif (mb_strlen(trim($data['reason'])) < 20) {
            $errors['reason'][] = 'دلیل اختلاف باید حداقل 20 کاراکتر باشد.';
        } elseif (mb_strlen(trim($data['reason'])) > 1000) {
            $errors['reason'][] = 'دلیل اختلاف نباید بیشتر از 1000 کاراکتر باشد.';
        }

        return $errors;
    }

    public static function validateReview(array $data): array
    {
        $errors = [];

        if (empty($data['decision'])) {
            $errors['decision'][] = 'تصمیم الزامی است.';
        } elseif (!in_array($data['decision'], ['approve', 'reject'])) {
            $errors['decision'][] = 'تصمیم نامعتبر است.';
        }

        if ($data['decision'] === 'reject' && empty(trim((string)($data['reason'] ?? '')))) {
            $errors['reason'][] = 'دلیل رد الزامی است.';
        }

        return $errors;
    }

    public static function validateRating(array $data): array
    {
        $errors = [];

        // امتیاز
        if (!isset($data['rating'])) {
            $errors['rating'][] = 'امتیاز الزامی است.';
        } elseif (!is_numeric($data['rating']) || $data['rating'] < 1 || $data['rating'] > 5) {
            $errors['rating'][] = 'امتیاز باید عددی بین 1 تا 5 باشد.';
        }

        // متن نظر (اختیاری ولی اگر وارد شد باید حداقل طول داشته باشد)
        if (!empty($data['review_text'])) {
            $reviewText = trim($data['review_text']);
            $minLength = (int) setting('custom_task_min_rating_text_length', 20);
            
            if (mb_strlen($reviewText) < $minLength) {
                $errors['review_text'][] = "متن نظر باید حداقل {$minLength} کاراکتر باشد.";
            }
            
            if (mb_strlen($reviewText) > 1000) {
                $errors['review_text'][] = 'متن نظر نباید بیشتر از 1000 کاراکتر باشد.';
            }
        }

        // بررسی الزامی بودن نظر
        if (setting('custom_task_require_review', 0) && empty(trim($data['review_text'] ?? ''))) {
            $errors['review_text'][] = 'ثبت نظر الزامی است.';
        }

        return $errors;
    }
}
