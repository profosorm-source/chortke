<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use Core\Database;
use App\Services\SettingService;
use App\Constants\PercentageConstants;
use App\Models\Ads;

/**
 * AdSocialAdapter - Adapter for dynamic Social Network Tasks (Instagram, Telegram, Twitter, etc.)
 */
class AdSocialAdapter extends AdapterBase implements AdSystemContract
{
    public function __construct(
        private Ads $adModel,
        private \App\Services\Wallet\WalletService $walletService,
        private Database $db,
        LoggerInterface $logger,
        SettingService $settingService,
        ValidatorFactoryInterface $validatorFactory
    ) {
        parent::__construct($logger, $settingService, $validatorFactory);
    }

    public function getType(): string 
    { 
        return 'social_task'; 
    }

    public function create(int $userId, array $data): array
    {
        $valid = $this->validate($data);
        if (!$valid['valid']) {
            return $this->errorResponse('اطلاعات وارد شده معتبر نیست', $valid['errors']);
        }

        $currency = $data['currency'] ?? 'irt';
        $pricePerTask = (float) ($data['price_per_task'] ?? 0);
        $quantity = (int) ($data['total_count'] ?? 1);
        
        // بازیابی درصد کمیسیون از تنظیمات سیستم یا استفاده از مقدار پیش‌فرض MagicNumbers
        $feePercent = (float) $this->settingService->get('social_task_site_fee_percent', PercentageConstants::SOCIAL_TASK_FEE_PERCENT);
        $totalBudget = $pricePerTask * $quantity;
        $totalWithFee = $totalBudget + ($totalBudget * $feePercent / 100);

        try {
            $this->db->beginTransaction();

            $idempotencyKey = \Core\IdempotencyKey::generateFromPayload('social_task_budget', [
                'user_id' => $userId,
                'platform' => $data['platform'] ?? 'unknown',
                'amount' => $totalWithFee
            ]);

            // کسر متمرکز وجه از کیف پول کاربر (اتمی)
            $txId = $this->walletService->withdraw(
                $userId,
                $totalWithFee,
                $currency,
                [
                    'type' => 'social_task_budget',
                    'description' => "بودجه تسک شبکه اجتماعی ({$data['platform']}): {$data['title']}",
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            if (!$txId) {
                $this->db->rollBack();
                return $this->errorResponse('موجودی کیف پول برای ثبت آگهی کافی نیست.');
            }

            // درج مستقیم در جدول واحد ads با نوع 'social_task'
            $ad = $this->adModel->create([
                'user_id' => $userId,
                'type' => 'social_task',
                'platform' => $data['platform'],
                'task_type' => $data['task_type'] ?? 'follow',
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'link' => $data['link'] ?? null,
                'target_url' => $data['link'] ?? null,
                'price_per_task' => $pricePerTask,
                'currency' => $currency,
                'total_budget' => $totalBudget,
                'remaining_budget' => $totalBudget,
                'total_count' => $quantity,
                'remaining_count' => $quantity,
                'site_commission_percent' => $feePercent,
                'status' => 'pending',
                'created_by' => $userId
            ]);

            if (!$ad) {
                 $this->db->rollBack();
                 return $this->errorResponse('خطا در نهایی‌سازی تراکنش تبلیغ در سیستم.');
            }

            $this->db->commit();
            $adId = is_object($ad) ? $ad->id : (is_numeric($ad) ? $ad : $this->db->lastInsertId());

            return $this->successResponse('تسک شبکه اجتماعی با موفقیت ثبت و در صف تایید قرار گرفت', ['id' => $adId]);

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('create', $e->getMessage());
            return $this->errorResponse('خطا در سیستم تراکنش: ' . $e->getMessage());
        }
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        
        if (empty($data['platform'])) {
            $errors[] = 'تعیین پلتفرم (اینستاگرام، تلگرام و...) الزامی است';
        } elseif (!in_array($data['platform'], ['instagram', 'telegram', 'twitter', 'youtube', 'facebook', 'other'], true)) {
            $errors[] = 'پلتفرم نامعتبر است';
        }

        if (empty($data['task_type'])) {
            $errors[] = 'نوع تسک الزامی است';
        } elseif (!in_array($data['task_type'], ['follow', 'like', 'comment', 'view', 'join', 'subscribe', 'retweet', 'other'], true)) {
            $errors[] = 'نوع تسک نامعتبر است';
        }

        if (empty($data['title'])) {
            $errors[] = 'عنوان تبلیغ نباید خالی باشد';
        }
        
        if (empty($data['link'])) {
            $errors[] = 'آدرس (لینک) کانال یا صفحه الزامی است';
        } elseif (!filter_var($data['link'], FILTER_VALIDATE_URL)) {
            $errors[] = 'فرمت لینک وارد شده معتبر نیست';
        }

        $price = (float)($data['price_per_task'] ?? 0);
        $minPrice = (float)$this->settingService->get('social_task_min_price', 10);
        if ($price < $minPrice) {
            $errors[] = "حداقل قیمت هر تسک در شبکه های اجتماعی {$minPrice} تومان است";
        }

        $qty = (int)($data['total_count'] ?? 0);
        if ($qty < 1) {
            $errors[] = 'تعداد درخواست حداقل باید ۱ عدد باشد';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function isExpired(int $adId): bool
    {
        $ad = $this->adModel->find($adId);
        return !$ad || in_array($ad->status, ['expired', 'completed']) || (isset($ad->remaining_budget) && $ad->remaining_budget <= 0);
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        $feePercent = (float) $this->settingService->get('social_task_site_fee_percent', PercentageConstants::SOCIAL_TASK_FEE_PERCENT);
        return $amount * ($feePercent / 100);
    }

        public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        $result = $this->walletService->pay($userId, $amount, $currency, [
            'type' => 'social_task_payment',
            'ad_id' => $adId
        ]);
        
        if (!empty($result['success'])) {
            return ['success' => true, 'transaction_id' => $result['transaction_id'] ?? '', 'message' => 'پرداخت انجام شد'];
        }
        return ['success' => false, 'message' => 'موجودی ناکافی است یا خطا در پرداخت'];
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $this->logInfo('track', ['event' => $eventType, 'ad_id' => $adId, 'user_id' => $userId]);
        return $this->successResponse('تراک رویداد انجام شد');
    }

    public function getStatus(int $adId): ?array
    {
        $ad = $this->adModel->find($adId);
        return $ad ? ['id' => $ad->id, 'type' => 'social_task', 'status' => $ad->status] : null;
    }
}


