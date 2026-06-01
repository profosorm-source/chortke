<?php

namespace App\Controllers\User;

use App\Services\ManualDepositService;
use App\Services\BankCardService;
use App\Services\UploadService;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;

class ManualDepositController extends BaseUserController
{
    private ManualDepositService $depositService;
    private BankCardService $cardService;
    private UploadService $uploadService;
    private \App\Services\Shared\IdempotencyService $idempotencyService;

    public function __construct(
        ManualDepositService $depositService,
        BankCardService $cardService,
        \App\Services\UploadService $uploadService,
        ?\App\Services\Shared\IdempotencyService $idempotencyService = null,
        ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->depositService = $depositService;
        $this->cardService = $cardService;
        $this->uploadService = $uploadService;
        $this->idempotencyService = $idempotencyService ?? \Core\Container::getInstance()->make(\App\Services\Shared\IdempotencyService::class);
    }

    /**
     * فرم واریز دستی
     */
    public function create(): void
    {
        $userId = $this->userId();

        try {
            // دریافت کارت‌های تأییدشده
            $cards = $this->cardService->getUserCards($userId, 'verified');

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
            return;
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

        $data = [
            'bank_card_id'   => $this->request->input('bank_card_id'),
            'amount'         => $this->request->input('amount'),
            'tracking_code'  => $this->request->input('tracking_code'),
            'user_description' => $this->request->input('description'),
        ];

        $idempotencyKey = $this->request->input('idempotency_key');

        $validator = $this->validatorFactory()->make($data, [
            'bank_card_id'   => 'required|numeric',
            'amount'         => 'required|numeric|min:10000',
            'tracking_code'  => 'required|min:5|max:50',
        ], [
            'bank_card_id.required'   => 'انتخاب کارت الزامی است',
            'amount.required'         => 'مبلغ الزامی است',
            'amount.numeric'          => 'مبلغ باید عددی باشد',
            'amount.min'              => 'حداقل مبلغ واریز 10,000 تومان است',
            'tracking_code.required'  => 'شماره پیگیری الزامی است',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0]);
            $this->session->setFlash('old', $data);
            redirect('/wallet/deposit/manual');
            return;
        }

        try {
            // بررسی کارت با استفاده از Service
            $card = $this->cardService->findVerifiedCardForUser($userId, (int)$data['bank_card_id']);
            if (!$card) {
                throw new \RuntimeException('کارت نامعتبر است');
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

            // تولید کلید قطعی در صورت عدم ارسال
            $effectiveIdempotencyKey = $idempotencyKey ?: null;

            // استفاده از IdempotencyService برای بسته‌بندی امن و تضمین Idempotency
            $result = $this->idempotencyService->executeWithTransaction(
                'manual_deposit.create',
                $userId,
                $data,
                function() use ($userId, $data, $receiptPath) {
                    return $this->depositService->create($userId, [
                        'bank_card_id' => (int)$data['bank_card_id'],
                        'amount' => (string)$data['amount'],
                        'tracking_code' => (string)$data['tracking_code'],
                        'user_description' => (string)($data['user_description'] ?? ''),
                    ], $receiptPath);
                },
                $effectiveIdempotencyKey
            );

            if (!($result['success'] ?? false)) {
                throw new \RuntimeException($result['message'] ?? 'خطا در ثبت درخواست');
            }

            $this->logger->activity('manual_deposit_requested', "درخواست واریز دستی {$data['amount']} تومان", $userId, [
                    'deposit_id'    => $result['deposit_id'] ?? null,
                    'tracking_code' => $data['tracking_code'],
                    'request_id'    => $requestId,
                    'ip'            => $ipAddress,
                ] ?? []);

            $this->session->setFlash('success', 'درخواست واریز شما ثبت شد و در انتظار بررسی است');
            redirect('/wallet');
            return;
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
            return;
        }
    }

    /**
     * لیست درخواست‌های واریز دستی کاربر
     */
    public function index(): void
    {
        $userId = $this->userId();

        try {
            // دریافت Model از طریق Container برای نمایش لیست
            $depositModel = app()->make(\App\Models\ManualDeposit::class);
            $deposits = $depositModel->where('user_id', $userId)->orderBy('created_at', 'DESC')->get();

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
            return;
        }
    }
}