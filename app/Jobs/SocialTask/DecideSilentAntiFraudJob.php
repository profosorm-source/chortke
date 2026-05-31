<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

class DecideSilentAntiFraudJob
{
    public function __construct(
        private \App\Services\Settings\AppSettings $appSettings,
        private \App\Services\AuditTrail $auditTrail
    ) {}

    public function handle(
        int   $userId,
        int   $executionId,
        float $taskScore,
        array $riskResult
    ): array {
        $userObj = $this->userService->findById($userId);
        $trustScore = $userObj ? $this->trustService->getTrustScore($userObj, ModuleContext::SOCIAL_TASKS) : 50.0;
        $riskScore  = (int)($riskResult['risk_score'] ?? 0);

        $minTaskScore  = (int)$this->appSettings->get('antifraud_min_task_score', 70);
        $minTrustScore = (int)$this->appSettings->get('antifraud_min_trust_score', 60);
        $maxRiskScore  = (int)$this->appSettings->get('antifraud_max_risk_score', 30);
        $softMinScore  = (int)$this->appSettings->get('antifraud_soft_min_score', 40);

        // MED-20: Disambiguated soft_approved branching logic by explicitly applying detailed operational tags
        if ($taskScore >= $minTaskScore && $trustScore >= $minTrustScore && $riskScore < $maxRiskScore) {
            $decision   = 'approved';
            $payReward  = true;
            $giveScore  = true;
            $flagReview = false;
            $reason     = 'score_trust_risk_all_good';
        } elseif ($taskScore >= $minTaskScore) {
            // Flow A: Verified tasks that suffer from external signals (low trust or device risk markers)
            $decision   = 'soft_approved';
            $payReward  = true;
            $giveScore  = true;
            $flagReview = false;
            $reason     = $trustScore < $minTrustScore ? 'soft_approved_low_trust' : 'soft_approved_high_risk';
        } elseif ($taskScore >= $softMinScore) {
            // Flow B: Bare minimum borderline quality tasks that skip direct trust bonuses
            $decision   = 'soft_approved';
            $payReward  = true;
            $giveScore  = false;
            $flagReview = $taskScore < 50;
            $reason     = 'soft_approved_borderline_score';
        } else {
            $decision   = 'rejected';
            $payReward  = false;
            $giveScore  = false;
            $flagReview = $taskScore < 20;
            $reason     = 'low_score_rejected';
        }

        if ($decision === 'approved') {
            if ($userObj) {
                $this->trustService->evaluate($userObj, ModuleContext::SOCIAL_TASKS, 'task_approved');
            }
        } elseif ($decision === 'rejected') {
            if ($userObj) {
                $this->trustService->evaluate($userObj, ModuleContext::SOCIAL_TASKS, 'task_rejected');
            }

            // ENHANCEMENT: Notify administrator automatically when highly critical rejections warrant verification flag
            if ($flagReview) {
                $adminId = (int)$this->appSettings->get('system_admin_user_id', 1);
                $this->eventDispatcher->dispatchAsync('notification.requested', [
                    'user_id' => $adminId,
                    'type' => 'antifraud.critical_rejection_flagged',
                    'title' => 'هشدار: رد بحرانی تشخیص داده شد',
                    'message' => "رد بحرانی برای کاربر {$userId} شناسایی شد. امتیاز: {$taskScore}، ریسک: {$riskScore}",
                    'data' => [
                        'user_id' => $userId,
                        'execution_id' => $executionId,
                        'task_score' => $taskScore,
                        'risk_score' => $riskScore,
                        'reason' => $reason
                    ],
                    'priority' => 'urgent'
                ]);
            }
        }

        $this->auditTrail->record(
            $decision === 'approved' ? 'task.execution.approved' : 'task.execution.rejected',
            $userId,
            [
                'execution_id' => $executionId,
                'task_score'   => $taskScore,
                'trust_score'  => $trustScore,
                'risk_score'   => $riskScore,
                'decision'     => $decision,
                'reason'       => $reason,
            ]
        );

        return [
            'decision'    => $decision,
            'task_score'  => $taskScore,
            'trust_score' => $trustScore,
            'risk_score'  => $riskScore,
            'reason'      => $reason,
            'pay_reward'  => $payReward,
            'give_score'  => $giveScore,
            'flag_review' => $flagReview,
        ];
    }
}
