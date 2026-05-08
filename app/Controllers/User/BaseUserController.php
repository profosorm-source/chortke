<?php

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\CaptchaService;
use App\Services\Auth\AuthService;
use App\Services\User\UserService;

/**
 * BaseUserController — پایه تمام کنترلرهای پنل کاربر
 *
 * ─── سلسله مراتب ────────────────────────────────────────────────
 *
 *   Container::make(SomeUserController)
 *       └─→ SomeController::__construct(...services)
 *               └─→ parent::__construct()   ← بدون پارامتر
 *                       └─→ BaseController::__construct()
 *                               └─→ از Container: Request, Response, Session
 *
 * ─── قرارداد ───────────────────────────────────────────────────
 *   AuthService / UserService / CaptchaService از Container گرفته می‌شوند
 *   (نه از پارامتر constructor — چون همه فرزندها parent() بدون آرگومان صدا می‌زنند)
 */
abstract class BaseUserController extends BaseController
{
    protected AuthService    $authService;
    protected UserService    $userService;
    protected CaptchaService $captchaService;

    /**
     * وابستگی‌ها از طریق constructor تزریق می‌شوند
     */
    public function __construct(
        ?\Core\Session $session = null,
        ?\Core\Request $request = null,
        ?\Core\Response $response = null,
        ?\App\Services\Shared\PolicyService $policyService = null,
        ?\App\Contracts\LoggerInterface $logger = null,
        ?AuthService $authService = null,
        ?UserService $userService = null,
        ?CaptchaService $captchaService = null
    ) {
        $container = \Core\Container::getInstance();
        
        $this->session = $session ?? $container->make(\Core\Session::class);
        $this->request = $request ?? $container->make(\Core\Request::class);
        $this->response = $response ?? $container->make(\Core\Response::class);
        $this->policyService = $policyService ?? $container->make(\App\Services\Shared\PolicyService::class);
        $this->logger = $logger ?? $container->make(\App\Contracts\LoggerInterface::class);
        
        $this->authService = $authService ?? $container->make(AuthService::class);
        $this->userService = $userService ?? $container->make(UserService::class);
        $this->captchaService = $captchaService ?? $container->make(CaptchaService::class);
    }

    /** user_id کاربر لاگین‌شده یا null */
    protected function userId(): ?int
    {
        $id = $this->session->get('user_id');
        return $id ? (int) $id : null;
    }

    /** اگر لاگین نباشد → redirect به login */
    protected function requireAuth(): void
    {
        if (!$this->userId()) {
            if (function_exists('is_ajax') && is_ajax()) {
                $this->response->error('احراز هویت لازم است', [], 401);
                exit;
            }
            $this->session->setFlash('error', 'ابتدا وارد حساب کاربری خود شوید.');
            $this->response->redirect(url('login'));
            exit;
        }
    }
}
