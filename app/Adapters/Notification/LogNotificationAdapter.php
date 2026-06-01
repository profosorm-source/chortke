<?php

declare(strict_types=1);

namespace App\Adapters\Notification;

use App\Models\Notification;
use App\Models\SystemTelemetryModel;
use App\Traits\ExternalCallTrait;
use Core\CircuitBreaker;
use Core\Logger;

/**
 * LogNotificationService
 *
 * سرویس ارسال نوتیفیکیشن‌ها و مدیریت هشدارها
 *
 * Section 8.3/8.4 — فراخوانی‌های HTTP خارجی (Telegram, Webhook) از طریق
 * ExternalCallTrait درون Core\CircuitBreaker اجرا می‌شوند تا در صورت
 * cascading failure مسیر hot به‌سرعت fail-fast شود.
 */
class LogNotificationAdapter
{
    use ExternalCallTrait;

    /**
     * @internal exposed for ExternalCallTrait::resolveCircuitBreaker()
     */
    protected CircuitBreaker $circuit;

    private Notification $notification;
    private SystemTelemetryModel $telemetry;
    private Logger $logger;
    public function __construct(
        Notification $notification,
        SystemTelemetryModel $telemetry,
        Logger $logger,
        CircuitBreaker $circuit
    ) {        $this->notification = $notification;
        $this->telemetry = $telemetry;
        $this->logger = $logger;

        $this->circuit = $circuit;
    }

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
     * ارسال پیام تلگرام (با CircuitBreaker + retry روی خطاهای transient)
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
            return (bool) $this->callWithBreaker('log_notif_telegram', function () use ($url, $data): bool {
                return $this->retryTransient(function () use ($url, $data): bool {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $data,
                        CURLOPT_TIMEOUT        => 10,
                        CURLOPT_CONNECTTIMEOUT => 5,
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $errno    = (int) curl_errno($ch);
                    curl_close($ch);

                    if ($httpCode === 200) {
                        return true;
                    }
                    throw $this->classifyHttpFailure($httpCode, $errno, (string)$response, ['provider' => 'log_notif_telegram']);
                });
            });
        } catch (\Core\Exceptions\PermanentFailure $e) {
            $this->logger->warning('log_notification.telegram.permanent_failure', [
                'channel' => 'notification',
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('log_notification.telegram.send.failed', [
                'channel' => 'notification',
                'class' => get_class($e),
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
     * ارسال به Webhook (با CircuitBreaker + retry روی خطاهای transient)
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

        // مشتق نام برای CB از host وب‌هوک تا breakerهای کانال‌های جدا مستقل باشند
        $host = parse_url((string)$config['url'], PHP_URL_HOST) ?: 'unknown';
        $providerName = 'log_notif_webhook_' . $host;

        try {
            return (bool) $this->callWithBreaker($providerName, function () use ($config, $payload): bool {
                return $this->retryTransient(function () use ($config, $payload): bool {
                    $ch = curl_init((string)$config['url']);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $payload,
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                        CURLOPT_TIMEOUT        => 10,
                        CURLOPT_CONNECTTIMEOUT => 5,
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $errno    = (int) curl_errno($ch);
                    curl_close($ch);

                    if ($httpCode >= 200 && $httpCode < 300) {
                        return true;
                    }
                    throw $this->classifyHttpFailure($httpCode, $errno, (string)$response, ['provider' => $providerName ?? 'log_notif_webhook']);
                });
            });
        } catch (\Core\Exceptions\PermanentFailure $e) {
            $this->logger->warning('log_notification.webhook.permanent_failure', [
                'channel' => 'notification',
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('log_notification.webhook.send.failed', [
                'channel' => 'notification',
                'class' => get_class($e),
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
