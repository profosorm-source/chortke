<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use App\Models\Ads;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\IdempotencyService;
use Core\Database;
use App\Services\Settings\AppSettings;
use Core\Exceptions\ValidationException;

/**
 * NotificationAdAdapter - Specialized adapter for mass push-notification advertising.
 */
class NotificationAdAdapter extends AdapterBase implements AdSystemContract
{
    private Ads $adModel;
    private WalletServiceInterface $walletService;
    private Database $db;
    private IdempotencyService $idempotencyService;
    public function __construct(
        Ads $adModel,
        WalletServiceInterface $walletService,
        Database $db,
        LoggerInterface $logger,
        AppSettings $appSettings,
        ValidatorFactoryInterface $validatorFactory,
        IdempotencyService $idempotencyService
    ) {        $this->adModel = $adModel;
        $this->walletService = $walletService;
        $this->db = $db;
        $this->idempotencyService = $idempotencyService;

        parent::__construct($logger, $settingService, $validatorFactory);
    }

    public function getType(): string { return 'notification'; }

    public function create(int $userId, array $data): array
    {
        try {
            $this->validateData($data, [
                'title'         => 'required|string|min:3|max:100',
                'body'          => 'required|string|min:10|max:500',
                'budget'        => 'required|numeric|min:1000',
                'target_link'   => 'nullable|url'
            ]);
        } catch (ValidationException $e) {
            throw new \Core\Exceptions\BusinessException('ورودی‌های آگهی معتبر نیستند.', $e->getErrors());
        }

        $budget = (float) ($data['budget'] ?? 0);
        
        // 1. Calculate pricing fee based on dynamic platform settings
        $feePercent = (float) $this->appSettings->get('notification_ad_fee_percent', 15.0);
        $totalWithFee = $budget + ($budget * $feePercent / 100);

        try {
            $this->db->beginTransaction();

            $payload = [
                'user_id' => $userId,
                'amount' => $totalWithFee,
                'currency' => 'irt',
                'metadata' => [
                    'type' => 'notification_ad_budget',
                ],
            ];

            $txId = $this->idempotencyService->executeWithTransaction(
                'notification_ad_budget',
                $userId,
                $payload,
                function () use ($userId, $totalWithFee) {
                    return $this->walletService->withdraw($userId, $totalWithFee, 'irt', ['type' => 'notification_ad_budget']);
                }
            );

            if (!$txId) {
                $this->db->rollBack();
                throw new \Core\Exceptions\BusinessException('موجودی برای پرداخت هزینه آگهی نوتیفیکیشن کافی نیست.');
            }

            // 3. Centralized Ingestion
            $adId = $this->adModel->create([
                'user_id'            => $userId,
                'type'               => 'notification',
                'title'              => $data['title'],
                'budget'             => $budget,
                'remaining_budget'   => $budget,
                'site_commission_percent' => $feePercent,
                'status'             => 'pending',
                'is_active'          => 0,
                'link'               => $data['target_link'] ?? null,
                'restrictions'       => json_encode([
                    'push_body'      => $data['body'],
                    'image_path'     => $data['image_path'] ?? null,
                    'icon'           => $data['icon'] ?? 'default_ad_icon',
                    'scheduled_time' => $data['scheduled_time'] ?? null, // When to ideally send
                ])
            ]);

            $this->db->commit();
            $this->logInfo('push_ad_created', ['id' => $adId]);

            return $this->successResponse('آگهی نوتیفیکیشنی شما ثبت شد و پس از بررسی به نوبت ارسال اضافه می‌گردد.', ['id' => $adId]);

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('push_ad_fail', $e->getMessage());
            throw new \Core\Exceptions\BusinessException('بروز خطا در ثبت آگهی: ' . $e->getMessage());
        }
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        if (empty($data['title'])) $errors[] = 'عنوان نوتیفیکیشن الزامی است.';
        if (empty($data['body'])) $errors[] = 'متن پیام نوتیفیکیشن نمی‌تواند خالی باشد.';
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public function isExpired(int $adId): bool
    {
        $ad = $this->adModel->find($adId);
        if (!$ad) return true;
        
        // Notification ads generally deplete upon transmission to dynamic target counts
        return (float)$ad->remaining_budget <= 0;
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        $fee = (float) $this->appSettings->get('notification_ad_fee_percent', 15.0);
        return $amount * ($fee / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        return $this->successResponse('پرداخت با بودجه اولیه کسر شده مدیریت می‌شود.');
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        // Standard impressions (deliveries) and clicks tracking.
        $column = ($eventType === 'click') ? 'clicks' : 'impressions';
        $this->db->query("UPDATE ads SET {$column} = {$column} + 1 WHERE id = ?", [$adId]);
        
        return $this->successResponse('آمار تعامل با نوتیفیکیشن ارتقا یافت.');
    }

    public function getStatus(int $adId): ?array
    {
        $ad = $this->adModel->find($adId);
        if (!$ad) return null;
        
        return [
            'id'            => $ad->id,
            'type'          => 'notification',
            'status'        => $ad->status,
            'impressions'   => $ad->impressions ?? 0,
            'clicks'        => $ad->clicks ?? 0
        ];
    }
}


