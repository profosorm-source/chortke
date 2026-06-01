<?php

namespace App\Controllers\Api;

use App\Models\InfluencerModel;
use App\Models\StoryOrder;
use App\Models\Dispute;
use App\Services\InfluencerService;
use App\Services\Shared\DisputeService;
use App\Services\ScoreService;
use App\Services\UploadService;
use App\Services\VerificationService;

/**
 * API\InfluencerController
 *
 * -- پروفایل اینفلوئنسر --
 * GET    /api/v1/influencer/profile               → پروفایل خودم
 * POST   /api/v1/influencer/profile               → ثبت / ویرایش پروفایل
 * POST   /api/v1/influencer/profile/verify        → ثبت لینک پست تایید مالکیت
 *
 * -- بازار (عمومی) --
 * GET    /api/v1/influencer/list                  → لیست اینفلوئنسرهای تایید شده
 * GET    /api/v1/influencer/{id}                  → جزئیات + رتبه یک پروفایل
 *
 * -- سفارش‌ها (تبلیغ‌دهنده) --
 * POST   /api/v1/influencer/orders                → ثبت سفارش
 * GET    /api/v1/influencer/orders/placed         → سفارش‌هایی که داده‌ام
 * POST   /api/v1/influencer/orders/{id}/confirm   → تایید انجام
 * POST   /api/v1/influencer/orders/{id}/dispute   → اعتراض
 *
 * -- سفارش‌ها (اینفلوئنسر) --
 * GET    /api/v1/influencer/orders/received       → سفارش‌های دریافتی
 * POST   /api/v1/influencer/orders/{id}/respond   → قبول / رد
 * POST   /api/v1/influencer/orders/{id}/proof     → ثبت مدرک
 *
 * -- اختلاف --
 * GET    /api/v1/influencer/orders/{id}/dispute   → جزئیات اختلاف
 * POST   /api/v1/influencer/orders/{id}/dispute/message   → ارسال پیام
 * POST   /api/v1/influencer/orders/{id}/dispute/escalate  → ارجاع به مدیر
 * POST   /api/v1/influencer/orders/{id}/dispute/resolve   → توافق دوطرفه
 */
class InfluencerController extends BaseApiController
{
    private InfluencerService       $promotionService;
    private DisputeService              $disputeService;
    private ScoreService $scoreService;
    private VerificationService         $verificationService;
    private UploadService               $uploadService;
    private InfluencerModel             $profileModel;
    private StoryOrder                  $orderModel;
    private Dispute                     $disputeModel;

    public function __construct(
        InfluencerService       $promotionService,
        DisputeService              $disputeService,
        ScoreService $scoreService,
        VerificationService         $verificationService,
        UploadService               $uploadService,
        InfluencerModel             $profileModel,
        StoryOrder                  $orderModel,
        Dispute                     $disputeModel,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->promotionService  = $promotionService;
        $this->disputeService    = $disputeService;
        $this->scoreService = $scoreService;
        $this->verificationService = $verificationService;
        $this->uploadService     = $uploadService;
        $this->profileModel      = $profileModel;
        $this->orderModel        = $orderModel;
        $this->disputeModel      = $disputeModel;
    }

    // ══════════════════════════════════════════════════════
    //  پروفایل اینفلوئنسر
    // ══════════════════════════════════════════════════════

    /**
     * GET /api/v1/influencer/profile
     * پروفایل اینفلوئنسر خودم + آمار رتبه
     */
    public function myProfile(): never
    {
        $userId  = $this->userId();
        $profile = $this->promotionService->getProfileByUserId($userId);

        if (!$profile) {
            $this->success(null, 'پروفایلی ثبت نشده است');
        }

        $stats = $this->scoreService->getInfluencerStats((int)$profile->id);
        $verificationStatus = $this->verificationService->getVerificationStatus((int)$profile->id);

        $this->success([
            'profile' => $this->formatProfile($profile),
            'stats'   => $this->formatStats($stats),
            'verification' => $verificationStatus,
        ]);
    }

    /**
     * POST /api/v1/influencer/profile
     * ثبت پروفایل جدید یا ویرایش
     */
    public function saveProfile(): never
    {
        $userId = $this->userId();
        $data   = $this->request->body();

        $required = ['username', 'page_url', 'follower_count'];
        $errors   = [];
        foreach ($required as $f) {
            if (empty($data[$f])) $errors[$f] = "فیلد {$f} الزامی است";
        }
        if (!empty($errors)) $this->validationError($errors);

        $existing = $this->profileModel->findByUserId($userId);

        if ($existing) {
            $merged = \array_merge((array)$existing, $data);
            if (\in_array($existing->status, ['rejected'])) {
                $merged['status'] = 'pending';
                $merged['rejection_reason'] = null;
            }
            $ok = $this->profileModel->update((int)$existing->id, $merged);
            if (!$ok) $this->error('خطا در بروزرسانی پروفایل');
            $profile = $this->profileModel->find((int)$existing->id);
        } else {
            $result = $this->promotionService->registerInfluencer($userId, $data);
            if (!$result['success']) $this->error($result['message'], 422);
            $profile = $result['profile'];
        }

        $this->success([
            'profile'           => $this->formatProfile($profile),
            'verification_code' => $profile->verification_code ?? null,
        ], $existing ? 'پروفایل بروزرسانی شد.' : 'پروفایل ثبت شد. کد تایید را منتشر کنید.');
    }

    /**
     * POST /api/v1/influencer/profile/verify
     * ثبت لینک پست تایید مالکیت
     */
    public function submitVerification(): never
    {
        $userId  = $this->userId();
        $postUrl = \trim($this->request->body('post_url') ?? '');

        if (empty($postUrl)) $this->validationError(['post_url' => 'لینک پست الزامی است']);

        $profile = $this->profileModel->findByUserId($userId);
        if (!$profile) $this->error('پروفایل یافت نشد.', 404);

        $status = $this->verificationService->getVerificationStatus((int)$profile->id);
        if (in_array($status['status'], ['not_started', 'expired'], true)) {
            $generate = $this->verificationService->generateVerificationCode((int)$profile->id);
            if (!$generate['ok']) {
                $this->error($generate['error'] ?? 'خطا در تولید کد تایید', 422);
            }
        }

        $result = $this->verificationService->submitVerificationProof((int)$profile->id, $userId, $postUrl);
        if (!$result['ok']) $this->error($result['error'] ?? 'خطا در ثبت لینک تایید', 422);

        $this->success(['verification_id' => $result['verification_id']], $result['message'] ?? 'اثبات ارسال شد.');
    }

    // ══════════════════════════════════════════════════════
    //  بازار — لیست و جزئیات
    // ══════════════════════════════════════════════════════

    /**
     * GET /api/v1/influencer/list
     * لیست اینفلوئنسرهای تایید شده با فیلتر و رتبه
     */
    public function getList(): never
    {
        [$page, $perPage, $offset] = $this->paginationParams(15);

        $filters = [
            'category'      => $this->request->get('category')      ?? '',
            'platform'      => $this->request->get('platform')      ?? '',
            'search'        => $this->request->get('search')        ?? '',
            'min_followers' => $this->request->get('min_followers') ?? '',
            'max_price'     => $this->request->get('max_price')     ?? '',
        ];
        $sort = $this->request->get('sort') ?? 'priority';

        $profiles = $this->promotionService->listVerifiedProfiles($filters, $sort, $perPage, $offset);
        $total    = $this->promotionService->countVerifiedProfiles($filters);

        $items = \array_map(function($p) {
            $stats = $this->scoreService->getInfluencerStats((int)$p->id);
            return \array_merge($this->formatProfile($p), ['stats' => $this->formatStats($stats)]);
        }, $profiles);

        $this->paginated($items, $total, $page, $perPage);
    }

    /**
     * GET /api/v1/influencer/{id}
     * جزئیات کامل یک اینفلوئنسر
     */
    public function show(): never
    {
        $id      = (int)($this->request->param('id') ?? 0);
        $profile = $this->promotionService->getProfileById($id);

        if (!$profile || $profile->status !== 'verified' || !(int)$profile->is_active) {
            $this->error('اینفلوئنسر یافت نشد', 404, 'NOT_FOUND');
        }

        $stats = $this->scoreService->getInfluencerStats($id);

        $this->success([
            'profile' => $this->formatProfile($profile),
            'stats'   => $this->formatStats($stats),
            'pricing' => $this->formatPricing($profile),
        ]);
    }

    // ══════════════════════════════════════════════════════
    //  سفارش‌ها — تبلیغ‌دهنده
    // ══════════════════════════════════════════════════════

    /**
     * POST /api/v1/influencer/orders
     */
    public function createOrder(): never
    {
        $userId = $this->userId();
        $data   = $this->request->body();

        if (empty($data['influencer_id'])) {
            $this->validationError(['influencer_id' => 'شناسه اینفلوئنسر الزامی است']);
        }
        if (empty($data['order_type'])) {
            $this->validationError(['order_type' => 'نوع سفارش الزامی است']);
        }
        if (empty($data['caption'])) {
            $this->validationError(['caption' => 'توضیح سفارش الزامی است']);
        }

        $result = $this->promotionService->createOrder(
            $userId,
            (int)$data['influencer_id'],
            $data
        );

        if (!$result['success']) $this->error($result['message'], 422, 'ORDER_FAILED');

        $this->success(
            ['order' => $this->formatOrder($result['order'])],
            $result['message'],
            201
        );
    }

    /**
     * GET /api/v1/influencer/orders/placed
     */
    public function myPlacedOrders(): never
    {
        $userId = $this->userId();
        [$page, $perPage, $offset] = $this->paginationParams(20);
        $status = $this->request->get('status');

        $orders = $this->promotionService->getOrdersByCustomer($userId, $status, $perPage, $offset);
        $total  = $this->promotionService->countOrdersByCustomer($userId, $status);

        $this->paginated(
            \array_map([$this, 'formatOrder'], $orders),
            $total, $page, $perPage
        );
    }

    /**
     * POST /api/v1/influencer/orders/{id}/confirm
     */
    public function buyerConfirm(): never
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $result  = $this->promotionService->buyerConfirm($orderId, $this->userId());

        if (!$result['success']) $this->error($result['message'], 422);
        $this->success(null, $result['message']);
    }

    /**
     * POST /api/v1/influencer/orders/{id}/dispute
     */
    public function buyerDispute(): never
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $reason  = \trim($this->request->body('reason') ?? '');

        if (empty($reason)) $this->validationError(['reason' => 'دلیل اعتراض الزامی است']);

        $r1 = $this->promotionService->buyerDispute($orderId, $this->userId(), $reason);
        if (!$r1['success']) $this->error($r1['message'], 422);

        $r2 = $this->disputeService->openDispute($orderId, $this->userId(), $reason);
        if (!$r2['success']) $this->error($r2['message'], 422);

        $this->success(
            ['dispute_id' => $r2['dispute']->id ?? null],
            $r2['message'],
            201
        );
    }

    // ══════════════════════════════════════════════════════
    //  سفارش‌ها — اینفلوئنسر
    // ══════════════════════════════════════════════════════

    /**
     * GET /api/v1/influencer/orders/received
     */
    public function receivedOrders(): never
    {
        $userId  = $this->userId();
        $profile = $this->profileModel->findByUserId($userId);

        if (!$profile) $this->error('ابتدا پروفایل ثبت کنید', 403, 'NO_PROFILE');

        [$page, $perPage, $offset] = $this->paginationParams(20);
        $status = $this->request->get('status');

        $orders = $this->orderModel->getByInfluencer($userId, $status, $perPage, $offset);
        $total  = \count($this->orderModel->getByInfluencer($userId, $status, 1000, 0));

        $this->paginated(
            \array_map([$this, 'formatOrder'], $orders),
            $total, $page, $perPage
        );
    }

    /**
     * POST /api/v1/influencer/orders/{id}/respond
     * body: { "action": "accept"|"reject", "reason": "..." }
     */
    public function respondOrder(): never
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $action  = $this->request->body('action') ?? '';
        $reason  = $this->request->body('reason');

        if (!\in_array($action, ['accept', 'reject'])) {
            $this->validationError(['action' => 'مقدار باید accept یا reject باشد']);
        }

        $result = $this->promotionService->respondToOrder(
            $orderId, $this->userId(), $action, $reason
        );

        if (!$result['success']) $this->error($result['message'], 422);
        $this->success(null, $result['message']);
    }

    /**
     * POST /api/v1/influencer/orders/{id}/proof
     * multipart/form-data: proof_link, proof_notes, proof_screenshot(file)
     */
    public function submitProof(): never
    {
        $orderId   = (int)($this->request->param('id') ?? 0);
        $proofData = [
            'proof_link'  => \trim($this->request->body('proof_link')  ?? ''),
            'proof_notes' => \trim($this->request->body('proof_notes') ?? ''),
        ];

        if (empty($proofData['proof_link'])) {
            $this->validationError(['proof_link' => 'لینک مدرک الزامی است']);
        }

        if (!empty($_FILES['proof_screenshot']['name'])) {
            $up = $this->uploadService->upload($_FILES['proof_screenshot'], 'inf-proof');
            if ($up['success']) $proofData['proof_screenshot'] = $up['path'];
        }

        $result = $this->promotionService->submitProof($orderId, $this->userId(), $proofData);

        if (!$result['success']) $this->error($result['message'], 422);
        $this->success(null, $result['message']);
    }

    // ══════════════════════════════════════════════════════
    //  اختلاف
    // ══════════════════════════════════════════════════════

    /**
     * GET /api/v1/influencer/orders/{id}/dispute
     */
    public function getDispute(): never
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $userId  = $this->userId();
        $order   = $this->orderModel->find($orderId);

        if (!$order) $this->error('سفارش یافت نشد', 404);

        $isParty = (int)$order->customer_id === $userId
                || (int)$order->influencer_user_id === $userId;
        if (!$isParty) $this->error('دسترسی غیرمجاز', 403, 'FORBIDDEN');

        $dispute  = $this->disputeModel->findByOrderId($orderId);
        if (!$dispute) $this->error('اختلافی یافت نشد', 404, 'NO_DISPUTE');

        $messages = $this->disputeModel->getMessages((int)$dispute->id);
        $role     = (int)$order->influencer_user_id === $userId ? 'influencer' : 'customer';

        $this->success([
            'dispute'  => $this->formatDispute($dispute),
            'messages' => \array_map([$this, 'formatDisputeMessage'], $messages),
            'role'     => $role,
            'order'    => $this->formatOrder($order),
        ]);
    }

    /**
     * POST /api/v1/influencer/orders/{id}/dispute/message
     * body: { "message": "..." }, file: attachment
     */
    public function sendDisputeMessage(): never
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $userId  = $this->userId();
        $text    = \trim($this->request->body('message') ?? '');

        if (empty($text)) $this->validationError(['message' => 'متن پیام الزامی است']);

        $order = $this->orderModel->find($orderId);
        if (!$order) $this->error('سفارش یافت نشد', 404);

        $role    = (int)$order->influencer_user_id === $userId ? 'influencer' : 'customer';
        $dispute = $this->disputeModel->findByOrderId($orderId);
        if (!$dispute) $this->error('اختلاف یافت نشد', 404);

        $attachment = null;
        if (!empty($_FILES['attachment']['name'])) {
            $up = $this->uploadService->upload($_FILES['attachment'], 'dispute-evidence');
            if ($up['success']) $attachment = $up['path'];
        }

        $result = $this->disputeService->sendMessage(
            (int)$dispute->id, $userId, $role, $text, $attachment
        );

        if (!$result['success']) $this->error($result['message'], 422);
        $this->success(
            ['message' => $this->formatDisputeMessage($result['msg'])],
            $result['message'],
            201
        );
    }

    /**
     * POST /api/v1/influencer/orders/{id}/dispute/escalate
     */
    public function escalateDispute(): never
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $dispute = $this->disputeModel->findByOrderId($orderId);
        if (!$dispute) $this->error('اختلاف یافت نشد', 404);

        $result = $this->disputeService->escalateToAdmin((int)$dispute->id, $this->userId());
        if (!$result['success']) $this->error($result['message'], 422);
        $this->success(null, $result['message']);
    }

    /**
     * POST /api/v1/influencer/orders/{id}/dispute/resolve
     * body: { "verdict": "favor_influencer|favor_customer|partial", "resolution": "..." }
     */
    public function resolveDispute(): never
    {
        $orderId    = (int)($this->request->param('id') ?? 0);
        $verdict    = $this->request->body('verdict')    ?? '';
        $resolution = $this->request->body('resolution') ?? '';

        $allowed = ['favor_influencer', 'favor_customer', 'partial'];
        if (!\in_array($verdict, $allowed)) {
            $this->validationError(['verdict' => 'مقدار verdict نامعتبر است']);
        }

        $dispute = $this->disputeModel->findByOrderId($orderId);
        if (!$dispute) $this->error('اختلاف یافت نشد', 404);

        $result = $this->disputeService->resolveByAgreement(
            (int)$dispute->id, $this->userId(), $resolution, $verdict
        );

        if (!$result['success']) $this->error($result['message'], 422);
        $this->success(null, $result['message']);
    }

    // ══════════════════════════════════════════════════════
    //  Formatters — خروجی یکدست برای موبایل
    // ══════════════════════════════════════════════════════

    private function formatProfile(object $p): array
    {
        return [
            'id'               => (int)$p->id,
            'username'         => $p->username,
            'page_url'         => $p->page_url,
            'platform'         => $p->platform ?? 'instagram',
            'profile_image'    => $p->profile_image ?? null,
            'follower_count'   => (int)($p->follower_count ?? 0),
            'engagement_rate'  => (float)($p->engagement_rate ?? 0),
            'category'         => $p->category ?? null,
            'bio'              => $p->bio ?? null,
            'status'           => $p->status,
            'verification_code'=> $p->verification_code ?? null,
            'is_active'        => (bool)($p->is_active ?? false),
            'total_orders'     => (int)($p->total_orders ?? 0),
            'completed_orders' => (int)($p->completed_orders ?? 0),
            'pricing'          => $this->formatPricing($p),
            'verified_at'      => $p->verified_at ?? null,
        ];
    }

    private function formatPricing(object $p): array
    {
        $pricing = [];
        if (($p->story_price_24h ?? 0) > 0)
            $pricing[] = ['type'=>'story','hours'=>24,'price'=>(float)$p->story_price_24h,'label'=>'استوری ۲۴ ساعته'];
        if (($p->post_price_24h ?? 0) > 0)
            $pricing[] = ['type'=>'post','hours'=>24,'price'=>(float)$p->post_price_24h,'label'=>'پست ۲۴ ساعته'];
        if (($p->post_price_48h ?? 0) > 0)
            $pricing[] = ['type'=>'post','hours'=>48,'price'=>(float)$p->post_price_48h,'label'=>'پست ۴۸ ساعته'];
        if (($p->post_price_72h ?? 0) > 0)
            $pricing[] = ['type'=>'post','hours'=>72,'price'=>(float)$p->post_price_72h,'label'=>'پست ۷۲ ساعته'];
        return $pricing;
    }

    private function formatStats(object $s): array
    {
        return [
            'total_points'     => (int)($s->total_points     ?? 0),
            'total_orders'     => (int)($s->total_orders     ?? 0),
            'completed_orders' => (int)($s->completed_orders ?? 0),
            'disputed_orders'  => (int)($s->disputed_orders  ?? 0),
            'completion_rate'  => (int)($s->completion_rate  ?? 0),
            'dispute_rate'     => (int)($s->dispute_rate     ?? 0),
            'grade'            => $s->grade       ?? '—',
            'grade_label'      => $s->grade_label ?? '—',
            'stars'            => (int)($s->stars ?? 0),
        ];
    }

    private function formatOrder(object $o): array
    {
        return [
            'id'                      => (int)$o->id,
            'order_type'              => $o->order_type,
            'duration_hours'          => (int)($o->duration_hours ?? 24),
            'caption'                 => $o->caption ?? null,
            'link'                    => $o->link ?? null,
            'preferred_publish_time'  => $o->preferred_publish_time ?? null,
            'price'                   => (float)($o->price ?? 0),
            'influencer_earning'      => (float)($o->influencer_earning ?? 0),
            'currency'                => $o->currency ?? 'irt',
            'status'                  => $o->status,
            'proof_link'              => $o->proof_link ?? null,
            'proof_notes'             => $o->proof_notes ?? null,
            'proof_screenshot'        => $o->proof_screenshot ?? null,
            'proof_submitted_at'      => $o->proof_submitted_at ?? null,
            'buyer_check_deadline'    => $o->buyer_check_deadline ?? null,
            'influencer_username'     => $o->influencer_username ?? null,
            'influencer_avatar'       => $o->influencer_avatar ?? null,
            'customer_name'           => $o->customer_name ?? null,
            'created_at'              => $o->created_at,
            'updated_at'              => $o->updated_at ?? null,
        ];
    }

    private function formatDispute(object $d): array
    {
        return [
            'id'              => (int)$d->id,
            'order_id'        => (int)$d->order_id,
            'status'          => $d->status,
            'reason'          => $d->reason ?? null,
            'peer_deadline'   => $d->peer_deadline ?? null,
            'resolution_note' => $d->resolution_note ?? null,
            'admin_verdict'   => $d->admin_verdict ?? null,
            'created_at'      => $d->created_at,
        ];
    }

    private function formatDisputeMessage(object $m): array
    {
        return [
            'id'          => (int)$m->id,
            'role'        => $m->role,
            'sender_name' => $m->sender_name ?? null,
            'message'     => $m->message,
            'attachment'  => $m->attachment ?? null,
            'created_at'  => $m->created_at,
        ];
    }
}
