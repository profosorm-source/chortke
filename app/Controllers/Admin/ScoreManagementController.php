<?php

namespace App\Controllers\Admin;

use App\Services\ScoreService;

class ScoreManagementController
{
    private ScoreService $scoreService;

    public function __construct(ScoreService $scoreService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        $this->scoreService = $scoreService;
    }

    public function showUserScores($id): void
    {
        $this->ensureAdmin();
        $userId = (int)$id;

        $user = $this->scoreService->getUserForScoreManagement($userId);
        if (!$user) {
            http_response_code(404);
            exit('User not found');
        }

        $fraudRaw = (float)($user->fraud_score ?? 0);
        $fraudEffective = $this->scoreService->getEffectiveScore($userId, 'fraud', $fraudRaw);

        $taskRaw = $this->scoreService->getTaskRawRisk($userId);
        $taskEffective = $this->scoreService->getEffectiveScore($userId, 'task', $taskRaw);

        $fraudAdjustments = $this->scoreService->getActiveAdjustments($userId, 'fraud');
        $taskAdjustments = $this->scoreService->getActiveAdjustments($userId, 'task');

        $recentEvents = $this->scoreService->getRecentScoreEvents($userId, 50);

        $this->render('admin/fraud/user-scores', [
            'user' => (array)$user,
            'fraud_raw' => $fraudRaw,
            'fraud_effective' => $fraudEffective,
            'task_raw' => $taskRaw,
            'task_effective' => $taskEffective,
            'fraud_adjustments' => $fraudAdjustments,
            'task_adjustments' => $taskAdjustments,
            'events' => $recentEvents,
        ]);
    }

    public function adjustScore($id): void
    {
        $this->ensureAdmin();

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/admin/users/' . (int)$id . '/scores');
        }

        $userId = (int)$id;
        $domain = trim((string)($_POST['domain'] ?? 'fraud'));
        $operation = strtolower(trim((string)($_POST['operation'] ?? 'add')));
        $value = (float)($_POST['value'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $expiresAt = trim((string)($_POST['expires_at'] ?? ''));

        if (!in_array($domain, ['fraud', 'task'], true)) {
            $this->flash('error', 'دامنه امتیاز نامعتبر است.');
            $this->redirect('/admin/users/' . $userId . '/scores');
        }

        if (!in_array($operation, ['set', 'add', 'subtract'], true)) {
            $this->flash('error', 'عملیات نامعتبر است.');
            $this->redirect('/admin/users/' . $userId . '/scores');
        }

        if ($reason === '') {
            $this->flash('error', 'ثبت دلیل برای اصلاح امتیاز الزامی است.');
            $this->redirect('/admin/users/' . $userId . '/scores');
        }

        $adminId = $this->currentAdminId();

        $result = $this->scoreService->createAdjustment(
            $userId,
            $domain,
            $operation,
            $value,
            $reason,
            ($expiresAt !== '' ? $expiresAt : null),
            $adminId
        );

        if ($result['success']) {
            $this->flash('success', $result['message']);
        } else {
            $this->flash('error', $result['message']);
        }

        $this->redirect('/admin/users/' . $userId . '/scores');
    }

    public function revokeAdjustment($id): void
    {
        $this->ensureAdmin();

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/admin/dashboard');
        }

        $adjustmentId = (int)$id;
        $reason = trim((string)($_POST['reason'] ?? 'revoke_by_admin'));
        $adminId = $this->currentAdminId();

        $success = $this->scoreService->revokeScoreAdjustment($adjustmentId, $adminId, $reason);

        if ($success) {
            $this->flash('success', 'اصلاح امتیاز غیرفعال شد.');
            $this->redirect('/admin/users/' . $adjustmentId . '/scores');
        } else {
            $this->flash('error', 'رکورد اصلاح امتیاز یافت نشد.');
            $this->redirect('/admin/dashboard');
        }
    }

    public function history($id): void
    {
        $this->ensureAdmin();
        $userId = (int)$id;

        $user = $this->scoreService->getUserForScoreManagement($userId);
        if (!$user) {
            http_response_code(404);
            exit('User not found');
        }

        $events = $this->scoreService->getRecentScoreEvents($userId, 200);

        // اگر ویوی تاریخچه جدا نداری، همین view اصلی را با events بیشتر باز کن
        $this->render('admin/fraud/user-scores', [
            'user' => (array)$user,
            'fraud_raw' => (float)($user->fraud_score ?? 0),
            'fraud_effective' => $this->scoreService->getEffectiveScore($userId, 'fraud', (float)($user->fraud_score ?? 0)),
            'task_raw' => $this->scoreService->getTaskRawRisk($userId),
            'task_effective' => $this->scoreService->getEffectiveScore($userId, 'task', $this->scoreService->getTaskRawRisk($userId)),
            'fraud_adjustments' => $this->scoreService->getActiveAdjustments($userId, 'fraud'),
            'task_adjustments' => $this->scoreService->getActiveAdjustments($userId, 'task'),
            'events' => $events,
        ]);
    }


    private function ensureAdmin(): void
    {
        if (method_exists($this, 'requireAdmin')) {
            $this->requireAdmin();
            return;
        }

        $role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? null;
        if (!in_array($role, ['admin', 'super_admin', 'support'], true)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    private function currentAdminId(): ?int
    {
        $id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
        return $id ? (int)$id : null;
    }

    private function render(string $viewPath, array $data = []): void
    {
        if (function_exists('view')) {
            echo view($viewPath, $data);
            return;
        }

        extract($data, EXTR_SKIP);
        $full = dirname(__DIR__, 3) . '/views/' . $viewPath . '.php';
        if (is_file($full)) {
            include $full;
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function redirect(string $url): void
    {
        if (function_exists('redirect')) {
            redirect($url);
            return;
        }
        header('Location: ' . $url);
        exit;
    }

    private function flash(string $type, string $message): void
    {
        \Core\Session::set('flash.' . $type, $message);
    }
}