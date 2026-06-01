<?php

namespace App\Adapters;

use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use App\Models\Ads;
use App\Contracts\WalletServiceInterface;
use Core\Database;
use App\Services\Settings\AppSettings;

/**
 * CustomTaskAdapter — Adapter برای سیستم Custom Tasks
 */
class CustomTaskAdapter extends AdapterBase implements AdSystemContract
{
    private Ads $taskModel;
    private WalletServiceInterface $walletService;
    private Database $db;
    public function __construct(
        Ads $taskModel,
        WalletServiceInterface $walletService,
        Database $db,
        LoggerInterface $logger,
        AppSettings $appSettings,
        ValidatorFactoryInterface $validatorFactory
    ) {        $this->taskModel = $taskModel;
        $this->walletService = $walletService;
        $this->db = $db;

        parent::__construct($logger, $settingService, $validatorFactory);
    }

    public function getType(): string
    {
        return 'custom_task';
    }

    public function create(int $userId, array $data): array
    {
        try {
            $this->validateData($data);
        } catch (\Core\Exceptions\ValidationException $e) {
            throw new \Core\Exceptions\BusinessException('داده‌های ورودی نامعتبر', $e->getErrors());
        }

        $currency = $data['currency'] ?? 'irt';
        $pricePerTask = (float) ($data['price_per_task'] ?? 0);
        $quantity = (int) ($data['total_quantity'] ?? 1);

        $feePercent = (float) $this->appSettings->get('custom_task_site_fee_percent', 10);
        $totalBudget = $pricePerTask * $quantity;
        $totalWithFee = $totalBudget + ($totalBudget * $feePercent / 100);

        try {
            $this->db->beginTransaction();

            $idempotencyKey = "ctask_" . $userId . "_" . time() . "_" . bin2hex(random_bytes(4));
            
            $txId = $this->walletService->withdraw(
                $userId,
                $totalWithFee,
                $currency,
                [
                    'type' => 'task_budget',
                    'description' => $data['title'],
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            if (!$txId) {
                $this->db->rollBack();
                throw new \Core\Exceptions\BusinessException('موجودی کافی نیست');
            }

            $task = $this->taskModel->create([
                'creator_id' => $userId,
                'title' => $data['title'],
                'description' => $data['description'],
                'price_per_task' => $pricePerTask,
                'currency' => $currency,
                'total_budget' => $totalBudget,
                'total_quantity' => $quantity,
                'status' => $this->appSettings->get('custom_task_auto_approve', 0) ? 'active' : 'pending_review',
                'site_fee_percent' => $feePercent,
                'site_fee_amount' => $totalBudget * $feePercent / 100,
            ]);

            if (!$task) {
                $this->db->rollBack();
                throw new \Core\Exceptions\BusinessException('خطا در ایجاد تسک');
            }

            $this->db->commit();

            return $this->successResponse('تسک با موفقیت ایجاد شد', ['id' => $task->id]);
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('create', $e->getMessage());
            throw new \Core\Exceptions\BusinessException('خطای سیستمی: ' . $e->getMessage());
        }
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (empty($data['title'])) {
            $errors[] = 'عنوان الزامی است';
        }

        if (empty($data['description'])) {
            $errors[] = 'توضیح الزامی است';
        }

        $price = (float) ($data['price_per_task'] ?? 0);
        if ($price <= 0) {
            $errors[] = 'قیمت باید بیشتر از صفر باشد';
        }

        $qty = (int) ($data['total_quantity'] ?? 0);
        if ($qty <= 0) {
            $errors[] = 'تعداد باید بیشتر از صفر باشد';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public function isExpired(int $adId): bool
    {
        $task = $this->taskModel->find($adId);
        if (!$task) return true;

        if (empty($task->deadline_hours) || $task->deadline_hours <= 0) {
            return false;
        }

        $createdTime = strtotime($task->created_at);
        $deadline = $task->deadline_hours * 3600;
        
        return (time() - $createdTime) > $deadline;
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        $feePercent = (float) $this->appSettings->get('custom_task_site_fee_percent', 10);
        return $amount * ($feePercent / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        $idempotencyKey = "ctask_pay_" . $adId . "_" . $userId . "_" . time();
        
        $result = $this->walletService->withdraw(
            $userId,
            $amount,
            $currency,
            [
                'type' => 'task_payment',
                'ad_id' => $adId,
                'idempotency_key' => $idempotencyKey,
            ]
        );

        if ($result) {
            return $this->successResponse('پرداخت موفق', ['transaction_id' => $result]);
        }

        throw new \Core\Exceptions\BusinessException('خطا در پرداخت');
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $task = $this->taskModel->find($adId);
        if (!$task) {
            throw new \Core\Exceptions\BusinessException('تسک یافت نشد');
        }

        // ردیابی رویداد برای تحلیل
        $this->logInfo('track', ['event' => $eventType, 'ad_id' => $adId, 'user_id' => $userId]);

        return $this->successResponse('رویداد ثبت شد');
    }

    public function getStatus(int $adId): ?array
    {
        $task = $this->taskModel->find($adId);
        if (!$task) return null;

        return [
            'id' => $task->id,
            'type' => $this->getType(),
            'status' => $task->status,
            'created_at' => $task->created_at,
            'budget' => $task->total_budget,
            'currency' => $task->currency,
            'is_expired' => $this->isExpired($adId),
        ];
    }
}


