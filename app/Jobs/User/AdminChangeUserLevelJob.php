<?php

declare(strict_types=1);

namespace App\Jobs\User;

class AdminChangeUserLevelJob
{
    private \Core\Database $db;
    public function __construct(
        \Core\Database $db
    ) {        $this->db = $db;
}

    public function handle(int $userId, string $newSlug, string $reason = ''): bool
    {
        $stmt = $this->db->prepare("SELECT level_slug FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_OBJ);
        if (!$user) return false;

        $reason = htmlspecialchars(trim($reason), ENT_QUOTES, 'UTF-8');
        return $this->changeLevel($userId, $user->level_slug, $newSlug, 'admin', $reason);
    }
}
