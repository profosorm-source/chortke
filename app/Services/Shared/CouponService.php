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
class CouponService extends \App\Services\BaseService
{
    public function __construct(
        private Coupon $couponModel,
        private CouponRedemption $redemptionModel,
        private Database $db,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
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

        if ($coupon->applicable_to !== 'all' && $coupon->applicable_to !== $applicableTo) {
            return ['valid' => false, 'error' => 'این کد تخفیف برای این نوع عملیات قابل استفاده نیست'];
        }

        if ($coupon->min_purchase && $amount < $coupon->min_purchase) {
            return ['valid' => false, 'error' => sprintf('مبلغ خرید باید حداقل %s باشد', number_format($coupon->min_purchase))];
        }

        if ($this->redemptionModel->hasUserUsedCoupon($userId, $coupon->id)) {
            return ['valid' => false, 'error' => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید'];
        }

        $discount = 0;
        if ($coupon->type === 'percent') {
            $discount = ($amount * $coupon->value) / 100;
            if ($coupon->max_discount && $discount > $coupon->max_discount) {
                $discount = $coupon->max_discount;
            }
        } else {
            $discount = min($coupon->value, $amount);
        }

        $finalAmount = max(0, $amount - $discount);

        return [
            'valid' => true,
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'original_amount' => $amount,
            'discount_amount' => round($discount, 2),
            'final_amount' => round($finalAmount, 2),
            'currency' => $currency
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
        ?int $entityId = null
    ): bool {
        $db = \Core\Container::getInstance()->make(\Core\Database::class);
        try {
            $db->beginTransaction();

            $coupon = $db->query("SELECT * FROM coupons WHERE id = ? FOR UPDATE", [$couponId])->fetch(\PDO::FETCH_OBJ);
            
            if (!$coupon) {
                $db->rollback();
                return false;
            }

            if ($coupon->usage_limit !== null && $coupon->usage_count >= $coupon->usage_limit) {
                $db->rollback();
                throw new \Exception('ظرفیت استفاده از این کد تخفیف به پایان رسیده است.');
            }

            if ($this->redemptionModel->hasUserUsedCoupon($userId, $couponId)) {
                $db->rollback();
                throw new \Exception('کد تخفیف قبلا توسط این کاربر استفاده شده است.');
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
                $db->rollback();
                return false;
            }

            $success = $this->couponModel->incrementUsage($couponId);
            if (!$success) {
                $db->rollback();
                return false;
            }

            $db->commit();
            return true;

        } catch (\PDOException $e) {
            if ($db->inTransaction()) $db->rollback();
            if ($e->getCode() == '23000') {
                throw new \Exception('کد تخفیف قبلا توسط این کاربر استفاده شده است.');
            }
            throw $e;
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollback();
            throw $e;
        }
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
            return $this->couponModel->all() ?? [];
        }
        
        return $this->db->query(
            "SELECT * FROM coupons ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        )->fetchAll(\PDO::FETCH_OBJ) ?? [];
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
        $offset = ($page - 1) * $perPage;

        $query = "SELECT * FROM coupons WHERE 1=1";
        $params = [];

        // Apply filters
        if (!empty($filters['status'])) {
            $query .= " AND active = ?";
            $params[] = $filters['status'] === 'active' ? 1 : 0;
        }

        if (!empty($filters['type'])) {
            $query .= " AND type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['search'])) {
            $query .= " AND (code LIKE ? OR description LIKE ?)";
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        // Count total
        $countQuery = "SELECT COUNT(*) as total FROM (" . str_replace('SELECT *', 'SELECT 1', $query) . ") as cnt";
        $countResult = $this->db->query($countQuery, $params)->fetch(\PDO::FETCH_OBJ);
        $total = $countResult->total ?? 0;

        // Get paginated results
        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $coupons = $this->db->query($query, $params)->fetchAll(\PDO::FETCH_OBJ) ?? [];

        return [
            'data' => $coupons,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
        ];
    }
}

