<?php

namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════
 *  Maintenance Mode - حالت تعمیر و نگهداری
 * ═══════════════════════════════════════════════════════════════
 */
class MaintenanceMode
{
    private string $flagFile;

    public function __construct()
    {
        $this->flagFile = config('paths.storage') . '/maintenance.flag';
    }

    /**
     * فعال‌سازی
     */
    public function enable(string $message = null, array $allowedIPs = []): void
    {
        $data = [
            'enabled_at' => date('Y-m-d H:i:s'),
            'message' => $message ?? 'سایت در دست تعمیر است. لطفاً بعداً مراجعه کنید.',
            'allowed_ips' => $allowedIPs,
        ];

        file_put_contents($this->flagFile, json_encode($data));

        $this->logger->warning('Maintenance mode enabled');
    }

    /**
     * غیرفعال‌سازی
     */
    public function disable(): void
    {
        if (file_exists($this->flagFile)) {
            unlink($this->flagFile);
            $this->logger->info('Maintenance mode disabled');
        }
    }

    /**
     * بررسی فعال بودن
     */
    public function isEnabled(): bool
    {
        return file_exists($this->flagFile);
    }

    /**
     * دریافت پیام
     */
    public function getMessage(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $data = json_decode(file_get_contents($this->flagFile), true);
        return $data['message'] ?? 'در دست تعمیر';
    }

    /**
     * بررسی IP مجاز
     */
    public function isAllowedIP(string $ip): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $data = json_decode(file_get_contents($this->flagFile), true);
        $allowedIPs = $data['allowed_ips'] ?? [];

        return in_array($ip, $allowedIPs);
    }

    /**
     * بررسی و نمایش صفحه Maintenance
     */
    public function check(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $currentIP = get_real_ip();

        if ($this->isAllowedIP($currentIP)) {
            return;
        }

        // نمایش صفحه Maintenance
        http_response_code(503);
        
        if (file_exists(config('paths.views') . '/maintenance.php')) {
            require config('paths.views') . '/maintenance.php';
        } else {
            $this->renderDefaultPage();
        }

        exit;
    }

    /**
     * صفحه پیش‌فرض
     */
    private function renderDefaultPage(): void
    {
        $message = $this->getMessage();

        echo <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>در دست تعمیر</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: #333;
        }
        .container {
            background: white;
            padding: 60px 50px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
            max-width: 600px;
        }
        h1 {
            font-size: 48px;
            color: #667eea;
            margin-bottom: 20px;
        }
        p {
            color: #777;
            line-height: 1.8;
            font-size: 18px;
        }
        .icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔧</div>
        <h1>در دست تعمیر</h1>
        <p>{$message}</p>
    </div>
</body>
</html>
HTML;
    }
}