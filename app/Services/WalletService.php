<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\Wallet;
use App\Models\Transaction;
use Core\Database;
use App\Services\AuditTrail;
use App\Services\LedgerService;
use App\Services\SettingService;
use App\Contracts\WalletServiceInterface;
use App\Services\Cache\CacheInvalidationService;
use App\Traits\WalletHelperTrait;

class WalletService extends \App\Services\BaseService implements WalletServiceInterface
{
    use WalletHelperTrait;

    private Wallet      $walletModel;
    private Transaction $transactionModel;
    private Database    $db;
    private ?LedgerService $ledgerService = null;
    private AuditTrail $auditTrail;
    private DistributedLockService $lockService;
    private SettingService $settingService;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;
    private \Core\Cache $cache;
    private ?CacheInvalidationService $cacheInvalidation;
    private ?OutboxService $outbox;
    private array $supportedCurrencies = ['irt', 'usdt'];
    private \Core\IdempotencyKey $idempotencyKey;

    public function __construct(
        Database $db,
        \App\Models\Wallet $walletModel,
        \App\Models\Transaction $transactionModel,
        \Core\IdempotencyKey $idempotencyKey,
        LoggerInterface $logger,
        AuditTrail $auditTrail,
        LedgerService $ledgerService,
        DistributedLockService $lockService,
        SettingService $settingService,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard,
        \Core\Cache $cache,
        ?CacheInvalidationService $cacheInvalidation = null,
        ?OutboxService $outbox = null
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->walletModel = $walletModel;
        $this->transactionModel = $transactionModel;
        $this->idempotencyKey = $idempotencyKey;
        $this->auditTrail = $auditTrail;
        $this->ledgerService = $ledgerService;
        $this->lockService = $lockService;
        $this->settingService = $settingService;
        $this->fraudGuard = $fraudGuard;
        $this->cache = $cache;
        $this->cacheInvalidation = $cacheInvalidation;
        $this->outbox = $outbox;

        // Load supported currencies from config
        $configuredCurrencies = $settingService->get('wallet_supported_currencies');
        if (is_array($configuredCurrencies) && !empty($configuredCurrencies)) {
            $this->supportedCurrencies = array_map('strtolower', $configuredCurrencies);
        } elseif (function_exists('config')) {
            $configCurrencies = config('wallet.supported_currencies', ['irt', 'usdt']);
            if (is_array($configCurrencies)) {
                $this->supportedCurrencies = array_map('strtolower', $configCurrencies);
            }
        }
    }
}
