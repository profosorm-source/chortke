<?php

namespace App\Controllers;

use Core\Container;
use Core\Session;
use Core\Request;
use Core\Response;
use App\Services\Shared\PolicyService;
use App\Policies\RolePolicy;
use App\Contracts\LoggerInterface;

/**
 * BaseController — پایه تمام کنترلرهای پروژه
 *
 * ─── جریان صحیح (تعریف‌شده) ───────────────────────────────────
 *
 *   Container::make(UserController)
 *       └─→ UserController::__construct()          ← هیچ پارامتری لازم نیست
 *               └─→ BaseController::__construct()
 *                       └─→ از Container: Request, Response, Session
 *
 * ─── قرارداد ───────────────────────────────────────────────────
 *   $this->request   → Core\Request   (singleton از Container)
 *   $this->response  → Core\Response  (singleton از Container)
 *   $this->session   → Core\Session   (singleton از Container)
 *
 * ─── تذکر مهم ──────────────────────────────────────────────────
 *   هیچ کنترلری نباید مستقیم از Database یا Model استفاده کند.
 *   وابستگی‌ها باید از طریق Service به Controller تزریق شوند.
 */
abstract class BaseController
{
    use \App\Traits\UsesValidatorFactory;
    
    protected Session  $session;
    protected Request  $request;
    protected Response $response;
    protected PolicyService $policyService;
    protected LoggerInterface $logger;
    protected \Core\CSRF $csrf;

    /**
     * وابستگی‌ها از طریق Constructor Dependency Injection
     * Container خودکار این dependencies را resolve می‌کند (Auto-wiring)
     * 
     * توجه: اگر parameters null باشند، resolveFromContainer استفاده می‌شود
     */
    public function __construct(
        ?Session $session = null,
        ?Request $request = null,
        ?Response $response = null,
        ?PolicyService $policyService = null,
        ?LoggerInterface $logger = null,
        ?\Core\CSRF $csrf = null
    ) {
        $this->session = $session ?? $this->resolveFromContainer(\Core\Session::class);
        $this->request = $request ?? $this->resolveFromContainer(\Core\Request::class);
        $this->response = $response ?? $this->resolveFromContainer(\Core\Response::class);
        $this->policyService = $policyService ?? $this->resolveFromContainer(\App\Services\Shared\PolicyService::class);
        $this->logger = $logger ?? $this->resolveFromContainer(\App\Contracts\LoggerInterface::class);
        $this->csrf = $csrf ?? $this->resolveFromContainer(\Core\CSRF::class);
    }
    
    /**
     * اعتبارسنجی توکن CSRF
     */
    protected function validateCsrf(): void
    {
        $this->csrf->validate();
    }
    
    /**
     * Helper method برای resolve کردن dependencies از Container
     * استفاده می‌شود زمانی که parameters null باشند
     */
    protected function resolveFromContainer(string $class): object
    {
        return \Core\Container::getInstance()->make($class);
    }

    // ─────────────────────────────────────────────────────────────
    // Auth Helpers
    // ─────────────────────────────────────────────────────────────

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
            if (is_ajax()) {
                $this->response->error('احراز هویت لازم است', [], 401);
                exit;
            }
            $this->session->setFlash('error', 'ابتدا وارد حساب کاربری خود شوید.');
            $this->response->redirect(url('login'));
            exit;
        }
    }



    /** اگر admin نباشد → 403 */
    protected function requireAdmin(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->requireAuth();
            return;
        }

        // استفاده از PolicyService (Sprint 5) برای centralized authorization
        if (!$this->policyService->isAdminById($userId)) {
            if (is_ajax()) {
                $this->response->error('دسترسی غیرمجاز', [], 403);
                exit;
            }
            $this->response->redirect(url('dashboard'));
            exit;
        }
    }

    /** بررسی permission خاص */
    protected function requirePermission(string $permission): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->requireAuth();
            return;
        }

        // استفاده از PolicyService (Sprint 5)
        if (!$this->policyService->authorizeById($permission, $userId)) {
            if (is_ajax()) {
                $this->response->error('مجوز کافی ندارید', [], 403);
                exit;
            }
            $this->session->setFlash('error', 'مجوز کافی ندارید.');
            $this->back();
            exit;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Response Helpers
    // ─────────────────────────────────────────────────────────────

    protected function json(bool $success, string $message = '', array $data = [], int $code = 200): void
    {
        // H10 Fix: تجمیع متد اختصاصی کنترلر در شیء Response مرکزی جهت فعال شدن معماری Exception-based
        $this->response->json([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ], $code);
        // متد بالا اتوماتیک HttpResponseException شلیک کرده و اجرا را متوقف می‌کند.
    }

    protected function jsonSuccess(string $message = '', array $data = []): void
    {
        $this->json(true, $message, $data, 200);
    }

    protected function jsonError(string $message, array $data = [], int $code = 422): void
    {
        $this->json(false, $message, $data, $code);
    }

    /** redirect به صفحه قبلی (یا fallback) */
   protected function back(string $fallback = '/'): void {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    
    // ✅ اعتبارسنجی اینکه HTTP_REFERER مربوط به همین دامنه است
    if (!empty($ref)) {
        // تنها URL‌هایی که با app.url شروع می‌شوند
        $appUrl = config('app.url') ?: url('/');
        if (strpos($ref, $appUrl) === 0) {
            $this->response->redirect($ref);
            exit;
        }
    }
    
    // fallback به صفحه پیشفرض
    $this->response->redirect(url($fallback));
    exit;
}

    /** flash + redirect ترکیبی */
    protected function redirectWithError(string $message, string $to = ''): void
    {
        $this->session->setFlash('error', $message);
        $to ? $this->response->redirect(url($to)) : $this->back();
        exit;
    }

    protected function redirectWithSuccess(string $message, string $to = ''): void
    {
        $this->session->setFlash('success', $message);
        $to ? $this->response->redirect(url($to)) : $this->back();
        exit;
    }

    /** render view با داده */
    protected function view(string $template, array $data = []): void
    {
        view($template, $data);
    }

    /**
     * اعتبارسنجی خودکار یک داده ورودی با کلاس FormRequest
     */
    protected function validateRequest(string $formRequestClass, array $data = []): array
    {
        if (empty($data)) {
            $data = $this->request->all();
        }

        if (!class_exists($formRequestClass)) {
            throw new \InvalidArgumentException("کلاس اعتبارسنجی {$formRequestClass} یافت نشد.");
        }

        /** @var \App\Validators\BaseFormRequest $request */
        $request = new $formRequestClass($data);

        if (!$request->validate()) {
            $errors = $request->errors();

            if ($this instanceof \App\Controllers\Api\BaseApiController || is_ajax()) {
                // ارسال پاسخ استاندارد JSON در صورت درخواست AJAX
                $this->json(false, 'داده‌های ورودی نامعتبر است', ['errors' => $errors], 422);
                return [];  // Exit execution to prevent validated() from running
            } else {
                $firstError = is_array($errors) ? (reset($errors)[0] ?? reset($errors)) : 'داده‌های ورودی نامعتبر است';
                $this->session->setFlash('error', $firstError);
                $this->session->setFlash('errors', $errors);
                $this->session->setFlash('old', $data);
                $this->back();
                return [];  // Exit execution after redirect
            }
        }

        return $request->validated();
    }
}
