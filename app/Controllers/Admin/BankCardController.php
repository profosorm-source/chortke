<?php

namespace App\Controllers\Admin;

use App\Controllers\Admin\BaseAdminController;
use App\Services\AuditTrail;
use App\Services\BankCardService;
use App\Models\BankCard;
use Core\Logger;

class BankCardController extends BaseAdminController
{
    private Logger $logger;
    private AuditTrail $auditTrail;
    private BankCardService $bankCardService;
    private BankCard $bankCardModel;

    public function __construct(
        Logger $logger,
        AuditTrail $auditTrail,
        BankCardService $bankCardService,
        BankCard $bankCardModel
    ) {
        parent::__construct();
        $this->logger = $logger;
        $this->auditTrail = $auditTrail;
        $this->bankCardService = $bankCardService;
        $this->bankCardModel = $bankCardModel;
    }

    public function index()
    {
        try {
            // اگر متد خاص در مدل شما فرق دارد همینجا اسمش را عوض کن
            $cards = method_exists($this->bankCardModel, 'getPendingCards')
                ? $this->bankCardModel->getPendingCards(100, 0)
                : [];

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
                $card = $this->bankCardModel->find($id);

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

                $this->session->setFlash('success', $result['message'] ?? 'کارت تایید شد');
            } else {
                $this->session->setFlash('error', $result['message'] ?? 'خطا در تایید کارت');
            }

            return redirect('/admin/bank-cards');
        } catch (\Throwable $e) {
            $this->logger->error('admin.bank_card.verify.failed', [
                'channel' => 'admin',
                'card_id' => $id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->session->setFlash('error', 'خطا در تایید کارت');
            return redirect('/admin/bank-cards');
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
                $card = $this->bankCardModel->find($id);

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

                $this->session->setFlash('success', $result['message'] ?? 'کارت رد شد');
            } else {
                $this->session->setFlash('error', $result['message'] ?? 'خطا در رد کارت');
            }

            return redirect('/admin/bank-cards');
        } catch (\Throwable $e) {
            $this->logger->error('admin.bank_card.reject.failed', [
                'channel' => 'admin',
                'card_id' => $id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->session->setFlash('error', 'خطا در رد کارت');
            return redirect('/admin/bank-cards');
        }
    }
}