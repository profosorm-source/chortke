<?php

namespace App\Controllers;

use App\Services\Payment\PaymentService;
use App\Contracts\WalletServiceInterface;
use App\Services\ReconciliationService;
use App\Controllers\BaseController;
use Core\Exceptions\ValidationException;
use Core\Exceptions\NotFoundException;
use Core\Exceptions\BusinessException;

class PaymentController extends BaseController
{
    private WalletServiceInterface $walletService;
    private PaymentService $paymentService;
    private ReconciliationService $reconciliationService;
    private \Core\Cache $cache;

    public function __construct(
        WalletServiceInterface $walletService,
        PaymentService $paymentService,
        ReconciliationService $reconciliationService,
        \Core\Cache $cache
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->walletService = $walletService;
        $this->paymentService = $paymentService;
        $this->reconciliationService = $reconciliationService;
        $this->cache = $cache;
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
            'idempotency_key' => $this->request->input('idempotency_key'),
        ];

        // اعتبارسنجی با Core\Validator
        $validator = \Core\Validator::create($data, [
            'gateway' => 'required|string|in:zarinpal,idpay,nextpay',
            'amount' => 'required|numeric|min:1000',
            'idempotency_key' => 'required|string|min:10|max:128',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $firstError = reset($errors);
            $msg = is_array($firstError) ? reset($firstError) : $firstError;
            $this->session->setFlash('error', $msg ?: 'داده‌های ورودی نامعتبر است');
            $this->response->redirect(url('wallet/deposit'));
            return;
        }

        $validated = $validator->data();

        try {
            $amount = (float)$validated['amount'];
            $bankCardId = (int)($this->request->input('bank_card_id') ?? 0);
            $idempotencyKey = (string)$validated['idempotency_key'];

        $clientIp = function_exists('get_client_ip') ? get_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $userAgent = function_exists('get_user_agent') ? get_user_agent() : (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

        $result = $this->paymentService->create(
            $userId,
            (string)$data['gateway'],
            $amount,
            $bankCardId,
            $idempotencyKey,
            $clientIp,
            $userAgent
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

        if (!$this->request->isPost()) {
            $this->logger->warning('payment.callback.invalid_method', [
                'gateway' => $gateway,
                'method' => $this->request->method(),
                'ip' => get_client_ip()
            ]);

            $this->response->status(405)->json([
                'success' => false,
                'message' => 'Callback must be delivered via POST request'
            ]);
            return;
        }

        try {
            $clientIp = function_exists('get_client_ip') ? get_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $userAgent = function_exists('get_user_agent') ? get_user_agent() : (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

            $result = $this->paymentService->callback(
                $gateway,
                $this->request->all(),
                $this->userId(),
                $clientIp,
                $userAgent
            );

            if (!empty($result['success'])) {
                $this->session->setFlash('success', $result['message'] ?? 'پرداخت با موفقیت انجام شد');
            } else {
                $this->session->setFlash('error', $result['message'] ?? 'پرداخت ناموفق بود');
            }
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
        }

        $this->response->redirect(url('wallet'));
    }

    public function callbackGet(): void
    {
        $gateway = (string)(
            $this->request->param('gateway')
            ?? $this->request->get('gateway')
            ?? 'unknown'
        );

        // Log suspicious activity
        $this->logger->warning('payment.callback.get_attempt_blocked', [
            'gateway' => $gateway,
            'ip' => get_client_ip(),
            'user_agent' => get_user_agent(),
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'none',
        ]);

        // Block IP after 3 attempts in 1 hour (3600 seconds)
        $ipKey = "callback_get_abuse:" . get_client_ip();
        $attempts = (int)$this->cache->get($ipKey, 0);
        $this->cache->setSeconds($ipKey, $attempts + 1, 3600);

        if ($attempts >= 3) {
            $this->logger->critical('payment.callback.get_abuse_detected', [
                'ip' => get_client_ip(),
                'attempts' => $attempts + 1
            ]);

            $this->response->status(403)->json([
                'success' => false,
                'message' => 'Access forbidden due to suspicious activity'
            ]);
            return;
        }

        $this->response->status(405)->json([
            'success' => false,
            'message' => 'Method not allowed. Callbacks must use POST.'
        ]);
    }
}
