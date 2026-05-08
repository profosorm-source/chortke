<?php
// app/Controllers/Admin/InvestmentController.php

namespace App\Controllers\Admin;

use App\Models\Investment;
use App\Models\TradingRecord;
use App\Models\InvestmentProfit;
use App\Models\InvestmentWithdrawal;
use App\Services\InvestmentService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class InvestmentController extends BaseAdminController
{
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
        \App\Services\InvestmentService $investmentService)
    {
        parent::__construct();
        $this->investmentService = $investmentService;
        $this->investmentModel = $investmentModel;
        $this->investmentProfitModel = $investmentProfitModel;
        $this->investmentWithdrawalModel = $investmentWithdrawalModel;
        $this->tradingRecordModel = $tradingRecordModel;
    }

    /**
     * داشبورد سرمایه‌گذاری (ادمین)
     */
    public function index()
    {
        $investModel = $this->investmentModel;
        $tradingModel = $this->tradingRecordModel;

        $filters = [
            'status' => $_GET['status'] ?? null,
            'search' => $_GET['search'] ?? null,
        ];

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $investments = $investModel->getAll($filters, $perPage, $offset);
        $total = $investModel->countAll($filters);
        $totalPages = ceil($total / $perPage);
        $stats = $investModel->getStats();
        $tradeStats = $tradingModel->getStats();

        return view('admin.investment.index', [
            'investments' => $investments,
            'stats' => $stats,
            'tradeStats' => $tradeStats,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
        ]);
    }

    /**
     * جزئیات سرمایه‌گذاری
     */
    public function show()
    {
                $id = (int)$this->request->param('id');

        $investModel = $this->investmentModel;
        $profitModel = $this->investmentProfitModel;
        $withdrawalModel = $this->investmentWithdrawalModel;

        $investment = $investModel->findWithUser($id);
        if (!$investment) {
            return view('errors.404');
        }

        $profits = $profitModel->getByInvestment($id);
        $totalStats = $profitModel->getTotalByInvestment($id);
        $withdrawals = $withdrawalModel->getAll(['status' => null], 50, 0);

        return view('admin.investment.show', [
            'investment' => $investment,
            'profits' => $profits,
            'totalStats' => $totalStats,
            'withdrawals' => $withdrawals,
        ]);
    }

    /**
     * لیست تریدها
     */
    public function trades()
    {
        $tradingModel = $this->tradingRecordModel;

        $filters = ['status' => $_GET['status'] ?? null];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $trades = $tradingModel->getAll($filters, $perPage, $offset);
        $total = $tradingModel->countAll($filters);
        $totalPages = ceil($total / $perPage);
        $stats = $tradingModel->getStats();

        return view('admin.investment.trades', [
            'trades' => $trades,
            'stats' => $stats,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
        ]);
    }

    /**
     * فرم ثبت ترید جدید
     */
    public function tradeCreate()
    {
        return view('admin.investment.trade-create', []);
    }

    /**
     * ثبت ترید (POST - AJAX)
     */
    public function tradeStore()
    {
                $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'direction' => 'required|in:buy,sell',
            'open_price' => 'required|numeric|min:0',
            'open_time' => 'required',
            'pair' => 'max:20',
            'lot_size' => 'numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false, 'message' => 'اطلاعات ورودی نامعتبر.',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->investmentService->createTrade(user_id(), (array)$data);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * بستن ترید (AJAX)
     */
    public function tradeClose()
    {
                        $id = (int)$this->request->param('id');

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'close_price' => 'required|numeric|min:0',
            'profit_loss_percent' => 'required|numeric',
            'profit_loss_amount' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false, 'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->investmentService->closeTrade($id, user_id(), (array)$data);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * فرم اعمال سود/ضرر هفتگی
     */
    public function applyProfitForm()
    {
        $tradingModel = $this->tradingRecordModel;
        $closedTrades = $tradingModel->getRecentClosed(20);

        return view('admin.investment.apply-profit', [
            'closedTrades' => $closedTrades,
            'settings' => $this->investmentService->getSettings(),
        ]);
    }

    /**
     * اعمال سود/ضرر هفتگی (POST - AJAX)
     */
    public function applyProfit()
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'trading_record_id' => 'required|numeric',
            'profit_loss_percent' => 'required|numeric',
            'period' => 'required|max:10',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false, 'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->investmentService->applyWeeklyProfitLoss(
            user_id(),
            (int)$data['trading_record_id'],
            (float)$data['profit_loss_percent'],
            $data['period']
        );

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * لیست درخواست‌های برداشت
     */
    public function withdrawals()
    {
        $model = $this->investmentWithdrawalModel;

        $filters = ['status' => $_GET['status'] ?? null];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $withdrawals = $model->getAll($filters, $perPage, $offset);
        $total = $model->countAll($filters);
        $totalPages = ceil($total / $perPage);

        return view('admin.investment.withdrawals', [
            'withdrawals' => $withdrawals,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
        ]);
    }

    /**
     * تأیید برداشت (AJAX)
     */
    public function withdrawalApprove()
    {
                        $id = (int)$this->request->param('id');

        $result = $this->investmentService->approveWithdrawal($id, user_id());

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * رد برداشت (AJAX)
     */
    public function withdrawalReject()
    {
                        $id = (int)$this->request->param('id');

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'reason' => 'required|min:10|max:500',
        ]);

        if ($validator->fails()) {
            return $this->response->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->data();
        $result = $this->investmentService->rejectWithdrawal($id, user_id(), $data['reason']);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * تعلیق سرمایه‌گذاری (AJAX)
     */
    public function suspend()
    {
                        $id = (int)$this->request->param('id');

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $investModel = $this->investmentModel;
        $investment = $investModel->find($id);

        if (!$investment) {
            return $this->response->json(['success' => false, 'message' => 'سرمایه‌گذاری یافت نشد.'], 404);
        }

        $investModel->update($id, [
            'status' => Investment::STATUS_SUSPENDED,
            'admin_notes' => $input['reason'] ?? 'تعلیق توسط مدیر',
        ]);

        $this->logger->info('investment_suspended', ['message' => "Admin " . user_id() . " suspended investment #{$id}"]);

        return $this->response->json(['success' => true, 'message' => 'سرمایه‌گذاری تعلیق شد.']);
    }
}