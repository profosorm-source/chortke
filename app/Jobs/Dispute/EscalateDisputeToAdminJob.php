<?php

declare(strict_types=1);

namespace App\Jobs\Dispute;

class EscalateDisputeToAdminJob
{
    public function __construct(
        private \App\Models\Dispute $disputeModel,
        private \App\Contracts\LoggerInterface $logger
    ) {}

    public function handle(int $disputeId, int $requesterId): array
    {
        $dispute = $this->disputeModel->getSafe($disputeId);
        if (!$dispute) {
            return ['success' => false, 'message' => 'پرونده یافت نشد.'];
        }
        
        if ($dispute->status !== Dispute::STATUS_OPEN_PEER && $dispute->status !== Dispute::STATUS_OPEN) {
            return ['success' => false, 'message' => 'امکان ارجاع این پرونده وجود ندارد.'];
        }
        
        $ok = $this->disputeModel->update($disputeId, [
            'status' => Dispute::STATUS_ESCALATED,
            'resolved_by' => $requesterId
        ]);
        
        if (!$ok) {
            return ['success' => false, 'message' => 'خطا در ارجاع پرونده به ادمین.'];
        }
        
        $this->logger->info('case.escalated', [
            'dispute_id' => $disputeId,
            'requester_id' => $requesterId
        ]);
        
        return ['success' => true];
    }
}
