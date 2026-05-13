<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Models\SocialTaskExecutionModel;

use App\Contracts\LoggerInterface;
/**
 * CameraVerificationService
 *
 * مدیریت فرآیند Camera Verification.
 */
class CameraVerificationService extends \App\Services\BaseService
{
    // وضعیت‌های یک camera request
    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED   = 'skipped';
    public const STATUS_EXPIRED   = 'expired';

    // حداکثر مدت انتظار برای پاسخ کاربر (ثانیه)
    private const EXPIRY_SECONDS = 120;

    public function __construct(
        private SocialTaskExecutionModel $model,
        private BehaviorAnalysisService $behavior
    ) {}

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
        return $this->model->createCameraRequest([
            'execution_id' => $executionId,
            'user_id' => $userId,
            'expiry' => self::EXPIRY_SECONDS
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

        $this->model->updateCameraRequestResult($request->id, $cameraScore, json_encode($verifiedSignals, JSON_UNESCAPED_UNICODE));

        $contribution = $this->scoreContribution($cameraScore, $verifiedSignals);

        $this->model->updateExecutionBehaviorJson($executionId, $cameraScore, json_encode($verifiedSignals));

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
        $base = 0;
        if ($cameraScore >= 80) $base = 15;
        elseif ($cameraScore >= 60) $base = 8;
        elseif ($cameraScore >= 40) $base = 2;
        else $base = -10;

        $bonus = 0;
        $highValueSignals = ['follow_button_visible', 'username_match', 'subscribe_confirmed', 'like_button_active'];
        foreach ($highValueSignals as $sig) {
            if (in_array($sig, $verifiedSignals, true)) $bonus += 3;
        }

        return $base + min($bonus, 10);
    }

    public function getStats(): object
    {
        return $this->model->getCameraStats() ?: (object)[];
    }
}

