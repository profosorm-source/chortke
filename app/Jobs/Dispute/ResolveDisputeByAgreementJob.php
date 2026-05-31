<?php

declare(strict_types=1);

namespace App\Jobs\Dispute;

class ResolveDisputeByAgreementJob
{
    public function __construct(
        private \App\Models\Dispute $disputeModel,
        private \App\Contracts\LoggerInterface $logger
    ) {}

    public function handle(int $disputeId, int $initiatorId, string $resolution, string $verdict): array
    {
        $dispute = $this->disputeModel->getSafe($disputeId);
        if (!$dispute) {
            return ['success' => false, 'message' => 'پرونده یافت نشد.'];
        }
        
        $ok = $this->disputeModel->update($disputeId, [
            'status' => Dispute::STATUS_RESOLVED_PEER,
            'resolution_note' => $resolution,
            'resolved_at' => date('Y-m-d H:i:s'),
            'resolved_by' => $initiatorId
        ]);
        
        if (!$ok) {
            return ['success' => false, 'message' => 'خطا در ثبت تفاهم‌نامه.'];
        }
        
        $this->logger->info('case.resolved_peer', [
            'dispute_id' => $disputeId,
            'resolved_by' => $initiatorId
        ]);
        
        $this->eventDispatcher->dispatchAsync('notification.requested', [
            'user_id' => (int)$dispute->user_id,
            'type' => 'system',
            'title' => 'حل اختلاف به صورت دوستانه',
            'message' => 'اختلاف سفارش شما به توافق طرفین خاتمه یافت.'
        ]);
        if ($dispute->target_user_id) {
            $this->eventDispatcher->dispatchAsync('notification.requested', [
                'user_id' => (int)$dispute->target_user_id,
                'type' => 'system',
                'title' => 'حل اختلاف به صورت دوستانه',
                'message' => 'اختلاف سفارش شما به توافق طرفین خاتمه یافت.'
            ]);
        }
        
        return ['success' => true];
    }
}
