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
     * وابستگی‌ها از طریق Constructor Dependency Injection
     * Container خودکار این dependencies را resolve می‌کند (Auto-wiring)
     * 
     * توجه: اگر parameters نادیده گرفته شوند، Container خودش resolve می‌کند
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
        // اگر parent parameters null باشند، Container آنها را resolve می‌کند
        parent::__construct(
            $session ?? $this->resolveFromContainer(\Core\Session::class),
            $request ?? $this->resolveFromContainer(\Core\Request::class),
            $response ?? $this->resolveFromContainer(\Core\Response::class),
            $policyService ?? $this->resolveFromContainer(\App\Services\Shared\PolicyService::class),
            $logger ?? $this->resolveFromContainer(\App\Contracts\LoggerInterface::class)
        );
        
        $this->authService = $authService ?? $this->resolveFromContainer(AuthService::class);
        $this->userService = $userService ?? $this->resolveFromContainer(UserService::class);
        $this->captchaService = $captchaService ?? $this->resolveFromContainer(CaptchaService::class);
    }
    
    /**
     * Helper method برای resolve کردن dependencies از Container
     */
    protected function resolveFromContainer(string $class): object
    {
        return \Core\Container::getInstance()->make($class);
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
