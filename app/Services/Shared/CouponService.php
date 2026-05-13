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
}

