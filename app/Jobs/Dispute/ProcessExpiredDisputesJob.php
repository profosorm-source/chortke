<?php

declare(strict_types=1);

namespace App\Jobs\Dispute;

class ProcessExpiredDisputesJob
{
    private \Core\Database $db;
    private \App\Models\Dispute $disputeModel;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Models\Dispute $disputeModel,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->disputeModel = $disputeModel;
        $this->logger = $logger;
}

    public function handle(): int
    {
        $expired = $this->db->fetchAll(
            "SELECT id FROM disputes 
             WHERE status = ? AND peer_deadline < NOW()",
            [Dispute::STATUS_OPEN_PEER]
        );
        
        $count = 0;
        foreach ($expired as $row) {
            $ok = $this->disputeModel->update((int)$row->id, [
                'status' => Dispute::STATUS_ESCALATED,
                'resolution_note' => 'سیستم: پایان زمان گفتگوی طرفین و ارجاع خودکار به مدیریت.'
            ]);
            
            if ($ok) {
                $count++;
                $this->logger->info('case.auto_escalated', ['dispute_id' => $row->id]);
            }
        }
        
        return $count;
    }
}
