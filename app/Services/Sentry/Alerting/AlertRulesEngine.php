<?php

declare(strict_types=1);

namespace App\Services\Sentry\Alerting;

use App\Models\SentryModel;
use Core\Logger;

/**
 * 🎯 AlertRulesEngine - موتور پردازش قوانین هشدار
 */
class AlertRulesEngine
{
    private array $cache = [];

    private SentryModel $model;
    private Logger $logger;
    private AlertDispatcher $dispatcher;
    public function __construct(
        SentryModel $model,
        Logger $logger,
        AlertDispatcher $dispatcher
    ) {        $this->model = $model;
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
}

    /**
     * ✅ Evaluate All Rules
     */
    public function evaluateAllRules(): array
    {
        $activeRules = $this->model->getActiveRules();
        $triggered = [];

        foreach ($activeRules as $rule) {
            if ($this->evaluateRule($rule)) {
                $triggered[] = $rule;
                $this->triggerRule($rule);
            }
        }

        return $triggered;
    }

    /**
     * 🔍 Evaluate Rule
     */
    public function evaluateRule(object $rule): bool
    {
        try {
            $condition = json_decode((string)$rule->condition, true);
            $metric = $condition['metric'] ?? null;
            $operator = $condition['operator'] ?? '>';

            if (!$metric) {
                return false;
            }

            $currentValue = $this->getMetricValue($metric, (int)$rule->time_window);

            return $this->compareValues($currentValue, $operator, (float)$rule->threshold);

        } catch (\Throwable $e) {
            $this->logger->error('Rule evaluation failed', ['rule_id' => $rule->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function getMetricValue(string $metric, int $timeWindow): float
    {
        $cacheKey = "{$metric}_{$timeWindow}";
        $now = \time();

        // AL5: Introduce explicit 60s TTL on in-memory cache to suppress stale reads in persistent setups
        if (isset($this->cache[$cacheKey])) {
            $entry = $this->cache[$cacheKey];
            if (($now - $entry['timestamp']) < 60) {
                return (float)$entry['value'];
            }
            unset($this->cache[$cacheKey]);
        }

        $value = (float)$this->model->getMetricValue($metric, $timeWindow);
        $this->cache[$cacheKey] = [
            'value' => $value,
            'timestamp' => $now
        ];
        
        return $value;
    }

    private function compareValues(float $current, string $operator, float $threshold): bool
    {
        return match($operator) {
            '>' => $current > $threshold,
            '>=' => $current >= $threshold,
            '<' => $current < $threshold,
            '<=' => $current <= $threshold,
            '==' => abs($current - $threshold) < 0.01,
            '!=' => abs($current - $threshold) >= 0.01,
            default => false
        };
    }

    private function triggerRule(object $rule): void
    {
        try {
            if ($this->wasRecentlyTriggered((int)$rule->id, (int)$rule->time_window)) {
                $this->logger->info('Rule recently triggered, skipping', ['rule_id' => $rule->id]);
                return;
            }

            $this->dispatcher->dispatch([
                'type' => $rule->rule_type,
                'severity' => $rule->severity,
                'title' => "Alert: {$rule->rule_name}",
                'message' => $this->formatRuleMessage($rule),
                'metadata' => [
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->rule_name,
                    'threshold' => $rule->threshold,
                    'time_window' => $rule->time_window,
                ],
            ]);

            $this->model->updateRuleLastTriggered((int)$rule->id);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to trigger rule', ['rule_id' => $rule->id, 'error' => $e->getMessage()]);
        }
    }

    private function wasRecentlyTriggered(int $ruleId, int $timeWindow): bool
    {
        $rule = $this->model->getRuleStatus($ruleId);
        if (!$rule || !$rule->last_triggered_at) {
            return false;
        }

        $lastTriggered = strtotime((string)$rule->last_triggered_at);
        $cooldown = $timeWindow * 60 * 2; // 2x time window
        return (time() - $lastTriggered) < $cooldown;
    }

    private function formatRuleMessage(object $rule): string
    {
        $condition = json_decode((string)$rule->condition, true);
        $metric = $condition['metric'] ?? 'Unknown';
        return "Metric '{$metric}' exceeded threshold of {$rule->threshold} over the last {$rule->time_window} minutes.";
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
