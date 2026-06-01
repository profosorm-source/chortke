<?php

declare(strict_types=1);

namespace App\Services\Sentry\Alerting;

use App\Events\AlertRequestedEvent;
use App\Models\SentryModel;
use Core\EventDispatcher;
use Core\Logger;

/**
 * 🚨 AlertDispatcher - سیستم ارسال هوشمند Alert
 */
class AlertDispatcher
{
    private array $throttleConfig = [
        'critical' => 60,
        'high' => 300,
        'medium' => 900,
        'low' => 3600,
    ];

    private SentryModel $model;
    private Logger $logger;
    private EventDispatcher $eventDispatcher;
    public function __construct(
        SentryModel $model,
        Logger $logger,
        EventDispatcher $eventDispatcher
    ) {        $this->model = $model;
        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;
}

    /**
     * 🤖 Process Rules - بررسی خودکار تمام قوانین هشدار
     */
    public function processRules(): int
    {
        $rules = $this->model->getActiveRules();
        $triggeredCount = 0;

        foreach ($rules as $rule) {
            try {
                $value = $this->model->getMetricValue($rule->rule_type, (int)($rule->time_window ?: 60));
                
                if ($value >= (float)$rule->threshold) {
                    $alert = [
                        'type' => 'automated_rule',
                        'severity' => $rule->severity,
                        'title' => "Rule: {$rule->rule_name}",
                        'message' => "Threshold reached: {$value} >= {$rule->threshold} (Window: {$rule->time_window} min)",
                        'metadata' => [
                            'rule_id' => $rule->id,
                            'metric' => $rule->rule_type,
                            'value' => $value,
                            'threshold' => $rule->threshold
                        ]
                    ];

                    if ($this->dispatch($alert)) {
                        $this->model->updateRuleLastTriggered((int)$rule->id);
                        $triggeredCount++;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('alert.rule_processing.failed', [
                    'rule_id' => $rule->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $triggeredCount;
    }

    /**
     * 📤 Dispatch Alert - ارسال alert
     */
    public function dispatch(array $alert): bool
    {
        $listeners = $this->eventDispatcher->getListeners('alert.requested');
        if (empty($listeners)) {
            $this->logger->warning('alert.no_listeners', ['alert' => $alert['title'] ?? 'unknown']);
            return false;
        }

        $event = new AlertRequestedEvent($alert);
        $this->eventDispatcher->dispatch('alert.requested', $event);
        return true;
    }

    public function handleAlertRequest(AlertRequestedEvent $event): bool
    {
        try {
            $alert = $this->normalizeAlert($event->alert);

            if ($this->isThrottled($alert)) {
                $this->logger->info('alert.throttled', ['alert' => $alert['title']]);
                return false;
            }

            $alertId = $this->storeAlert($alert);
            $channels = $this->model->getActiveChannels($alert['severity']);

            $sentCount = 0;
            foreach ($channels as $channel) {
                if ($this->sendToChannel($channel, $alert)) {
                    $sentCount++;
                    $this->model->recordNotificationHistory((int)$channel->id, $alertId, 'sent');
                } else {
                    $this->model->recordNotificationHistory((int)$channel->id, $alertId, 'failed');
                }
            }

            if ($sentCount > 0) {
                $this->model->markAlertAsSent($alertId);
            }

            return $sentCount > 0;
        } catch (\Throwable $e) {
            $this->logger->error('alert.dispatch.failed', [
                'channel' => 'alerting',
                'error' => $e->getMessage(),
                'alert' => $event->alert['title'] ?? 'unknown',
            ]);
            return false;
        }
    }

    private function normalizeAlert(array $alert): array
    {
        return array_merge([
            'type' => 'custom',
            'severity' => 'medium',
            'title' => 'Alert',
            'message' => '',
            'metadata' => [],
            'event_id' => null,
            'environment' => 'production',
        ], $alert);
    }

    private function isThrottled(array $alert): bool
    {
        $severity = $alert['severity'];
        $throttleSeconds = $this->throttleConfig[$severity] ?? 600;
        $fingerprint = $this->createAlertFingerprint($alert);

        $lastAlert = $this->model->getLastAlert($fingerprint, $severity);

        if (!$lastAlert) {
            return false;
        }

        $lastTime = strtotime((string)$lastAlert->created_at);
        $elapsed = time() - $lastTime;

        return $elapsed < $throttleSeconds;
    }

    private function createAlertFingerprint(array $alert): string
    {
        $components = [
            $alert['type'],
            $alert['title'],
            $alert['environment'] ?? 'production',
        ];

        return hash('sha256', implode('|', $components));
    }

    private function storeAlert(array $alert): int
    {
        return $this->model->storeAlert(array_merge($alert, [
            'fingerprint' => $this->createAlertFingerprint($alert)
        ]));
    }

    private function sendToChannel(object $channel, array $alert): bool
    {
        $config = json_decode((string)$channel->config, true) ?: [];

        return match($channel->channel_type) {
            'telegram' => $this->sendTelegram($config, $alert),
            'email' => $this->sendEmail($config, $alert),
            'slack' => $this->sendSlack($config, $alert),
            'webhook' => $this->sendWebhook($config, $alert),
            default => false,
        };
    }

    private function sendTelegram(array $config, array $alert): bool
    {
        if (!isset($config['bot_token']) || !isset($config['chat_id'])) {
            return false;
        }

        $text = $this->formatTelegramMessage($alert);
        $url = "https://api.telegram.org/bot{$config['bot_token']}/sendMessage";
        
        $data = [
            'chat_id' => $config['chat_id'],
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $ch = null;
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            if ($response === false) {
                throw new \RuntimeException(curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $httpCode === 200;
        } catch (\Throwable $e) {
            $this->logger->error('Telegram send failed', ['error' => $e->getMessage()]);
            return false;
        } finally {
            if ($ch !== null) {
                curl_close($ch);
            }
        }
    }

    private function sendEmail(array $config, array $alert): bool
    {
        if (empty($config['email'])) {
            return false;
        }

        $email = filter_var($config['email'], FILTER_VALIDATE_EMAIL);
        if (!$email) {
            $this->logger->warning('Invalid alert destination email', ['email' => $config['email']]);
            return false;
        }

        // AL2: Strip any potential CRLF injections from the subject line
        $subject = str_replace(["\r", "\n"], ' ', "[{$alert['severity']}] {$alert['title']}");
        $body = $this->formatEmailMessage($alert);

        // AL2: Using array representation for headers is native protection against injection
        $headers = [
            'From' => 'noreply@chortke.com',
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Mailer' => 'PHP/' . phpversion()
        ];

        try {
            return mail($email, $subject, $body, $headers);
        } catch (\Throwable $e) {
            $this->logger->error('Email send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function sendSlack(array $config, array $alert): bool
    {
        if (!isset($config['webhook_url'])) {
            return false;
        }

        $payload = [
            'text' => $alert['title'],
            'attachments' => [
                [
                    'color' => $this->getSeverityColor($alert['severity']),
                    'text' => $alert['message'],
                    'fields' => [
                        ['title' => 'Severity', 'value' => strtoupper($alert['severity']), 'short' => true],
                        ['title' => 'Environment', 'value' => $alert['environment'], 'short' => true],
                    ],
                    'footer' => 'Chortke Sentry',
                    'ts' => time(),
                ],
            ],
        ];

        if (!$this->isSafeUrl($config['webhook_url'])) {
            $this->logger->warning('Blocked SSRF vector or invalid URL in Slack dispatch', ['url' => $config['webhook_url']]);
            return false;
        }

        $ch = null;
        try {
            $ch = curl_init($config['webhook_url']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            if ($response === false) {
                throw new \RuntimeException(curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $httpCode === 200;
        } catch (\Throwable $e) {
            $this->logger->error('Slack send failed', ['error' => $e->getMessage()]);
            return false;
        } finally {
            if ($ch !== null) {
                curl_close($ch);
            }
        }
    }

    private function sendWebhook(array $config, array $alert): bool
    {
        if (!isset($config['url'])) {
            return false;
        }

        if (!$this->isSafeUrl($config['url'])) {
            $this->logger->warning('Blocked SSRF vector or invalid URL in webhook dispatch', ['url' => $config['url']]);
            return false;
        }

        $ch = null;
        try {
            $ch = curl_init($config['url']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($alert));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            if ($response === false) {
                throw new \RuntimeException(curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $httpCode >= 200 && $httpCode < 300;
        } catch (\Throwable $e) {
            $this->logger->error('Webhook send failed', ['error' => $e->getMessage()]);
            return false;
        } finally {
            if ($ch !== null) {
                curl_close($ch);
            }
        }
    }

    private function formatTelegramMessage(array $alert): string
    {
        $emoji = match($alert['severity']) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };

        $text = "{$emoji} <b>{$alert['title']}</b>\n\n";
        $text .= "{$alert['message']}\n\n";
        $text .= "📊 Severity: <code>{$alert['severity']}</code>\n";
        $text .= "🌍 Environment: <code>{$alert['environment']}</code>\n";
        if ($alert['event_id']) {
            $text .= "🔗 Event ID: <code>{$alert['event_id']}</code>\n";
        }
        return $text;
    }

    private function formatEmailMessage(array $alert): string
    {
        $now = date('Y-m-d H:i:s');
        $color = $this->getSeverityColor($alert['severity']);
        return <<<HTML
        <html>
        <body style="font-family: Arial, sans-serif;">
            <h2 style="color: {$color};">{$alert['title']}</h2>
            <p>{$alert['message']}</p>
            <table>
                <tr><td><strong>Severity:</strong></td><td>{$alert['severity']}</td></tr>
                <tr><td><strong>Environment:</strong></td><td>{$alert['environment']}</td></tr>
                <tr><td><strong>Time:</strong></td><td>{$now}</td></tr>
            </table>
        </body>
        </html>
        HTML;
    }

    private function getSeverityColor(string $severity): string
    {
        return match($severity) {
            'critical' => '#dc3545',
            'high' => '#fd7e14',
            'medium' => '#ffc107',
            'low' => '#28a745',
            default => '#6c757d',
        };
    }

    /**
     * 🛡️ isSafeUrl - SSRF Mitigation by resolving host IP and filtering out local/private ranges.
     */
    private function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'];
        
        // Defensive local check before DNS resolution
        $forbiddenHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        if (in_array(strtolower($host), $forbiddenHosts, true)) {
            return false;
        }

        $ip = gethostbyname($host);
        if (!$ip || $ip === $host) {
            // Could not resolve or matches original hostname (e.g. invalid IP or internal host)
            return false;
        }

        // Enforce validation that IP is not in private or reserved ranges
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
