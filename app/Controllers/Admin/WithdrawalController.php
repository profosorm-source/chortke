<?php

namespace App\Controllers\Admin;

use App\Services\WalletService;
use App\Services\User\UserService;
use App\Services\BankCardService;
use App\Services\ReconciliationService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class WithdrawalController extends BaseAdminController
{
    private WalletService  $walletService;
    private UserService    $userService;
    private BankCardService $cardService;
	private \App\Services\WithdrawalService $withdrawalService;
	protected \App\Contracts\LoggerInterface $logger;
	private ReconciliationService $reconciliationService;

public function __construct(
    \App\Services\BankCardService $bankCardService,
    \App\Services\WalletService $walletService,
    \App\Services\User\UserService $userService,
    \App\Services\WithdrawalService $withdrawalService,
	\Core\Logger $logger,
	ReconciliationService $reconciliationService
) {
    parent::__construct();
    $this->walletService = $walletService;
    $this->userService = $userService;
    $this->cardService = $bankCardService;
    $this->withdrawalService = $withdrawalService;
	$this->logger = $logger;
	$this->reconciliationService = $reconciliationService;
}


    /**
     * لیست درخواست‌های برداشت
     */
    public function index(): void
    {
                
        $page = (int)$this->request->get('page', 1);
        $status = $this->request->get('status');
        $currency = $this->request->get('currency');
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            if ($status || $currency) {
                $withdrawals = $this->withdrawalService->getAll($status, $currency, $limit, $offset);
                $total = $this->withdrawalService->countAll($status, $currency);
            } else {
                $withdrawals = $this->withdrawalService->getPendingWithdrawals($limit, $offset);
                $total = $this->withdrawalService->countPendingWithdrawals();
            }

            $totalPages = (int)\ceil($total / $limit);

            // آمار خلاصه
            $summary = $this->withdrawalService->getSummaryStats();

            view('admin.withdrawals.index', [
                'withdrawals' => $withdrawals,
                'currentPage' => $page,
                'totalPages'  => $totalPages,
                'total'       => $total,
                'status'      => $status,
                'currency'    => $currency,
                'summary'     => $summary ?? [],
                'pageTitle'   => 'درخواست‌های برداشت'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('admin.withdrawals.index.failed', [
        'channel' => 'admin',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');
            redirect('/admin/dashboard');
        }
    }

    /**
     * صفحه بررسی درخواست برداشت
     */
    public function review(): void
    {
                $withdrawalId = (int)$this->request->get('id');

        try {
            $withdrawal = $this->withdrawalService->findById($withdrawalId);

            if (!$withdrawal) {
                $this->session->setFlash('error', 'درخواست یافت نشد');
                redirect('/admin/withdrawals');
                return;
            }

            // دریافت اطلاعات کاربر
            $user = $this->userService->findById($withdrawal->user_id);

            // دریافت اطلاعات کارت (برای IRT)
            $card = null;
            if ($withdrawal->card_id) {
                $card = $this->cardService->findById($withdrawal->card_id);
            }

            view('admin.withdrawals.review', [
                'withdrawal' => $withdrawal,
                'user' => $user,
                'card' => $card,
                'pageTitle' => 'بررسی درخواست برداشت'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('admin.withdrawal.review.failed', [
        'channel' => 'admin',
        'withdrawal_id' => $withdrawalId ?? null,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
            redirect('/admin/withdrawals');
        }
    }

 
    /**
     * تأیید و پرداخت درخواست برداشت
     */
    public function process(): void
    {
        $adminId = $this->userId();
        
        // ✅ دریافت metadata
        $requestId = get_request_id();
        $ipAddress = get_client_ip();

        $data = [
            'withdrawal_id' => $this->request->input('withdrawal_id'),
            'payment_reference' => $this->request->input('payment_reference'), // شماره پیگیری پرداخت
        ];

        // اعتبارسنجی
        $validator = new Validator($data, [
            'withdrawal_id' => 'required|numeric',
            'payment_reference' => 'required|min:5|max:100',
        ], [
            'withdrawal_id.required' => 'شناسه برداشت الزامی است',
            'payment_reference.required' => 'شماره پیگیری پرداخت الزامی است',
            'payment_reference.min' => 'شماره پیگیری باید حداقل 5 کاراکتر باشد',
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => $validator->errors()[0]
            ]);
            return;
        }

        try {
            $withdrawalId = (int)$data['withdrawal_id'];
            $paymentRef = (string)$data['payment_reference'];

            $result = $this->withdrawalService->approveWithdrawal($withdrawalId, $paymentRef, $adminId);

            if (!empty($result['success'])) {
                // ✅ ثبت لاگ فعالیت در سطح کنترلر (لاگ مانیتورینگ)
                $this->logger->activity(
                    'withdrawal_approved',
                    "تأیید برداشت به شناسه {$withdrawalId} توسط ادمین {$adminId}",
                    $adminId,
                    [
                        'channel' => 'admin',
                        'request_id' => $requestId,
                        'admin_ip' => $ipAddress,
                        'withdrawal_id' => $withdrawalId,
                    ]
                );

                $this->response->json([
                    'success' => true,
                    'message' => $result['message'] ?? 'عملیات با موفقیت انجام شد'
                ]);
            } else {
                $this->response->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'عملیات تأیید ناموفق بود'
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('admin.withdrawal.approve.exception', [
                'channel' => 'admin',
                'withdrawal_id' => $withdrawalId ?? null,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            $this->response->json([
                'success' => false,
                'message' => 'بروز خطای سیستمی در پردازش درخواست'
            ], 500);
        }
    }

    /**
     * رد درخواست برداشت - با Event Sourcing
     */
    public function reject(): void
    {
        $adminId = $this->userId();
        
        // ✅ دریافت metadata
        $requestId = get_request_id();
        $ipAddress = get_client_ip();

        $data = [
            'withdrawal_id' => $this->request->input('withdrawal_id'),
            'rejection_reason' => $this->request->input('rejection_reason'),
        ];

        // اعتبارسنجی
        $validator = new Validator($data, [
            'withdrawal_id' => 'required|numeric',
            'rejection_reason' => 'required|min:10|max:500',
        ], [
            'rejection_reason.required' => 'دلیل رد الزامی است',
            'rejection_reason.min' => 'دلیل رد باید حداقل 10 کاراکتر باشد',
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => $validator->errors()[0]
            ]);
            return;
        }

        try {
            $withdrawalId = (int)$data['withdrawal_id'];
            $withdrawal = $this->withdrawalService->findById($withdrawalId);

            if (!$withdrawal) {
                $this->response->json([
                    'success' => false,
                    'message' => 'درخواست یافت نشد'
                ]);
                return;
            }

            if ($withdrawal->status !== 'pending') {
                $this->response->json([
                    'success' => false,
                    'message' => 'این درخواست قبلاً پردازش شده است'
                ]);
                return;
            }

            // ✅ لغو برداشت (آزادسازی موجودی قفل‌شده)
            $cancelled = $this->walletService->cancelWithdrawal(
                $withdrawal->user_id,
                (float)$withdrawal->amount,
                $withdrawal->currency,
                $withdrawal->transaction_id
            );

            if (!$cancelled) {
                throw new \RuntimeException('خطا در آزادسازی موجودی');
            }

            // ✅ بروزرسانی وضعیت
            $updated = $this->withdrawalService->updateStatus(
                $withdrawalId,
                'rejected',
                $data['rejection_reason'],
                $adminId
            );

            if ($updated) {
                // ✅ ثبت تغییر وضعیت در transaction_events
                $this->withdrawalService->recordTransactionStatusChange(
                    $withdrawal->transaction_id,
                    'cancelled',
                    "رد توسط ادمین: {$data['rejection_reason']}",
                    $adminId,
                    [
                        'rejection_reason' => $data['rejection_reason'],
                        'withdrawal_id' => $withdrawalId,
                        'request_id' => $requestId
                    ]
                );

                // ✅ ثبت لاگ
                $this->logger->info('withdrawal.reject.completed', [
    'channel' => 'withdrawal',
    'request_id' => $requestId,
    'withdrawal_id' => $withdrawalId,
    'user_id' => $withdrawal->user_id,
    'reason' => $data['rejection_reason'] ?? null,
]);

$this->response->json([
    'success' => true,
    'message' => 'برداشت رد شد و موجودی به کاربر بازگردانده شد'
]);
            } else {
                throw new \RuntimeException('خطا در رد برداشت');
            }

        } catch (\Exception $e) {
    $this->logger->error('withdrawal.reject.failed', [
    'channel' => 'withdrawal',
    'request_id' => $requestId,
    'withdrawal_id' => $withdrawalId ?? null,
    'admin_id' => $adminId ?? null,
    'error' => $e->getMessage(),
    'exception' => get_class($e),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
]);

$this->response->json([
    'success' => false,
    'message' => 'خطا در رد برداشت'
]);
        }
    }
}