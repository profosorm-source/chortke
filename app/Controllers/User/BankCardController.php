<?php

namespace App\Controllers\User;

use App\Services\BankCardService;
use App\Services\User\UserService;
use Core\Validator;
use App\Controllers\User\BaseUserController;

class BankCardController extends BaseUserController
{
    private BankCardService $bankCardService;

    public function __construct(BankCardService $bankCardService)
    {
        parent::__construct();
        $this->bankCardService = $bankCardService;
    }

    /**
     * لیست کارت‌های بانکی کاربر
     */
    public function index(): void
    {
        $userId = $this->userId();
        
        try {
            $cards = $this->bankCardService->getUserCards($userId);
            $cardCount = count($cards); // Helper or proxy
            
            view('user.bank-cards.index', [
                'cards' => $cards,
                'cardCount' => $cardCount,
                'maxCards' => 4,
                'pageTitle' => 'کارت‌های بانکی من'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('bank_card.index.failed', [
                'channel' => 'banking',
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            $this->session->setFlash('error', 'خطا در دریافت لیست کارت‌ها');
            $this->response->redirect(url('wallet'));
        }
    }

    /**
     * فرم افزودن کارت بانکی
     */
    public function create(): void
    {
        $userId = $this->userId();
        $cards = $this->bankCardService->getUserCards($userId);
        
        if (count($cards) >= 4) {
            $this->session->setFlash('error', 'حداکثر 4 کارت بانکی می‌توانید ثبت کنید');
            $this->response->redirect(url('bank-cards'));
            return;
        }

        view('user.bank-cards.create', [
            'pageTitle' => 'افزودن کارت بانکی'
        ]);
    }

    /**
     * ذخیره کارت بانکی جدید
     */
    public function store(): void
    {
        $userId = $this->userId();
        $input = $this->request->all();
        
        // Convert request format to service expected payload
        $payload = [
            'card_number' => $input['card_number'] ?? '',
            'card_holder' => $input['cardholder_name'] ?? '',
            'iban' => $input['sheba'] ?? ''
        ];

        $result = $this->bankCardService->create($userId, $payload);

        if (!empty($result['success'])) {
            $this->session->setFlash('success', $result['message'] ?? 'عملیات موفق');
            $this->response->redirect(url('bank-cards'));
        } else {
            $this->session->setFlash('error', $result['message'] ?? 'خطا در ثبت');
            $this->session->setFlash('old', $input);
            $this->response->redirect(url('bank-cards/create'));
        }
    }

    /**
     * تنظیم کارت پیش‌فرض
     */
    public function setDefault(string $id): void
    {
        $userId = $this->userId();
        $result = $this->bankCardService->setPrimary($userId, (int)$id);
        $this->response->json($result);
    }

    /**
     * حذف کارت بانکی
     */
    public function delete(string $id): void
    {
        $userId = $this->userId();
        $result = $this->bankCardService->softDeleteByUser($userId, (int)$id);
        $this->response->json($result);
    }
}
