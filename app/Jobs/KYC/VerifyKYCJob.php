<?php

declare(strict_types=1);

namespace App\Jobs\KYC;

class VerifyKYCJob
{
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->logger = $logger;
}

public function handle(int $kycId, int $adminId): array
{
    try {
        $this->db->beginTransaction();

        $kyc = $this->kycModel->findForUpdate($kycId);
        if (!$kyc) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'درخواست KYC یافت نشد'];
        }

        if (!in_array($kyc->status, ['pending', 'under_review'], true)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'این درخواست قبلا بررسی شده است'];
        }

        // H-2: concurrency lock check
        if (!empty($kyc->under_review_by) && (int)$kyc->under_review_by !== $adminId) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'این درخواست توسط ادمین دیگری در حال بررسی است'];
        }

        $okKyc = $this->kycModel->update($kycId, [
            'status' => 'verified',
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => null,
            'under_review_by' => null,
            'review_started_at' => null,
        ]);

        if (!$okKyc) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در بروزرسانی KYC'];
        }

        $this->db->commit();

        $this->eventDispatcher->dispatchAsync('kyc.status_changed', [
            'kyc_id' => $kycId,
            'user_id' => (int)$kyc->user_id,
            'old_status' => $kyc->status,
            'new_status' => 'verified',
            'admin_id' => $adminId
        ]);

        // Dispatch class-based approved event for new listeners
        $this->eventDispatcher->dispatchAsync(
            KYCApprovedEvent::class,
            new KYCApprovedEvent((int)$kyc->user_id, $kycId)
        );

        return ['success' => true, 'message' => 'KYC با موفقیت تایید شد'];
    } catch (\Throwable $e) {
        $this->db->rollBack();

        $this->logger->critical('kyc.verify.exception', [
            'channel' => 'kyc',
            'kyc_id' => $kycId,
            'admin_id' => $adminId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);

        return ['success' => false, 'message' => 'خطای سیستمی در تایید KYC'];
    }
}
}
