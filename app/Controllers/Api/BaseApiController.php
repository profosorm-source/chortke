<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;

/**
 * BaseApiController - کنترلر پایه API
 *
 * همه API controllers باید از این کلاس extend کنند.
 * متدهای کمکی برای پاسخ‌های استاندارد JSON.
 */
abstract class BaseApiController extends BaseController
{
    public function __construct(
        ?\Core\Session $session = null,
        ?\Core\Request $request = null,
        ?\Core\Response $response = null,
        ?\App\Services\Shared\PolicyService $policyService = null,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger);
        // اطمینان از هدر صحیح برای پاسخ‌های API
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
    }

    /** پاسخ موفق */
    protected function success(mixed $data = null, string $message = '', int $code = 200): void
    {
        $this->response->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /** پاسخ با pagination */
    protected function paginated(array $items, int $total, int $page, int $perPage): void
    {
        $this->response->json([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int)ceil($total / $perPage),
                'from'         => ($page - 1) * $perPage + 1,
                'to'           => min($page * $perPage, $total),
            ],
        ], 200);
    }

    /** پاسخ خطا */
    protected function error(string $message, int $code = 400, ?string $errorCode = null): void
    {
        $this->response->json([
            'success' => false,
            'message' => $message,
            'data'    => ['error_code' => $errorCode],
        ], $code);
    }

    /** خطای اعتبارسنجی */
    protected function validationError(array $errors): void
    {
        $this->response->json([
            'success' => false,
            'message' => 'خطای اعتبارسنجی',
            'data'    => ['errors' => $errors],
        ], 422);
    }

    /** کاربر جاری (از middleware inject شده) */
    protected function currentUser(): ?object
    {
        return $this->request->getUser();
    }

    /** ID کاربر جاری */
    protected function userId(): int
    {
        return (int)($this->currentUser()->id ?? 0);
    }

    /** دریافت pagination params */
    protected function paginationParams(int $defaultPerPage = 20): array
    {
        $page    = max(1, (int)($this->request->get('page') ?? 1));
        $perPage = min(100, max(1, (int)($this->request->get('per_page') ?? $defaultPerPage)));
        $offset  = ($page - 1) * $perPage;
        return [$page, $perPage, $offset];
    }
}
