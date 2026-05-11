<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Notification\NotificationService;
use App\Services\User\UserService;
use App\Services\SettingService;
use App\Models\VitrineListing;
use App\Models\VitrineRequest;
use App\Models\Notification;
use App\Services\AuditTrail;
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
        private readonly WalletService      $wallet,
        private readonly NotificationService $notif,
        private readonly FeatureFlagService $flags,
        private readonly Database           $db,
        private readonly LoggerInterface    $logger,
        private AuditTrail                  $auditTrail,
        private readonly FinancialEscrowService $escrow,
        private readonly StateMachineService $stateMachine,
        private readonly WebSocketService   $webSocket,
        private readonly SettingService     $settings,
        private readonly UserService        $userService,
        private readonly \App\Services\Shared\RatingService $ratingService,
    ) {}

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
        return ['success' => false, 'message' => 'خطا در تایید: ' . $e->getMessage()];
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
        return ['success' => false, 'message' => 'خطا در رد: ' . $e->getMessage()];
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
        return ['success' => false, 'message' => 'خطا در ریفاند: ' . $e->getMessage()];
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
        if (!$listing || $listing->status !== VitrineListing::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'آگهی فعال نیست.'];
        }
        if ((int) $listing->seller_id === $buyerId) {
            return ['success' => false, 'message' => 'نمی‌توانید آگهی خود را بخرید.'];
        }

        // قیمت نهایی: اگر قیمت پیشنهادی پذیرفته‌شده وجود داشت، آن را اعمال کن
        $finalPrice = $listing->offer_price_usdt ?? $listing->price_usdt;

        $this->db->beginTransaction();
        try {
            $debit = $this->wallet->debit($buyerId, $finalPrice, 'usdt', 'vitrine_escrow', "اسکرو ویترین #{$listingId}");
            if (!$debit['success']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $debit['message'] ?? 'موجودی کافی نیست.'];
            }

            $escrowDays  = (int) $this->settings->get('vitrine_escrow_days', '3');
            $deadline    = date('Y-m-d H:i:s', strtotime("+{$escrowDays} days"));

            $ok = $this->listing->updateStatus($listingId, VitrineListing::STATUS_IN_ESCROW, [
                'buyer_id'         => $buyerId,
                'escrow_locked_at' => date('Y-m-d H:i:s'),
                'escrow_deadline'  => $deadline,
            ]);
            if (!$ok) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در به‌روزرسانی وضعیت.'];
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('vitrine.escrow.lock_failed', ['id' => $listingId, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }

        // اعلان به فروشنده
        $this->notif->send(
            (int) $listing->seller_id,
            Notification::TYPE_INFO,
            'پرداخت انجام شد — اطلاعات را ارسال کنید',
            "خریدار مبلغ " . number_format($finalPrice, 2) . " USDT برای آگهی «{$listing->title}» پرداخت کرد. اطلاعات دسترسی را در ۷۲ ساعت ارسال کنید.",
            ['listing_id' => $listingId],
            url('/vitrine/' . $listingId),
            'ارسال اطلاعات',
            'urgent'
        );

        $this->auditTrail->record('vitrine.escrow_locked', $buyerId, [
            'listing_id' => $listingId,
            'amount'     => $finalPrice,
            'deadline'   => $deadline,
        ]);
        $this->logger->activity('vitrine.escrow_locked', "پرداخت escrow ویترین انجام شد — مبلغ: {$finalPrice} USDT", $buyerId, ['listing_id' => $listingId, 'amount' => $finalPrice] ?? []);
        $this->logger->info('vitrine.escrow_locked', [
            'listing_id' => $listingId,
            'buyer_id'   => $buyerId,
            'amount'     => $finalPrice,
            'deadline'   => $deadline,
        ]);

        return ['success' => true, 'deadline' => $deadline, 'amount' => $finalPrice];
    }

    /**
     * تایید دریافت توسط خریدار → آزادسازی وجه به فروشنده
     */
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
            $credit = $this->wallet->credit(
                (int) $listing->seller_id,
                $net,
                'usdt',
                'vitrine_sale',
                "درآمد ویترین #{$listing->id}"
            );
            if (!$credit['success']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در پرداخت به فروشنده.'];
            }

            $extra = ['auto_confirmed' => ($reason === 'auto_cron') ? 1 : 0];
            $ok    = $this->listing->updateStatus((int) $listing->id, VitrineListing::STATUS_SOLD, $extra);
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

        // اعلان به فروشنده
        $this->notif->send(
            (int) $listing->seller_id,
            Notification::TYPE_INFO,
            'وجه به حساب شما واریز شد',
            "مبلغ " . number_format($net, 2) . " USDT (پس از کسر کمیسیون) بابت فروش آگهی «{$listing->title}» به کیف پول شما واریز شد.",
            ['listing_id' => $listing->id, 'amount' => $net],
            url('/wallet'),
            'مشاهده کیف پول',
            'high'
        );

        // اعلان به خریدار
        if ($listing->buyer_id) {
            $autoText = ($reason === 'auto_cron') ? ' (تایید خودکار پس از پایان مهلت)' : '';
            $this->notif->send(
                (int) $listing->buyer_id,
                Notification::TYPE_INFO,
                'معامله تکمیل شد' . $autoText,
                "معامله آگهی «{$listing->title}» با موفقیت تکمیل شد." . $autoText,
                ['listing_id' => $listing->id],
                url('/vitrine/my-purchases'),
                'مشاهده خریدها'
            );
        }

        // اعلان به کسانی که این آگهی را watch کرده بودند
        $watchers = $this->listing->getWatcherIds((int) $listing->id);
        foreach ($watchers as $watcherId) {
            if ((int)$watcherId === (int)$listing->seller_id || (int)$watcherId === (int)$listing->buyer_id) continue;
            $this->notif->send(
                (int) $watcherId,
                Notification::TYPE_INFO,
                'آگهی مورد علاقه شما فروخته شد',
                "آگهی «{$listing->title}» که آن را نشانه گذاشته بودید فروخته شد.",
                ['listing_id' => $listing->id],
                url('/vitrine'),
                'مشاهده آگهی‌های مشابه'
            );
        }

        $this->auditTrail->record('vitrine.funds_released', (int) $listing->seller_id, [
            'listing_id' => $listing->id,
            'net'        => $net,
            'reason'     => $reason,
        ]);
        $this->logger->activity('vitrine.funds_released', "وجه ویترین آزاد شد — {$net} USDT به فروشنده پرداخت شد", (int) $listing->seller_id, ['listing_id' => $listing->id, 'net' => $net, 'reason' => $reason] ?? []);
        $this->logger->info('vitrine.funds_released', [
            'listing_id' => $listing->id,
            'seller_id'  => $listing->seller_id,
            'buyer_id'   => $listing->buyer_id,
            'net'        => $net,
            'reason'     => $reason,
        ]);

        return ['success' => true, 'net' => $net];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // اختلاف
    // ─────────────────────────────────────────────────────────────────────────

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
                $credit = $this->wallet->credit(
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
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
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
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
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
}

