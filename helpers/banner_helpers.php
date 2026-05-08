<?php

if (!function_exists('get_banners_for_placement')) {
    function get_banners_for_placement(string $placementSlug, int $limit = 5): array
    {
        static $cache = [];
        $key = "{$placementSlug}_{$limit}";
        
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        try {
            $banner = app(\App\Models\Banner::class);
            $banners = $banner->getActiveByPlacement($placementSlug, $limit);
            $cache[$key] = $banners;
            return $banners;
        } catch (\Exception $e) {
            return [];
        }
    }
}

if (!function_exists('render_banner_widget')) {
    function render_banner_widget(array $banners, string $placementSlug): void
    {
        if (empty($banners)) return;

        try {
            $placement = app(\App\Models\BannerPlacement::class)->findBySlug($placementSlug);

            if (!$placement || !$placement->is_active) return;

            $isMobile = is_mobile();
            if ($isMobile && !$placement->show_on_mobile) return;
            if (!$isMobile && !$placement->show_on_desktop) return;

            if ($placement->display_style === 'carousel' && count($banners) > 1) {
                include dirname(__DIR__) . '/views/partials/banners/carousel.php';
            } else {
                include dirname(__DIR__) . '/views/partials/banners/static.php';
            }

            foreach ($banners as $banner) {
                increment_banner_impression($banner->id);
            }
        } catch (\Exception $e) {
        }
    }
}

if (!function_exists('placement_is_active')) {
    function placement_is_active(string $placementSlug): bool
    {
        try {
            $placement = app(\App\Models\BannerPlacement::class)->findBySlug($placementSlug);
            return $placement && $placement->is_active;
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('increment_banner_impression')) {
    function increment_banner_impression(int $bannerId): void
    {
        $key = "banner_imp_{$bannerId}";
        $session = \Core\Session::getInstance();
        
        // استفاده از Session به جای $_SESSION
        if ($session->has($key)) {
            return;
        }

        try {
            app(\App\Models\Banner::class)->incrementImpression($bannerId);
            $session->set($key, true);
        } catch (\Exception $e) {
            // Log error
        }
    }
}

if (!function_exists('register_banner_click')) {
    function register_banner_click(int $bannerId): bool
    {
        try {
            $userId = function_exists('user_id') ? user_id() : null;
            
            // استفاده از get_client_ip به جای REMOTE_ADDR
            $ip = get_client_ip();
            
            return app(\App\Models\Banner::class)->registerClick($bannerId, $userId, $ip);
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('is_mobile')) {
    function is_mobile(): bool
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return preg_match('/(android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini)/i', $userAgent);
    }
}

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
