<?php

declare(strict_types=1);

namespace App\Services\Notification\Adapters;

use App\Models\Notification;
use App\Models\SystemTelemetryModel;
use Core\Logger;

/**
 * LogNotificationService
 * 
 * سرویس ارسال نوتیفیکیشن‌ها و مدیریت هشدارها
 */
class LogNotificationAdapter
{
    public function __construct(
        private Notification $notification,
        private SystemTelemetryModel $telemetry,
        private Logger $logger
    ) {}

    /**
     * ارسال هشدار به تمام کانال‌های فعال
     */
    public function sendAlert(string $title, string $message, string $severity = 'medium'): void
    {
        $channels = $this->notification->getActiveChannelsBySeverity($severity);

        foreach ($channels as $channel) {
            try {
                $config = json_decode((string)$channel->config, true);
                
                $sent = match($channel->channel_type) {
                    'telegram' => $this->sendTelegram($config, $title, $message, $severity),
                    'email' => $this->sendEmail($config, $title, $message),
                    'sms' => $this->sendSMS($config, $title, $message),
                    'webhook' => $this->sendWebhook($config, $title, $message, $severity),
                    default => false
                };

                $this->notification->logHistory(
                    (int)$channel->id,
                    'alert',
                    $title,
                    $message,
                    $sent ? 'sent' : 'failed'
                );

            } catch (\Throwable $e) {
                $this->logger->error('log_notification.channel.send.failed', [
                    'channel' => 'notification',
                    'channel_id' => $channel->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * ارسال پیام تلگرام
     */
    private function sendTelegram(array $config, string $title, string $message, string $severity): bool
    {
        if (empty($config['bot_token']) || empty($config['chat_id'])) {
            return false;
        }

        $emoji = match($severity) {
            'low' => '🔵',
            'medium' => '🟡',
            'high' => '🟠',
            'critical' => '🔴',
            default => '⚪'
        };

        $text = "{$emoji} *{$title}*\n\n{$message}\n\n⏰ " . date('Y-m-d H:i:s');
        $url = "https://api.telegram.org/bot{$config['bot_token']}/sendMessage";
        
        $data = [
            'chat_id' => $config['chat_id'],
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } catch (\Throwable $e) {
            $this->logger->error('log_notification.telegram.send.failed', [
                'channel' => 'notification',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * ارسال ایمیل
     */
    private function sendEmail(array $config, string $title, string $message): bool
    {
        if (empty($config['email'])) {
            return false;
        }

        $subject = "🔔 {$title}";
        $body = "
        <html>
        <body style='font-family: Tahoma, Arial; direction: rtl;'>
            <h2 style='color: #d32f2f;'>{$title}</h2>
            <p>{$message}</p>
            <hr>
            <small>زمان: " . date('Y-m-d H:i:s') . "</small>
        </body>
        </html>
        ";

        $headers = [
            'From: System Alert <noreply@chortke.com>',
            'Content-Type: text/html; charset=UTF-8',
            'MIME-Version: 1.0'
        ];

        return mail((string)$config['email'], $subject, $body, implode("\r\n", $headers));
    }

    /**
     * ارسال SMS
     */
    private function sendSMS(array $config, string $title, string $message): bool
    {
        return false;
    }

    /**
     * ارسال به Webhook
     */
    private function sendWebhook(array $config, string $title, string $message, string $severity): bool
    {
        if (empty($config['url'])) {
            return false;
        }

        $payload = json_encode([
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'timestamp' => time()
        ]);

        try {
            $ch = curl_init((string)$config['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode >= 200 && $httpCode < 300;
        } catch (\Throwable $e) {
            $this->logger->error('log_notification.webhook.send.failed', [
                'channel' => 'notification',
                'error' => $e->getMessage(),
                ]);
            return false;
        }
    }

    /**
     * تست کانال نوتیفیکیشن
     */
    public function testChannel(int $channelId): array
    {
        $channel = $this->notification->getChannel($channelId);

        if (!$channel) {
            return ['success' => false, 'message' => 'کانال یافت نشد'];
        }

        $config = json_decode((string)$channel->config, true);
        
        $success = match($channel->channel_type) {
            'telegram' => $this->sendTelegram(
                $config, 
                'تست سیستم', 
                'این یک پیام تست است', 
                'low'
            ),
            'email' => $this->sendEmail($config, 'تست سیستم', 'این یک ایمیل تست است'),
            default => false
        };

        return [
            'success' => $success,
            'message' => $success ? 'پیام با موفقیت ارسال شد' : 'ارسال پیام ناموفق بود'
        ];
    }

    /**
     * بررسی و اجرای قوانین هشدار
     */
    public function checkAlertRules(): void
    {
        $rules = $this->telemetry->getActiveAlertRules();

        foreach ($rules as $rule) {
            try {
                $condition = json_decode((string)$rule->condition, true);
                $triggered = $this->evaluateRule($rule, $condition);

                if ($triggered) {
                    $lastTrigger = $rule->last_triggered_at ? strtotime((string)$rule->last_triggered_at) : 0;
                    
                    if (time() - $lastTrigger < 3600) {
                        continue;
                    }

                    $this->sendAlert(
                        (string)$rule->rule_name,
                        "قانون '{$rule->rule_name}' فعال شد",
                        (string)$rule->severity
                    );

                    $this->telemetry->updateRuleLastTriggered((int)$rule->id);
                }
            } catch (\Throwable $e) {
                $this->logger->error('log_notification.alert_rule.check.failed', [
                    'channel' => 'notification',
                    'rule_id' => $rule->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * ارزیابی قانون هشدار
     */
    private function evaluateRule(object $rule, array $condition): bool
    {
        $metric = $condition['metric'] ?? '';
        $operator = $condition['operator'] ?? '>';
        
        $value = match($metric) {
            'error_count' => $this->telemetry->getErrorCount((int)$rule->time_window),
            'critical_errors' => $this->telemetry->getCriticalErrorCount((int)$rule->time_window),
            'slow_requests' => $this->telemetry->getSlowRequestCount((int)$rule->time_window),
            'failed_login' => $this->telemetry->getFailedLoginCount((int)$rule->time_window),
            default => 0
        };

        return match($operator) {
            '>' => $value > (int)$rule->threshold,
            '>=' => $value >= (int)$rule->threshold,
            '<' => $value < (int)$rule->threshold,
            '<=' => $value <= (int)$rule->threshold,
            '==' => $value == (int)$rule->threshold,
            default => false
        };
    }
}
