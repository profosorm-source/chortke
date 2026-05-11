<?php

if (!function_exists('banner_type_label')) {
    function banner_type_label(string $type): string
    {
        $labels = [
            'system' => 'سیستمی',
            'startup' => 'استارتاپی',
            'user' => 'کاربری',
            'promo' => 'تبلیغاتی',
        ];
        return $labels[$type] ?? $type;
    }
}

if (!function_exists('banner_status_badge')) {
    function banner_status_badge($banner): string
    {
        if ($banner->is_active) {
            return '<span class="badge badge-success">فعال</span>';
        }
        if (in_array($banner->banner_type, ['user', 'startup']) && !$banner->approved_at) {
            return '<span class="badge badge-warning">در انتظار تایید</span>';
        }
        if ($banner->rejection_reason) {
            return '<span class="badge badge-danger">رد شده</span>';
        }
        if ($banner->end_date && strtotime($banner->end_date) < time()) {
            return '<span class="badge badge-secondary">منقضی</span>';
        }
        return '<span class="badge badge-secondary">غیرفعال</span>';
    }
}
