<?php

namespace App\Controllers\User;

use App\Services\Auth\TwoFactorService;
use App\Services\User\UserService;
use App\Models\ActivityLog;
use App\Controllers\User\BaseUserController;

/**
 * Two Factor Authentication Controller
 */
class TwoFactorController extends BaseUserController
{
    private TwoFactorService $twoFactorService;
    private ActivityLog $activityLog;

    public function __construct(
        ActivityLog $activityLog,
        TwoFactorService $twoFactorService
    ) {
        parent::__construct();
        $this->activityLog      = $activityLog;
        $this->twoFactorService = $twoFactorService;
    }

    public function index(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->response->redirect(url('login'));
            return;
        }

        $user = $this->userService->find($userId);
        if (!$user) {
            $this->response->redirect(url('login'));
            return;
        }

        $data = [
            'title'      => 'احراز هویت دو مرحله‌ای',
            'is_enabled' => ($user->two_factor_enabled ?? 0) == 1,
        ];

        if (!$data['is_enabled']) {
            if (empty($user->two_factor_secret)) {
                $secret = $this->twoFactorService->generateSecret();
                $this->userService->update($user->id, ['two_factor_secret' => $secret]);
                $user->two_factor_secret = $secret;
            }

            $data['secret']       = $user->two_factor_secret;
            $data['qr_code_url']  = $this->twoFactorService->getQRCodeUrl(
                $user->username ?? $user->email,
                $user->two_factor_secret
            );
        }

        $this->view('user/security/two-factor', $data);
    }

    public function showVerify(): void
    {
        $userId = $this->session->get('pending_2fa_user');
        if (!$userId) {
            $this->response->redirect(url('login'));
            return;
        }

        $this->view('user/security/verify-2fa', [
            'title' => 'تأیید هویت دو مرحله‌ای',
        ]);
    }

    public function verify(): void
    {
        $userId = $this->session->get('pending_2fa_user');
        if (!$userId) {
            if ($this->request->isAjax()) {
                $this->jsonError('نشست نامعتبر است.', [], 401);
                return;
            }
            $this->response->redirect(url('login'));
            return;
        }

        $code = trim((string)($this->request->input('code') ?? ''));
        if ($code === '') {
            $this->response->json(['success' => false, 'message' => 'لطفاً کد را وارد کنید.']);
            return;
        }

        $user = $this->userService->find((int)$userId);
        if (!$user || empty($user->two_factor_secret)) {
            $this->response->json(['success' => false, 'message' => 'خطا در احراز هویت.']);
            return;
        }

        if ($this->twoFactorService->verifyCode($user->two_factor_secret, $code)) {
            $this->session->remove('pending_2fa_user');
            
            // اصلاح لاجیک سشن برای مدیریت دسترسی و جلوگیری از خطای میدلویر
            $this->session->set('user_id',   $user->id);
            $this->session->set('username',  $user->username  ?? '');
            $this->session->set('email',     $user->email);
            $this->session->set('role',      $user->role);
            $this->session->set('user_role', $user->role); // متغیر کلیدی اضافه شد
            $this->session->set('is_admin',  in_array($user->role, ['admin', 'super_admin'], true));
            $this->session->set('logged_in', true);
            $this->session->regenerate();

            $this->logger->activity('2fa.verified', 'تأیید موفق احراز هویت دو مرحله‌ای', $user->id, [
                'channel' => 'auth',
            ]);
            
            $this->response->json([
                'success'  => true,
                'message'  => 'ورود موفقیت‌آمیز بود.',
                'redirect' => url('dashboard'),
            ]);
            return;
        }

        $this->userService->incrementFraudScore((int)$userId, 5);
        $this->response->json(['success' => false, 'message' => 'کد وارد شده نامعتبر است.']);
    }

    public function enable(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->jsonError('لطفاً وارد شوید.', [], 401);
            return;
        }

        $code = trim((string)($this->request->input('code') ?? ''));
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            $this->response->json(['success' => false, 'message' => 'لطفاً کد ۶ رقمی را وارد کنید.']);
            return;
        }

        $result = $this->twoFactorService->enable($userId, $code);

        if ($result['success']) {
            $this->logger->activity('2fa.enabled', 'فعال‌سازی احراز هویت دو مرحله‌ای', $userId, [
                'channel' => 'auth',
            ]);
        }

        $this->response->json($result);
    }

    public function disable(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->jsonError('لطفاً وارد شوید.', [], 401);
            return;
        }

        $password = (string)($this->request->input('password') ?? '');
        if ($password === '') {
            $this->response->json(['success' => false, 'message' => 'لطفاً رمز عبور خود را وارد کنید.']);
            return;
        }

        $result = $this->twoFactorService->disable($userId, $password);

        if ($result['success']) {
            $this->logger->activity('2fa.disabled', 'غیرفعال‌سازی احراز هویت دو مرحله‌ای', $userId, [
                'channel' => 'auth',
            ]);
        }

        $this->response->json($result);
    }
}