<?php

namespace App\Controllers\User;

use App\Controllers\User\BaseUserController;
use App\Services\ApiTokenService;

class ApiTokenController extends BaseUserController
{
    private ApiTokenService $apiTokenService;

    public function __construct(
        ApiTokenService $apiTokenService,
        \Core\Session $session,
        \Core\Request $request,
        \Core\Response $response,
        \App\Services\Shared\PolicyService $policyService,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Auth\AuthService $authService,
        \App\Services\User\UserService $userService,
        \App\Services\CaptchaService $captchaService
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger, $authService, $userService, $captchaService);
        $this->apiTokenService = $apiTokenService;
    }

    /** لیست توکن‌های کاربر */
    public function index(): void
    {
        $this->requireAuth();

        $userId = $this->userId();
        $tokens = $this->apiTokenService->listTokensForUser($userId);
        $newToken = $this->session->getFlash('new_api_token');

        $this->view('user.api-tokens.index', [
            'title'    => 'توکن‌های API',
            'tokens'   => $tokens,
            'newToken' => $newToken,
        ]);
    }

    /** ساخت توکن جدید */
    public function create(): void
    {
        $this->requireAuth();
        $this->validateCsrf();

        $userId    = $this->userId();
        $name      = trim($this->request->post('name') ?? '');
        $expiresIn = (int)($this->request->post('expires_in') ?? 30);

        if ($name === '') {
            $this->redirectWithError('نام توکن الزامی است', '/api-tokens');
            return;
        }

        $count = $this->apiTokenService->getActiveTokenCountForUser($userId);
        if ($count >= 10) {
            $this->redirectWithError('حداکثر ۱۰ توکن فعال مجاز است. ابتدا یکی را باطل کنید.', '/api-tokens');
            return;
        }

        $scope = trim($this->request->post('scope') ?? 'read');
        $result = $this->apiTokenService->createTokenForUser($userId, $name, $expiresIn, $scope);
        if (!$result['success']) {
            $this->redirectWithError($result['message'] ?? 'خطا در ایجاد توکن', '/api-tokens');
            return;
        }

        $this->session->setFlash('new_api_token', $result['payload']['token']);
        $this->redirectWithSuccess('توکن با موفقیت ساخته شد', '/api-tokens');
    }

    /** باطل کردن توکن */
    public function revoke(): void
    {
        $this->requireAuth();
        $this->validateCsrf();

        $userId = $this->userId();
        $id = (int)$this->request->param('id');

        $result = $this->apiTokenService->revokeTokenById($userId, $id);
        if ($result['success']) {
            $this->jsonSuccess('توکن باطل شد');
            return;
        }

        $this->jsonError($result['message'] ?? 'توکن یافت نشد', [], $result['status'] ?? 404);
    }
}
