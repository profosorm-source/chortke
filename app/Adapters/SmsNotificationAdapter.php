<?php

namespace App\Adapters;

use Core\Logger;
use Core\CircuitBreaker;
use App\Models\User;
use App\Traits\ExternalCallTrait;

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
    use ExternalCallTrait;

    private User   $userModel;
    private Logger $logger;
    private ?CircuitBreaker $circuit;
    private bool   $enabled;
    private string $provider;
    private string $apiKey;
    private string $from;

    public function __construct(User $userModel, Logger $logger, ?CircuitBreaker $circuit = null)
    {
        $this->userModel = $userModel;
        $this->logger   = $logger;
        $this->circuit  = $circuit;
        $this->enabled  = (bool)config('services.sms.enabled', false);
        $this->provider = config('services.sms.provider', '');
        $this->apiKey   = config('services.sms.api_key', '');
        $this->from     = config('services.sms.from', '');
    }

    public function sendToUser(int $userId, string $message): bool
    {
        try {
            $user = $this->userModel->find($userId);
            if (!$user || empty($user->mobile)) {
                $this->logger->warning('sms.user_missing_mobile', ['user_id' => $userId]);
                return false;
            }
            
            return $this->send((string)$user->mobile, $message);
        } catch (\Throwable $e) {
            $this->logger->error('sms.send_to_user_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
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
            $providerName = 'sms_' . ($this->provider !== '' ? $this->provider : 'unknown');
            $result = (bool) $this->callWithBreaker($providerName, function () use ($mobile, $message): bool {
                return $this->sendViaSmsProvider($mobile, $message);
            });

            $this->logger->info('sms.sent', [
                'mobile'   => $this->maskMobile($mobile),
                'provider' => $this->provider,
                'success'  => $result,
            ]);

            return $result;

        } catch (\Core\Exceptions\PermanentFailure $e) {
            $this->logger->warning('sms.permanent_failure', [
                'mobile' => $this->maskMobile($mobile),
                'error'  => $e->getMessage(),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('sms.send_failed', [
                'mobile' => $this->maskMobile($mobile),
                'class'  => get_class($e),
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

    public function sendSecurityAlertToUser(int $userId, string $message): bool
    {
        try {
            $user = $this->userModel->find($userId);
            if (!$user || empty($user->mobile)) return false;
            return $this->sendSecurityAlert((string)$user->mobile, $message);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * پیامک تأیید برداشت
     */
    public function sendWithdrawalAlert(string $mobile, float $amount, string $currency): bool
    {
        $msg = "برداشت {$amount} {$currency} از حساب چرتکه شما پردازش شد.";
        return $this->send($mobile, $msg);
    }

    public function sendWithdrawalAlertToUser(int $userId, float $amount, string $currency): bool
    {
        try {
            $user = $this->userModel->find($userId);
            if (!$user || empty($user->mobile)) return false;
            return $this->sendWithdrawalAlert((string)$user->mobile, $amount, $currency);
        } catch (\Throwable $e) {
            return false;
        }
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
        if (empty($this->apiKey)) {
            $this->logger->error('sms.kavenegar.missing_apikey');
            return false;
        }

        $url = "https://api.kavenegar.com/v1/{$this->apiKey}/sms/send.json";
        $sender = !empty($this->from) ? $this->from : '10008663';
        
        $params = [
            'receptor' => $mobile,
            'sender'   => $sender,
            'message'  => $message
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $status = (int)($data['return']['status'] ?? 0);
            if ($status === 200) {
                return true;
            }
            $this->logger->error('sms.kavenegar.api_error', ['status' => $status, 'msg' => $data['return']['message'] ?? '']);
        } else {
            $this->logger->error('sms.kavenegar.http_error', ['http_code' => $httpCode, 'response' => $response]);
        }

        return false;
    }

    private function sendMelipayamak(string $mobile, string $message): bool
    {
        if (empty($this->apiKey)) {
            $this->logger->error('sms.melipayamak.missing_apikey');
            return false;
        }

        $url = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
        $username = config('services.sms.username', '');
        
        $params = [
            'username' => $username,
            'password' => $this->apiKey,
            'to'       => $mobile,
            'from'     => $this->from,
            'text'     => $message,
            'isFlash'  => false
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['RetStatus']) && (int)$data['RetStatus'] === 1) {
                return true;
            }
            $this->logger->error('sms.melipayamak.api_error', ['data' => $data]);
        } else {
            $this->logger->error('sms.melipayamak.http_error', ['http_code' => $httpCode, 'response' => $response]);
        }

        return false;
    }

    private function sendIdehpayam(string $mobile, string $message): bool
    {
        if (empty($this->apiKey)) {
            $this->logger->error('sms.idehpayam.missing_apikey');
            return false;
        }

        $url = 'https://panel.idehpayam.com/api/v1/sms/send/simple';
        $params = [
            'receptor' => $mobile,
            'sender'   => $this->from,
            'message'  => $message
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => [
                'ApiKey: ' . $this->apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['status']) && $data['status'] === 'success') {
                return true;
            }
            $this->logger->error('sms.idehpayam.api_error', ['data' => $data]);
        } else {
            $this->logger->error('sms.idehpayam.http_error', ['http_code' => $httpCode, 'response' => $response]);
        }

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


