<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\LoggerInterface;
use App\Services\ReconciliationService;
use App\Services\Withdrawal\WithdrawalAdminService;

/**
 * CLI for the safe stuck-withdrawal review workflow (Section 8.5 / 8.7).
 *
 * This command is a thin orchestrator on top of:
 *   - ReconciliationService (detect/flag/list/admin-ops)
 *   - WithdrawalAdminService::autoResolveStuck() (safe auto-fix)
 *
 * Usage:
 *   php cli.php withdrawals:review:scan      [--minutes=120] [--limit=200]
 *   php cli.php withdrawals:review:auto-fix  [--stable=30]   [--limit=50] [--admin-id=0]
 *   php cli.php withdrawals:review:list      [--limit=50]    [--offset=0]
 *   php cli.php withdrawals:review:resolve   <id> --note="..." [--admin-id=0]
 *   php cli.php withdrawals:review:dismiss   <id> --note="..." [--admin-id=0]
 */
class StuckWithdrawalReviewCommand
{
    private ReconciliationService $reconciliation;
    private WithdrawalAdminService $withdrawalAdminService;
    private LoggerInterface $logger;
    public function __construct(
        ReconciliationService $reconciliation,
        WithdrawalAdminService $withdrawalAdminService,
        LoggerInterface $logger
    ) {        $this->reconciliation = $reconciliation;
        $this->withdrawalAdminService = $withdrawalAdminService;
        $this->logger = $logger;
}

    public function run(array $argv): void
    {
        $command = $argv[1] ?? 'withdrawals:review:list';
        $opts = $this->parseOptions($argv);

        switch ($command) {
            case 'withdrawals:review:scan':
                $this->scan($opts);
                return;
            case 'withdrawals:review:auto-fix':
                $this->autoFix($opts);
                return;
            case 'withdrawals:review:list':
                $this->listOpen($opts);
                return;
            case 'withdrawals:review:resolve':
                $this->resolve($argv[2] ?? null, $opts);
                return;
            case 'withdrawals:review:dismiss':
                $this->dismiss($argv[2] ?? null, $opts);
                return;
            default:
                throw new \InvalidArgumentException("Unsupported command: {$command}");
        }
    }

    private function scan(array $opts): void
    {
        $minutes = (int)($opts['minutes'] ?? ReconciliationService::DEFAULT_STUCK_MINUTES);
        $limit   = (int)($opts['limit']   ?? ReconciliationService::STUCK_SCAN_BATCH);

        $r = $this->reconciliation->flagStuckWithdrawals($minutes, $limit);
        echo sprintf(
            "[scan] scanned=%d flagged=%d notified=%d skipped=%d (older_than=%dmin)\n",
            $r['scanned'], $r['flagged'], $r['notified'], $r['skipped'], $minutes
        );
    }

    private function autoFix(array $opts): void
    {
        $stable  = (int)($opts['stable']   ?? 30);
        $limit   = (int)($opts['limit']    ?? 50);
        $adminId = isset($opts['admin-id']) ? (int)$opts['admin-id'] : null;

        $r = $this->withdrawalAdminService->autoResolveStuck($adminId, $stable, $limit);
        echo sprintf(
            "[auto-fix] scanned=%d fixed=%d escalated=%d errors=%d (stable=%dmin)\n",
            $r['scanned'], $r['fixed'], $r['escalated'], $r['errors'], $stable
        );
    }

    private function listOpen(array $opts): void
    {
        $limit  = (int)($opts['limit']  ?? 50);
        $offset = (int)($opts['offset'] ?? 0);

        $rows = $this->reconciliation->listOpenReviews($limit, $offset);
        if (empty($rows)) {
            echo "No open stuck-withdrawal reviews.\n";
            return;
        }
        foreach ($rows as $r) {
            echo sprintf(
                "#%d  w=#%d  user=%d  status=%s  sev=%s  reason=%s  stuck=%dmin  amount=%s %s  notified=%s\n",
                (int)$r->id,
                (int)$r->withdrawal_id,
                (int)$r->user_id,
                (string)$r->review_status,
                (string)$r->severity,
                (string)$r->reason_code,
                (int)$r->stuck_minutes,
                (string)($r->withdrawal_amount ?? '?'),
                strtoupper((string)($r->withdrawal_currency ?? '')),
                $r->notified_admin_at ? 'yes' : 'no'
            );
        }
    }

    private function resolve(?string $idArg, array $opts): void
    {
        $id = (int)($idArg ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('Review id is required.');
        }
        $note = (string)($opts['note'] ?? '');
        if ($note === '') {
            throw new \InvalidArgumentException('--note="..." is required.');
        }
        $adminId = (int)($opts['admin-id'] ?? 0);
        $ok = $this->reconciliation->adminResolveReview($id, $adminId, $note);
        echo $ok ? "Resolved review #{$id}.\n" : "Review #{$id} not in resolvable state.\n";
    }

    private function dismiss(?string $idArg, array $opts): void
    {
        $id = (int)($idArg ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('Review id is required.');
        }
        $note = (string)($opts['note'] ?? '');
        if ($note === '') {
            throw new \InvalidArgumentException('--note="..." is required.');
        }
        $adminId = (int)($opts['admin-id'] ?? 0);
        $ok = $this->reconciliation->dismissReview($id, $adminId, $note);
        echo $ok ? "Dismissed review #{$id}.\n" : "Review #{$id} not in dismissable state.\n";
    }

    private function parseOptions(array $argv): array
    {
        $opts = [];
        $count = count($argv);
        for ($i = 2; $i < $count; $i++) {
            $arg = (string)$argv[$i];
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$k, $v] = explode('=', $arg, 2);
                $opts[$k] = trim($v, "\"' ");
            } else {
                $next = $argv[$i + 1] ?? null;
                if ($next !== null && !str_starts_with((string)$next, '--')) {
                    $opts[$arg] = trim((string)$next, "\"' ");
                    $i++;
                } else {
                    $opts[$arg] = true;
                }
            }
        }
        return $opts;
    }
}
