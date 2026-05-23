<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Notification\NotificationService;
use App\Services\Payment\PaymentBaseService;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Models\Withdrawal;
use App\Services\SettingService;
use App\Services\BankCardService;
use App\Services\KYCService;
use App\Services\AntiFraud\FraudGuardService;
use App\Services\ReconciliationService;
use App\Services\OutboxService;
use App\Services\WalletService;
use App\Traits\WithdrawalHelperTrait;
use Core\IdempotencyKey;

class WithdrawalService extends PaymentBaseService
{
    use WithdrawalHelperTrait;

    private Database $db;
    private KYCService $kycService;
    private WalletService $wallet;
    private FraudGuardService $fraudGuard;
    private BankCardService $bankCardService;
    private OutboxService $outbox;
    private ReconciliationService $reconciliation;
    private AuditTrail $auditTrail;
    private Withdrawal $model;

    public function __construct(
        Database $db,
        KYCService $kycService,
        WalletService $wallet,
        FraudGuardService $fraudGuard,
        BankCardService $bankCardService,
        OutboxService $outbox,
        ReconciliationService $reconciliation,
        IdempotencyKey $idempotencyKey,
        AuditTrail $auditTrail,
        Withdrawal $model,
        LoggerInterface $logger
    ) {
        parent::__construct($logger, $idempotencyKey);
        $this->db = $db;
        $this->kycService = $kycService;
        $this->wallet = $wallet;
        $this->fraudGuard = $fraudGuard;
        $this->bankCardService = $bankCardService;
        $this->outbox = $outbox;
        $this->reconciliation = $reconciliation;
        $this->auditTrail = $auditTrail;
        $this->model = $model;
    }
}
