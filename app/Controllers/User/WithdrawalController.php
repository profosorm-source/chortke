<?php

namespace App\Controllers\User;

use App\Contracts\WalletServiceInterface;
use App\Services\Withdrawal\WithdrawalUserService;
use App\Services\Withdrawal\WithdrawalQueryService;
use App\Services\BankCardService;
use App\Services\User\UserService;
use App\Services\AntiFraud\RiskDecisionService;
use App\Validators\Requests\CreateWithdrawalRequest;
use Core\Logger;
use App\Controllers\User\BaseUserController;

class WithdrawalController extends BaseUserController
{
    private BankCardService $bankCardService;
    private WalletServiceInterface $walletService;
    private RiskDecisionService $riskDecisionService;
    private WithdrawalUserService $withdrawalUserService;
    private WithdrawalQueryService $withdrawalQueryService;
    private UserService $userService;
    private Logger $logger;

    public function __construct(
        BankCardService $bankCardService,
        WalletServiceInterface $walletService,
        RiskDecisionService $riskDecisionService,
        WithdrawalUserService $withdrawalUserService,
        WithdrawalQueryService $withdrawalQueryService,
        UserService $userService,
        Logger $logger
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->bankCardService = $bankCardService;
        $this->walletService = $walletService;
        $this->riskDecisionService = $riskDecisionService;
        $this->withdrawalUserService = $withdrawalUserService;
        $this->withdrawalQueryService = $withdrawalQueryService;
        $this->userService = $userService;
        $this->logger = $logger;
    }

    /**
     * فرم برداشت وجه
     */
    public function create(): void
    {
        $userId = $this->userId();

        try {
            $user = $this->userService->find($userId);

            if (!$user || $user->kyc_status !== 'verified') {
                $this->session->setFlash('error', 'برای برداشت وجه ابتدا باید احراز هویت کنید');
                $this->response->redirect(url('kyc'));
                return;
            }

            if ($this->withdrawalQueryService->hasPendingWithdrawal($userId)) {
                $this->session->setFlash('error', 'شما یک درخواست برداشت در انتظار دارید');
                $this->response->redirect(url('wallet'));
                return;
            }

            $summary = $this->walletService->getWalletSummary($userId);
            if (!$summary->can_withdraw_today) {
                $this->session->setFlash('error', 'شما امروز یکبار برداشت کرده‌اید');
                $this->response->redirect(url('wallet'));
                return;
            }

            $siteCurrency = config('site_currency', 'irt');
            $cards = [];
            if ($siteCurrency === 'irt') {
                $cards = $this->bankCardService->getUserCards($userId, 'verified');
                if (empty($cards)) {
                    $this->session->setFlash('error', 'ابتدا باید کارت بانکی خود را ثبت و تأیید کنید');
                    $this->response->redirect(url('bank-cards/create'));
                    return;
                }
            }

            $minWithdrawal = $siteCurrency === 'usdt'
                ? (float)config('min_withdrawal_usdt', 10)
                : (float)config('min_withdrawal_irt', 50000);

            view('user.withdrawal.create', [
                'summary' => $summary,
                'cards' => $cards,
                'siteCurrency' => $siteCurrency,
                'minWithdrawal' => $minWithdrawal,
                'pageTitle' => 'برداشت وجه'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('withdrawal.create.failed', [
                'channel' => 'withdrawal',
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $this->session->setFlash('error', 'خطا در بارگذاری صفحه');
            $this->response->redirect(url('wallet'));
        }
    }

    /**
     * ثبت درخواست برداشت - با Request Validation + Idempotency
     */
    public function store(): void
    {
        $userId = (int) user_id();

        try {
            $request = new CreateWithdrawalRequest($this->request->all());
            
            if (!$request->validate()) {
                $this->response->json([
                    'success' => false,
                    'message' => 'خطای اعتبارسنجی',
                    'errors'  => $request->errors()
                ], 422);
                return;
            }

            $payload = array_merge($request->validated(), [
                'request_id'   => $this->request->header('X-Request-ID') ?? bin2hex(random_bytes(8)),
                'ip'           => get_client_ip(),
                'user_agent'   => get_user_agent(),
                'fingerprint'  => generate_device_fingerprint(),
            ]);

            $result = $this->withdrawalUserService->requestFromUser($userId, $payload);

            $this->response->json([
                'success' => (bool)($result['success'] ?? false),
                'message' => $result['message'] ?? 'خطا',
                'data'    => $result['data'] ?? null,
            ], !empty($result['success']) ? 200 : 422);

        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.request.controller.failed', [
                'channel'   => 'withdrawal',
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $this->response->json([
                'success' => false,
                'message' => 'خطای سرور'
            ], 500);
        }
    }

    public function index(): void
    {
        $userId = $this->userId();

        try {
            $withdrawals = $this->withdrawalQueryService->getUserWithdrawals($userId);

            view('user.withdrawal.index', [
                'withdrawals' => $withdrawals,
                'pageTitle' => 'درخواست‌های برداشت'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('withdrawal.index.failed', [
                'channel' => 'withdrawal',
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');
            $this->response->redirect(url('wallet'));
        }
    }

    public function limitsInfo(): void
    {
        $userId   = (int)user_id();
        $currency = strtoupper($this->request->get('currency') ?? 'IRT');
        
        if (!in_array($currency, ['IRT', 'USDT'], true)) {
            $currency = 'IRT';
        }

        $info = $this->withdrawalQueryService->getLimitsForUser($userId, $currency);

        $this->response->json([
            'success' => true,
            'limits'  => $info,
        ]);
    }

    // متدهای challenge (OTP) فعلاً بدون تغییر نگه داشته می‌شوند
    public function requestWithdrawalChallenge(): void { /* ... */ }
    public function verifyWithdrawalChallenge(): void { /* ... */ }
}
