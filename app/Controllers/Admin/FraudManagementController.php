<?php

namespace App\Controllers\Admin;

use App\Services\AntiFraud\FraudManagementService;
use App\Services\ScoreService;
use Core\Request;
use Core\Response;
use InvalidArgumentException;

class FraudManagementController extends BaseAdminController
{
    private FraudManagementService $fraudManagementService;
    private ScoreService $scoreService;

    public function __construct(
        FraudManagementService $fraudManagementService,
        ScoreService $scoreService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->fraudManagementService = $fraudManagementService;
        $this->scoreService = $scoreService;
    }

    public function ipBlacklist(Request $request, Response $response)
    {
        $ips = $this->fraudManagementService->getIpBlacklist();
        return view('admin/fraud/ip-blacklist', ['ips' => $ips]);
    }

    public function blockIP(Request $request, Response $response)
    {
        $ip = (string) $request->input('ip');
        $reason = (string) $request->input('reason', 'مسدود شده توسط ادمین');
        $duration = $request->input('duration');
        $adminId = (int) $this->session->get('user_id');

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->session->setFlash('error', 'IP نامعتبر است');
            return $response->redirect(url('/admin/fraud/ip-blacklist'));
        }

        $this->fraudManagementService->blockIp(
            $ip,
            $reason,
            $duration !== null ? (int) $duration : null
        );

        $this->auditLog(
            'ip_blocked',
            'ip_block',
            0,
            null,
            ['ip' => $ip, 'reason' => $reason]
        );
        $this->session->setFlash('success', 'IP با موفقیت مسدود شد');
        return $response->redirect(url('/admin/fraud/ip-blacklist'));
    }

    public function unblockIP(Request $request, Response $response)
    {
        $id = (int) $request->input('id');

        $this->fraudManagementService->deleteIpBlacklistEntry($id);

        $adminId = (int) $this->session->get('user_id');
        $this->auditLog(
            'ip_unblocked',
            'ip_block',
            0,
            ['ip' => $ip],
            ['ip' => null]
        );
        $this->session->setFlash('success', 'مسدودیت IP برداشته شد');
        return $response->redirect(url('/admin/fraud/ip-blacklist'));
    }

    public function deviceBlacklist(Request $request, Response $response)
    {
        $devices = $this->fraudManagementService->getDeviceBlacklist();
        return view('admin/fraud/device-blacklist', ['devices' => $devices]);
    }

    public function blockDevice(Request $request, Response $response)
    {
        $fingerprint = (string) $request->input('fingerprint');
        $reason = (string) $request->input('reason', 'مسدود شده توسط ادمین');
        $adminId = (int) $this->session->get('user_id');

        $this->fraudManagementService->blockDevice($fingerprint, $reason, null);

        $this->auditLog(
            'device_blocked',
            'device_block',
            0,
            null,
            ['device_id' => $deviceId, 'reason' => $reason]
        );
        $this->session->setFlash('success', 'دستگاه با موفقیت مسدود شد');
        return $response->redirect(url('/admin/fraud/device-blacklist'));
    }

    public function unblockDevice(Request $request, Response $response)
    {
        $id = (int) $request->input('id');

        $this->fraudManagementService->deleteDeviceBlacklistEntry($id);

        $adminId = (int) $this->session->get('user_id');
        $this->auditLog(
            'device_unblocked',
            'device_block',
            0,
            ['device_id' => $deviceId],
            ['device_id' => null]
        );
        $this->session->setFlash('success', 'مسدودیت دستگاه برداشته شد');
        return $response->redirect(url('/admin/fraud/device-blacklist'));
    }

    public function fraudLogs(Request $request, Response $response)
    {
        $page = (int) $request->get('page', 1);
        $perPage = 50;

        $result = $this->fraudManagementService->getFraudLogs($page, $perPage);

        return view('admin/fraud/logs', [
            'logs' => $result['logs'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    /**
     * Instead of hard reset, create auditable set adjustment.
     */
    public function resetFraudScore(Request $request, Response $response)
    {
        $adminId = (int) $this->session->get('user_id');
        $userId = (int) $request->input('user_id');
        $reason = (string) $request->input('reason', 'Reset by admin');

        try {
            $ok = $this->scoreService->adjust(
                $adminId,
                $userId,
                'fraud',
                'set',
                0,
                $reason,
                null
            );

            if ($ok) {
                $this->auditLog(
                    'fraud_score_reset',
                    'user_fraud_score',
                    $userId,
                    ['old_score' => 'unknown'],
                    ['new_score' => 0, 'reason' => $reason]
                );
                $this->session->setFlash('success', 'Fraud score با adjustment ریست شد.');
            } else {
                $this->session->setFlash('error', 'ریست Fraud score ناموفق بود.');
            }
        } catch (InvalidArgumentException $e) {
            $this->session->setFlash('error', $e->getMessage());
        }

        return $response->back();
    }
}