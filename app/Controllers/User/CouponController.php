<?php

namespace App\Controllers\User;

use App\Services\Shared\CouponService;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Controllers\User\BaseUserController;

class CouponController extends BaseUserController
{
    private CouponService $couponService;
    private CouponRedemption $redemptionModel;

    public function __construct(
        Coupon $couponModel,
        CouponRedemption $redemptionModel,
        CouponService $couponService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->couponService = $couponService;
        $this->redemptionModel = $redemptionModel;
    }

    /**
     * اعتبارسنجی کوپن (AJAX)
     * POST /user/coupons/validate
     */
    public function validate(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $code = trim($data['code'] ?? '');
        $amount = (float)($data['amount'] ?? 0);
        $currency = $data['currency'] ?? 'irt';
        $applicableTo = $data['applicable_to'] ?? 'all';
        $userId = user_id();

        if (empty($code) || $amount <= 0) {
            $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ارسالی ناقص است'
            ]);
            return;
        }

        $result = $this->couponService->validateAndCalculate(
            $code,
            $amount,
            $currency,
            $userId,
            $applicableTo
        );

        if ($result['valid']) {
            $this->response->json([
                'success' => true,
                'data' => [
                    'coupon_id' => $result['coupon_id'],
                    'coupon_code' => $result['coupon_code'],
                    'original_amount' => $result['original_amount'],
                    'discount_amount' => $result['discount_amount'],
                    'final_amount' => $result['final_amount'],
                    'validation_token' => $result['validation_token']
                ],
                'message' => sprintf('کد تخفیف با موفقیت اعمال شد. تخفیف: %s', number_format($result['discount_amount']))
            ]);
        } else {
            $this->response->json([
                'success' => false,
                'message' => $result['error']
            ]);
        }
    }

    /**
     * تاریخچه استفاده از کوپن‌ها
     * GET /user/coupons/history
     */
    public function history(): void
    {
        $userId = user_id();
        $history = $this->redemptionModel->getUserHistory($userId);

        view('user/coupons/history', [
            'history' => $history,
            'user' => auth()
        ]);
    }
}