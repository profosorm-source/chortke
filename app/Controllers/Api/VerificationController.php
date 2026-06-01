<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\VerificationService;

/**
 * VerificationController - Influencer verification API endpoints
 */
class VerificationController extends BaseApiController
{
    private VerificationService $verification;

    public function __construct(VerificationService $verification, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->verification = $verification;
    }

    /**
     * Generate verification code
     */
    public function generateCode(): void
    {
        try {
            $userId = $this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
            }

            $profileId = (int)$this->request->post('profile_id');
            if ($profileId <= 0) {
                $this->error('Invalid profile_id', 400);
            }

            // ✅ Verify ownership
            $profile = $this->verification->getUserProfile($profileId, $userId);
            if (!$profile) {
                $this->error('Profile not found or not owned by you', 403);
            }

            // ✅ Generate code
            $result = $this->verification->generateVerificationCode($profileId);
            if (!$result['ok']) {
                $this->error($result['message'] ?? 'Generation failed', 400);
            }

            $this->logger->info('verification.code_generated', [
                'profile_id' => $profileId,
                'user_id'    => $userId
            ]);

            $this->success([
                'code'         => $result['code'],
                'instructions' => 'Post this code in your Instagram/TikTok bio or in the first comment on a recent post',
                'expires_in'   => 86400,
                'platform'     => $profile['platform'] ?? 'instagram'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('verification.generate_code_failed', ['error' => $e->getMessage()]);
            $this->error('Generation failed', 500);
        }
    }

    /**
     * Get verification status
     */
    public function getStatus(): void
    {
        try {
            $userId = $this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
            }

            $profileId = (int)$this->request->query('profile_id');
            if ($profileId <= 0) {
                $this->error('Invalid profile_id', 400);
            }

            // ✅ Verify ownership
            $profile = $this->verification->getUserProfile($profileId, $userId);
            if (!$profile) {
                $this->error('Profile not found or not owned by you', 403);
            }

            // ✅ Get status
            $status = $this->verification->getVerificationStatus($profileId);

            $this->success([
                'status' => $status['status'] ?? 'not_started',
                'msg'    => $status['message'] ?? '',
                'code'   => $status['code'] ?? null,
                'proof'  => $status['proof_url'] ?? null,
                'submitted_at' => $status['submitted_at'] ?? null,
                'rejection_reason' => $status['rejection_reason'] ?? null
            ]);

        } catch (\Exception $e) {
            $this->logger->error('verification.get_status_failed', ['error' => $e->getMessage()]);
            $this->error('Get status failed', 500);
        }
    }

    /**
     * Submit verification proof (screenshot)
     */
    public function submitProof(): void
    {
        try {
            $userId = $this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
            }

            $profileId = (int)$this->request->post('profile_id');
            $proofUrl = trim((string)$this->request->post('proof_url'));

            if ($profileId <= 0 || empty($proofUrl)) {
                $this->error('Invalid parameters', 400);
            }

            // ✅ Verify ownership
            $profile = $this->verification->getUserProfile($profileId, $userId);
            if (!$profile) {
                $this->error('Profile not found or not owned by you', 403);
            }

            // ✅ Submit proof
            $result = $this->verification->submitVerificationProof($profileId, $userId, $proofUrl);
            if (!$result['ok']) {
                $this->error($result['message'] ?? 'Submission failed', 400);
            }

            $this->logger->info('verification.proof_submitted', [
                'profile_id' => $profileId,
                'user_id'    => $userId,
                'proof_url'  => $proofUrl
            ]);

            $this->success([
                'message' => 'Proof submitted successfully, waiting for admin review',
                'status'  => 'submitted'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('verification.submit_proof_failed', ['error' => $e->getMessage()]);
            $this->error('Submission failed', 500);
        }
    }

    /**
     * Get verification history
     */
    public function getHistory(): void
    {
        try {
            $userId = $this->userId();
            if (!$userId) {
                $this->error('Unauthorized', 401);
            }

            $profileId = (int)$this->request->query('profile_id');
            $limit = min((int)($this->request->query('limit') ?? 10), 50);

            if ($profileId <= 0) {
                $this->error('Invalid profile_id', 400);
            }

            // ✅ Verify ownership
            $profile = $this->verification->getUserProfile($profileId, $userId);
            if (!$profile) {
                $this->error('Profile not found or not owned by you', 403);
            }

            // ✅ Get history
            $history = $this->verification->getVerificationHistory($profileId, $limit);

            $this->success([
                'history' => $history,
                'count'   => count($history)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('verification.get_history_failed', ['error' => $e->getMessage()]);
            $this->error('Get history failed', 500);
        }
    }
}
