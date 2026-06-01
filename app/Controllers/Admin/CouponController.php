<?php

namespace App\Controllers\Admin;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Services\Shared\CouponService;
use App\Controllers\Admin\BaseAdminController;

class CouponController extends BaseAdminController
{
    private Coupon $couponModel;
    private CouponRedemption $redemptionModel;
    private CouponService $couponService;
    private \App\Services\AuditTrail $auditTrail;

    public function __construct(
        Coupon $couponModel,
        CouponRedemption $redemptionModel,
        CouponService $couponService,
        \App\Services\AuditTrail $auditTrail
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->couponModel     = $couponModel;
        $this->redemptionModel = $redemptionModel;
        $this->couponService   = $couponService;
        $this->auditTrail      = $auditTrail;
    }

    /**
     * لیست کوپن‌ها
     * GET /admin/coupons
     */
    public function index(): void
    {
        $coupons = $this->couponService->all();

        view('admin/coupons/index', [
            'coupons' => $coupons,
            'user' => user()
        ]);
    }

    /**
     * فرم ایجاد کوپن
     * GET /admin/coupons/create
     */
    public function create(): void
    {
        view('admin/coupons/create', [
            'user' => user()
        ]);
    }

    /**
     * ذخیره کوپن جدید
     * POST /admin/coupons/store
     */
    public function store(): void
    {
        $validator = $this->validatorFactory()->make($this->request->all(), [
            'code' => 'required|string|max:50',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'applicable_to' => 'required|in:all,task,investment,vip,story_order'
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ]);
            return;
        }

        $data = $this->request->all();
        $data['code'] = strtoupper(trim($data['code']));
        $data['created_by'] = user_id();
        $data['active'] = isset($data['active']) ? 1 : 0;

        // بررسی تکراری نبودن کد
        if ($this->couponService->findByCode($data['code'])) {
            $this->response->json([
                'success' => false,
                'message' => 'کد تخفیف تکراری است',
            ]);
            return;
        }

        // تنظیم مقادیر پیش‌فرض
        $data['min_purchase'] = !empty($data['min_purchase']) ? $data['min_purchase'] : null;
        $data['max_discount'] = !empty($data['max_discount']) ? $data['max_discount'] : null;
        $data['start_date'] = !empty($data['start_date']) ? $data['start_date'] : null;
        $data['end_date'] = !empty($data['end_date']) ? $data['end_date'] : null;
        $data['usage_limit'] = !empty($data['usage_limit']) ? (int)$data['usage_limit'] : 0;
        $data['usage_count'] = 0;

        $couponId = $this->couponService->create($data);

        if ($couponId) {
            $this->logger->info('coupon_created', [
                'coupon_id' => $couponId,
                'code' => $data['code'],
                'admin_id' => user_id()
            ]);

            $this->auditTrail->record('coupon.created', null, [
                'coupon_id' => $couponId,
                'code' => $data['code'],
                'data' => $data
            ]);

            $this->response->json([
                'success'  => true,
                'message'  => 'کد تخفیف با موفقیت ایجاد شد',
                'redirect' => url('admin/coupons'),
            ]);
        } else {
            $this->response->json([
                'success' => false,
                'message' => 'خطا در ایجاد کد تخفیف',
            ]);
        }
    }

    /**
     * فرم ویرایش کوپن
     * GET /admin/coupons/edit?id=1
     */
    public function edit(): void
    {
        $id = (int)$this->request->param('id');
        if (!$id) $id = (int)$this->request->get('id');

        $coupon = $this->couponService->find($id);

        if (!$coupon) {
            redirect('admin/coupons');
            return;
        }

        view('admin/coupons/edit', [
            'coupon' => $coupon,
            'user' => user()
        ]);
    }

    /**
     * بروزرسانی کوپن
     * POST /admin/coupons/update
     */
    public function update(): void
    {
        $id = (int)$this->request->param('id');
        if (!$id) $id = (int)$this->request->input('id');
        $coupon = $this->couponService->find($id);

        if (!$coupon) {
            $this->response->json([
                'success' => false,
                'message' => 'کوپن یافت نشد'
            ]);
            return;
        }

        $validator = $this->validatorFactory()->make($this->request->all(), [
            'type'          => 'required|in:percent,fixed',
            'value'         => 'required|numeric|min:0',
            'applicable_to' => 'required|in:all,task,investment,vip,story_order',
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ]);
            return;
        }

        $data = [
            'type'          => $this->request->input('type'),
            'value'         => $this->request->input('value'),
            'min_purchase'  => !empty($this->request->input('min_purchase')) ? $this->request->input('min_purchase') : null,
            'max_discount'  => !empty($this->request->input('max_discount')) ? $this->request->input('max_discount') : null,
            'start_date'    => !empty($this->request->input('start_date')) ? $this->request->input('start_date') : null,
            'end_date'      => !empty($this->request->input('end_date')) ? $this->request->input('end_date') : null,
            'usage_limit'   => !empty($this->request->input('usage_limit')) ? (int)$this->request->input('usage_limit') : 0,
            'applicable_to' => $this->request->input('applicable_to'),
            'active'        => $this->request->input('active') ? 1 : 0,
        ];

        if ($this->couponService->update($id, $data)) {
            $this->logger->info('coupon_updated', [
                'coupon_id' => $id,
                'admin_id' => user_id()
            ]);

            $before = (array)$coupon;
            $this->auditTrail->diff('coupon.updated', null, $before, $data);

            $this->response->json([
                'success'  => true,
                'message'  => 'کوپن با موفقیت بروزرسانی شد',
                'redirect' => url('admin/coupons'),
            ]);
        } else {
            $this->response->json([
                'success' => false,
                'message' => 'خطا در بروزرسانی کوپن',
            ]);
        }
    }

    /**
     * حذف کوپن (Soft Delete)
     * POST /admin/coupons/delete
     */
    public function delete(): void
    {
        $id = (int)$this->request->input('id');
        if (!$id) $id = (int)($this->request->body()['id'] ?? 0);
        $coupon = $this->couponService->find($id);

        if ($this->couponService->delete($id)) {
            $this->logger->info('coupon_deleted', [
                'coupon_id' => $id,
                'admin_id' => user_id()
            ]);

            $this->auditTrail->record('coupon.deleted', null, [
                'coupon_id' => $id,
                'code' => $coupon ? $coupon->code : 'unknown'
            ]);

            $this->response->json(['success' => true, 'message' => 'کد تخفیف حذف شد']);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در حذف']);
        }
    }

    /**
     * تغییر وضعیت کوپن
     * POST /admin/coupons/toggle-active
     */
    public function toggleActive(): void
    {
        $id     = (int)$this->request->input('id');
        if (!$id) $id = (int)($this->request->body()['id'] ?? 0);
        $coupon = $this->couponModel->find($id);

        if (!$coupon) {
            $this->response->json(['success' => false, 'message' => 'کوپن یافت نشد']);
            return;
        }

        if ($this->couponService->toggle($id)) {
            $this->logger->info('coupon_toggled', [
                'coupon_id' => $id,
                'admin_id' => user_id()
            ]);

            $this->auditTrail->record('coupon.toggled', null, [
                'coupon_id' => $id,
                'code' => $coupon->code,
                'active' => $coupon->active ? 0 : 1
            ]);

            $this->response->json(['success' => true,  'message' => 'وضعیت کوپن تغییر کرد']);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در تغییر وضعیت']);
        }
    }

    /**
     * مشاهده جزئیات و آمار کوپن
     * GET /admin/coupons/details?id=1
     */
    public function details(): void
    {
        $id = (int)$this->request->param('id');
        if (!$id) $id = (int)$this->request->get('id');

        $statistics = $this->couponService->getCouponStatistics($id);

        if (!$statistics['coupon']) {
            redirect('admin/coupons');
            return;
        }

        view('admin/coupons/details', [
            'coupon' => $statistics['coupon'],
            'stats' => $statistics['stats'],
            'recent_uses' => $statistics['recent_uses'],
            'user' => auth()
        ]);
    }

    /**
     * تاریخچه مصرف کوپن‌ها
     * GET /admin/coupons/redemptions
     */
    public function redemptions(): void
    {
        $redemptions = $this->couponService->getRedemptions();

        view('admin/coupons/redemptions', [
            'redemptions' => $redemptions,
            'user' => auth()
        ]);
    }

    /**
     * داشبورد آمار کوپن‌ها
     * GET /admin/coupons/statistics
     */
    public function statistics(): void
    {
        $stats = $this->couponService->getOverallStatistics();

        view('admin/coupons/statistics', [
            'stats' => $stats,
            'user' => auth()
        ]);
    }
}