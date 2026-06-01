<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Payment\PaymentService;
use App\Services\User\UserService;
use App\Controllers\Admin\BaseAdminController;
use Core\Logger;

class OnlinePaymentController extends BaseAdminController
{
    private PaymentService $paymentService;
    private UserService $userService;
    protected \App\Contracts\LoggerInterface $logger;

    public function __construct(
        PaymentService $paymentService,
        UserService $userService,
        Logger $logger
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->paymentService = $paymentService;
        $this->userService = $userService;
        $this->logger = $logger;
    }

    /**
     * نمایش لیست پرداخت‌های معلق در انتظار تایید
     */
    public function index(): void
    {
        try {
            $payments = $this->paymentService->getPendingVerificationPayments();

            view('admin.gateway-payments.index', [
                'payments' => $payments,
                'pageTitle' => 'بررسی پرداخت‌های آنلاین معلق'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('admin.gateway_payments.index.failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $this->session->setFlash('error', 'خطا در دریافت لیست پرداخت‌های معلق');
            redirect('/admin/dashboard');
        }
    }

    /**
     * تایید دستی پرداخت از طریق استعلام مجدد مستقیم درگاه
     */
    public function verify(): void
    {
        try {
            $adminId = (int) user_id();
            $paymentId = (int) ($this->request->input('payment_id') ?? 0);

            if ($paymentId <= 0) {
                $this->response->json([
                    'success' => false,
                    'message' => 'شناسه پرداخت نامعتبر است'
                ], 422);
                return;
            }

            // فراخوانی متد بازبینی و استعلام از درگاه بانکی
            $result = $this->paymentService->manuallyVerifyPayment($paymentId, $adminId);

            $this->response->json([
                'success' => (bool) ($result['success'] ?? false),
                'message' => $result['message'] ?? 'تراکنش با موفقیت تأیید نشد'
            ], ($result['success'] ?? false) ? 200 : 422);
        } catch (\Throwable $e) {
            $this->logger->error('admin.gateway_payments.verify.failed', [
                'payment_id' => $paymentId ?? null,
                'error' => $e->getMessage()
            ]);

            $this->response->json([
                'success' => false,
                'message' => 'خطای سرور در تایید پرداخت: ' . $e->getMessage()
            ], 500);
        }
    }
}
