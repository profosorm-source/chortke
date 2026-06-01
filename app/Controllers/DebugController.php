<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class DebugController extends BaseController
{
    public function __construct(?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
    }

    public function router(): void
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $clientIp = function_exists('get_client_ip') ? get_client_ip() : $remoteAddr;
    $allowedProxies = (array)config('app.trusted_proxies', ['127.0.0.1', '::1']);

    if (!\in_array($remoteAddr, $allowedProxies, true) && $remoteAddr !== '127.0.0.1' && $remoteAddr !== '::1') {
        $this->response->html('Forbidden', 403);
        return;
    }

    // از fallback پیش‌فرض حذف شد تا ورودی واقعی بررسی شود
    $rawPath = (string)($_GET['path'] ?? '');

    if ($rawPath === '') {
        $this->response->html('<pre style="direction:ltr;text-align:left;">Missing "path" query param</pre>', 400);
        return;
    }

    // sanitize پایه
    $path = preg_replace('/[\x00-\x1f\x7f]/', '', $rawPath);
    $path = str_replace(['..', '\\', "\0"], '', $path);
    $path = '/' . ltrim($path, '/');

    // الگوی امن‌تر: folder + filename
    $safe = '#^/file/view/([a-zA-Z0-9_-]+)/([a-zA-Z0-9._-]+)$#';

    $m = [];
    $ok = \preg_match($safe, $path, $m) === 1;

    $out = "=== APP ROUTER DEBUG ===\n";
    $out .= "Remote addr: {$remoteAddr}\n";
    $out .= "Resolved client IP: {$clientIp}\n";
    $out .= "Input path: {$rawPath}\n";
    $out .= "Sanitized path: {$path}\n\n";
    $out .= "[SAFE] => " . ($ok ? '1' : '0') . "\n";
    $out .= "Matches: " . \json_encode($m, JSON_UNESCAPED_UNICODE) . "\n\n";

    // اسکن Router.php برای دیدن placeholder/preg_replace
    $routerFile = __DIR__ . '/../../core/Router.php';
    $out .= "Router.php: {$routerFile}\n";
    if (\file_exists($routerFile)) {
        $lines = \file($routerFile);
        $out .= "---- lines containing preg_replace/placeholder ----\n";
        foreach ($lines as $i => $line) {
            $l = \trim($line);
            if (\strpos($l, 'preg_replace') !== false && (\strpos($l, '{') !== false || \strpos($l, '\{') !== false)) {
                $out .= \str_pad((string)($i + 1), 5, ' ', STR_PAD_LEFT) . " | " . $l . "\n";
            }
        }
    } else {
        $out .= "Router.php NOT FOUND\n";
    }

    $this->response->html(
        '<pre style="direction:ltr;text-align:left;">' . \e($out, ENT_QUOTES, 'UTF-8') . '</pre>'
    );
}
}