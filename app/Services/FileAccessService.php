<?php

namespace App\Services;

use App\Models\FileAccess;
use App\Contracts\LoggerInterface;
class FileAccessService
{
    private FileAccess $fileModel;
    /** پوشه‌هایی که بدون احراز هویت قابل دسترسی هستند */
    private const PUBLIC_FOLDERS = ['avatars', 'banners', 'captcha'];

    /** پوشه‌های حساس که دسترسی باید لاگ شود */
    private const SENSITIVE_FOLDERS = ['kyc', 'receipts', 'dispute-evidence'];

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        FileAccess $fileModel
    )
    {        $this->logger = $logger;

                $this->fileModel = $fileModel;
    }

    public function checkAccess(string $folder, string $filename, ?int $userId, bool $isAdmin): array
    {
        // ── ادمین: دسترسی کامل ──────────────────────────────────────────────
        if ($isAdmin) {
            return $this->allow();
        }

        // ── عمومی ────────────────────────────────────────────────────────────
        if (in_array($folder, self::PUBLIC_FOLDERS, true)) {
            return $this->allow();
        }

        // ── از اینجا login الزامی است ────────────────────────────────────────
        if (!$userId) {
            return $this->deny_result('برای مشاهده این فایل باید وارد سیستم شوید');
        }

        // ── per-folder ───────────────────────────────────────────────────────
        return match ($folder) {
            'kyc'                 => $this->accessKyc($filename, $userId),
            'receipts'            => $this->accessReceipt($filename, $userId),
            'task-proofs'         => $this->accessTaskProof($filename, $userId),
            'task-samples'        => $this->accessTaskSample($filename, $userId),
            'ad-tasks'            => $this->accessAdTaskSample($filename, $userId),
            'dispute-evidence'    => $this->accessDisputeEvidence($filename, $userId),
            'story-proofs'        => $this->accessStoryProof($filename, $userId),
            'story-media'         => $this->accessStoryMedia($filename, $userId),
            'influencer-profiles' => $this->accessInfluencerProfile($filename, $userId),
            'ticket-attachments'  => $this->accessTicketAttachment($filename, $userId),
            default               => $this->deny_result('پوشه ناشناخته است'),
        };
    }

    public function isSensitiveFolder(string $folder): bool
    {
        return in_array($folder, self::SENSITIVE_FOLDERS, true);
    }

    public function logAccess(string $folder, string $filename, string $action, ?int $userId, string $ip): void
    {
        if ($userId === null) {
            return;
        }

        try {
            $this->fileModel->logFileAccess($folder, $filename, $userId, $action, $ip);
        } catch (\Throwable) {
            // silent
        }
    }

    public function logDeniedAccess(string $folder, string $filename, ?int $userId, string $ip): void
    {
        $logUserId = $userId ?? 0;

        // H-09 Fix: Log security warnings to system logs for brute force detection
        $this->logger->warning('file.access.denied', [
            'folder' => $folder,
            'filename' => $filename,
            'user_id' => $logUserId,
            'ip' => $ip
        ]);

        try {
            $this->fileModel->logDeniedFileAccess($folder, $filename, $logUserId, $ip);
        } catch (\Throwable) {
            // silent
        }
    }

    private function allow(): array
    {
        return ['allowed' => true, 'reason' => ''];
    }

    private function deny_result(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }

    private function accessKyc(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkKycOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا متعلق به شما نیست');
        }

        return $this->allow();
    }

    private function accessReceipt(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkReceiptOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا متعلق به شما نیست');
        }

        return $this->allow();
    }

    private function accessTaskProof(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkTaskProofOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }


    private function accessTaskSample(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkTaskSampleOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }


    private function accessAdTaskSample(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkAdTaskSampleOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }


    private function accessDisputeEvidence(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkDisputeEvidenceOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }

    private function accessStoryProof(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkStoryProofOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }

    private function accessStoryMedia(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkStoryMediaOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }

    private function accessInfluencerProfile(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkInfluencerProfileOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا متعلق به شما نیست');
        }

        return $this->allow();
    }

    private function accessTicketAttachment(string $filename, int $userId): array
    {
        if (!$this->fileModel->checkTicketAttachmentOwnership($filename, $userId)) {
            return $this->deny_result('فایل یافت نشد یا دسترسی غیرمجاز');
        }

        return $this->allow();
    }
}
