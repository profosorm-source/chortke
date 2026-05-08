<?php

namespace App\Controllers;

use App\Services\Payment\PaymentService;
use App\Services\WalletService;
use App\Controllers\BaseController;
use App\Validators\Requests\WalletDepositRequest;
use Core\Exceptions\ValidationException;
use Core\Exceptions\NotFoundException;
use Core\Exceptions\BusinessException;

class PaymentController extends BaseController
{
    private WalletService $walletService;
    private PaymentService $paymentService;

    public function __construct(
        WalletService $walletService,
        PaymentService $paymentService
    ) {
        parent::__construct();
        $this->walletService = $walletService;
        $this->paymentService = $paymentService;
    }

    /**
     * درخواست پرداخت آنلاین
     */
    public function request(): void
    {
        if (!$this->userId()) {
            $this->session->setFlash('error', 'ابتدا وارد شوید');
            $this->response->redirect(url('login'));
            return;
        }

        $userId = $this->userId();

        // دریافت داده‌ها
        $data = [
            'gateway' => $this->request->input('gateway'),
            'amount' => $this->request->input('amount'),
        ];

        // اعتبارسنجی با FormRequest
        $request = new WalletDepositRequest($data);
        if (!$request->validate()) {
            $this->session->setFlash('error', $request->errors()[0] ?? 'داده‌های ورودی نامعتبر است');
            $this->response->redirect(url('wallet/deposit'));
            return;
        }

        $validated = $request->validated();

        try {
            $amount = (float)$validated['amount'];
            $bankCardId = (int)($this->request->input('bank_card_id') ?? 0);

    $result = $this->paymentService->create(
        $userId,
        (string)$data['gateway'],
        $amount,
        $bankCardId
    );

    $this->response->redirect($result['payment_url']);
} catch (ValidationException $e) {
    $this->session->setFlash('error', 'داده‌های ورودی نامعتبر: ' . implode(', ', $e->getErrors()));
    $this->response->redirect(url('wallet/deposit'));
} catch (NotFoundException $e) {
    $this->session->setFlash('error', $e->getMessage());
    $this->response->redirect(url('wallet/deposit'));
} catch (BusinessException $e) {
    $this->session->setFlash('error', $e->getMessage());
    $this->response->redirect(url('wallet/deposit'));
} catch (\Throwable $e) {
    $this->logger->error('payment.request.failed', [
        'channel' => 'payment',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    $this->session->setFlash('error', 'خطا در اتصال به درگاه پرداخت');
    $this->response->redirect(url('wallet/deposit'));
}
    }

    /**
     * بازگشت از درگاه پرداخت
     */
   public function callback(): void
{
    $gateway = (string)(
        $this->request->get('gateway')
        ?? $this->request->param('gateway')
        ?? ''
    );

    if ($gateway === '') {
        $this->session->setFlash('error', 'درگاه نامعتبر است');
        $this->response->redirect(url('wallet'));
        return;
    }

    try {
        $result = $this->paymentService->callback($gateway, $this->request->all());

        if (!empty($result['success'])) {
            $this->session->setFlash('success', $result['message'] ?? 'پرداخت با موفقیت انجام شد');
        } else {
            $this->session->setFlash('error', $result['message'] ?? 'پرداخت ناموفق بود');
        }

        $this->response->redirect(url('wallet'));
    } catch (\Throwable $e) {
        $this->logger->error('payment.callback.failed', [
            'channel' => 'payment',
            'gateway' => $gateway,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->session->setFlash('error', 'پرداخت ناموفق بود');
        $this->response->redirect(url('wallet'));
    }
}
}