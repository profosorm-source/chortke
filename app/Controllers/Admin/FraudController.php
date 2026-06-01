<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Core\Request;
use Core\Response;
use App\Services\AntiFraud\FraudDetectionService;

/**
 * FraudController - مدیریت سیستم تشخیص تقلب
 */
class FraudController extends BaseAdminController
{
    private FraudDetectionService $fraudService;

    public function __construct(FraudDetectionService $fraudService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->fraudService = $fraudService;
    }

    /**
     * گرفتن گزارش ریسک کاربر
     */
    public function getRiskReport(Request $request): Response
    {
        $userId = (int) $request->get('user_id');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        try {
            $report = $this->fraudService->getRiskReport($userId);

            return Response::json([
                'success' => true,
                'data' => $report
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to generate risk report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * محاسبه مجدد امتیاز تقلب کاربر
     */
    public function recalculateScore(Request $request): Response
    {
        $userId = (int) $request->post('user_id');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        try {
            $score = $this->fraudService->calculateFraudScore($userId);

            return Response::json([
                'success' => true,
                'data' => [
                    'user_id' => $userId,
                    'fraud_score' => $score
                ]
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to recalculate fraud score: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * اجرای اقدامات خودکار بر اساس امتیاز
     */
    public function executeActions(Request $request): Response
    {
        $userId = (int) $request->post('user_id');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        try {
            $actions = $this->fraudService->executeAutomatedActions($userId);

            return Response::json([
                'success' => true,
                'data' => [
                    'user_id' => $userId,
                    'executed_actions' => $actions
                ]
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to execute automated actions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * گرفتن لیست کاربران پر ریسک
     */
    public function getHighRiskUsers(Request $request): Response
    {
        $minScore = (int) ($request->get('min_score') ?? 50);
        $limit = (int) ($request->get('limit') ?? 50);

        try {
            $users = $this->fraudService->getHighRiskUsers($minScore, $limit);

            return Response::json([
                'success' => true,
                'data' => [
                    'users' => $users,
                    'count' => count($users),
                    'min_score' => $minScore
                ]
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to fetch high risk users: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * گرفتن لاگ‌های تقلب
     */
    public function getFraudLogs(Request $request): Response
    {
        $userId = $request->get('user_id') ? (int) $request->get('user_id') : null;
        $fraudType = $request->get('fraud_type');
        $limit = (int) ($request->get('limit') ?? 100);

        try {
            $logs = $this->fraudService->getFraudLogs($userId, $fraudType, $limit);

            return Response::json([
                'success' => true,
                'data' => [
                    'logs' => $logs,
                    'count' => count($logs)
                ]
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to fetch fraud logs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * پاک کردن پرچم‌های بررسی
     */
    public function clearFlags(Request $request): Response
    {
        $userId = (int) $request->post('user_id');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        try {
            $this->fraudService->clearUserFlags($userId);

            return Response::json([
                'success' => true,
                'message' => 'Fraud flags cleared successfully'
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to clear flags: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * تعلیق دستی حساب
     */
    public function suspendUser(Request $request): Response
    {
        $userId = (int) $request->post('user_id');
        $reason = trim($request->post('reason') ?? '');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        if (!$reason) {
            return Response::json([
                'success' => false,
                'message' => 'Suspension reason is required'
            ], 400);
        }

        try {
            $this->fraudService->suspendUser($userId, $reason);

            return Response::json([
                'success' => true,
                'message' => 'User suspended successfully'
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to suspend user: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * رفع تعلیق حساب
     */
    public function unsuspendUser(Request $request): Response
    {
        $userId = (int) $request->post('user_id');

        if (!$userId) {
            return Response::json([
                'success' => false,
                'message' => 'User ID is required'
            ], 400);
        }

        try {
            $this->fraudService->unsuspendUser($userId);

            return Response::json([
                'success' => true,
                'message' => 'User unsuspended successfully'
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to unsuspend user: ' . $e->getMessage()
            ], 500);
        }
    }
}