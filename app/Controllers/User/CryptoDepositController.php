<?php

namespace App\Controllers\User;

use App\Models\CryptoDeposit;
use App\Controllers\User\BaseUserController;

class CryptoDepositController extends BaseUserController
{
    private CryptoDeposit $depositModel;
    private \Core\IdempotencyKey $idempotencyKey;

    public function __construct(
        \App\Models\CryptoDeposit $depositModel,
        \Core\IdempotencyKey $idempotencyKey
    ) {
        parent::__construct();
        $this->depositModel = $depositModel;
        $this->idempotencyKey = $idempotencyKey;
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
            $bnb20Address = setting('site_usdt_bnb20_address');
            $trc20Address = setting('site_usdt_trc20_address');

            if (!$bnb20Address && !$trc20Address) {
                $this->session->setFlash('error', 'آدرس کیف پول سایت تنظیم نشده است');
                redirect('/wallet');
                return;
            }

            $minDeposit = (float)setting('min_withdrawal_usdt', 10);

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
            return;
        }
    }

    /**
     * ثبت درخواست واریز کریپتو
     */
    public function store(): void
    {
        // CSRF verification (M-09)
        $this->validateCsrf();

        $userId = $this->userId();

        // بررسی درخواست در انتظار
        if ($this->depositModel->hasPendingDeposit($userId)) {
            $this->session->setFlash('error', 'شما یک درخواست واریز در انتظار بررسی دارید');
            redirect('/wallet');
            return;
        }

        // دریافت داده‌ها
        $data = [
            'network' => trim(strtolower((string)$this->request->input('network'))),
            'amount' => $this->request->input('amount'),
            'tx_hash' => trim((string)$this->request->input('tx_hash')),
            'deposit_date' => $this->request->input('deposit_date'),
            'deposit_time' => $this->request->input('deposit_time'),
        ];

        // اعتبارسنجی
        $validator = $this->validatorFactory()->make($data, [
            'network' => 'required|in:bnb20,trc20,sol,erc20,ton',
            'amount' => 'required|numeric|min:10',
            'tx_hash' => 'required',
            'deposit_date' => 'required',
            'deposit_time' => 'required',
        ], [
            'network.required' => 'انتخاب شبکه الزامی است',
            'network.in' => 'شبکه نامعتبر است',
            'amount.required' => 'مبلغ الزامی است',
            'amount.min' => 'حداقل مبلغ واریز 10 USDT است',
            'tx_hash.required' => 'هش تراکنش الزامی است',
            'deposit_date.required' => 'تاریخ واریز الزامی است',
            'deposit_time.required' => 'ساعت واریز الزامی است',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0]);
            $this->session->setFlash('old', $data);
            redirect('/wallet/deposit/crypto');
            return;
        }

        // اعتبارسنجی هش تراکنش بر اساس شبکه (جلوگیری از Poisoning و Bypass)
        // Normalize transaction hash (C-10, C-14). Ethereum/BSC/Tron hashes are case-insensitive, Solana is case-sensitive (Base58).
        $txHash = trim((string)$data['tx_hash']);
        $network = $data['network'];
        if ($network !== 'sol') {
            $txHash = strtolower($txHash);
        }
        $data['tx_hash'] = $txHash;
        $hashError = null;

        if ($network === 'bnb20' || $network === 'erc20') {
            if (!preg_match('/^0x[a-f0-9]{64}$/i', $txHash)) {
                $hashError = 'هش تراکنش نامعتبر است (باید با 0x شروع شده و دارای ۶۴ کاراکتر هگزادسیمال بعد از آن باشد)';
            }
        } elseif ($network === 'trc20') {
            if (!preg_match('/^[a-f0-9]{64}$/i', $txHash)) {
                $hashError = 'هش تراکنش نامعتبر است (باید دقیقاً ۶۴ کاراکتر هگزادسیمال باشد)';
            }
        } elseif ($network === 'sol') {
            if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{88}$/', $txHash)) {
                $hashError = 'هش تراکنش Solana نامعتبر است (باید ۸۸ کاراکتر Base58 باشد)';
            }
        } elseif ($network === 'ton') {
            if (!preg_match('/^[a-f0-9]{64}$/i', $txHash) && !preg_match('/^[a-zA-Z0-9\/+]{43}=$/', $txHash)) {
                $hashError = 'هش تراکنش TON نامعتبر است (باید ۶۴ کاراکتر هگزادسیمال یا ۴۴ کاراکتر Base64 باشد)';
            }
        }

        if ($hashError !== null) {
            $this->session->setFlash('error', $hashError);
            $this->session->setFlash('old', $data);
            redirect('/wallet/deposit/crypto');
            return;
        }

        // Use IdempotencyKey wrapper to prevent duplicate API submissions
        $idempotencyKey = $this->request->header('Idempotency-Key') ?: \Core\IdempotencyKey::generateFromPayload('crypto_deposit_store', array_merge($data, ['user_id' => $userId]));

        try {
            $result = $this->idempotencyKey->wrapInstance($idempotencyKey, $userId, 'crypto_deposit_store', function() use ($userId, $data) {
                $db = \app()->db;
                $db->beginTransaction();

                try {
                    // Pessimistic lock check on tx_hash and network to prevent race condition and cross-network bypass (C-01 & C-06, M-02)
                    $existingDeposit = $this->depositModel->findByHashAndNetworkForUpdate($data['tx_hash'], $data['network']);
                    if ($existingDeposit) {
                        throw new \RuntimeException('این هش تراکنش قبلاً ثبت شده است');
                    }

                    // دریافت آدرس کیف پول مقصد
                    $walletAddress = $data['network'] === 'bnb20' 
                        ? setting('site_usdt_bnb20_address')
                        : setting('site_usdt_trc20_address');

                    if (!$walletAddress) {
                        throw new \RuntimeException('آدرس کیف پول این شبکه تنظیم نشده است');
                    }

                    $data['user_id'] = $userId;
                    $data['wallet_address'] = $walletAddress;
                    $data['verification_status'] = 'pending';
                    
                    // Set auto_check_deadline (30 mins from now in default timezone) (C-12, L-02)
                    $minutes = (int) (setting('crypto_intent_expire_minutes') ?: \App\Constants\CryptoConstants::DEFAULT_INTENT_EXPIRE_MINUTES);
                    $data['auto_check_deadline'] = (new \DateTime())
                        ->modify("+{$minutes} minutes")
                        ->format('Y-m-d H:i:s');
                    $data['auto_check_attempts'] = 0;
                    $data['created_at'] = \date('Y-m-d H:i:s');
                    $data['updated_at'] = \date('Y-m-d H:i:s');

                    $deposit = $this->depositModel->create($data);
                    
                    $db->commit();
                } catch (\Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    // If it's a PDOException with code 23000 (Integrity constraint violation) or duplicate entry
                    if ($e instanceof \PDOException && ($e->getCode() === '23000' || \str_contains($e->getMessage(), 'Duplicate entry'))) {
                        throw new \RuntimeException('این هش تراکنش در همین لحظه ثبت شد و امکان ثبت مجدد وجود ندارد.');
                    }
                    throw $e;
                }

                if (!$deposit) {
                    throw new \RuntimeException('خطا در ثبت درخواست');
                }

                // ثبت لاگ
                $this->logger->activity('crypto_deposit_requested', "درخواست واریز {$data['amount']} USDT ({$data['network']})", $userId, ['deposit_id' => $deposit->id] ?? []);

                return [
                    'success' => true,
                    'message' => 'درخواست واریز شما ثبت شد و در حال بررسی خودکار است',
                    'deposit_id' => $deposit->id
                ];
            }, $data);

            if ($result['success'] ?? false) {
                $this->session->setFlash('success', $result['message']);
                redirect('/wallet');
                return;
            } else {
                throw new \RuntimeException($result['message'] ?? 'خطا در ثبت درخواست');
            }

        } catch (\Exception $e) {
            $this->logger->error('crypto_deposit.store.failed', [
                'channel' => 'crypto',
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $message = $e instanceof \RuntimeException 
                ? $e->getMessage() 
                : 'خطای سیستمی در ثبت درخواست';

            $this->session->setFlash('error', $message);
            $this->session->setFlash('old', $data);
            redirect('/wallet/deposit/crypto');
            return;
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
            return;
        }
    }
}