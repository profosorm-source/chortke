<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * CouponService - سرویس اشتراکی مدیریت کوپن و تخفیف‌ها
 *
 * این سرویس جایگزین App\Services\CouponService شده است.
 */
class CouponService
{
    private \Core\TransactionWrapper $transactionWrapper;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private Coupon $couponModel;
    private CouponRedemption $redemptionModel;
    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Coupon $couponModel,
        CouponRedemption $redemptionModel
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->db = $db;
        $this->logger = $logger;
        $this->couponModel = $couponModel;
        $this->redemptionModel = $redemptionModel;

        
    }

    /**
     * اعتبارسنجی و محاسبه تخفیف
     */
    public function validateAndCalculate(
        string $code,
        float $amount,
        string $currency,
        int $userId,
        string $applicableTo = 'all'
    ): array {
        $coupon = $this->couponModel->findByCode($code);

        if (!$coupon) {
            return ['valid' => false, 'error' => 'کد تخفیف معتبر نیست'];
        }

        if (!$coupon->isActive()) {
            return ['valid' => false, 'error' => 'کد تخفیف منقضی شده یا غیرفعال است'];
        }

        // Double-check usage limit explicitly to safeguard validation context
        if ($coupon->usage_limit !== null && $coupon->usage_limit > 0 && $coupon->usage_count >= $coupon->usage_limit) {
            return ['valid' => false, 'error' => 'ظرفیت استفاده از این کد تخفیف به پایان رسیده است.'];
        }

        if ($coupon->applicable_to !== 'all' && $coupon->applicable_to !== $applicableTo) {
            return ['valid' => false, 'error' => 'این کد تخفیف برای این نوع عملیات قابل استفاده نیست'];
        }

        if ($coupon->min_purchase && $amount < $coupon->min_purchase) {
            return ['valid' => false, 'error' => sprintf('مبلغ خرید باید حداقل %s باشد', number_format($coupon->min_purchase))];
        }

        if ($this->redemptionModel->hasUserUsedCoupon($userId, $coupon->id)) {
            return ['valid' => false, 'error' => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید'];
        }

        $discount = 0.0;
        if ($coupon->type === 'percent') {
            $discount = round(($amount * (float)$coupon->value) / 100.0, 2);
            if ($coupon->max_discount && $discount > (float)$coupon->max_discount) {
                $discount = (float)$coupon->max_discount;
            }
        } else {
            $discount = min((float)$coupon->value, $amount);
        }

        $finalAmount = max(0, $amount - $discount);

        // H-C2 Fix: Generate temporary validation token cached for 15 minutes to guarantee checkout integrity
        $validationToken = bin2hex(random_bytes(16));
        $cacheKey = "coupon_val_token_{$userId}_{$validationToken}";
        $cacheData = [
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'original_amount' => $amount,
            'discount_amount' => round($discount, 2),
            'final_amount' => round($finalAmount, 2),
            'currency' => $currency
        ];
        \Core\Cache::getInstance()->put($cacheKey, $cacheData, 15);

        return [
            'valid' => true,
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'original_amount' => $amount,
            'discount_amount' => round($discount, 2),
            'final_amount' => round($finalAmount, 2),
            'currency' => $currency,
            'validation_token' => $validationToken
        ];
    }

    /**
     * ثبت مصرف کوپن
     */
    public function redeem(
        int $couponId,
        int $userId,
        float $originalAmount,
        float $discountAmount,
        float $finalAmount,
        string $currency,
        string $entityType,
        ?int $entityId = null,
        ?string $validationToken = null
    ): bool {
        return $this->getTransactionWrapper()->runWithRetry(function() use (
            $couponId, $userId, $originalAmount, $discountAmount, $finalAmount, $currency, $entityType, $entityId, $validationToken
        ) {
            // H-C2 Fix: Verify validation token parameter integrity if supplied
            if ($validationToken !== null) {
                $cacheKey = "coupon_val_token_{$userId}_{$validationToken}";
                $cacheData = \Core\Cache::getInstance()->get($cacheKey);
                
                if (!$cacheData) {
                    throw new \Exception('توکن اعتبارسنجی کد تخفیف نامعتبر یا منقضی شده است. لطفا مجددا کد تخفیف را اعتبارسنجی کنید.');
                }

                // Verify parameters match exactly!
                if (
                    (int)$cacheData['coupon_id'] !== $couponId ||
                    abs((float)$cacheData['original_amount'] - $originalAmount) > 0.01 ||
                    abs((float)$cacheData['discount_amount'] - $discountAmount) > 0.01 ||
                    abs((float)$cacheData['final_amount'] - $finalAmount) > 0.01 ||
                    strtolower($cacheData['currency']) !== strtolower($currency)
                ) {
                    throw new \Exception('پارامترهای اعتبارسنجی کد تخفیف با مقادیر نهایی مغایرت دارند. لطفا مجددا تلاش کنید.');
                }
                
                // Consume the validation token so it cannot be reused
                \Core\Cache::getInstance()->forget($cacheKey);
            }

            // Architectural Fix: Utilize locked Model locator instead of writing inline RAW FOR UPDATE.
            $coupon = $this->couponModel->findWithLock($couponId);
            
            if (!$coupon) {
                return false;
            }

            // H-C2 & H-C3 Fix: Pessimistic lock on user usage + idempotency
            if ($this->redemptionModel->hasUserUsedCouponForUpdate($userId, $couponId)) {
                throw new \Exception('کد تخفیف قبلا توسط این کاربر استفاده شده است.');
            }

            if ($coupon->usage_limit !== null && (int)$coupon->usage_count >= (int)$coupon->usage_limit) {
                throw new \Exception('ظرفیت استفاده از این کد تخفیف به پایان رسیده است.');
            }

            // Idempotency: prevent double spend if entity already has a coupon applied
            $existing = $this->db->query(
                "SELECT id FROM coupon_redemptions WHERE entity_type = ? AND entity_id = ? LIMIT 1",
                [$entityType, $entityId]
            )->fetch();
            if ($existing) {
                throw new \Exception('برای این تراکنش قبلاً کد تخفیف اعمال شده است.');
            }

            $redemptionId = $this->redemptionModel->create([
                'coupon_id' => $couponId,
                'user_id' => $userId,
                'original_amount' => $originalAmount,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'currency' => $currency,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => \get_client_ip()
            ]);

            if (!$redemptionId) {
                throw new \RuntimeException('Redemption trace failed.');
            }

            $success = $this->couponModel->incrementUsage($couponId);
            if (!$success) {
                throw new \RuntimeException('Increment usage failed.');
            }

            // Success Structured Logging
            $this->logger->info('coupon.redeemed', [
                'coupon_id' => $couponId,
                'user_id' => $userId,
                'discount' => $discountAmount,
                'final' => $finalAmount,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);

            return true;
        });
    }

    /**
     * اعتبارسنجی و ثبت مصرف کوپن به صورت کاملاً اتمیک (حل Race Condition)
     */
    public function validateAndRedeem(
        int $userId,
        string $code,
        float $amount,
        string $currency,
        string $entityType,
        ?int $entityId = null,
        string $applicableTo = 'all'
    ): array {
        return $this->getTransactionWrapper()->runWithRetry(function() use (
            $userId, $code, $amount, $currency, $entityType, $entityId, $applicableTo
        ) {
            $coupon = $this->couponModel->findByCodeWithLock($code);

            if (!$coupon) {
                return ['success' => false, 'message' => 'کد تخفیف معتبر نیست'];
            }

            if (!$coupon->isActive()) {
                return ['success' => false, 'message' => 'کد تخفیف منقضی شده یا غیرفعال است'];
            }

            // بررسی محدودیت استفاده کلی با قفل FOR UPDATE
            if ($coupon->usage_limit !== null && $coupon->usage_limit > 0 && $coupon->usage_count >= $coupon->usage_limit) {
                return ['success' => false, 'message' => 'ظرفیت استفاده از این کد تخفیف به پایان رسیده است.'];
            }

            if ($coupon->applicable_to !== 'all' && $coupon->applicable_to !== $applicableTo) {
                return ['success' => false, 'message' => 'این کد تخفیف برای این نوع عملیات قابل استفاده نیست'];
            }

            if ($coupon->min_purchase && $amount < $coupon->min_purchase) {
                return ['success' => false, 'message' => sprintf('مبلغ خرید باید حداقل %s باشد', number_format($coupon->min_purchase))];
            }

            // بررسی استفاده قبلی کاربر با قفل FOR UPDATE
            if ($this->redemptionModel->hasUserUsedCouponForUpdate($userId, $coupon->id)) {
                return ['success' => false, 'message' => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید'];
            }

            // جلوگیری از ثبت مجدد برای همان ماهیت (Idempotency)
            $existing = $this->db->query(
                "SELECT id FROM coupon_redemptions WHERE entity_type = ? AND entity_id = ? FOR UPDATE",
                [$entityType, $entityId]
            )->fetch();
            if ($existing) {
                return ['success' => false, 'message' => 'برای این تراکنش قبلاً کد تخفیف اعمال شده است.'];
            }

            // محاسبه تخفیف
            $discount = 0.0;
            if ($coupon->type === 'percent') {
                $discount = round(($amount * (float)$coupon->value) / 100.0, 2);
                if ($coupon->max_discount && $discount > (float)$coupon->max_discount) {
                    $discount = (float)$coupon->max_discount;
                }
            } else {
                $discount = min((float)$coupon->value, $amount);
            }

            $finalAmount = max(0, $amount - $discount);

            $redemptionId = $this->redemptionModel->create([
                'coupon_id' => $coupon->id,
                'user_id' => $userId,
                'original_amount' => $amount,
                'discount_amount' => round($discount, 2),
                'final_amount' => round($finalAmount, 2),
                'currency' => $currency,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => \get_client_ip()
            ]);

            if (!$redemptionId) {
                throw new \RuntimeException('Redemption trace failed.');
            }

            $success = $this->couponModel->incrementUsage($coupon->id);
            if (!$success) {
                throw new \RuntimeException('Increment usage failed.');
            }

            $this->logger->info('coupon.redeemed', [
                'coupon_id' => $coupon->id,
                'user_id' => $userId,
                'discount' => $discount,
                'final' => $finalAmount,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);

            return [
                'success' => true,
                'coupon_id' => $coupon->id,
                'original_amount' => $amount,
                'discount_amount' => round($discount, 2),
                'final_amount' => round($finalAmount, 2),
                'redemption_id' => $redemptionId
            ];
        });
    }

    /**
     * دریافت آمار کوپن
     */
    public function getCouponStatistics(int $couponId): array
    {
        return [
            'coupon' => $this->couponModel->find($couponId),
            'stats' => $this->redemptionModel->getCouponStats($couponId),
            'recent_uses' => $this->redemptionModel->getCouponHistory($couponId, 10)
        ];
    }

    /**
     * آمار کلی سیستم کوپن
     */
    public function getOverallStatistics(): array
    {
        return [
            'overall' => $this->redemptionModel->getOverallStats(),
            'active_coupons_count' => count($this->couponModel->getActiveCoupons()),
            'expired_coupons_count' => count($this->couponModel->getExpiredCoupons()),
            'today_redemptions_count' => count($this->redemptionModel->getTodayRedemptions())
        ];
    }

    /**
     * تمام کوپن‌ها دریافت کنید (با pagination)
     */
    public function all(int $limit = null, int $offset = 0): array
    {
        if ($limit === null) {
            return $this->couponModel->getAll(100, $offset);
        }
        
        return $this->couponModel->getAll($limit, $offset);
    }

    /**
     * کوپن کد سے تلاش کنید
     */
    public function findByCode(string $code): ?object
    {
        return $this->couponModel->findByCode($code);
    }

    /**
     * کوپن ID سے تلاش کنید
     */
    public function find(int $id): ?object
    {
        return $this->couponModel->find($id);
    }

    /**
     * نیا کوپن بنائیں
     */
    public function create(array $data): ?int
    {
        // Validate code exists
        if (empty($data['code'])) {
            $this->logger->warning('coupon.create.empty_code');
            return null;
        }

        // Check for duplicates
        if ($this->couponModel->findByCode($data['code'])) {
            $this->logger->warning('coupon.create.duplicate', ['code' => $data['code']]);
            return null;
        }

        // Ensure data has defaults
        $data['code'] = strtoupper($data['code']);
        $data['usage_count'] = $data['usage_count'] ?? 0;
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');

        try {
            $result = $this->couponModel->create($data);

            if ($result) {
                $this->logger->info('coupon.created', ['code' => $data['code'], 'id' => $result]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('coupon.create.failed', ['code' => $data['code'], 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * کوپن کو اپ‌ڈیٹ کنید
     */
    public function update(int $id, array $data): bool
    {
        $coupon = $this->couponModel->find($id);
        if (!$coupon) {
            $this->logger->warning('coupon.update.not_found', ['id' => $id]);
            return false;
        }

        // Prevent code changes if not provided
        if (isset($data['code']) && $data['code'] !== $coupon->code) {
            // Check if new code already exists
            if ($this->couponModel->findByCode($data['code'])) {
                $this->logger->warning('coupon.update.duplicate_code', ['id' => $id, 'code' => $data['code']]);
                return false;
            }
            $data['code'] = strtoupper($data['code']);
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            $result = $this->couponModel->update($id, $data);

            if ($result) {
                $this->logger->info('coupon.updated', ['id' => $id, 'fields' => array_keys($data)]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('coupon.update.failed', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * کوپن کو حذف کنید
     */
    public function delete(int $id): bool
    {
        $coupon = $this->couponModel->find($id);
        if (!$coupon) {
            $this->logger->warning('coupon.delete.not_found', ['id' => $id]);
            return false;
        }

        try {
            $result = $this->couponModel->delete($id);

            if ($result) {
                $this->logger->info('coupon.deleted', ['id' => $id, 'code' => $coupon->code]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('coupon.delete.failed', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * کوپن کی حالت toggle کنید (فعال/غیرفعال)
     */
    public function toggle(int $id): bool
    {
        $coupon = $this->couponModel->find($id);
        if (!$coupon) {
            return false;
        }

        $newStatus = !$coupon->active;
        return $this->update($id, ['active' => $newStatus ? 1 : 0]);
    }

    /**
     * کوپنز کو صفحہ بندی کے ساتھ تلاش کنید
     */
    public function paginate(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        // Secure architectural refactor: Replace dynamic raw concatenations with Safe Query Builder
        $query = $this->db->table('coupons')->whereNull('deleted_at');

        if (!empty($filters['status'])) {
            $query->where('active', '=', $filters['status'] === 'active' ? 1 : 0);
        }

        if (!empty($filters['type'])) {
            $query->where('type', '=', $filters['type']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', $search)
                  ->orWhere('description', 'LIKE', $search);
            });
        }

        $total = $query->count();

        $coupons = $query->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return [
            'data' => $coupons ?? [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
        ];
    }

    /**
     * Wrap closures within atomic database transactions.
     */
    protected function transaction(callable $callback, int $maxRetries = 3): mixed
    {
        $started = !$this->db->inTransaction();
        if ($started) {
            $this->db->beginTransaction();
        }
        try {
            $result = $callback();
            if ($started) {
                $this->db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    /**
     * دریافت لیست تاریخچه استفاده از کوپن‌ها
     */
    public function getRedemptions(int $limit = 100, int $offset = 0): array
    {
        return $this->redemptionModel->all(); // Or implement pagination if needed
    }
}

