<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\User;
use App\Models\Score;
use App\Enums\ModuleContext;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * سرویس مدیریت امتیازات عمومی (Score)
 * مسئولیت: اعطا یا کسر امتیازات عمومی سیستم که برای رتبه‌بندی یا کیف پول‌های پاداش استفاده می‌شوند
 */
class ScoreService extends \App\Services\BaseService
{
    public function __construct(
        private Database $db,
        private Score $scoreModel,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * افزودن امتیاز به کاربر
     */
    public function addScore(int $userId, ModuleContext $context, float $amount, string $reason): bool
    {
        if ($amount <= 0) return false;

        return $this->scoreModel->addEvent([
            'entity_type' => 'user',
            'entity_id' => $userId,
            'domain' => 'score_' . $context->value,
            'delta' => $amount,
            'source' => $reason,
            'meta' => []
        ]);
    }

    /**
     * کسر امتیاز از کاربر
     */
    public function deductScore(int $userId, ModuleContext $context, float $amount, string $reason): bool
    {
        if ($amount <= 0) return false;

        return $this->scoreModel->addEvent([
            'entity_type' => 'user',
            'entity_id' => $userId,
            'domain' => 'score_' . $context->value,
            'delta' => -$amount,
            'source' => $reason,
            'meta' => []
        ]);
    }

    /**
     * دریافت کل امتیازات یک کاربر در یک ماژول خاص
     */
    public function getTotalScore(int $userId, ModuleContext $context): float
    {
        return $this->scoreModel->getDomainScore($userId, 'score_' . $context->value);
    }
}
