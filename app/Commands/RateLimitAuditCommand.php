<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\LoggerInterface;

/**
 * Section 8.8 — Audit the unified rate-limit policy.
 *
 * این کامند فقط config/rate_limits.php را می‌خواند و گزارش می‌دهد —
 * هیچ side-effect ندارد (idempotent).
 *
 * Usage:
 *   php cli.php ratelimit:audit
 */
class RateLimitAuditCommand
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function run(array $argv): void
    {
        $cfg = function_exists('config') ? config('rate_limits') : null;
        if (!is_array($cfg)) {
            fwrite(STDERR, "[ratelimit:audit] config/rate_limits.php not loaded.\n");
            exit(1);
        }

        $groups = array_filter(
            $cfg,
            fn($v, $k) => is_array($v) && !in_array($k, ['default', 'route_map', 'action_map'], true),
            ARRAY_FILTER_USE_BOTH
        );

        echo "═══════════════════════════════════════════════════════════════\n";
        echo " Rate-Limit Policy Audit (single source of truth)\n";
        echo "═══════════════════════════════════════════════════════════════\n\n";

        // Default
        $default = $cfg['default'] ?? [];
        echo sprintf(
            "DEFAULT: %d req / %d min\n\n",
            (int)($default['max_attempts'] ?? 0),
            (int)($default['decay_minutes'] ?? 0)
        );

        // Groups
        echo "── Groups ─────────────────────────────────────────────────────\n";
        foreach ($groups as $group => $entries) {
            $count = 0;
            foreach ((array)$entries as $endpoint => $entry) {
                if (!is_array($entry) || !isset($entry['max_attempts'])) {
                    continue;
                }
                $count++;
                $fc = !empty($entry['fail_closed']) ? ' [fail-closed]' : '';
                printf(
                    "  %-12s %-22s %4d req / %4d min%s\n",
                    $group,
                    (string)$endpoint,
                    (int)$entry['max_attempts'],
                    (int)$entry['decay_minutes'],
                    $fc
                );
            }
            if ($count === 0) {
                printf("  %-12s (no entries)\n", $group);
            }
        }

        // Route map
        echo "\n── Route → Policy map ─────────────────────────────────────────\n";
        $routeMap = $cfg['route_map'] ?? [];
        if (!$routeMap) {
            echo "  (empty — middleware will use defaults for everything!)\n";
        } else {
            $orphans = [];
            foreach ($routeMap as $pattern => $target) {
                $group    = (string)($target[0] ?? '');
                $endpoint = (string)($target[1] ?? 'general');
                $resolved = config("rate_limits.{$group}.{$endpoint}");
                if (!is_array($resolved) || !isset($resolved['max_attempts'])) {
                    $resolved = config("rate_limits.{$group}");
                }
                $ok = is_array($resolved) && isset($resolved['max_attempts']);
                if (!$ok) {
                    $orphans[] = "{$pattern} → {$group}.{$endpoint}";
                }
                printf(
                    "  %-30s → %s.%s %s\n",
                    (string)$pattern,
                    $group,
                    $endpoint,
                    $ok ? '' : '  ⚠ orphan (no matching config entry)'
                );
            }
            if ($orphans) {
                echo "\n  ⚠ " . count($orphans) . " orphan route mappings detected.\n";
            }
        }

        // Action map
        echo "\n── Action → Policy map ────────────────────────────────────────\n";
        $actionMap = $cfg['action_map'] ?? [];
        if (!$actionMap) {
            echo "  (empty — RateLimitPolicy will use legacy ACTIONS const)\n";
        } else {
            foreach ($actionMap as $action => $target) {
                printf(
                    "  %-25s → %s.%s\n",
                    (string)$action,
                    (string)($target[0] ?? ''),
                    (string)($target[1] ?? '')
                );
            }
        }

        echo "\n═══════════════════════════════════════════════════════════════\n";
        printf("  routes: %d   actions: %d   groups: %d\n",
            count($routeMap),
            count($actionMap),
            count($groups)
        );
        echo "═══════════════════════════════════════════════════════════════\n";
    }
}
