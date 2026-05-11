<?php

/**
 * توابع کمکی ظاهر سایت و سئو
 */

if (!function_exists('site_logo')) {
    function site_logo(string $type = 'main'): ?string
    {
        $key = $type === 'main' ? 'site_logo' : 'site_logo_' . $type;
        $path = setting($key);
        return $path ? url($path) : null;
    }
}

if (!function_exists('site_favicon')) {
    function site_favicon(string $type = 'default'): ?string
    {
        $key = $type === 'default' ? 'site_favicon' : 'site_favicon_' . $type;
        $path = setting($key);
        return $path ? url($path) : null;
    }
}

if (!function_exists('site_og_image')) {
    function site_og_image(): ?string
    {
        $path = setting('site_og_image');
        return $path ? url($path) : null;
    }
}

if (!function_exists('render_site_favicons')) {
    function render_site_favicons(): string
    {
        $html = '';
        if ($favicon = site_favicon()) {
            $html .= '<link rel="icon" type="image/x-icon" href="' . e($favicon) . '">' . "\n";
            $html .= '<link rel="icon" type="image/png" href="' . e($favicon) . '">' . "\n";
        }
        if ($appleFavicon = site_favicon('apple')) {
            $html .= '<link rel="apple-touch-icon" href="' . e($appleFavicon) . '">' . "\n";
        }
        return $html;
    }
}

if (!function_exists('render_site_og_tags')) {
    function render_site_og_tags(?string $title = null, ?string $description = null, ?string $customImage = null): string
    {
        $html = '';
        $ogTitle = $title ?? setting('site_name', 'وب‌سایت');
        $html .= '<meta property="og:title" content="' . e($ogTitle) . '">' . "\n";
        if ($description) $html .= '<meta property="og:description" content="' . e($description) . '">' . "\n";
        $image = $customImage ?: site_og_image();
        if ($image) $html .= '<meta property="og:image" content="' . e($image) . '">' . "\n";
        $html .= '<meta property="og:type" content="website">' . "\n";
        
        $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") 
                    . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
        $html .= '<meta property="og:url" content="' . e($currentUrl) . '">' . "\n";
        
        $html .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
        $html .= '<meta name="twitter:title" content="' . e($ogTitle) . '">' . "\n";
        if ($description) $html .= '<meta name="twitter:description" content="' . e($description) . '">' . "\n";
        if ($image) $html .= '<meta name="twitter:image" content="' . e($image) . '">' . "\n";
        
        return $html;
    }
}
