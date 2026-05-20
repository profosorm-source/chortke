<?php

namespace App\Controllers\User;

use App\Services\WalletService;
use Core\Validator;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;
use App\Services\BankCardService;

class WithdrawalController extends BaseUserController
{
    private \App\Services\AntiFraud\RiskDecisionService $riskDecisionService;
    private BankCardService $bankCardService;
    private WalletService $walletService;
    private \App\Services\WithdrawalService $withdrawalService;
    private \App\Services\User\UserService $userService;
    private \Core\Logger $logger;

    public function __construct(
        \App\Services\BankCardService $bankCardService,
        \App\Services\WalletService $walletService,
        \App\Services\AntiFraud\RiskDecisionService $riskDecisionService,
        \App\Services\WithdrawalService $withdrawalService,
        \App\Services\User\UserService $userService,
        \Core\Logger $logger
    ) {
        parent::__construct();
        $this->bankCardService = $bankCardService;
        $this->walletService = $walletService;
        $this->riskDecisionService = $riskDecisionService;
        $this->withdrawalService = $withdrawalService;
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
            // بررسی KYC
            $user = $this->userService->find($userId);

            if (!$user || $user->kyc_status !== 'verified') {
                $this->session->setFlash('error', 'برای برداشت وجه ابتدا باید احراز هویت کنید');
                $this->response->redirect(url('kyc'));
                return;
            }

            // بررسی درخواست در انتظار
            if ($this->withdrawalService->hasPendingWithdrawal($userId)) {
                $this->session->setFlash('error', 'شما یک درخواست برداشت در انتظار دارید');
                $this->response->redirect(url('wallet'));
                return;
            }

            // بررسی محدودیت روزانه
            $summary = $this->walletService->getWalletSummary($userId);
            if (!$summary->can_withdraw_today) {
                $this->session->setFlash('error', 'شما امروز یکبار برداشت کرده‌اید');
                $this->response->redirect(url('wallet'));
                return;
            }

            $siteCurrency = config('site_currency', 'irt');
            
            // دریافت کارت‌ها برای IRT
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
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در بارگذاری صفحه');
            $this->response->redirect(url('wallet'));
            return;
        }
    }

    /**
     * ثبت درخواست برداشت - با Idempotency Protection
     */
    public function store(): void
{
    $userId = (int) user_id();

    try {
        $idempotencyKey = $this->request->header('Idempotency-Key') ?? $this->request->header('X-Idempotency-Key') ?? $this->request->input('idempotency_key');

        $payload = [
            'amount' => $this->request->input('amount'),
            'currency' => $this->request->input('currency') ?? 'irt',
            'bank_card_id' => $this->request->input('bank_card_id'),
            'request_id' => $this->request->header('X-Request-ID') ?? bin2hex(random_bytes(8)),
            'idempotency_key' => $idempotencyKey,
            'ip' => get_client_ip(),
            'user_agent' => get_user_agent(),
            'fingerprint' => generate_device_fingerprint(),
        ];

        $result = $this->withdrawalService->requestFromUser($userId, $payload);

        $this->response->json([
            'success' => (bool)($result['success'] ?? false),
            'message' => $result['message'] ?? 'خطا',
            'data' => $result['data'] ?? null,
        ], !empty($result['success']) ? 200 : 422);
    } catch (\Throwable $e) {
        $this->logger->error('withdrawal.request.controller.failed', [
            'channel' => 'withdrawal',
            'user_id' => $userId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->response->json([
            'success' => false,
            'message' => 'خطای سرور'
        ], 500);
    }
}

    /**
     * لیست درخواست‌های برداشت کاربر
     */
    public function index(): void
    {
        $userId = $this->userId();

        try {
            $withdrawals = $this->withdrawalService->getUserWithdrawals($userId);

            view('user.withdrawal.index', [
                'withdrawals' => $withdrawals,
                'pageTitle' => 'درخواست‌های برداشت'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('withdrawal.index.failed', [
        'channel' => 'withdrawal',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');
            $this->response->redirect(url('wallet'));
            return;
        }
    }

    /**
     * نمایش محدودیت‌های برداشت برای کاربر جاری (JSON)
     * GET /user/withdrawal/limits?currency=IRT
     */
    public function limitsInfo(): void
    {
        $userId   = (int)user_id();
        $currency = strtoupper(($this->request)->get('currency') ?? 'IRT');
        if (!in_array($currency, ['IRT', 'USDT'], true)) {
            $currency = 'IRT';
        }

        $info = $this->withdrawalService->getLimitsForUser($userId, $currency);

        $this->response->json([
            'success' => true,
            'limits'  => $info,
        ]);
    }
	/**
 * درخواست چالش امنیتی برداشت (OTP موقت)
 * POST /withdrawal/challenge/request
 */
public function requestWithdrawalChallenge(): void
{
    $userId = (int)$this->userId();

    ApiRateLimiter::enforce('withdrawal_challenge_request', $userId, is_ajax());

    $code = (string)random_int(100000, 999999);

    $ipHash = md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $uaHash = md5($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');

    $this->session->set('withdraw_challenge', [
        'user_id'      => $userId,
        'code_hash'    => password_hash($code, PASSWORD_DEFAULT),
        'expires_at'   => time() + 300,
        'attempts'     => 0,
        'max_attempts' => feature_config('security_limits', 'withdrawal_challenge_max_attempts', 5),
        'created_at'   => time(),
        'ip_hash'      => $ipHash,
        'ua_hash'      => $uaHash,
    ]);

    $this->session->remove('withdraw_challenge_passed');
    $this->session->remove('withdraw_challenge_passed_until');
    $this->session->remove('withdraw_challenge_passed_ip');
    $this->session->remove('withdraw_challenge_passed_ua');

    // کد خام OTP را هرگز لاگ نکن
    $this->logger->info('Withdrawal challenge generated', [
        'user_id' => $userId,
    ]);

    $this->response->json([
        'success' => true,
        'message' => 'کد تایید امنیتی ارسال شد.',
    ]);
}

/**
 * تایید چالش امنیتی برداشت
 * POST /withdrawal/challenge/verify
 */
public function verifyWithdrawalChallenge(): void
{
    $userId = (int)$this->userId();

    ApiRateLimiter::enforce('withdrawal_challenge_verify', $userId, is_ajax());

    $code = trim((string)$this->request->input('code'));
    if ($code === '') {
        $this->response->json([
            'success' => false,
            'message' => 'کد تایید الزامی است',
        ], 422);
        return;
    }

    $challenge = $this->session->get('withdraw_challenge');
    if (!is_array($challenge)) {
        $this->response->json([
            'success' => false,
            'message' => 'درخواست چالش یافت نشد',
        ], 400);
        return;
    }

    if ((int)($challenge['user_id'] ?? 0) !== $userId) {
        $this->session->remove('withdraw_challenge');
        $this->session->remove('withdraw_challenge_passed');
        $this->session->remove('withdraw_challenge_passed_until');
        $this->session->remove('withdraw_challenge_passed_ip');
        $this->session->remove('withdraw_challenge_passed_ua');
        $this->response->json([
            'success' => false,
            'message' => 'چالش نامعتبر است',
        ], 403);
        return;
    }

    $ipHash = md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $uaHash = md5($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');

    if (($challenge['ip_hash'] ?? '') !== $ipHash || ($challenge['ua_hash'] ?? '') !== $uaHash) {
        $this->session->remove('withdraw_challenge');
        $this->session->remove('withdraw_challenge_passed');
        $this->session->remove('withdraw_challenge_passed_until');
        $this->session->remove('withdraw_challenge_passed_ip');
        $this->session->remove('withdraw_challenge_passed_ua');
        $this->response->json([
            'success' => false,
            'message' => 'محیط درخواست تغییر کرده است. چالش نامعتبر شد.',
        ], 403);
        return;
    }

    if ((int)($challenge['expires_at'] ?? 0) < time()) {
        $this->session->remove('withdraw_challenge');
        $this->response->json([
            'success' => false,
            'message' => 'کد منقضی شده است',
        ], 400);
        return;
    }

    $attempts = (int)($challenge['attempts'] ?? 0);
    $maxAttempts = (int)($challenge['max_attempts'] ?? feature_config('security_limits', 'withdrawal_challenge_max_attempts', 5));

    if ($attempts >= $maxAttempts) {
        $this->session->remove('withdraw_challenge');
        $this->response->json([
            'success' => false,
            'message' => 'تعداد تلاش بیش از حد مجاز است',
        ], 429);
        return;
    }

    $challenge['attempts'] = $attempts + 1;
    $this->session->set('withdraw_challenge', $challenge);

    if (!password_verify($code, (string)($challenge['code_hash'] ?? ''))) {
        $remaining = max(0, $maxAttempts - $challenge['attempts']);
        $this->response->json([
            'success' => false,
            'message' => 'کد تایید نادرست است',
            'remaining_attempts' => $remaining,
        ], 422);
        return;
    }

    $this->session->set('withdraw_challenge_passed', true);
    $this->session->set('withdraw_challenge_passed_until', time() + 300);
    $this->session->set('withdraw_challenge_passed_ip', $ipHash);
    $this->session->set('withdraw_challenge_passed_ua', $uaHash);
    $this->session->remove('withdraw_challenge');

    $this->response->json([
        'success' => true,
        'message' => 'تایید امنیتی با موفقیت انجام شد',
    ]);
}
}