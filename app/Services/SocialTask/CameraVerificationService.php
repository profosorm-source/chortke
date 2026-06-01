<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Models\SocialTaskExecutionModel;

use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;

/**
 * CameraVerificationService
 *
 * مدیریت فرآیند Camera Verification.
 */
class CameraVerificationService
{
    // وضعیت‌های یک camera request
    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED   = 'skipped';
    public const STATUS_EXPIRED   = 'expired';

    private \App\Contracts\LoggerInterface $logger;
    private SocialTaskExecutionModel $model;
    private BehaviorAnalysisService $behavior;
    private SocialTaskScoringService $scoring;
    private AppSettings $appSettings;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        SocialTaskExecutionModel $model,
        BehaviorAnalysisService $behavior,
        SocialTaskScoringService $scoring,
        AppSettings $appSettings
    ) {        $this->logger = $logger;
        $this->model = $model;
        $this->behavior = $behavior;
        $this->scoring = $scoring;
        $this->appSettings = $appSettings;

            }

    /**
     * بررسی اینکه آیا این execution نیاز به camera verification دارد.
     */
    public function isRequired(int $executionId, float $currentScore, array $behaviorSignals): bool
    {
        $existing = $this->model->getCameraRequest($executionId, ['completed', 'pending']);
        if ($existing) return false;

        $patterns = $this->behavior->detectPatterns($behaviorSignals);
        return $this->behavior->needsCameraVerification($currentScore, $patterns);
    }

    /**
     * ثبت یک camera request جدید در DB
     */
    public function createRequest(int $executionId, int $userId): int
    {
        // LOW-10: Fetch configurable dynamic duration boundaries from SettingService instead of static limits
        $expiry = (int)$this->appSettings->get('camera_verification_expiry', 120);

        return $this->model->createCameraRequest([
            'execution_id' => $executionId,
            'user_id'      => $userId,
            'expiry'       => $expiry
        ]);
    }

    public function getPendingRequest(int $executionId): ?object
    {
        return $this->model->getPendingCameraRequest($executionId);
    }

    /**
     * دریافت نتیجه ML محلی از موبایل و تبدیل به signal.
     */
    public function processResult(
        int   $executionId,
        int   $userId,
        int   $cameraScore,
        array $verifiedSignals = []
    ): array {
        $request = $this->model->getCameraRequestForUser($executionId, $userId);

        if (!$request) {
            return ['success' => false, 'message' => 'درخواست camera یافت نشد یا منقضی شده'];
        }

        // MED-15: Prevent silent serialization failures polluting DB entries
        $encodedSignals = \json_encode($verifiedSignals, JSON_UNESCAPED_UNICODE);
        if ($encodedSignals === false) {
            $this->logger->error('camera.process_result.json_encode_failed', [
                'user_id' => $userId,
                'execution_id' => $executionId
            ]);
            $encodedSignals = '[]';
        }

        $this->model->updateCameraRequestResult($request->id, $cameraScore, $encodedSignals);

        $contribution = $this->scoreContribution($cameraScore, $verifiedSignals);

        $this->model->updateExecutionBehaviorJson($executionId, $cameraScore, $encodedSignals);

        return [
            'success'            => true,
            'camera_score'       => $cameraScore,
            'score_contribution' => $contribution,
            'verified_signals'   => $verifiedSignals,
            'signal'             => [
                'camera_score'   => $cameraScore,
                'camera_signals' => $verifiedSignals,
                'camera_verified'=> true,
            ],
        ];
    }

    public function expireRequest(int $executionId): void
    {
        $this->model->expireCameraRequests($executionId);
    }

    /**
     * تبدیل camera score به contribution برای task score
     */
    public function scoreContribution(int $cameraScore, array $verifiedSignals = []): int
    {
        // MED-14: DRY Principle resolved by delegating scoring logic to SocialTaskScoringService
        return $this->scoring->calculateCameraContribution($cameraScore, $verifiedSignals);
    }

    public function getStats(): object
    {
        return $this->model->getCameraStats() ?: (object)[];
    }
}

