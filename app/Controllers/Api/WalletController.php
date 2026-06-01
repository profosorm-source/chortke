<?php

namespace App\Controllers\Api;

use App\Contracts\WalletServiceInterface;
use App\Services\ApiRateLimiter;

/**
 * API\WalletController - کیف‌پول
 *
 * GET  /api/v1/wallet              → موجودی
 * GET  /api/v1/wallet/transactions → تاریخچه تراکنش‌ها
 */
class WalletController extends BaseApiController
{
    private WalletServiceInterface $walletService;

    public function __construct(
        WalletServiceInterface $walletService
    , ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->walletService = $walletService;
    }

    /** موجودی کیف‌پول */
    public function balance(): never
    {
        $userId = $this->userId();
        $balances = $this->walletService->getWalletBalances($userId);

        if (empty($balances)) {
            $this->error('کیف‌پول یافت نشد', 404);
        }

        $this->success($balances);
    }

    /** تاریخچه تراکنش‌ها */
    public function transactions(): never
    {
        $userId            = $this->userId();
        [$page, $perPage, $offset] = $this->paginationParams(20);

        $filters = [];
        if ($type = $this->request->get('type')) {
            $filters['type'] = $type;
        }
        if ($status = $this->request->get('status')) {
            $filters['status'] = $status;
        }
        if ($currency = $this->request->get('currency')) {
            $filters['currency'] = $currency;
        }

        $items = $this->walletService->getUserTransactions($userId, $perPage, $offset, $filters);
        $total = $this->walletService->countUserTransactions($userId, $filters);

        // پاکسازی داده‌های حساس
        $items = array_map(fn($tx) => [
            'id'          => $tx->id,
            'type'        => $tx->type,
            'amount'      => (string)$tx->amount,
            'currency'    => $tx->currency,
            'status'      => $tx->status,
            'description' => $tx->description,
            'created_at'  => $tx->created_at,
        ], $items);

        $this->paginated($items, $total, $page, $perPage);
    }
}
