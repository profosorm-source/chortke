<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WalletServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Services\User\UserService;
use App\Services\SettingService;
use App\Models\VitrineListing;
use App\Models\VitrineRequest;
use App\Models\Notification;
use App\Services\AuditTrail;
use App\Services\Shared\ReferralService;
use Core\Database;
use App\Contracts\LoggerInterface;
/**
 * VitrineService — منطق تجاری سرویس ویترین
 *
 * مسئولیت‌ها:
 *   - ثبت آگهی (متن‌محور، بدون تصویر)
 *   - مدیریت جریان escrow (قفل، تایید، خودکار)
 *   - مدیریت درخواست‌های خرید
 *   - ارسال اعلان‌ها
 *   - محاسبه کمیسیون
 */
class VitrineService extends \App\Services\BaseService
{
    public function __construct(
        private readonly VitrineListing     $listing,
        private readonly VitrineRequest     $request,
        private readonly WalletServiceInterface      $wallet,
        private readonly NotificationServiceInterface $notif,
        private readonly FeatureFlagService $flags,
        private readonly Database           $db,
        LoggerInterface                     $logger,
        private AuditTrail                  $auditTrail,
        private readonly FinancialEscrowService $escrow,
        private readonly StateMachineService $stateMachine,
        private readonly WebSocketService   $webSocket,
        private readonly SettingService     $settings,
        private readonly UserService        $userService,
        private readonly ReferralService     $referralService,
        private readonly \App\Services\Shared\RatingService $ratingService,
    ) {
        parent::__construct($logger);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // بررسی پیش‌نیازها
    // ─────────────────────────────────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return $this->flags->isEnabled('vitrine_enabled');
    }

    /**
     * بررسی اینکه آیا کاربر مجاز به معامله است
     * (KYC تایید شده + در لیست سیاه نباشد)
     */
    public function canTrade(int $userId): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'message' => 'سرویس ویترین در حال حاضر غیرفعال است.'];
        }

        $kycRequired = (bool) (int) $this->settings->get('vitrine_kyc_required', '1');
        if ($kycRequired && !$this->userService->isKycVerified($userId)) {
            return ['ok' => false, 'message' => 'برای استفاده از ویترین ابتدا باید احراز هویت (KYC) را تکمیل کنید.'];
        }

        $user = $this->db->query("SELECT is_blacklisted, fraud_score FROM users WHERE id = ?", [$userId])->fetch();
        if ($user && $user->is_blacklisted) {
            return ['ok' => false, 'message' => 'حساب شما محدود شده است. با پشتیبانی تماس بگیرید.'];
        }

        return ['ok' => true];
    }

    public function getListings(array $filters, int $perPage, int $offset): array
    {
        return [
            'listings'   => $this->listing->getActive($filters, $perPage, $offset),
            'total'      => $this->listing->countActive($filters),
            'categories' => $this->listing->categories(),
            'platforms'  => $this->listing->platforms()
        ];
    }

    public function getWantedListings(array $filters, int $perPage, int $offset): array
    {
        return [
            'listings'   => $this->listing->getWantedListings($filters, $perPage, $offset),
            'categories' => $this->listing->categories(),
            'platforms'  => $this->listing->platforms()
        ];
    }

    public function getListingDetails(int $id, int $userId): ?array
    {
        $listing = $this->listing->find($id);
        if (!$listing) return null;

        // Check ownership and admin privileges for non-active listings to prevent IDOR
        if ($listing->status !== 'active' && 
            (int)$listing->seller_id !== $userId && 
            !is_admin()) {
            return null;
        }

        $isSeller = (int) $listing->seller_id === $userId;
        
        return [
            'listing'    => $listing,
            'isSeller'   => $isSeller,
            'isBuyer'    => (int) ($listing->buyer_id ?? 0) === $userId,
            'isWatched'  => $this->listing->isWatched($userId, $id),
            'watchCount' => $this->listing->watchCount($id),
            'requests'   => $isSeller ? $this->request->getAllByListing($id) : [],
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories(),
            'platforms'  => $this->listing->platforms()
        ];
    }

    public function getUserDashboard(int $userId): array
    {
        return [
            'listings'   => $this->listing->getBySeller($userId),
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories()
        ];
    }

    public function getUserPurchases(int $userId): array
    {
        return [
            'listings'   => $this->listing->getByBuyer($userId),
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories()
        ];
    }

public function adminApproveListing(int $listingId, int $adminId): array
{
    $this->db->beginTransaction();
    try {
        $listing = $this->listing->getSafe($listingId);
        if (!$listing) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        // ✅ State machine validation
        if (!$this->stateMachine->canTransitionVitrineListing('pending', 'active')) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'وضعیت آگهی قابل تایید نیست'];
        }

        // ✅ Prevent self-approval
        if ($this->listing->isSelfApproval($listingId, $adminId)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خود تایید فروشنده ممکن نیست'];
        }

        $ok = $this->listing->updateStatus($listingId, 'active');
        if (!$ok) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در تایید آگهی'];
        }

        // ✅ Log state machine event
        $this->db->query(
            "INSERT INTO state_machine_events (entity_type, entity_id, from_state, to_state, performed_by, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            ['vitrine_listing', $listingId, 'pending', 'active', $adminId, 'admin_approval']
        );

        $this->db->commit();

        // ✅ Send real-time notification to seller
        $this->webSocket->notifyListingApproved($listingId, (int)$listing->seller_id);

        return ['success' => true, 'message' => 'آگهی تایید شد'];
    } catch (\Throwable $e) {
        $this->db->rollBack();
        $this->logger->error('vitrine.approve.failed', ['error' => $e->getMessage()]);
        return ['success' => false, 'message' => config('app.debug') ? 'خطا در تایید: ' . $e->getMessage() : 'خطای سیستمی در تایید رخ داده است.'];
    }
}

public function adminRejectListing(int $listingId, string $reason, int $adminId): array
{
    $this->db->beginTransaction();
    try {
        $listing = $this->listing->getSafe($listingId);
        if (!$listing) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        // ✅ State machine validation
        if (!$this->stateMachine->canTransitionVitrineListing('pending', 'rejected')) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'آگهی قابل رد نیست'];
        }

        $ok = $this->listing->updateStatus($listingId, 'rejected', ['rejection_reason' => $reason]);
        if (!$ok) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در رد آگهی'];
        }

        // ✅ Log state machine event
        $this->db->query(
            "INSERT INTO state_machine_events (entity_type, entity_id, from_state, to_state, performed_by, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            ['vitrine_listing', $listingId, 'pending', 'rejected', $adminId, $reason]
        );

        $this->db->commit();
        return ['success' => true, 'message' => 'آگهی رد شد'];
    } catch (\Throwable $e) {
        $this->db->rollBack();
        $this->logger->error('vitrine.reject.failed', ['error' => $e->getMessage()]);
        return ['success' => false, 'message' => config('app.debug') ? 'خطا در رد: ' . $e->getMessage() : 'خطای سیستمی در رد رخ داده است.'];
    }
}

public function adminRefundListing(int $listingId, int $adminId): array
{
    $this->db->beginTransaction();
    try {
        $listing = $this->listing->getSafe($listingId);
        if (!$listing) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        // ✅ Use FinancialEscrowService for proper escrow handling
        $result = $this->escrow->refundVitrineFunds(
            $listingId,
            (int)$listing->buyer_id,
            'admin_refund'
        );

        if (!$result['ok']) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $result['error'] ?? 'خطا در بازگشت وجه'];
        }

        $ok = $this->listing->updateStatus($listingId, 'cancelled');
        if (!$ok) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'ریفاند انجام شد ولی تغییر وضعیت آگهی ناموفق بود'];
        }

        $this->db->commit();
        return ['success' => true, 'message' => 'ریفاند با موفقیت انجام شد'];
    } catch (\Throwable $e) {
        $this->db->rollBack();
        $this->logger->error('vitrine.refund.failed', ['error' => $e->getMessage()]);
        return ['success' => false, 'message' => config('app.debug') ? 'خطا در ریفاند: ' . $e->getMessage() : 'خطای سیستمی در ریفاند رخ داده است.'];
    }
}


    // ─────────────────────────────────────────────────────────────────────────
    // ثبت آگهی
    // ─────────────────────────────────────────────────────────────────────────

    public function createListing(int $userId, array $data): array
    {
        $check = $this->canTrade($userId);
        if (!$check['ok']) return ['success' => false, 'message' => $check['message']];

        // محدودیت تعداد آگهی فعال
        $maxActive = (int) $this->settings->get('vitrine_max_active_per_user', '5');
        $activeCount = $this->listing->countActiveByUser($userId);
        if ($activeCount >= $maxActive) {
            return ['success' => false, 'message' => "حداکثر {$maxActive} آگهی فعال می‌توانید داشته باشید."];
        }

        $minPrice = (float) $this->settings->get('vitrine_min_price_usdt', '1');
        $maxPrice = (float) $this->settings->get('vitrine_max_price_usdt', '100000');
        $price    = (float) ($data['price_usdt'] ?? 0);

        if ($price < $minPrice || $price > $maxPrice) {
            return ['success' => false, 'message' => "قیمت باید بین {$minPrice} و {$maxPrice} USDT باشد."];
        }

        $data['seller_id'] = $userId;

        $result = $this->listing->createListing($data);
        if (!$result) {
            return ['success' => false, 'message' => 'خطا در ثبت آگهی. لطفاً دوباره تلاش کنید.'];
        }

        $this->auditTrail->record('vitrine.listing_created', $userId, [
            'listing_id'   => $result->id,
            'category'     => $result->category,
            'listing_type' => $result->listing_type,
            'price'        => $result->price_usdt,
        ]);

        return ['success' => true, 'listing' => $result];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // درخواست خرید
    // ─────────────────────────────────────────────────────────────────────────

    public function sendRequest(int $requesterId, int $listingId, array $data): array
    {
        $check = $this->canTrade($requesterId);
        if (!$check['ok']) return ['success' => false, 'message' => $check['message']];

        $listing = $this->listing->find($listingId);
        if (!$listing || $listing->status !== VitrineListing::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'آگهی فعال نیست.'];
        }
        if ((int) $listing->seller_id === $requesterId) {
            return ['success' => false, 'message' => 'نمی‌توانید به آگهی خود درخواست دهید.'];
        }
        if ($this->request->existsPending($listingId, $requesterId)) {
            return ['success' => false, 'message' => 'درخواست شما قبلاً ثبت شده و در انتظار پاسخ است.'];
        }

        $req = $this->request->create([
            'listing_id'   => $listingId,
            'requester_id' => $requesterId,
            'offer_price'  => !empty($data['offer_price']) ? (float) $data['offer_price'] : null,
            'message'      => trim($data['message'] ?? ''),
        ]);
        if (!$req) return ['success' => false, 'message' => 'خطا در ثبت درخواست.'];

        // اعلان به فروشنده
        $requesterName = $this->db->query("SELECT full_name FROM users WHERE id = ?", [$requesterId])->fetch()->full_name ?? 'کاربر';
        $offerText = $req->offer_price ? ' با قیمت پیشنهادی ' . number_format((float)$req->offer_price, 2) . ' USDT' : '';

        $this->notif->send(
            (int) $listing->seller_id,
            Notification::TYPE_INFO,
            'درخواست خرید آگهی شما',
            "کاربر «{$requesterName}»{$offerText} برای آگهی «{$listing->title}» درخواست خرید ثبت کرد.",
            ['listing_id' => $listingId, 'request_id' => $req->id],
            url('/vitrine/' . $listingId),
            'مشاهده آگهی',
            'high'
        );

        $this->logger->info('vitrine.request_sent', [
            'listing_id'   => $listingId,
            'requester_id' => $requesterId,
            'offer_price'  => $req->offer_price,
        ]);
        return ['success' => true, 'request' => $req];
    }

    /**
     * پذیرش درخواست توسط فروشنده → منتقل به مرحله پرداخت
     */
    public function acceptRequest(int $sellerId, int $requestId): array
    {
        $req = $this->request->findById($requestId);
        if (!$req || $req->status !== VitrineRequest::STATUS_PENDING) {
            return ['success' => false, 'message' => 'درخواست یافت نشد.'];
        }

        $listing = $this->listing->find((int) $req->listing_id);
        if (!$listing || (int) $listing->seller_id !== $sellerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if ($listing->status !== VitrineListing::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'آگهی دیگر فعال نیست.'];
        }

        // به‌روزرسانی قیمت پیشنهادی اگر داده شده
        $finalPrice = $req->offer_price ?? $listing->price_usdt;

        $this->db->beginTransaction();
        try {
            $this->request->updateStatus($requestId, VitrineRequest::STATUS_ACCEPTED);

            // رد درخواست‌های دیگر
            $this->db->prepare(
                "UPDATE vitrine_requests SET status = 'rejected', responded_at = NOW()
                 WHERE listing_id = ? AND id != ? AND status = 'pending'"
            )->execute([$req->listing_id, $requestId]);

            // به‌روزرسانی قیمت پیشنهادی در آگهی
            if ($req->offer_price) {
                $this->listing->updateStatus(
                    (int) $req->listing_id,
                    VitrineListing::STATUS_ACTIVE,
                    ['offer_price_usdt' => $finalPrice]
                );
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('vitrine.request.accept_failed', ['err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }

        // اعلان به خریدار
        $this->notif->send(
            (int) $req->requester_id,
            Notification::TYPE_INFO,
            'درخواست شما پذیرفته شد',
            "فروشنده درخواست شما برای آگهی «{$listing->title}» را پذیرفت. لطفاً پرداخت را انجام دهید.",
            ['listing_id' => $req->listing_id, 'request_id' => $requestId],
            url('/vitrine/' . $req->listing_id),
            'پرداخت و تکمیل خرید',
            'urgent'
        );

        return ['success' => true, 'final_price' => $finalPrice];
    }

    public function rejectRequest(int $sellerId, int $requestId): array
    {
        $req = $this->request->findById($requestId);
        if (!$req || (int) $req->seller_id !== $sellerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        $this->request->updateStatus($requestId, VitrineRequest::STATUS_REJECTED);

        // اعلان به خریدار
        $listing = $this->listing->find((int) $req->listing_id);
        if ($listing) {
            $this->notif->send(
                (int) $req->requester_id,
                Notification::TYPE_INFO,
                'درخواست شما رد شد',
                "متأسفانه فروشنده درخواست شما برای آگهی «{$listing->title}» را رد کرد.",
                ['listing_id' => $req->listing_id],
                url('/vitrine'),
                'مشاهده آگهی‌های دیگر'
            );
        }

        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // پرداخت و Escrow
    // ─────────────────────────────────────────────────────────────────────────

    public function lockEscrow(int $buyerId, int $listingId): array
    {
        $check = $this->canTrade($buyerId);
        if (!$check['ok']) return ['success' => false, 'message' => $check['message']];

        $listing = $this->listing->find($listingId);
        if (!$listing || $listing->status !== \App\Models\VitrineListing::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'آگهی فعال نیست.'];
        }
        if ((int) $listing->seller_id === $buyerId) {
            return ['success' => false, 'message' => 'نمی‌توانید آگهی خود را بخرید.'];
        }

        $finalPrice = $listing->offer_price_usdt ?? $listing->price_usdt;

        $this->db->beginTransaction();
        try {
            $debit = $this->wallet->pay($buyerId, $finalPrice, 'usdt', [
                'type' => 'vitrine_escrow',
                'description' => "اسکرو ویترین #{$listingId}"
            ]);
            if (empty($debit['success'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $debit['message'] ?? 'موجودی کافی نیست.'];
            }

            $escrowDays  = (int) $this->settings->get('vitrine_escrow_days', '3');
            $deadline    = date('Y-m-d H:i:s', strtotime("+{$escrowDays} days"));

            $ok = $this->listing->updateStatus($listingId, \App\Models\VitrineListing::STATUS_IN_ESCROW, [
                'buyer_id'         => $buyerId,
                'escrow_locked_at' => date('Y-m-d H:i:s'),
                'escrow_deadline'  => $deadline,
            ]);
            if (!$ok) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در آپدیت وضعیت.'];
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('vitrine.escrow.lock_failed', ['id' => $listingId, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }

        $this->notif->send(
            (int) $listing->seller_id,
            \App\Models\Notification::TYPE_INFO,
            "پرداخت انجام شد",
            "خریدار مبلغ را پرداخت کرد. لطفا کالا/خدمات را تحویل دهید تا وجه پس از تایید آزاد شود.",
            ['action_url' => url("/user/vitrine/{$listingId}")]
        );

        $this->auditTrail->record('vitrine.escrow.locked', $buyerId, [
            'listing_id' => $listingId,
            'amount'     => $finalPrice,
            'deadline'   => $deadline,
        ]);

        return ['success' => true, 'deadline' => $deadline, 'amount' => $finalPrice];
    }
    public function confirmDelivery(int $buyerId, int $listingId): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing || (int) $listing->buyer_id !== $buyerId || $listing->status !== VitrineListing::STATUS_IN_ESCROW) {
            return ['success' => false, 'message' => 'عملیات غیرمجاز.'];
        }

        return $this->releaseFundsToSeller($listing, 'buyer_confirm');
    }

    /**
     * آزادسازی وجه به فروشنده (مشترک بین تایید دستی و خودکار)
     */
    public function releaseFundsToSeller(object $listing, string $reason = 'manual'): array
    {
        $commission = (float) $this->settings->get('vitrine_commission_percent', '5') / 100;
        $amount     = $listing->offer_price_usdt ?? $listing->price_usdt;
        $net        = round($amount * (1 - $commission), 6);

        $this->db->beginTransaction();
        try {
            $credit = $this->wallet->deposit(
                (int) $listing->seller_id,
                $net,
                'usdt',
                [
                    'type' => 'vitrine_sale',
                    'description' => "درآمد ویترین #{$listing->id}"
                ]
            );
            if (empty($credit['success'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در پرداخت به فروشنده.'];
            }
            
            // پورسانت ریفرال (معرف فروشنده محصول)
            $userRecord = $this->userService->findById((int)$listing->seller_id);
            if ($userRecord && !empty($userRecord->referred_by)) {
                $this->referralService->processCommission((int)$userRecord->referred_by, $net, 'usdt', [
                    'action' => 'vitrine_sale_reward',
                    'seller_id' => $listing->seller_id,
                    'listing_id' => $listing->id
                ]);
            }

            $extra = ['auto_confirmed' => ($reason === 'auto_cron') ? 1 : 0];
            $ok    = $this->listing->updateStatus((int) $listing->id, \App\Models\VitrineListing::STATUS_SOLD, $extra);
            if (!$ok) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در آپدیت وضعیت.'];
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('vitrine.escrow.release_failed', ['id' => $listing->id, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }

        $this->notif->send(
            (int) $listing->seller_id,
            \App\Models\Notification::TYPE_SUCCESS,
            "وجه آزاد شد",
            "مبلغ {$net} USDT به حساب شما واریز شد.",
            ['action_url' => url("/user/vitrine/{$listing->id}")]
        );

        $this->auditTrail->record('vitrine.escrow.released', (int)$listing->seller_id, [
            'listing_id' => $listing->id,
            'amount'     => $net,
            'reason'     => $reason,
        ]);

        return ['success' => true, 'net_amount' => $net];
    }
    public function openDispute(int $userId, int $listingId, string $reason): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing || $listing->status !== VitrineListing::STATUS_IN_ESCROW) {
            return ['success' => false, 'message' => 'فقط در حال انتقال می‌توان اختلاف ثبت کرد.'];
        }

        $isBuyer  = (int) $listing->buyer_id  === $userId;
        $isSeller = (int) $listing->seller_id === $userId;
        if (!$isBuyer && !$isSeller) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        $who = $isBuyer ? 'خریدار' : 'فروشنده';
        $this->listing->updateStatus($listingId, VitrineListing::STATUS_DISPUTED, [
            'admin_note' => "اختلاف توسط {$who}: " . mb_substr($reason, 0, 500),
        ]);

        $this->logger->warning('vitrine.dispute_opened', [
            'listing_id' => $listingId,
            'user_id'    => $userId,
            'reason'     => mb_substr($reason, 0, 100),
        ]);
        $this->logger->activity('vitrine.dispute_opened', "اختلاف ویترین ثبت شد", $userId, ['listing_id' => $listingId] ?? []);

        // اعلان به ادمین‌ها
        $this->notif->sendToAdmins(
            Notification::TYPE_INFO,
            'اختلاف ویترین ثبت شد',
            "{$who} برای آگهی «{$listing->title}» (#{$listingId}) اختلاف ثبت کرد: " . mb_substr($reason, 0, 100),
            ['action_url' => url('/admin/vitrine/' . $listingId . '/dispute')]
        );

        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ادمین: رسیدگی اختلاف
    // ─────────────────────────────────────────────────────────────────────────

    public function resolveDispute(int $listingId, string $winner, int $adminId): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing) return ['success' => false, 'message' => 'آگهی یافت نشد.'];

        if ($winner === 'seller') {
            $result = $this->releaseFundsToSeller($listing, 'admin_resolve');
        } else {
            // بازگشت وجه به خریدار
            $amount = $listing->offer_price_usdt ?? $listing->price_usdt;
            $this->db->beginTransaction();
            try {
                $credit = $this->wallet->deposit(
                    (int) $listing->buyer_id,
                    $amount,
                    'usdt',
                    'vitrine_refund',
                    "استرداد ویترین #{$listingId}"
                );
                if (!$credit['success']) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'خطا در استرداد وجه.'];
                }
                $this->listing->updateStatus($listingId, VitrineListing::STATUS_CANCELLED);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطای سیستمی.'];
            }

            $this->notif->send(
                (int) $listing->buyer_id,
                Notification::TYPE_INFO,
                'اختلاف به نفع شما حل شد — وجه بازگشت',
                "وجه " . number_format($amount, 2) . " USDT برای آگهی «{$listing->title}» به کیف پول شما بازگشت.",
                ['listing_id' => $listingId],
                url('/wallet'),
                'مشاهده کیف پول',
                'high'
            );
            $result = ['success' => true];
        }

        $this->auditTrail->record('vitrine.dispute_resolved', $adminId, [
            'listing_id' => $listingId,
            'winner'     => $winner,
        ]);
        $this->logger->info('vitrine.dispute_resolved', [
            'listing_id' => $listingId,
            'winner'     => $winner,
            'admin_id'   => $adminId,
        ]);

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // اعلان: آگهی مشابه برای کاربران علاقه‌مند
    // ─────────────────────────────────────────────────────────────────────────

    public function notifySimilarListing(object $newListing): void
    {
        $users = $this->listing->getCategoryAlertUsers($newListing->category, $newListing->platform);
        foreach ($users as $userId) {
            if ((int) $userId === (int) $newListing->seller_id) continue;
            $this->notif->send(
                (int) $userId,
                Notification::TYPE_INFO,
                'آگهی مشابه جدید در ویترین',
                "آگهی جدیدی در دسته «{$newListing->category}» منتشر شد: «{$newListing->title}»",
                ['listing_id' => $newListing->id],
                url('/vitrine/' . $newListing->id),
                'مشاهده آگهی'
            );
        }
    }

    /**
     * اعلان تایید آگهی توسط ادمین
     */
    public function notifyListingApproved(int $sellerId, object $listing): void
    {
        $this->notif->send(
            $sellerId,
            Notification::TYPE_INFO,
            'آگهی شما تایید شد',
            "آگهی «{$listing->title}» توسط تیم ویترین تایید و منتشر شد.",
            ['listing_id' => $listing->id],
            url('/vitrine/' . $listing->id),
            'مشاهده آگهی',
            'high'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cron: تایید خودکار اسکروهای منقضی
    // ─────────────────────────────────────────────────────────────────────────

    public function processExpiredEscrows(): array
    {
        $expired = $this->listing->getExpiredEscrows();
        $results = ['processed' => 0, 'errors' => 0];

        foreach ($expired as $listing) {
            $result = $this->releaseFundsToSeller($listing, 'auto_cron');
            if ($result['success']) {
                $results['processed']++;
                echo "[VITRINE CRON] آگهی #{$listing->id} — وجه آزاد شد (خودکار)\n";
            } else {
                $results['errors']++;
                $this->logger->error('vitrine.cron.auto_confirm_failed', [
                    'listing_id' => $listing->id,
                    'error'      => $result['message'],
                ]);
            }
        }

        return $results;
    }

    /**
     * ثبت امتیاز و نظر برای آگهی ویترین
     */
    public function rateListing(int $raterId, int $listingId, int $stars, string $comment = ''): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing) {
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        $stars = max(1, min(5, $stars));

        try {
            $ok = $this->ratingService->rate(
                $raterId,
                (int)$listing->seller_id,
                'vitrine_listing',
                $listingId,
                $stars,
                $comment
            );

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت امتیاز'];
            }

            return ['success' => true, 'message' => 'امتیاز با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            $this->logger->error('vitrine.rate.failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => config('app.debug') ? 'خطای سیستمی: ' . $e->getMessage() : 'خطای سیستمی رخ داده است.'];
        }
    }

    /**
     * ثبت گزارش تخلف برای آگهی ویترین
     */
    public function reportListing(int $reporterId, int $listingId, string $reason, string $description = ''): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing) {
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        try {
            $ok = $this->ratingService->report([
                'reporter_id' => $reporterId,
                'ref_type' => 'vitrine_listing',
                'ref_id' => $listingId,
                'reason' => $reason,
                'description' => $description
            ]);

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت گزارش'];
            }

            return ['success' => true, 'message' => 'گزارش تخلف با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            $this->logger->error('vitrine.report.failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => config('app.debug') ? 'خطای سیستمی: ' . $e->getMessage() : 'خطای سیستمی رخ داده است.'];
        }
    }

    public function searchVitrine(array $filters, int $limit, int $offset): array
    {
        // Enforce public access safety defaults
        $filters['status'] = 'active';
        $filters['listing_type'] = 'sell';

        $q = $filters['q'] ?? '';

        $sort = $filters['sort'] ?? 'newest';
        [$sortCol, $sortDir] = match ($sort) {
            'oldest'     => ['created_at', 'ASC'],
            'price_asc'  => ['price_usdt', 'ASC'],
            'price_desc' => ['price_usdt', 'DESC'],
            default      => ['created_at', 'DESC'],
        };

        // Optimized centralized model delegation
        return $this->listing->searchNative($q, $filters, $limit, $offset, $sortCol, $sortDir);
    }

    /**
     * علاقه‌مندی / نشانه‌گذاری
     */
    public function toggleWatch(int $userId, int $listingId): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing) {
            return ['success' => false, 'message' => 'آگهی یافت نشد.'];
        }

        $alreadyWatched = $this->listing->isWatched($userId, $listingId);
        if ($alreadyWatched) {
            $this->listing->removeWatch($userId, $listingId);
            return ['success' => true, 'watched' => false, 'message' => 'از لیست علاقه‌مندی‌ها حذف شد.'];
        } else {
            $this->listing->addWatch($userId, $listingId);
            return ['success' => true, 'watched' => true, 'message' => 'به لیست علاقه‌مندی‌ها اضافه شد.'];
        }
    }

    /**
     * مدیریت: لیست و آمار
     */
    public function getAdminIndexData(array $filters, int $perPage, int $offset): array
    {
        return [
            'listings'   => $this->listing->adminList($filters, $perPage, $offset),
            'total'      => $this->listing->adminCount($filters),
            'stats'      => $this->listing->adminStats(),
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories()
        ];
    }
}