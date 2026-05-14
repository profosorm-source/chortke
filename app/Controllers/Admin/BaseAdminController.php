<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * BaseAdminController — پایه تمام کنترلرهای پنل مدیریت
 *
 * ─── جریان صحیح ────────────────────────────────────────────────
 *
 *   Container::make(AdminUserController)
 *       └─→ AdminUserController::__construct()     ← بدون پارامتر
 *               └─→ BaseAdminController::__construct()
 *                       └─→ BaseController::__construct()
 *                               └─→ Container: Request, Response, Session
 *                       └─→ requireAuth() + requireAdmin()
 *
 * ─── تذکر ──────────────────────────────────────────────────────
 *   Auth در دو سطح بررسی می‌شود:
 *     ۱. AdminMiddleware  (در Route) — قبل از رسیدن به Controller
 *     ۲. requireAuth/requireAdmin  (اینجا) — لایه دوم اطمینان
 */
abstract class BaseAdminController extends BaseController
{
    /**
     * وابستگی‌ها را پذیرفته و به سازنده والد ارسال می‌کند.
     */
    public function __construct(
        ?\Core\Session $session = null,
        ?\Core\Request $request = null,
        ?\Core\Response $response = null,
        ?\App\Services\Shared\PolicyService $policyService = null,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger);
        $this->requireAuth();
        $this->requireAdmin();
    }
}
