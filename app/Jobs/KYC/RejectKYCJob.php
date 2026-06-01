<?php

declare(strict_types=1);

namespace App\Jobs\KYC;

class RejectKYCJob
{
    private \Core\Database $db;
    private \App\Models\User $userModel;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Models\User $userModel,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->userModel = $userModel;
        $this->logger = $logger;
}

public function handle(int $kycId, int $adminId, string $reason): array
{
    try {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'دلیل رد الزامی است'];
        }

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
            'status' => 'rejected',
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => $reason,
            'under_review_by' => null,
            'review_started_at' => null,
        ]);

        if (!$okKyc) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در بروزرسانی KYC'];
        }

        $okUser = $this->userModel->update((int)$kyc->user_id, [
            'kyc_status' => 'rejected',
        ]);

        if (!$okUser) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در بروزرسانی کاربر'];
        }

        $this->db->commit();

        $this->eventDispatcher->dispatchAsync('kyc.status_changed', [
            'kyc_id' => $kycId,
            'user_id' => (int)$kyc->user_id,
            'old_status' => $kyc->status,
            'new_status' => 'rejected',
            'reason' => $reason,
            'admin_id' => $adminId
        ]);

        return ['success' => true, 'message' => 'KYC با موفقیت رد شد'];
    } catch (\Throwable $e) {
        $this->db->rollBack();

        $this->logger->critical('kyc.reject.exception', [
            'channel' => 'kyc',
            'kyc_id' => $kycId,
            'admin_id' => $adminId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);

        return ['success' => false, 'message' => 'خطای سیستمی در رد KYC'];
    }
}
}
