<?php

declare(strict_types=1);

namespace App\Jobs\User;

class ChangeUserLevelJob
{
    public function __construct(
        private \Core\Database $db,
        private \App\Contracts\LoggerInterface $logger
    ) {}

    public function handle(int $userId, ?string $fromSlug, string $toSlug, string $changeType, string $reason = ''): bool
    {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // Snapshot current state securely with FOR UPDATE lock
            $stmtUser = $this->db->prepare("SELECT level_slug, level_type FROM users WHERE id = ? FOR UPDATE");
            $stmtUser->execute([$userId]);
            $currentUser = $stmtUser->fetch(\PDO::FETCH_OBJ);
            
            $realFromSlug = $currentUser ? $currentUser->level_slug : $fromSlug;

            // ✅ HIGH-11 Fix: Audit log BEFORE mutation
            $this->historyModel->create([
                'user_id' => $userId,
                'from_level' => $realFromSlug,
                'to_level' => $toSlug,
                'change_type' => $changeType,
                'reason' => $reason,
            ]);

            $stmt = $this->db->prepare("
                UPDATE users SET 
                    level_slug = ?, 
                    level_type = CASE WHEN ? = 'purchase' THEN 'purchased' ELSE 'activity' END,
                    level_expires_at = CASE WHEN ? IN ('downgrade','expire','reset') THEN NULL ELSE level_expires_at END,
                    level_downgraded_at = CASE WHEN ? = 'downgrade' THEN NOW() ELSE level_downgraded_at END
                WHERE id = ?
            ");
            $stmt->execute([$toSlug, $changeType, $changeType, $changeType, $userId]);

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // 📢 شلیک رویداد ارتقا فقط در صورتی که ارتقای واقعی، ادمین یا خرید باشد
        if (in_array($changeType, ['upgrade', 'purchase', 'admin'])) {
            try {
                $this->eventDispatcher->dispatchAsync('level.upgraded', new \App\Events\LevelUpgradedEvent(
                    $userId,
                    $realFromSlug ?? 'none',
                    $toSlug,
                    $changeType . ': ' . $reason
                ));
            } catch (\Throwable $e) {
                $this->logger->error('level.upgrade_event.failed', ['error' => $e->getMessage()]);
            }
        }

        return true;
    }
}
