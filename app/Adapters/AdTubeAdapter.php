<?php

namespace App\Adapters;

use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use Core\Database;
use App\Services\Settings\AppSettings;
use App\Constants\PercentageConstants;
use App\Services\Shared\IdempotencyService;
use App\Models\Ads;

class AdTubeAdapter extends AdapterBase implements AdSystemContract
{
    private Ads $adModel;
    private \App\Contracts\WalletServiceInterface $walletService;
    private Database $db;
    private IdempotencyService $idempotencyService;
    public function __construct(
        Ads $adModel,
        \App\Contracts\WalletServiceInterface $walletService,
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

    public function getType(): string { return 'adtube'; }

    public function create(int $userId, array $data): array
    {
        $valid = $this->validate($data);
        if (!$valid['valid']) {
            throw new \Core\Exceptions\BusinessException('اطلاعات وارد شده معتبر نیست', $valid['errors']);
        }

        $currency = $data['currency'] ?? 'irt';
        $pricePerTask = (float) ($data['price_per_task'] ?? 0);
        $quantity = (int) ($data['total_count'] ?? 1);
        
        $feePercent = (float) $this->appSettings->get('adtube_site_fee_percent', 10);
        $totalBudget = $pricePerTask * $quantity;
        $totalWithFee = $totalBudget + ($totalBudget * $feePercent / 100);

        try {
            $this->db->beginTransaction();

            $payload = [
                'user_id' => $userId,
                'amount' => $totalWithFee,
                'currency' => $currency,
                'metadata' => [
                    'type' => 'adtube_budget',
                    'title' => $data['title'] ?? 'untitled',
                    'description' => "شارژ بودجه تبلیغ ویدیویی: {$data['title']}",
                ],
            ];

            $txId = $this->idempotencyService->executeWithTransaction(
                'adtube_budget',
                $userId,
                $payload,
                function () use ($userId, $totalWithFee, $currency, $payload) {
                    return $this->walletService->withdraw($userId, $totalWithFee, $currency, $payload['metadata']);
                }
            );

            if (!$txId) {
                $this->db->rollBack();
                throw new \Core\Exceptions\BusinessException('موجودی کیف پول کافی نیست.');
            }

            // ایجاد تبلیغ در جدول متمرکز
            $ad = $this->adModel->create([
                'user_id' => $userId,
                'type' => 'adtube',
                'platform' => 'youtube',
                'task_type' => 'view',
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'link' => $data['link'] ?? null, // لینک هدف یوتیوب
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
                 throw new \Core\Exceptions\BusinessException('خطا در ذخیره نهایی تبلیغ');
            }

            $this->db->commit();
            $adId = is_object($ad) ? $ad->id : (is_numeric($ad) ? $ad : $this->db->lastInsertId());

            return $this->successResponse('تبلیغ AdTube با موفقیت ثبت شد', ['id' => $adId]);

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('create', $e->getMessage());
            throw new \Core\Exceptions\BusinessException('خطا در فرآیند ثبت: ' . $e->getMessage());
        }
    }

    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        if (empty($data['title'])) $errors[] = 'عنوان تبلیغ الزامی است';
        
        if (empty($data['link'])) {
            $errors[] = 'لینک ویدیوی یوتیوب الزامی است';
        } elseif (!preg_match('/(youtube\.com|youtu\.be)/', $data['link'])) {
            $errors[] = 'لینک وارد شده باید یک لینک معتبر یوتیوب باشد';
        }

        $price = (float)($data['price_per_task'] ?? 0);
        $minPrice = (float)$this->appSettings->get('adtube_min_price_per_view', 100);
        if ($price < $minPrice) {
            $errors[] = "حداقل هزینه هر نمایش {$minPrice} تومان می‌باشد";
        }

        $qty = (int)($data['total_count'] ?? 0);
        if ($qty < 1) {
            $errors[] = 'تعداد نمایش‌های درخواستی باید حداقل ۱ عدد باشد';
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
        return $amount * ((float) $this->appSettings->get('adtube_site_fee_percent', PercentageConstants::AD_TUBE_FEE_PERCENT) / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        $result = $this->walletService->withdraw($userId, $amount, $currency, [
            'type' => 'adtube_payment',
            'ad_id' => $adId
        ]);
        
        if ($result) {
            return ['success' => true, 'transaction_id' => $result, 'message' => 'پرداخت با موفقیت انجام شد'];
        }
        return ['success' => false, 'message' => 'موجودی ناکافی جهت پرداخت'];
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        $this->logInfo('track', ['event' => $eventType, 'ad_id' => $adId, 'user_id' => $userId]);
        return $this->successResponse('رویداد ثبت شد');
    }

    public function getStatus(int $adId): ?array
    {
        $ad = $this->adModel->find($adId);
        return $ad ? ['id' => $ad->id, 'type' => 'adtube', 'status' => $ad->status] : null;
    }
}


