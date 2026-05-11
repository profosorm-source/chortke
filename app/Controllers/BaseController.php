<?php

namespace App\Controllers;

use Core\Container;
use Core\Session;
use Core\Request;
use Core\Response;
use App\Services\Shared\PolicyService;
use App\Services\RolePolicy;
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
    protected Session  $session;
    protected Request  $request;
    protected Response $response;
    protected PolicyService $policyService;
    protected LoggerInterface $logger;

    /**
     * وابستگی‌ها را از طریق سازنده دریافت کرده یا به صورت خودکار از کانتینر رِزولوش می‌کند.
     */
    public function __construct(
        ?Session $session = null,
        ?Request $request = null,
        ?Response $response = null,
        ?PolicyService $policyService = null,
        ?LoggerInterface $logger = null
    ) {
        $container = \Core\Container::getInstance();
        
        $this->session = $session ?? $container->make(\Core\Session::class);
        $this->request = $request ?? $container->make(\Core\Request::class);
        $this->response = $response ?? $container->make(\Core\Response::class);
        $this->policyService = $policyService ?? $container->make(\App\Services\Shared\PolicyService::class);
        $this->logger = $logger ?? $container->make(\App\Contracts\LoggerInterface::class);
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
        http_response_code($code);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $flags = JSON_UNESCAPED_UNICODE;
        if (config('app.debug', false)) {
            $flags |= JSON_PRETTY_PRINT;
        }
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ], $flags);
        if (defined('TESTING') && TESTING === true) {
            return;
        }
        exit;
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
    protected function back(string $fallback = '/'): void
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $this->response->redirect($ref ?: url($fallback));
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
            if ($this instanceof \App\Controllers\Api\BaseApiController) {
                $this->validationError($request->errors());
            } else {
                $errors = $request->errors();
                $firstError = is_array($errors) ? (reset($errors)[0] ?? reset($errors)) : 'داده‌های ورودی نامعتبر است';
                $this->session->setFlash('error', $firstError);
                $this->session->setFlash('errors', $errors);
                $this->session->setFlash('old', $data);
                $this->back();
            }
        }

        return $request->validated();
    }
}
