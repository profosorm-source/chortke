<?php

if (!function_exists('view')) {
    function view($viewName, $data = [])
    {
        $session = \Core\Session::getInstance();

        $globals = [];

        $globals['currentUser'] = auth();
        $globals['isLoggedIn'] = $globals['currentUser'] !== null;

        $globals['flashSuccess'] = $session->getFlash('success');
        $globals['flashError']   = $session->getFlash('error');
        $globals['flashWarning'] = $session->getFlash('warning');
        $globals['errors']       = $session->getFlash('errors') ?? [];
        $globals['old']          = $session->getFlash('old')    ?? [];

        $globals['showResendVerification'] = $session->getFlash('show_resend_verification') ?? false;
        $globals['resendEmail']            = $session->getFlash('resend_email') ?? '';

        $data = array_merge($globals, (array)$data);

        extract($data);

        $viewPath = __DIR__ . '/../views/' . str_replace('.', '/', $viewName) . '.php';

        if (!file_exists($viewPath)) {
            throw new \Exception("View not found: {$viewName}");
        }

        require $viewPath;
    }
}

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function old(string $key, $default = ''): string
{
    $old = app()->session->getFlash('old');
    
    if ($old === null) {
        return e($default);
    }
    
    if (!is_array($old)) {
        return e($default);
    }
    
    return e($old[$key] ?? $default);
}

function error(string $field): ?string
{
    $errors = app()->session->getFlash('errors');
    
    if ($errors === null || !is_array($errors)) {
        return null;
    }
    
    return $errors[$field] ?? null;
}

function flash(string $key): ?string
{
    $value = app()->session->getFlash($key);
    return $value;
}

if (!function_exists('get_flash')) {
    function get_flash($key, $default = null)
    {
        return app()->session->getFlash($key, $default);
    }
}


