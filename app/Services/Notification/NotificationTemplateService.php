<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use Core\Cache;
use App\Contracts\LoggerInterface;

class NotificationTemplateService
{
    private const TEMPLATE_CACHE_PREFIX = 'notif_tpl:';
    private const TEMPLATE_CACHE_TTL = 30;

    private \Core\Cache $cache;
    private Notification $model;
    public function __construct(
        \Core\Cache $cache,
        Notification $model
    ) {        $this->cache = $cache;
        $this->model = $model;

        
    }

    public function renderTemplate(string $templateKey, array $vars = []): array
    {
        $template = $this->getTemplate($templateKey);

        return [
            'title'   => $this->interpolate($template['title'],   $vars),
            'message' => $this->interpolate($template['message'], $vars),
        ];
    }

    public function getTemplate(string $templateKey): array
    {
        $cacheKey = self::TEMPLATE_CACHE_PREFIX . $templateKey;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $dbTemplate = $this->model->getTemplateFromDb($templateKey);
        if ($dbTemplate) {
            $result = [
                'title'     => $dbTemplate->title,
                'message'   => $dbTemplate->message,
                'variables' => json_decode((string)($dbTemplate->variables ?? '{}'), true) ?: [],
            ];
            $this->cache->put($cacheKey, $result, self::TEMPLATE_CACHE_TTL);
            return $result;
        }

        $default = $this->getDefaultTemplate($templateKey);
        $this->cache->put($cacheKey, $default, self::TEMPLATE_CACHE_TTL);
        return $default;
    }

    private function interpolate(string $text, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $text = str_replace("{{{$key}}}", (string)$value, $text);
        }
        return $text;
    }

    public function getAllTemplatesWithVariables(): array
    {
        $templates = [];
        $defaults = $this->getDefaultTemplates();

        foreach ($defaults as $key => $template) {
            $dbTemplate = $this->model->getTemplateFromDb($key);
            if ($dbTemplate) {
                $templates[$key] = [
                    'title'     => $dbTemplate->title,
                    'message'   => $dbTemplate->message,
                    'variables' => json_decode((string)($dbTemplate->variables ?? '{}'), true) ?: [],
                    'is_custom' => true,
                ];
            } else {
                $templates[$key] = array_merge($template, ['is_custom' => false]);
            }
        }

        return $templates;
    }

    public function saveTemplateOverride(string $key, string $title, string $message): bool
    {
        $success = $this->model->saveTemplateOverride($key, $title, $message);
        if ($success) {
            $this->cache->forget(self::TEMPLATE_CACHE_PREFIX . $key);
        }
        return $success;
    }

    public function deleteTemplateOverride(string $key): bool
    {
        $success = $this->model->deleteTemplateOverride($key);
        if ($success) {
            $this->cache->forget(self::TEMPLATE_CACHE_PREFIX . $key);
        }
        return $success;
    }

    private function getDefaultTemplate(string $templateKey): array
    {
        $defaults = $this->getDefaultTemplates();
        return $defaults[$templateKey] ?? $defaults['system'];
    }

    private function getDefaultTemplates(): array
    {
        return [
            'deposit' => [
                'title'     => 'واریز موفق ✅',
                'message'   => 'مبلغ {{amount}} {{currency}} با موفقیت به کیف پول شما واریز شد.',
                'variables' => ['amount', 'currency'],
            ],
            'withdrawal' => [
                'title'     => 'برداشت تأیید شد 💸',
                'message'   => 'درخواست برداشت {{amount}} {{currency}} تأیید و پردازش شد.',
                'variables' => ['amount', 'currency'],
            ],
            'withdrawal_rejected' => [
                'title'     => 'برداشت رد شد ❌',
                'message'   => 'درخواست برداشت {{amount}} رد شد. دلیل: {{reason}}. مبلغ به کیف پول بازگشت.',
                'variables' => ['amount', 'reason'],
            ],
            'task' => [
                'title'     => 'تسک جدید 📋',
                'message'   => 'تسک جدید «{{task_title}}» برای شما در دسترس است.',
                'variables' => ['task_title'],
            ],
            'kyc_approved' => [
                'title'     => 'احراز هویت تأیید شد ✅',
                'message'   => 'احراز هویت شما تأیید شد. اکنون می‌توانید از تمام امکانات سایت استفاده کنید.',
                'variables' => [],
            ],
            'kyc_rejected' => [
                'title'     => 'احراز هویت رد شد ❌',
                'message'   => 'احراز هویت شما رد شد. دلیل: {{reason}}. لطفاً مدارک را مجدداً ارسال کنید.',
                'variables' => ['reason'],
            ],
            'lottery_winner' => [
                'title'     => '🎉 تبریک! برنده شدید!',
                'message'   => 'شما برنده قرعه‌کشی شدید! مبلغ {{amount}} به کیف پول شما واریز شد.',
                'variables' => ['amount'],
            ],
            'referral' => [
                'title'     => 'کمیسیون معرفی 💰',
                'message'   => 'از فعالیت «{{referred_user}}» مبلغ {{amount}} کمیسیون دریافت کردید.',
                'variables' => ['referred_user', 'amount'],
            ],
            'security' => [
                'title'     => '⚠️ هشدار امنیتی',
                'message'   => '{{message}}',
                'variables' => ['message', 'ip'],
            ],
            'investment_completed' => [
                'title'     => 'سرمایه‌گذاری تکمیل شد 📈',
                'message'   => 'سرمایه‌گذاری شما به پایان رسید. سود: {{profit}} — مجموع: {{total}}.',
                'variables' => ['profit', 'total'],
            ],
            'system' => [
                'title'     => '{{title}}',
                'message'   => '{{message}}',
                'variables' => ['title', 'message'],
            ],
            'critical_feature_change' => [
                'title'     => '⚠️ تغییر در فیچر حیاتی',
                'message'   => 'فیچر «{{feature}}» با موفقیت {{action}} شد.',
                'variables' => ['feature', 'action'],
            ],
        ];
    }
}
