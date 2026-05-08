<?php

namespace App\Controllers\User;

use App\Models\UserBankCard;
use App\Services\User\UserService;
use Core\Validator;
use App\Controllers\User\BaseUserController;

class BankCardController extends BaseUserController
{
    private UserBankCard $cardModel;

    public function __construct(
        \App\Models\UserBankCard $cardModel)
    {
        parent::__construct();
        $this->cardModel = $cardModel;
    }

    /**
     * لیست کارت‌های بانکی کاربر
     */
    public function index(): void
    {
        $userId = $this->userId();
        
        try {
            $cards = $this->cardModel->getUserCards($userId);
            $cardCount = $this->cardModel->countUserCards($userId);
            
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
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
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
        $cardCount = $this->cardModel->countUserCards($userId);
        
        if ($cardCount >= 4) {
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

        // بررسی تعداد کارت‌ها
        $cardCount = $this->cardModel->countUserCards($userId);
        if ($cardCount >= 4) {
            $this->session->setFlash('error', 'حداکثر 4 کارت بانکی می‌توانید ثبت کنید');
            $this->response->redirect(url('bank-cards'));
            return;
        }

        // دریافت داده‌ها
        $data = [
            'card_number' => preg_replace('/[\s\-]/', '', $this->request->input('card_number') ?? ''),
            'account_number' => $this->request->input('account_number'),
            'sheba' => $this->request->input('sheba'),
            'bank_name' => $this->request->input('bank_name'),
            'cardholder_name' => $this->request->input('cardholder_name'),
        ];

        // اعتبارسنجی
        $validator = new Validator($data, [
            'card_number' => 'required|numeric|min:16|max:16',
            'bank_name' => 'required|min:2|max:50',
            'cardholder_name' => 'required|min:3|max:100',
        ], [
            'card_number.required' => 'شماره کارت الزامی است',
            'card_number.numeric' => 'شماره کارت باید عددی باشد',
            'card_number.min' => 'شماره کارت باید 16 رقم باشد',
            'card_number.max' => 'شماره کارت باید 16 رقم باشد',
            'bank_name.required' => 'نام بانک الزامی است',
            'cardholder_name.required' => 'نام صاحب کارت الزامی است',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $firstError = array_values($errors)[0][0] ?? 'اطلاعات نامعتبر است';
            $this->session->setFlash('error', $firstError);
            $this->session->setFlash('old', $data);
            $this->response->redirect(url('bank-cards/create'));
            return;
        }

        try {
            // دریافت نام کاربر برای بررسی
            $user = $this->userService->find($userId);

            if (!$user) {
                throw new \RuntimeException('کاربر یافت نشد');
            }

            // بررسی تطابق نام (هشدار)
            $nameMatch = \mb_stripos($data['cardholder_name'], $user->full_name) !== false;
            if (!$nameMatch) {
                $this->session->setFlash('warning', 'توجه: نام صاحب کارت با نام شما مطابقت ندارد. این کارت ممکن است رد شود.');
            }

            $data['user_id'] = $userId;
            $data['status'] = 'pending';

            $card = $this->cardModel->create($data);

            if (!$card) {
                throw new \RuntimeException('این کارت قبلاً ثبت شده است');
            }

            // ثبت لاگ
            $this->logger->activity(
    'bank_card_created',
    'ثبت کارت بانکی جدید: ' . \substr($data['card_number'], 0, 6) . '******' . \substr($data['card_number'], -4),
    $userId,
    ['card_id' => $card->id]
);

            $this->session->setFlash('success', 'کارت بانکی با موفقیت ثبت شد و در انتظار تأیید است');
            $this->response->redirect(url('bank-cards'));

        } catch (\Exception $e) {
    $this->logger->error('bank_card.store.failed', [
        'channel' => 'banking',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
            
            $this->session->setFlash('error', $e->getMessage());
            $this->session->setFlash('old', $data);
            $this->response->redirect(url('bank-cards/create'));
        }
    }

    /**
     * تنظیم کارت پیش‌فرض
     */
    public function setDefault(string $id): void
    {
        $userId = $this->userId();
        $cardId = (int)$id;

        try {
            $card = $this->cardModel->find($cardId);

            if (!$card || $card->user_id !== $userId) {
                $this->response->json([
                    'success' => false,
                    'message' => 'کارت یافت نشد'
                ]);
                return;
            }

            if ($card->status !== 'verified') {
                $this->response->json([
                    'success' => false,
                    'message' => 'فقط کارت‌های تأییدشده را می‌توانید پیش‌فرض کنید'
                ]);
                return;
            }

            $updated = $this->cardModel->setDefault($cardId, $userId);

            if ($updated) {
                $this->logger->activity('bank_card_set_default', 'تنظیم کارت پیش‌فرض', $userId, ['card_id' => $cardId] ?? []);

                $this->response->json([
                    'success' => true,
                    'message' => 'کارت پیش‌فرض با موفقیت تنظیم شد'
                ]);
            } else {
                throw new \RuntimeException('خطا در تنظیم کارت پیش‌فرض');
            }

        } catch (\Exception $e) {
    $this->logger->error('bank_card.set_default.failed', [
        'channel' => 'banking',
        'user_id' => $userId,
        'card_id' => $cardId ?? null,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->response->json([
                'success' => false,
                'message' => 'خطا در تنظیم کارت پیش‌فرض'
            ]);
        }
    }

    /**
     * حذف کارت بانکی
     */
    public function delete(string $id): void
    {
        $userId = $this->userId();
        $cardId = (int)$id;

        try {
            $card = $this->cardModel->find($cardId);

            if (!$card || $card->user_id !== $userId) {
                $this->response->json([
                    'success' => false,
                    'message' => 'کارت یافت نشد'
                ]);
                return;
            }

            $deleted = $this->cardModel->deleteForUser($cardId, $userId);
if ($deleted) {
    $this->logger->activity(
        'bank_card_deleted',
        'حذف کارت بانکی',
        $userId,
        ['card_id' => $cardId]
    );

    $this->response->json([
        'success' => true,
        'message' => 'کارت بانکی با موفقیت حذف شد'
    ]);
} else {
                throw new \RuntimeException('این کارت قابل حذف نیست (احتمالاً در تراکنش‌ها استفاده شده)');
            }

        } catch (\Exception $e) {
    $this->logger->error('bank_card.delete.failed', [
        'channel' => 'banking',
        'user_id' => $userId,
        'card_id' => $cardId ?? null,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->response->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}