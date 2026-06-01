<?php

namespace App\Controllers\Admin;

use App\Controllers\Admin\BaseAdminController;
use App\Services\AuditTrail;
use App\Services\BankCardService;
use Core\Logger;

class BankCardController extends BaseAdminController
{
    private Logger $logger;
    private AuditTrail $auditTrail;
    private BankCardService $bankCardService;

    public function __construct(
        Logger $logger,
        AuditTrail $auditTrail,
        BankCardService $bankCardService
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->logger = $logger;
        $this->auditTrail = $auditTrail;
        $this->bankCardService = $bankCardService;
    }

    public function index()
    {
        try {
            // اگر متد خاص در مدل شما فرق دارد همینجا اسمش را عوض کن
            $cards = $this->bankCardService->getPendingCards(100, 0);

            return view('admin.bank-cards.index', [
                'cards' => $cards
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('admin.bank_cards.index.failed', [
                'channel' => 'admin',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return view('errors.500');
        }
    }

    public function verify()
    {
        $id = (int)$this->request->param('id');

        try {
            if ($id <= 0) {
                $this->session->setFlash('error', 'شناسه کارت نامعتبر است');
                return redirect('/admin/bank-cards');
            }

            $adminId = (int)user_id();
            $result = $this->bankCardService->adminVerify($adminId, $id, true, null);

            if (!empty($result['success'])) {
                $card = $this->bankCardService->findById($id);
                
                $this->auditTrail->record(
                    'bank_card.verified',
                    $card->user_id ?? null,
                    [
                        'channel' => 'wallet',
                        'card_id' => $id,
                        'admin_id' => $adminId,
                    ],
                    $adminId
                );

                $this->logger->activity(
                    'bank_card.verified',
                    "تایید کارت بانکی #{$id}",
                    $adminId,
                    ['channel' => 'admin']
                );

                $this->response->json([
                    'success' => true,
                    'message' => $result['message'] ?? 'کارت با موفقیت تایید شد',
                    'redirect' => url('/admin/bank-cards')
                ]);
            } else {
                $this->response->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'خطا در تایید کارت'
                ], 400);
            }
        } catch (\Throwable $e) {
            $this->logger->error('admin.bank_card.verify.failed', [
                'channel' => 'admin',
                'card_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $this->response->json([
                'success' => false,
                'message' => 'بروز خطای سیستمی در تایید کارت'
            ], 500);
        }
    }

    public function reject()
    {
        $id = (int)$this->request->param('id');
        $reason = trim((string)$this->request->post('reason'));

        try {
            if ($id <= 0) {
                $this->session->setFlash('error', 'شناسه کارت نامعتبر است');
                return redirect('/admin/bank-cards');
            }

            if ($reason === '') {
                $this->session->setFlash('error', 'دلیل رد الزامی است');
                return redirect('/admin/bank-cards');
            }

            $adminId = (int)user_id();
            $result = $this->bankCardService->adminVerify($adminId, $id, false, $reason);

            if (!empty($result['success'])) {
                $card = $this->bankCardService->findById($id);

                $this->auditTrail->record(
                    'bank_card.rejected',
                    $card->user_id ?? null,
                    [
                        'channel' => 'wallet',
                        'card_id' => $id,
                        'admin_id' => $adminId,
                        'reason' => $reason,
                    ],
                    $adminId
                );

                $this->logger->activity(
                    'bank_card.rejected',
                    "رد کارت بانکی #{$id}",
                    $adminId,
                    ['channel' => 'admin', 'reason' => $reason]
                );

                $this->response->json([
                    'success' => true,
                    'message' => $result['message'] ?? 'کارت با موفقیت رد شد',
                    'redirect' => url('/admin/bank-cards')
                ]);
            } else {
                $this->response->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'خطا در رد کردن کارت'
                ], 400);
            }
        } catch (\Throwable $e) {
            $this->logger->error('admin.bank_card.reject.failed', [
                'channel' => 'admin',
                'card_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $this->response->json([
                'success' => false,
                'message' => 'بروز خطای سیستمی در پردازش کارت'
            ], 500);
        }
    }
}