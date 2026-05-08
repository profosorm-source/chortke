<?php

namespace App\Controllers\User;

use App\Models\ManualDeposit;
use App\Models\UserBankCard;
use App\Services\UploadService;
use Core\Validator;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;

class ManualDepositController extends BaseUserController
{
    private ManualDeposit $depositModel;
    private UserBankCard $cardModel;
    private UploadService $uploadService;

    public function __construct(
        \App\Models\ManualDeposit $depositModel,
        \App\Models\UserBankCard $cardModel,
        \App\Services\UploadService $uploadService)
    {
        parent::__construct();
        $this->depositModel = $depositModel;
        $this->cardModel = $cardModel;
        $this->uploadService = $uploadService;
    }

    /**
     * فرم واریز دستی
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

            // دریافت کارت‌های تأییدشده
            $cards = $this->cardModel->getUserCards($userId, 'verified');

            if (empty($cards)) {
                $this->session->setFlash('error', 'ابتدا باید کارت بانکی خود را ثبت و تأیید کنید');
                redirect('/bank-cards/create');
                return;
            }

            // دریافت اطلاعات بانکی سایت از system_settings
            $siteCardNumber    = setting('site_irt_card_number');
            $siteAccountNumber = setting('site_irt_account_number');
            $siteSheba         = setting('site_irt_sheba');
            $siteBankName      = setting('site_irt_bank_name');

            if (!$siteCardNumber) {
                $this->session->setFlash('error', 'اطلاعات بانکی سایت تنظیم نشده است. لطفاً با پشتیبانی تماس بگیرید');
                redirect('/wallet');
                return;
            }

            view('user.manual-deposit.create', [
                'cards'             => $cards,
                'siteCardNumber'    => $siteCardNumber,
                'siteAccountNumber' => $siteAccountNumber,
                'siteSheba'         => $siteSheba,
                'siteBankName'      => $siteBankName,
                'pageTitle'         => 'واریز دستی'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('manual_deposit.create.failed', [
        'channel' => 'manual_deposit',
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
     * ثبت درخواست واریز دستی
     */
    public function store(): void
    {
        $userId = $this->userId();
        ApiRateLimiter::enforce('manual_deposit', (int)user_id(), is_ajax());

        $requestId         = get_request_id();
        $ipAddress         = get_client_ip();
        $deviceFingerprint = generate_device_fingerprint();

        // بررسی درخواست در انتظار
        if ($this->depositModel->hasPendingDeposit($userId)) {
            $this->session->setFlash('error', 'شما یک درخواست واریز در انتظار بررسی دارید');
            redirect('/wallet');
            return;
        }

        $data = [
            'card_id'        => $this->request->input('card_id'),
            'amount'         => $this->request->input('amount'),
            'tracking_code'  => $this->request->input('tracking_code'),
            'deposit_date'   => $this->request->input('deposit_date'),
            'deposit_time'   => $this->request->input('deposit_time'),
        ];

        $idempotencyKey = $this->request->input('idempotency_key');

        $validator = new Validator($data, [
            'card_id'       => 'required|numeric',
            'amount'        => 'required|numeric|min:10000',
            'tracking_code' => 'required|min:5|max:50',
            'deposit_date'  => 'required',
            'deposit_time'  => 'required',
        ], [
            'card_id.required'       => 'انتخاب کارت الزامی است',
            'amount.required'        => 'مبلغ الزامی است',
            'amount.numeric'         => 'مبلغ باید عددی باشد',
            'amount.min'             => 'حداقل مبلغ واریز 10,000 تومان است',
            'tracking_code.required' => 'شماره پیگیری الزامی است',
            'deposit_date.required'  => 'تاریخ واریز الزامی است',
            'deposit_time.required'  => 'ساعت واریز الزامی است',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0]);
            $this->session->setFlash('old', $data);
            redirect('/wallet/deposit/manual');
            return;
        }

        try {
            $card = $this->cardModel->find((int)$data['card_id']);

            if (!$card || $card->user_id !== $userId || $card->status !== 'verified') {
                throw new \RuntimeException('کارت نامعتبر است');
            }

            $existingDeposit = $this->depositModel->findByTrackingCode($data['tracking_code'], $userId);
            if ($existingDeposit) {
                throw new \RuntimeException('این شماره پیگیری قبلاً ثبت شده است');
            }

            $receiptPath = null;
            $receiptFile = $this->request->file('receipt_image');

            if ($receiptFile && $receiptFile['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $this->uploadService->upload(
                    $receiptFile,
                    'receipts',
                    ['image/jpeg', 'image/png'],
                    2 * 1024 * 1024
                );

                if ($uploadResult['success']) {
                    $receiptPath = $uploadResult['path'];
                } else {
                    throw new \RuntimeException($uploadResult['message']);
                }
            }

            $data['user_id']            = $userId;
            $data['receipt_image']      = $receiptPath;
            $data['status']             = 'pending';
            $data['request_id']         = $requestId;
            $data['ip_address']         = $ipAddress;
            $data['device_fingerprint'] = $deviceFingerprint;
            $data['idempotency_key']    = $idempotencyKey;

            $deposit = $this->depositModel->create($data);

            if (!$deposit) {
                throw new \RuntimeException('خطا در ثبت درخواست');
            }

            $this->logger->activity('manual_deposit_requested', "درخواست واریز دستی {$data['amount']} تومان", $userId, [
                    'deposit_id'    => $deposit->id,
                    'tracking_code' => $data['tracking_code'],
                    'request_id'    => $requestId,
                    'ip'            => $ipAddress,
                ] ?? []);

            $this->session->setFlash('success', 'درخواست واریز شما ثبت شد و در انتظار بررسی است');
            redirect('/wallet');

        } catch (\Exception $e) {
    $this->logger->error('manual_deposit.store.failed', [
        'channel' => 'manual_deposit',
        'request_id' => $requestId ?? null,
        'user_id' => $userId,
        'amount' => $data['amount'] ?? 0,
        'tracking_code' => $data['tracking_code'] ?? null,
        'ip' => $ipAddress ?? null,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', $e->getMessage());
            $this->session->setFlash('old', $data);
            redirect('/wallet/deposit/manual');
        }
    }

    /**
     * لیست درخواست‌های واریز دستی کاربر
     */
    public function index(): void
    {
        $userId = $this->userId();

        try {
            $deposits = $this->depositModel->getUserDeposits($userId);

            view('user.manual-deposit.index', [
                'deposits'  => $deposits,
                'pageTitle' => 'درخواست‌های واریز دستی'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('manual_deposit.index.failed', [
        'channel' => 'manual_deposit',
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