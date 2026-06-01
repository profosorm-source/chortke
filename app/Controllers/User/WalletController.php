<?php

namespace App\Controllers\User;

use App\Contracts\WalletServiceInterface;
use App\Controllers\User\BaseUserController;

class WalletController extends BaseUserController
{
    private WalletServiceInterface $walletService;

    public function __construct(WalletServiceInterface $walletService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->walletService = $walletService;
    }

    /**
     * صفحه اصلی کیف پول (نمایش موجودی)
     */
    public function index(): void
    {
        $userId = $this->userId();
        
        try {
            $summary = $this->walletService->getWalletSummary($userId);
            $siteCurrency = config('site_currency', 'irt');
            
            view('user.wallet.index', [
                'summary' => $summary,
                'siteCurrency' => $siteCurrency,
                'pageTitle' => 'کیف پول من'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('wallet.index.failed', [
        'channel' => 'wallet',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
            
            $this->session->setFlash('error', 'خطا در دریافت اطلاعات کیف پول');
            $this->response->redirect(url('dashboard'));
        }
    }

    /**
     * صفحه انتخاب روش افزایش موجودی
     */
    public function depositIndex(): void
    {
        $siteCurrency = config('site_currency', 'irt');
        
        view('user.wallet.deposit-select', [
            'siteCurrency' => $siteCurrency,
            'pageTitle' => 'افزایش موجودی'
        ]);
    }

    /**
     * تاریخچه تراکنش‌ها
     */
    public function history(): void
    {
                $userId = $this->userId();
        
        $page = (int)$this->request->get('page', 1);
        $type = $this->request->get('type');
        $currency = $this->request->get('currency');
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            $filters = ['type' => $type, 'currency' => $currency];
            
            $transactions = $this->walletService->getUserTransactions(
                $userId,
                $limit,
                $offset,
                $filters
            );
            
            $total = $this->walletService->countUserTransactions($userId, $filters);
            $totalPages = (int)\ceil($total / $limit);

            view('user.wallet.history', [
                'transactions' => $transactions,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'type' => $type,
                'currency' => $currency,
                'pageTitle' => 'تاریخچه تراکنش‌ها'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('wallet.transaction_history.failed', [
        'channel' => 'wallet',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
            
            $this->session->setFlash('error', 'خطا در دریافت تاریخچه');
            $this->response->redirect(url('wallet'));
        }
    }
}