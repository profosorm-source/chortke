<?php

namespace App\Controllers\User;

use App\Models\CryptoDeposit;
use Core\Validator;
use App\Controllers\User\BaseUserController;

class CryptoDepositController extends BaseUserController
{
    private CryptoDeposit $depositModel;

    public function __construct(
        \App\Models\CryptoDeposit $depositModel
    ) {
        parent::__construct();
        $this->depositModel = $depositModel;
    }

    /**
     * فرم واریز کریپتو
     */
    public function create(): void
    {
        $userId = $this->userId();

        try {
            // بررسی درخواست در انتظار
            if ($this->depositModel->hasPendingDeposit($userId)) {
                $this->session->setFlash('error', 'شما یک درخواست واریز در انتظار بررسی دارید');
                redirect('/wallet');
                return;
            }

            // دریافت آدرس کیف پول‌های سایت
            $bnb20Address = config('site_usdt_bnb20_address');
            $trc20Address = config('site_usdt_trc20_address');

            if (!$bnb20Address && !$trc20Address) {
                $this->session->setFlash('error', 'آدرس کیف پول سایت تنظیم نشده است');
                redirect('/wallet');
                return;
            }

            $minDeposit = (float)config('min_withdrawal_usdt', 10);

            view('user.crypto-deposit.create', [
                'bnb20Address' => $bnb20Address,
                'trc20Address' => $trc20Address,
                'minDeposit' => $minDeposit,
                'pageTitle' => 'واریز USDT'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('crypto_deposit.create.failed', [
        'channel' => 'crypto',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در بارگذاری صفحه');
            redirect('/wallet');
        }
    }

    /**
     * ثبت درخواست واریز کریپتو
     */
    public function store(): void
    {
                $userId = $this->userId();

        // بررسی درخواست در انتظار
        if ($this->depositModel->hasPendingDeposit($userId)) {
            $this->session->setFlash('error', 'شما یک درخواست واریز در انتظار بررسی دارید');
            redirect('/wallet');
            return;
        }

        // دریافت داده‌ها
        $data = [
            'network' => $this->request->input('network'),
            'amount' => $this->request->input('amount'),
            'tx_hash' => $this->request->input('tx_hash'),
            'deposit_date' => $this->request->input('deposit_date'),
            'deposit_time' => $this->request->input('deposit_time'),
        ];

        // اعتبارسنجی
        $validator = new Validator($data, [
            'network' => 'required|in:bnb20,trc20',
            'amount' => 'required|numeric|min:10',
            'tx_hash' => 'required|min:64|max:66',
            'deposit_date' => 'required',
            'deposit_time' => 'required',
        ], [
            'network.required' => 'انتخاب شبکه الزامی است',
            'network.in' => 'شبکه نامعتبر است',
            'amount.required' => 'مبلغ الزامی است',
            'amount.min' => 'حداقل مبلغ واریز 10 USDT است',
            'tx_hash.required' => 'هش تراکنش الزامی است',
            'tx_hash.min' => 'هش تراکنش نامعتبر است',
            'deposit_date.required' => 'تاریخ واریز الزامی است',
            'deposit_time.required' => 'ساعت واریز الزامی است',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0]);
            $this->session->setFlash('old', $data);
            redirect('/wallet/deposit/crypto');
            return;
        }

        try {
            // بررسی تکراری نبودن Hash
            $existingDeposit = $this->depositModel->findByHash($data['tx_hash']);
            if ($existingDeposit) {
                throw new \RuntimeException('این هش تراکنش قبلاً ثبت شده است');
            }

            // دریافت آدرس کیف پول مقصد
            $walletAddress = $data['network'] === 'bnb20' 
                ? config('site_usdt_bnb20_address')
                : config('site_usdt_trc20_address');

            if (!$walletAddress) {
                throw new \RuntimeException('آدرس کیف پول این شبکه تنظیم نشده است');
            }

            $data['user_id'] = $userId;
            $data['wallet_address'] = $walletAddress;
            $data['verification_status'] = 'pending';

            $deposit = $this->depositModel->create($data);

            if (!$deposit) {
                throw new \RuntimeException('خطا در ثبت درخواست');
            }

            // ثبت لاگ
            $this->logger->activity('crypto_deposit_requested', "درخواست واریز {$data['amount']} USDT ({$data['network']})", $userId, ['deposit_id' => $deposit->id] ?? []);

            $this->session->setFlash('success', 'درخواست واریز شما ثبت شد و در حال بررسی خودکار است');
            redirect('/wallet');

        } catch (\Exception $e) {
    $this->logger->error('crypto_deposit.store.failed', [
        'channel' => 'crypto',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', $e->getMessage());
            $this->session->setFlash('old', $data);
            redirect('/wallet/deposit/crypto');
        }
    }

    /**
     * لیست درخواست‌های واریز کریپتو کاربر
     */
    public function index(): void
    {
        $userId = $this->userId();

        try {
            $deposits = $this->depositModel->getUserDeposits($userId);

            view('user.crypto-deposit.index', [
                'deposits' => $deposits,
                'pageTitle' => 'درخواست‌های واریز USDT'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('crypto_deposit.index.failed', [
        'channel' => 'crypto',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');
            redirect('/wallet');
        }
    }
}