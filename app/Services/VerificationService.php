<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\InfluencerModel;
use App\Models\InfluencerVerification;
use Core\Database;
use App\Services\Settings\AppSettings;

/**
 * VerificationService - Influencer verification without external APIs
 * 
 * Verification Method:
 * 1. User provides Instagram username
 * 2. System generates verification code
 * 3. User posts verification code in specific story/post
 * 4. Admin or system verifies manually
 * 
 * No external API calls - all verification is user-initiated
 */
class VerificationService
{
    private InfluencerModel $profileModel;
    private InfluencerVerification $verificationModel;

    private AppSettings $appSettings;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        InfluencerModel $profileModel,
        InfluencerVerification $verificationModel,
        AppSettings $appSettings
    ) {        $this->db = $db;
        $this->logger = $logger;

        
        $this->appSettings = $appSettings;
        $this->profileModel = $profileModel;
        $this->verificationModel = $verificationModel;
        }

    // ──────────────────────────────────────────────────────────────────────────
    // Verification Code Generation
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Generate verification code for influencer profile
     * 
     * Code format: Random alphanumeric 8 characters
     * User must post this in a story/bio to prove profile ownership
     */
    public function generateVerificationCode(int $profileId): array
    {
        try {
            // ✅ Generate random code
            $code = $this->generateRandomCode();

            $existing = $this->verificationModel->findPendingByProfile($profileId);
            if ($existing) {
                return [
                    'ok' => true,
                    'code' => $existing->code,
                    'expires_at' => $existing->expires_at,
                    'message' => 'کد تایید قبلاً برای این پروفایل ایجاد شده است'
                ];
            }

            $this->verificationModel->expirePendingForProfile($profileId);

            $hours = (int)$this->appSettings->get('verification_otp_validity_hours', 24);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $hours . ' hours'));
            $this->verificationModel->createVerification($profileId, $code, $expiresAt);

            $this->logger->info('verification.code.generated', [
                'profile_id' => $profileId,
                'code_hash' => hash('sha256', $code),
            ]);

            return [
                'ok' => true,
                'code' => $code,
                'expires_at' => $expiresAt,
                'message' => 'کد تایید تولید شد. این کد را در کاپشن تصویر/استوری خود قرار دهید.',
                'instructions' => [
                    '۱. یک تصویر یا استوری از پروفایل خود انتشار دهید',
                    '۲. کد زیر را در کاپشن یا توضیح قرار دهید: ' . $code,
                    '۳. پس از انتشار، درخواست تایید را ارسال کنید',
                    '۴. در ظرف ۲۴ ساعت تایید کنید یا کد منقضی می‌شود'
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error('verification.code.generation.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در تولید کد تایید'];
        }
    }

    /**
     * Generate random alphanumeric code
     */
    private function generateRandomCode(?int $length = null): string
    {
        if ($length === null) {
            $length = (int)$this->appSettings->get('verification_otp_length', 8);
        }
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $code;
    }

    /**
     * Get influencer profile only if owned by the requesting user.
     */
    public function getUserProfile(int $profileId, int $userId): ?object
    {
        return $this->profileModel->findOwnedByUser($profileId, $userId);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Manual Verification by Admin/User
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * User submits proof of verification (screenshot of post with code)
     */
    public function submitVerificationProof(int $profileId, int $userId, string $proofUrl): array
    {
        try {
            $verification = $this->verificationModel->findPendingByProfile($profileId);
            if (!$verification) {
                return ['ok' => false, 'error' => 'کد تایید معتبر یافت نشد'];
            }

            if (empty($proofUrl) || !$this->isValidProofUrl($proofUrl)) {
                return ['ok' => false, 'error' => 'URL اثبات نامعتبر است'];
            }

            $profile = $this->profileModel->find($profileId);
            if (!$profile) {
                return ['ok' => false, 'error' => 'پروفایل اینفلوئنسر یافت نشد'];
            }

            // Return to pending review / Update with proof URL
            $this->profileModel->updateProfile($profileId, [
                'status' => InfluencerModel::STATUS_PENDING_ADMIN_REVIEW,
                'verification_post_url' => $proofUrl,
            ]);

            // Issue 1 Fix: Update the verification record status to 'submitted'
            $this->verificationModel->updateStatus($verification->id, 'submitted', [
                'submitted_at' => date('Y-m-d H:i:s')
            ]);

            $this->logger->info('verification.proof.submitted', [
                'profile_id' => $profileId,
                'user_id' => $userId,
                'verification_id' => $verification->id
            ]);

            return [
                'ok' => true,
                'message' => 'اثبات ارسال شد. منتظر تایید مدیر باشید.',
                'verification_id' => $verification->id
            ];
        } catch (\Exception $e) {
            $this->logger->error('verification.proof.submission.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در ارسال اثبات'];
        }
    }

    /**
     * Get pending verification requests for admin review
     */
    public function getVerificationRequests(int $limit = 50, int $offset = 0): array
    {
        return $this->verificationModel->getSubmittedRequests($limit, $offset);
    }

    public function countVerificationRequests(): int
    {
        return $this->verificationModel->countSubmittedRequests();
    }

    public function getVerificationById(int $verificationId): ?object
    {
        return $this->verificationModel->findById($verificationId);
    }

    public function getPendingVerificationByProfile(int $profileId): ?object
    {
        return $this->verificationModel->findSubmittedByProfile($profileId);
    }

    /**
     * Admin approves verification
     */
    public function approveVerification(int $verificationId, int $adminId): array
    {
        try {
            $this->db->beginTransaction();

            $verification = $this->verificationModel->findByIdForUpdate($verificationId);
            if (!$verification || $verification->status !== 'submitted') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'تایید معتبر نیست'];
            }

            $profile = $this->profileModel->findProfileForUpdate($verification->profile_id);
            if (!$profile) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'پروفایل یافت نشد'];
            }

            $this->verificationModel->updateStatus($verificationId, 'approved', [
                'approved_at' => date('Y-m-d H:i:s'),
                'approved_by' => $adminId,
            ]);

            $this->profileModel->updateProfile($verification->profile_id, [
                'status' => InfluencerModel::STATUS_VERIFIED,
                'verified_at' => date('Y-m-d H:i:s'),
                'verified_by' => $adminId,
            ]);

            $this->db->commit();

            $this->logger->info('verification.approved', [
                'profile_id' => $verification->profile_id,
                'admin_id' => $adminId,
                'verification_id' => $verificationId
            ]);

            return ['ok' => true, 'message' => 'تایید پذیرفته شد'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('verification.approval.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در تایید'];
        }
    }

    /**
     * Admin rejects verification
     */
    public function rejectVerification(int $verificationId, int $adminId, string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $verification = $this->verificationModel->findByIdForUpdate($verificationId);
            if (!$verification) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'تایید یافت نشد'];
            }

            $profile = $this->profileModel->findProfileForUpdate($verification->profile_id);
            if (!$profile) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'پروفایل یافت نشد'];
            }

            $this->verificationModel->updateStatus($verificationId, 'rejected', [
                'rejected_at' => date('Y-m-d H:i:s'),
                'rejected_by' => $adminId,
                'rejection_reason' => $reason,
            ]);

            $this->profileModel->updateProfile($verification->profile_id, [
                'status' => InfluencerModel::STATUS_PENDING,
                'rejection_reason' => $reason,
            ]);

            $this->db->commit();

            $this->logger->info('verification.rejected', [
                'profile_id' => $verification->profile_id,
                'admin_id' => $adminId,
                'reason' => $reason
            ]);

            return ['ok' => true, 'message' => 'تایید رد شد'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('verification.rejection.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'خطا در رد تایید'];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Verification Status & History
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get verification status for profile
     */
    public function getVerificationStatus(int $profileId): array
    {
        $verification = $this->verificationModel->findLatestByProfile($profileId);

        if (!$verification) {
            return [
                'status' => 'not_started',
                'message' => 'تایید هنوز شروع نشده'
            ];
        }

        return [
            'status' => $verification->status,
            'code' => $verification->status === 'pending' ? $verification->code : null,
            'expires_at' => $verification->expires_at,
            'submitted_at' => $verification->submitted_at,
            'approved_at' => $verification->approved_at,
            'rejection_reason' => $verification->rejection_reason,
            'message' => $this->getStatusMessage($verification->status),
        ];
    }

    /**
     * Get human-readable status message
     */
    private function getStatusMessage(string $status): string
    {
        return match ($status) {
            'pending' => 'منتظر ارسال اثبات',
            'submitted' => 'منتظر تایید مدیر',
            'approved' => 'تایید شده ✓',
            'rejected' => 'رد شده',
            'expired' => 'کد منقضی شده',
            default => 'نامشخص'
        };
    }

    /**
     * Get verification history for profile
     */
    public function getVerificationHistory(int $profileId, int $limit = 10): array
    {
        $records = $this->verificationModel->getHistoryByProfile($profileId, $limit);

        return array_map(function ($record) {
            return [
                'id' => $record['id'],
                'status' => $record['status'],
                'created_at' => $record['created_at'],
                'submitted_at' => $record['submitted_at'],
                'approved_at' => $record['approved_at'],
                'rejection_reason' => $record['rejection_reason'],
            ];
        }, $records);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Cron: Cleanup expired verifications
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Mark expired pending verifications as expired
     * Run hourly via cron
     */
    public function cleanupExpiredVerifications(): int
    {
        $count = $this->verificationModel->cleanupExpiredPending();
        if ($count > 0) {
            $this->logger->info('verification.cleanup', ['expired_count' => $count]);
        }

        return $count;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Validation Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validate proof URL (screenshot)
     */
    private function isValidProofUrl(string $url): bool
    {
        // ✅ Must be a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (empty($host)) {
            return false;
        }

        $allowedDomains = [
            'localhost',
            parse_url(base_url(''), PHP_URL_HOST) ?? '',
            'instagram.com',
            'www.instagram.com',
            'tiktok.com',
            'www.tiktok.com',
            'twitter.com',
            'www.twitter.com',
            'facebook.com',
            'www.facebook.com',
        ];

        if (in_array($host, $allowedDomains, true)) {
            return true;
        }

        return $host === parse_url(base_url(''), PHP_URL_HOST);
    }
}

