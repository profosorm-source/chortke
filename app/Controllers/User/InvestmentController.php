<?php
// app/Controllers/User/InvestmentController.php

namespace App\Controllers\User;

use App\Models\Investment;
use App\Models\TradingRecord;
use App\Models\InvestmentProfit;
use App\Models\InvestmentWithdrawal;
use App\Services\InvestmentService;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;
use App\Contracts\WalletServiceInterface;

class InvestmentController extends BaseUserController
{
    private \App\Services\Notification\NotificationService $notificationService;
    private WalletServiceInterface $walletService;
    private \App\Models\TradingRecord $tradingRecordModel;
    private \App\Models\InvestmentWithdrawal $investmentWithdrawalModel;
    private \App\Models\InvestmentProfit $investmentProfitModel;
    private \App\Models\Investment $investmentModel;
    private InvestmentService $investmentService;

    public function __construct(
        \App\Models\Investment $investmentModel,
        \App\Models\InvestmentProfit $investmentProfitModel,
        \App\Models\InvestmentWithdrawal $investmentWithdrawalModel,
        \App\Models\TradingRecord $tradingRecordModel,
        WalletServiceInterface $walletService,
        \App\Services\Notification\NotificationService $notificationService,
        \App\Services\InvestmentService $investmentService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->investmentService = $investmentService;
        $this->investmentModel = $investmentModel;
        $this->investmentProfitModel = $investmentProfitModel;
        $this->investmentWithdrawalModel = $investmentWithdrawalModel;
        $this->tradingRecordModel = $tradingRecordModel;
        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
    }

    /**
     * صفحه اصلی سرمایه‌گذاری (داشبورد)
     */
    public function index()
    {
        $userId = user_id();

        $activeInvestment = $this->investmentModel->getActiveByUser($userId);

        // canWithdraw — نیاز به سرمایه‌گذاری فعال دارد
        $canWithdraw = $activeInvestment
            ? $this->investmentModel->canWithdraw($userId)
            : ['allowed' => false, 'reason' => ''];

        // آخرین ۵ رکورد سود/ضرر
        $profitHistory = $this->investmentProfitModel->getByUser($userId, 5, 0);

        // آخرین ۱۰ ترید بسته‌شده (عمومی برای همه کاربران)
        $recentTrades = $this->tradingRecordModel->getRecentClosed(10);

        // درخواست‌های برداشت کاربر
        $withdrawals = $this->investmentWithdrawalModel->getByUser($userId, 5, 0);

        return view('user.investment.index', [
            'user'             => user(),
            'activeInvestment' => $activeInvestment,
            'canWithdraw'      => $canWithdraw,
            'profitHistory'    => $profitHistory,
            'recentTrades'     => $recentTrades,
            'withdrawals'      => $withdrawals,
            'settings'         => $this->investmentService->getSettings(),
            'isDepositLocked'  => $this->investmentModel->isDepositLocked($userId),
        ]);
    }

    /**
     * صفحه ثبت سرمایه‌گذاری
     */
    public function create()
    {
        $userId = user_id();

        if ($this->investmentModel->hasActiveInvestment($userId)) {
            session()->setFlash('error', 'شما یک پلن فعال دارید. امکان ایجاد پلن جدید نیست.');
            return redirect(url('/investment'));
        }

        if ($this->investmentModel->isDepositLocked($userId)) {
            session()->setFlash('error', 'به دلیل برداشت اخیر، فعلاً امکان سرمایه‌گذاری جدید ندارید.');
            return redirect(url('/investment'));
        }

        return view('user.investment.create', [
            'user'            => user(),
            'riskWarning'     => $this->investmentService->getRiskWarning(),
            'settings'        => $this->investmentService->getSettings(),
            'isDepositLocked' => false,
        ]);
    }

    /**
     * ثبت سرمایه‌گذاری (POST - AJAX)
     */
    public function store()
    {
        $input = $this->request->json() ?? $this->request->all();

        $validator = \Core\Validator::create($input, [
            'amount'          => 'required|numeric|min:1',
            'risk_accepted'   => 'required',
            'idempotency_key' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->data();

        ApiRateLimiter::enforce('investment_create', (int) user_id(), is_ajax());

        $result = $this->investmentService->createInvestment((int) user_id(), [
            'amount'        => (float) ($data['amount'] ?? 0),
            'risk_accepted' => (int)   ($data['risk_accepted'] ?? 0),
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }
    /**
     * درخواست برداشت (POST - AJAX)
     */
    public function withdraw()
    {
        $input = $this->request->json() ?? $this->request->all();

        $validator = \Core\Validator::create($input, [
            'withdrawal_type' => 'required|in:profit_only,full_close',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'نوع برداشت را انتخاب کنید.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->data();

        $result = $this->investmentService->requestWithdrawal(user_id(), [
            'withdrawal_type' => $data['withdrawal_type'],
        ]);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * تاریخچه سود/ضرر
     */
    public function profitHistory()
    {
        $userId  = user_id();
        $page    = max(1, (int) ($this->request->get('page', 1)));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $profits    = $this->investmentProfitModel->getByUser($userId, $perPage, $offset);
        $total      = $this->investmentProfitModel->countByUser($userId);
        $totalPages = (int) ceil($total / $perPage);

        return view('user.investment.profit-history', [
            'user'        => user(),
            'profits'     => $profits,
            'total'       => $total,
            'totalPages'  => $totalPages,
            'currentPage' => $page,
        ]);
    }
}