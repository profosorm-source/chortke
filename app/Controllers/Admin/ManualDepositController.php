<?php

namespace App\Controllers\Admin;
use App\Models\UserBankCard;
use App\Services\User\UserService;

use App\Models\ManualDeposit;
use App\Services\WalletService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;
use App\Services\ManualDepositService;
use Core\Logger;

class ManualDepositController extends BaseAdminController
{
    private UserBankCard $userBankCardModel;
    private UserService $userService;
    private ManualDeposit $depositModel;
    private WalletService $walletService;
	private ManualDepositService $manualDepositService;
	protected \App\Contracts\LoggerInterface $logger;

    public function __construct(
    UserBankCard $userBankCardModel,
    UserService $userService,
    \App\Models\ManualDeposit $depositModel,
    \App\Services\WalletService $walletService,
    ManualDepositService $manualDepositService,
	Logger $logger,
) {
    parent::__construct();
    $this->userBankCardModel = $userBankCardModel;
    $this->userService = $userService;
    $this->depositModel = $depositModel;
    $this->walletService = $walletService;
    $this->manualDepositService = $manualDepositService;
	$this->logger = $logger;
}

    /**
     * لیست واریزهای دستی در انتظار
     */
    public function index(): void
    {
                
        $page = (int)$this->request->get('page', 1);
        $status = $this->request->get('status');
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            if ($status) {
                $deposits = $this->depositModel->getAll($status, $limit, $offset);
                $total = $this->depositModel->countAll($status);
            } else {
                $deposits = $this->depositModel->getPendingDeposits($limit, $offset);
                $total = $this->depositModel->countPendingDeposits();
            }

            $totalPages = (int)\ceil($total / $limit);

            view('admin.manual-deposits.index', [
                'deposits' => $deposits,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
                'status' => $status,
                'pageTitle' => 'واریزهای دستی'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('admin.manual_deposits.index.failed', [
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
     * صفحه بررسی واریز دستی
     */
    public function review(): void
    {
                $depositId = (int)$this->request->get('id');

        try {
            $deposit = $this->depositModel->find($depositId);

            if (!$deposit) {
                $this->session->setFlash('error', 'واریز یافت نشد');
                redirect('/admin/manual-deposits');
                return;
            }

            // دریافت اطلاعات کاربر
            $user = $this->userService->find($deposit->user_id);

            // دریافت اطلاعات کارت
            $cardModel = $this->userBankCardModel;
            $card = $cardModel->find($deposit->card_id);

            view('admin.manual-deposits.review', [
                'deposit' => $deposit,
                'user' => $user,
                'card' => $card,
                'pageTitle' => 'بررسی واریز دستی'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('admin.manual_deposit.review.failed', [
        'channel' => 'admin',
        'deposit_id' => $depositId ?? null,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
            redirect('/admin/manual-deposits');
        }
    }

   /**
 * تأیید واریز دستی
 */
public function verify(): void
{
    try {
        $adminId = (int) user_id();

        $depositId = (int) ($this->request->input('deposit_id') ?? 0);
        if ($depositId <= 0) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه واریز نامعتبر است'
            ], 422);
            return;
        }

        $note = (string) ($this->request->input('admin_note') ?? '');

        $result = $this->manualDepositService->approve($adminId, $depositId, $note);

        $this->response->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => $result['message'] ?? 'خطا'
        ], ($result['success'] ?? false) ? 200 : 422);
        return;
    } catch (\Throwable $e) {
        $this->logger->error('admin.manual_deposit.verify.failed', [
            'channel' => 'admin',
            'admin_id' => user_id(),
            'deposit_id' => $depositId ?? null,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->response->json([
            'success' => false,
            'message' => 'خطای سرور در تایید واریز'
        ], 500);
    }
}

    /**
     * رد واریز دستی
     */
   public function reject(): void
{
    try {
        $adminId = (int) user_id();

        $depositId = (int) ($this->request->input('deposit_id') ?? 0);
        $reason = trim((string) ($this->request->input('rejection_reason') ?? ''));

        if ($depositId <= 0) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه واریز نامعتبر است'
            ], 422);
            return;
        }

        if ($reason === '') {
            $this->response->json([
                'success' => false,
                'message' => 'دلیل رد الزامی است'
            ], 422);
            return;
        }

        $result = $this->manualDepositService->reject($adminId, $depositId, $reason);

        $this->response->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => $result['message'] ?? 'خطا'
        ], ($result['success'] ?? false) ? 200 : 422);
        return;
    } catch (\Throwable $e) {
        $this->logger->error('admin.manual_deposit.reject.failed', [
            'channel' => 'admin',
            'admin_id' => user_id(),
            'deposit_id' => $depositId ?? null,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->response->json([
            'success' => false,
            'message' => 'خطای سرور در رد واریز'
        ], 500);
    }
}
}