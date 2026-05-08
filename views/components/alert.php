<?php
/**
 * Alert Component
 * 
 * استفاده:
 * include __DIR__ . '/../components/alert.php';
 * renderAlert('success', 'عملیات موفق بود');
 */

if (!function_exists('renderAlert')) {
    function renderAlert($type = 'info', $message = '', $dismissible = true)
    {
        $icons = [
            'success' => 'check_circle',
            'danger' => 'error',
            'warning' => 'warning',
            'info' => 'info',
        ];
        
        $icon = $icons[$type] ?? 'info';
        $dismissBtn = $dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' : '';
        
        echo '<div class="alert alert-' . e($type) . ' alert-dismissible fade show" role="alert">';
        echo '<i class="material-icons-outlined">' . $icon . '</i> ';
        echo e($message);
        echo e($dismissBtn);
        echo '</div>';
    }
}

// استفاده خودکار از Flash Messages
if (get_flash('success')) {
    renderAlert('success', get_flash('success'));
}

if (get_flash('error')) {
    renderAlert('danger', get_flash('error'));
}

if (get_flash('warning')) {
    renderAlert('warning', get_flash('warning'));
}

if (get_flash('info')) {
    renderAlert('info', get_flash('info'));
}

if (get_flash('errors')) {
    $errors = get_flash('errors');
    foreach ($errors as $field => $messages) {
        foreach ($messages as $message) {
            renderAlert('danger', $message);
        }
    }
}
?>