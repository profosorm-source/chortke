<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\User;
use App\Models\Score;
use App\Enums\ModuleContext;
use App\Contracts\LoggerInterface;
use App\Domain\Gamification\Strategies\TrustEvaluationStrategy;
use Core\Database;
use Core\EventDispatcher;

/**
 * سرویس مدیریت اعتماد و سلامت کاربر (Trust Score)
 * مسئولیت: افزایش یا کاهش امتیاز اعتماد کاربران در ماژول‌های مختلف جهت تشخیص تقلب
 */
class TrustService
{
    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private Score $scoreModel;
    private TrustEvaluationStrategy $trustStrategy;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Score $scoreModel,
        TrustEvaluationStrategy $trustStrategy
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->scoreModel = $scoreModel;
        $this->trustStrategy = $trustStrategy;

        
    }

    /**
     * ارزیابی و اعمال تغییرات Trust کاربر
     */
    public function evaluate(User $user, ModuleContext $context, string $actionType, array $payload = []): bool
    {
        $payload['action'] = $actionType;
        $delta = $this->trustStrategy->calculate($user, $context, $payload);

        if ($delta == 0.0) {
            return false;
        }

        try {
            $this->db->beginTransaction();

            $domain = 'trust_' . $context->value;
            
            $this->scoreModel->addEvent([
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'domain' => $domain,
                'delta' => $delta,
                'source' => $actionType,
                'meta' => []
            ]);

            $currentTrust = $this->scoreModel->getDomainScore($user->id, $domain);

            $this->db->commit();

            // شلیک رویداد در صورت افت شدید Trust (مثلا برای مسدودسازی خودکار کاربر متقلب)
            if ($delta < 0 && $currentTrust < -50.0) {
                $this->eventDispatcher->dispatchAsync('trust.critical_drop', (object)[
                    'user_id' => $user->id,
                    'context' => $context->value,
                    'current_trust' => $currentTrust
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('trust_service.evaluation_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * دریافت امتیاز اعتماد فعلی کاربر در یک ماژول خاص
     */
    public function getTrustScore(User $user, ModuleContext $context): float
    {
        // امتیاز پایه همه کاربران ۱۰۰ در نظر گرفته می‌شود (یا ۰ که با deltaها جمع می‌خورد)
        // در اینجا فرض بر این است که Trust مبنا صفر است.
        return $this->scoreModel->getDomainScore($user->id, 'trust_' . $context->value);
    }
}
