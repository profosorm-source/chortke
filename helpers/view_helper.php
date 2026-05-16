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

        // HIGH-11 Fix: Inject CSP Nonce from the current request into all views
        try {
            $request = \Core\Container::getInstance()->make(\Core\Request::class);
            $globals['cspNonce'] = $request->nonce();
        } catch (\Throwable $e) {
            $globals['cspNonce'] = '';
        }

        $data = array_merge($globals, (array)$data);

        extract($data, EXTR_SKIP);

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
    $val = app()->session->getOld($key, $default);
    return e($val);
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


