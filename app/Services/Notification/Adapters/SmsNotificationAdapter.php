<?php

namespace App\Services\Notification\Adapters;

use Core\Logger;

/**
 * SmsNotificationAdapter — ارسال پیامک برای نوتیفیکیشن‌های فوری
 *
 * ─── وضعیت فعلی ────────────────────────────────────────────────────────────
 *  آماده‌سازی برای اتصال به پنل پیامکی — پنل هنوز انتخاب نشده.
 *  برای فعال‌سازی، متد sendViaSmsProvider() را با SDK پنل موردنظر پر کنید.
 *
 * ─── تنظیمات .env مورد نیاز (بعد از اتصال) ──────────────────────────────
 *  SMS_PROVIDER=kavenegar        # kavenegar | melipayamak | idehpayam
 *  SMS_API_KEY=your-api-key
 *  SMS_FROM=1000...              # شماره فرستنده
 *  SMS_ENABLED=false
 */
class SmsNotificationAdapter
{
    private Logger $logger;
    private bool   $enabled;
    private string $provider;
    private string $apiKey;
    private string $from;

    public function __construct(Logger $logger)
    {
        $this->logger   = $logger;
        $this->enabled  = (bool)config('services.sms.enabled', false);
        $this->provider = config('services.sms.provider', '');
        $this->apiKey   = config('services.sms.api_key', '');
        $this->from     = config('services.sms.from', '');
    }

    /**
     * ارسال پیامک
     *
     * @param  string $mobile  شماره موبایل (مثلاً 09121234567)
     * @param  string $message متن پیامک
     * @return bool
     */
    public function send(string $mobile, string $message): bool
    {
        if (!$this->enabled) {
            $this->logger->info('sms.disabled', ['mobile' => $this->maskMobile($mobile)]);
            return false;
        }

        if (!$this->isValidMobile($mobile)) {
            $this->logger->warning('sms.invalid_mobile', ['mobile' => $this->maskMobile($mobile)]);
            return false;
        }

        try {
            $result = $this->sendViaSmsProvider($mobile, $message);

            $this->logger->info('sms.sent', [
                'mobile'   => $this->maskMobile($mobile),
                'provider' => $this->provider,
                'success'  => $result,
            ]);

            return $result;

        } catch (\Throwable $e) {
            $this->logger->error('sms.send_failed', [
                'mobile' => $this->maskMobile($mobile),
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * پیامک هشدار امنیتی (OTP / login alert)
     */
    public function sendSecurityAlert(string $mobile, string $message): bool
    {
        return $this->send($mobile, "هشدار امنیتی چرتکه:\n{$message}");
    }

    /**
     * پیامک تأیید برداشت
     */
    public function sendWithdrawalAlert(string $mobile, float $amount, string $currency): bool
    {
        $msg = "برداشت {$amount} {$currency} از حساب چرتکه شما پردازش شد.";
        return $this->send($mobile, $msg);
    }

    /**
     * بررسی فعال بودن سرویس
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey) && !empty($this->provider);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal — اتصال به پنل (پر کردن این متد بعد از انتخاب پنل)
    // ─────────────────────────────────────────────────────────────────────────

    private function sendViaSmsProvider(string $mobile, string $message): bool
    {
        return match ($this->provider) {
            'kavenegar'   => $this->sendKavenegar($mobile, $message),
            'melipayamak' => $this->sendMelipayamak($mobile, $message),
            'idehpayam'   => $this->sendIdehpayam($mobile, $message),
            default       => false,
        };
    }

    private function sendKavenegar(string $mobile, string $message): bool
    {
        // TODO: پر شود با SDK یا API کاوه‌نگار
        // https://api.kavenegar.com/v1/{API_KEY}/sms/send.json
        return false;
    }

    private function sendMelipayamak(string $mobile, string $message): bool
    {
        // TODO: پر شود با API ملی‌پیامک
        return false;
    }

    private function sendIdehpayam(string $mobile, string $message): bool
    {
        // TODO: پر شود با API ایده‌پیام
        return false;
    }

    private function isValidMobile(string $mobile): bool
    {
        return (bool)preg_match('/^09[0-9]{9}$/', $mobile);
    }

    private function maskMobile(string $mobile): string
    {
        return substr($mobile, 0, 4) . '****' . substr($mobile, -3);
    }
}
