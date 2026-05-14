<?php

namespace App\Controllers\User;

use App\Services\User\UserSettingsService;
use App\Services\Auth\AuthService;
use App\Services\User\UserService;
use App\Services\CaptchaService;

/**
 * SettingsController � ?????? ??????? ??????? ?????
 */
class SettingsController extends BaseUserController
{
    private UserSettingsService $settingsService;

    public function __construct(
        UserSettingsService $settingsService,
        \Core\Session $session,
        \Core\Request $request,
        \Core\Response $response,
        \App\Services\Shared\PolicyService $policyService,
        \App\Contracts\LoggerInterface $logger,
        AuthService $authService,
        UserService $userService,
        CaptchaService $captchaService
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger, $authService, $userService, $captchaService);
        $this->settingsService = $settingsService;
    }

    /**
     * ???? ??????? ?????
     */
    public function general(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();
            $settings = $this->settingsService->getAll($userId);

            $this->view('user/settings/general', [
                'title' => '??????? ?????',
                'settings' => $settings,
                'timezones' => timezone_identifiers_list(),
                'themes' => [
                    'light' => '????',
                    'dark' => '?????',
                    'auto' => '??????',
                ],
                'languages' => [
                    'fa' => '?????',
                    'en' => 'English',
                ],
                'date_formats' => [
                    'jalali' => '????? ?????',
                    'gregorian' => '????? ??????',
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.general.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '??? ?? ???????? ???????');
            $this->response->redirect(url('/dashboard'));
        }
    }

    /**
     * ????????? ??????? ?????
     */
    public function updateGeneral(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();

            $settings = [
                'language' => $this->request->post('language') ?? 'fa',
                'timezone' => $this->request->post('timezone') ?? 'Asia/Tehran',
                'theme' => $this->request->post('theme') ?? 'light',
                'date_format' => $this->request->post('date_format') ?? 'jalali',
                'items_per_page' => (int)($this->request->post('items_per_page') ?? 20),
            ];

            if ($this->settingsService->setMultiple($userId, $settings)) {
                $this->session->setFlash('success', '??????? ????? ??');
                $this->logger->info('settings.general.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', '??? ?? ????? ???????');
            }

            $this->response->redirect(url('/settings/general'));
        } catch (\Exception $e) {
            $this->logger->error('settings.general.update.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '???? ????? ????');
            $this->response->redirect(url('/settings/general'));
        }
    }

    /**
     * ???? ??????? ???? ?????
     */
    public function privacy(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();
            $settings = $this->settingsService->getAll($userId);

            $this->view('user/settings/privacy', [
                'title' => '??????? ???? ?????',
                'settings' => $settings,
                'visibility_options' => [
                    'public' => '?????',
                    'friends' => '??? ??????',
                    'private' => '?????',
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.privacy.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '??? ?? ???????? ???????');
            $this->response->redirect(url('/dashboard'));
        }
    }

    /**
     * ????????? ??????? ???? ?????
     */
    public function updatePrivacy(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();

            $settings = [
                'profile_visibility' => $this->request->post('profile_visibility') ?? 'public',
                'show_online_status' => (bool)$this->request->post('show_online_status'),
                'show_activity' => (bool)$this->request->post('show_activity'),
                'allow_messages' => (bool)$this->request->post('allow_messages'),
                'allow_friend_requests' => (bool)$this->request->post('allow_friend_requests'),
            ];

            if ($this->settingsService->setMultiple($userId, $settings)) {
                $this->session->setFlash('success', '??????? ????? ??');
                $this->logger->info('settings.privacy.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', '??? ?? ????? ???????');
            }

            $this->response->redirect(url('/settings/privacy'));
        } catch (\Exception $e) {
            $this->logger->error('settings.privacy.update.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '???? ????? ????');
            $this->response->redirect(url('/settings/privacy'));
        }
    }

    /**
     * ???? ??????? ??????
     */
    public function security(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();
            $settings = $this->settingsService->getAll($userId);
            $user = $this->userService->findById($userId);

            $this->view('user/settings/security', [
                'title' => '??????? ??????',
                'settings' => $settings,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.security.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '??? ?? ???????? ???????');
            $this->response->redirect(url('/dashboard'));
        }
    }

    /**
     * ????????? ??????? ??????
     */
    public function updateSecurity(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();

            $settings = [
                'login_alerts' => (bool)$this->request->post('login_alerts'),
                'suspicious_activity_alerts' => (bool)$this->request->post('suspicious_activity_alerts'),
                'session_timeout' => (int)($this->request->post('session_timeout') ?? 30),
            ];

            if ($this->settingsService->setMultiple($userId, $settings)) {
                $this->session->setFlash('success', '??????? ?????? ????????? ??');
                $this->logger->info('settings.security.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', '??? ?? ????? ???????');
            }

            $this->response->redirect(url('/settings/security'));
        } catch (\Exception $e) {
            $this->logger->error('settings.security.update.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '???? ????? ????');
            $this->response->redirect(url('/settings/security'));
        }
    }

    /**
     * ???? ??????? ???????
     */
    public function notifications(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();
            $settings = $this->settingsService->getAll($userId);

            $this->view('user/settings/notifications', [
                'title' => '??????? ???????',
                'settings' => $settings,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.notifications.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '??? ?? ???????? ???????');
            $this->response->redirect(url('/dashboard'));
        }
    }

    /**
     * ????????? ??????? ???????
     */
    public function updateNotifications(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();

            $settings = [
                'email_notifications' => (bool)$this->request->post('email_notifications'),
                'push_notifications' => (bool)$this->request->post('push_notifications'),
                'sms_notifications' => (bool)$this->request->post('sms_notifications'),
                'marketing_emails' => (bool)$this->request->post('marketing_emails'),
            ];

            if ($this->settingsService->setMultiple($userId, $settings)) {
                $this->session->setFlash('success', '??????? ??????? ????? ??');
                $this->logger->info('settings.notifications.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', '??? ?? ????? ???????');
            }

            $this->response->redirect(url('/settings/notifications'));
        } catch (\Exception $e) {
            $this->logger->error('settings.notifications.update.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '???? ????? ????');
            $this->response->redirect(url('/settings/notifications'));
        }
    }

    /**
     * ???? ???? ???? ??????
     */
    public function dataExport(): void
    {
        $this->requireAuth();

        try {
            $this->view('user/settings/data-export', [
                'title' => '???? ???? ??????? ??',
                'export_formats' => [
                    'json' => 'JSON',
                    'csv' => 'CSV',
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.data_export.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '??? ?? ???????? ????');
            $this->response->redirect(url('/dashboard'));
        }
    }

    /**
     * ??? ???? ??????
     */
    public function accountDeletion(): void
    {
        $this->requireAuth();

        try {
            $this->view('user/settings/account-deletion', [
                'title' => '??? ???? ??????',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.account_deletion.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '??? ?? ???????? ????');
            $this->response->redirect(url('/dashboard'));
        }
    }

    /**
     * ??????? ??? ???? ??????
     */
    public function requestAccountDeletion(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();
            $password = $this->request->post('password') ?? '';

            if (empty($password)) {
                $this->session->setFlash('error', '??????? ?????? ???');
                $this->response->redirect(url('/settings/account-deletion'));
                return;
            }

            $result = $this->settingsService->requestAccountDeletion($userId, $password);
            if ($result['ok']) {
                $this->session->setFlash('success', '??????? ??? ??? ??. ???? ??? ?? 7 ??? ??? ????? ??');
                $this->logger->warning('settings.account_deletion_requested', ['user_id' => $userId]);
                $this->response->redirect(url('/dashboard'));
            } else {
                $this->session->setFlash('error', $result['message'] ?? '??? ?? ??????? ??? ????');
                $this->response->redirect(url('/settings/account-deletion'));
            }
        } catch (\Exception $e) {
            $this->logger->error('settings.account_deletion_request.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '???? ????? ????');
            $this->response->redirect(url('/settings/account-deletion'));
        }
    }

    /**
     * ??? ??????? ??? ???? ??????
     */
    public function cancelAccountDeletion(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();

            if ($this->settingsService->cancelAccountDeletion($userId)) {
                $this->session->setFlash('success', '??????? ??? ???? ??? ??');
                $this->logger->info('settings.account_deletion_cancelled', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', '??? ?? ??? ???????');
            }

            $this->response->redirect(url('/settings/account-deletion'));
        } catch (\Exception $e) {
            $this->logger->error('settings.account_deletion_cancel.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '???? ????? ????');
            $this->response->redirect(url('/settings/account-deletion'));
        }
    }
}
