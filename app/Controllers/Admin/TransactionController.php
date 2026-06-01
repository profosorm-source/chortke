<?php

namespace App\Controllers\Admin;
use App\Services\User\UserService;

use App\Contracts\WalletServiceInterface;
use App\Controllers\Admin\BaseAdminController;

class TransactionController extends BaseAdminController
{
    private UserService $userService;
    private WalletServiceInterface $walletService;

    public function __construct(UserService $userService,
        WalletServiceInterface $walletService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->userService = $userService;
        $this->walletService = $walletService;
    }

    /**
     * لیست تمام تراکنش‌ها
     */
    public function index(): void
{
    
    $page = (int) $this->request->get('page', 1);
    if ($page < 1) $page = 1;

    $status = $this->request->get('status');
    $type = $this->request->get('type');
    $currency = $this->request->get('currency');

    $limit = 50;
    $offset = ($page - 1) * $limit;

    try {
        $transactions = $this->walletService->getAllTransactions($status, $type, $currency, $limit, $offset);
        $total = $this->walletService->countAllTransactions($status, $type, $currency);
        $totalPages = (int) \ceil($total / $limit);

        echo view('admin.transactions.index', [
            'transactions' => $transactions ?? [],
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'status' => $status,
            'type' => $type,
            'currency' => $currency,
            'pageTitle' => 'تراکنش‌های مالی',
        ]);
        return;

    } catch (\Throwable $e) {
    try {
        $this->logger->error('admin.transactions.index.failed', [
            'channel' => 'admin',
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    } catch (\Throwable $ignore) {}

        $this->session->setFlash('error', 'خطا در دریافت لیست تراکنش‌ها');

        // ✅ به جای redirect به داشبورد، همان صفحه را با لیست خالی نشان بده
        echo view('admin.transactions.index', [
            'transactions' => [],
            'currentPage' => 1,
            'totalPages' => 1,
            'total' => 0,
            'status' => $status,
            'type' => $type,
            'currency' => $currency,
            'pageTitle' => 'تراکنش‌های مالی',
        ]);
        return;
    }
}

    /**
     * نمایش جزئیات تراکنش
     */
    public function show(): void
    {
                $transactionId = (int)$this->request->get('id');

        try {
            $transaction = $this->walletService->findTransactionById($transactionId);

            if (!$transaction) {
                $this->session->setFlash('error', 'تراکنش یافت نشد');
                redirect('/admin/transactions');
                return;
            }

            // دریافت اطلاعات کاربر
            $user = $this->userService->find($transaction->user_id);

            // تبدیل metadata از JSON
            $metadata = null;
            if ($transaction->metadata) {
                $metadata = \json_decode($transaction->metadata, true);
            }

            view('admin.transactions.show', [
                'transaction' => $transaction,
                'user' => $user,
                'metadata' => $metadata,
                'pageTitle' => 'جزئیات تراکنش'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('admin.transaction.show.failed', [
        'channel' => 'admin',
        'transaction_id' => $transactionId ?? null,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
            redirect('/admin/transactions');
        }
    }
}