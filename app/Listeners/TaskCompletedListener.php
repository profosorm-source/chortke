<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TaskCompletedEvent;
use App\Services\Gamification\XpService;
use App\Services\Notification\NotificationService;
use App\Services\ScoreService;
use App\Services\AuditTrail;
use App\Contracts\LoggerInterface;
use App\Enums\ModuleContext;
use App\Services\OutboxService;
use Core\Container;

/**
 * TaskCompletedListener - Handles custom task completion events
 * 
 * Decouples task service from:
 * - XP award system
 * - Trust score updates
 * - User notifications
 * - Audit logging
 */
class TaskCompletedListener
{
    private Container $container;
    private LoggerInterface $logger;

    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Handle task.completed event
     * 
     * Awards XP points
     * Updates trust score
     * Sends notification
     * Logs to audit trail
     */
    public function handle(TaskCompletedEvent $event): void
    {
        try {
            $data = $event->getData();
            $userId = $data['user_id'] ?? null;
            $taskId = $data['task_id'] ?? null;
            $title = $data['title'] ?? 'تسک';
            $xpReward = (int)($data['xp_reward'] ?? 10);

            if (!$userId || !$taskId) {
                $this->logger->warning('task.completed event missing required data', $data);
                return;
            }

            // Award XP points
            $xpService = $this->container->make(XpService::class);
            $xpService->award($userId, ModuleContext::CUSTOM_TASKS, (float)$xpReward, "task_$taskId");

            // Update trust score
            $scoreService = $this->container->make(ScoreService::class);
            $scoreService->applyDelta('user', $userId, 'score_trust', 3, 'task_completed');

            // Log to audit trail
            $auditTrail = $this->container->make(AuditTrail::class);
            $auditTrail->log([
                'user_id' => $userId,
                'action' => 'task.completed',
                'resource_id' => $taskId,
                'metadata' => [
                    'title' => $title,
                    'xp_awarded' => $xpReward
                ]
            ]);

            // Send notification asynchronously via OutboxService
            $outboxService = $this->container->make(OutboxService::class);
            $outboxService->record(
                'notification',
                $userId . '_task_completed',
                'send_notification',
                [
                    'notification' => [
                        'method' => 'send',
                        'args' => [
                            $userId,
                            'task.completed',
                            'تسک تکمیل شد',
                            "تسک \"$title\" تکمیل شد. $xpReward XP کسب کردید!",
                            ['task_id' => $taskId, 'xp_reward' => $xpReward]
                        ]
                    ]
                ]
            );

        } catch (\Throwable $e) {
            $this->logger->error('task.completed listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }
}
