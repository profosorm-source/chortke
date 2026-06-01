<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WalletServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Services\User\UserService;
use App\Services\Settings\AppSettings;
use App\Models\VitrineListing;
use App\Models\VitrineRequest;
use App\Models\Notification;
use App\Services\AuditTrail;
use App\Services\Shared\ReferralService;
use Core\Database;
use App\Contracts\LoggerInterface;

class VitrineService
{
    private \Core\EventDispatcher $eventDispatcher;
    private VitrineListing $listing;
    private VitrineRequest $request;
    private FeatureFlagService $flags;
    private AppSettings $settings;
    private UserService $userService;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        VitrineListing $listing,
        VitrineRequest $request,
        FeatureFlagService $flags,
        AppSettings $settings,
        UserService $userService
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->listing = $listing;
        $this->request = $request;
        $this->flags = $flags;
        $this->settings = $settings;
        $this->userService = $userService;
}

    public function isEnabled(): bool
    {
        return $this->flags->isEnabled('vitrine_enabled');
    }

    public function canTrade(int $userId): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'message' => 'سرویس ویترین در حال حاضر غیرفعال است.'];
        }

        $kycRequired = (bool) (int) $this->settings->get('vitrine_kyc_required', '1');
        if ($kycRequired && !$this->userService->isKycVerified($userId)) {
            return ['ok' => false, 'message' => 'برای استفاده از ویترین ابتدا باید احراز هویت (KYC) را تکمیل کنید.'];
        }

        if ($this->userService->isBlacklisted($userId)) {
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
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\AdminApproveVitrineListingJob::class);
        return $job->handle($listingId, $adminId);
    }

    public function adminRejectListing(int $listingId, string $reason, int $adminId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\AdminRejectVitrineListingJob::class);
        return $job->handle($listingId, $reason, $adminId);
    }

    public function adminRefundListing(int $listingId, int $adminId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\AdminRefundVitrineListingJob::class);
        return $job->handle($listingId, $adminId);
    }

    public function createListing(int $userId, array $data): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\CreateVitrineListingJob::class);
        return $job->handle($userId, $data);
    }

    public function sendRequest(int $requesterId, int $listingId, array $data): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\SendVitrineRequestJob::class);
        return $job->handle($requesterId, $listingId, $data);
    }

    public function acceptRequest(int $sellerId, int $requestId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\AcceptVitrineRequestJob::class);
        return $job->handle($sellerId, $requestId);
    }

    public function rejectRequest(int $sellerId, int $requestId): array
    {
        $req = $this->request->findById($requestId);
        if (!$req || (int) $req->seller_id !== $sellerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        $this->request->updateStatus($requestId, VitrineRequest::STATUS_REJECTED);

        $this->eventDispatcher->dispatch('vitrine.request_rejected', [
            'requester_id' => (int) $req->requester_id,
            'listing_id' => $req->listing_id
        ]);

        return ['success' => true];
    }

    public function lockEscrow(int $buyerId, int $listingId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\LockVitrineEscrowJob::class);
        return $job->handle($buyerId, $listingId);
    }

    public function confirmDelivery(int $buyerId, int $listingId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\ConfirmVitrineDeliveryJob::class);
        return $job->handle($buyerId, $listingId);
    }

    public function releaseFundsToSeller(object $listing, string $reason = 'manual'): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\ReleaseVitrineFundsJob::class);
        return $job->handle($listing, $reason);
    }

    public function openDispute(int $userId, int $listingId, string $reason): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\OpenVitrineDisputeJob::class);
        return $job->handle($userId, $listingId, $reason);
    }

    public function resolveDispute(int $listingId, string $winner, int $adminId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\ResolveVitrineDisputeJob::class);
        return $job->handle($listingId, $winner, $adminId);
    }

    public function notifySimilarListing(object $newListing): void
    {
        $users = $this->listing->getCategoryAlertUsers($newListing->category, $newListing->platform);
        foreach ($users as $userId) {
            if ((int) $userId === (int) $newListing->seller_id) continue;
            if (isset($this->eventDispatcher) && $this->eventDispatcher) {
                $this->eventDispatcher->dispatchAsync('notification.requested', [
                    'user_id' => (int) $userId,
                    'type' => \App\Models\Notification::TYPE_INFO,
                    'title' => 'آگهی مشابه جدید در ویترین',
                    'message' => "آگهی جدیدی در دسته «{$newListing->category}» منتشر شد: «{$newListing->title}»",
                    'data' => ['listing_id' => $newListing->id],
                    'action_url' => url('/vitrine/' . $newListing->id),
                    'action_text' => 'مشاهده آگهی',
                    'priority' => 'normal'
                ]);
            }
        }
    }

    public function notifyListingApproved(int $sellerId, object $listing): void
    {
        if (isset($this->eventDispatcher) && $this->eventDispatcher) {
            $this->eventDispatcher->dispatchAsync('notification.requested', [
                'user_id' => $sellerId,
                'type' => \App\Models\Notification::TYPE_INFO,
                'title' => 'آگهی شما تایید شد',
                'message' => "آگهی «{$listing->title}» توسط تیم ویترین تایید و منتشر شد.",
                'data' => ['listing_id' => $listing->id],
                'action_url' => url('/vitrine/' . $listing->id),
                'action_text' => 'مشاهده آگهی',
                'priority' => 'high'
            ]);
        }
    }

    public function processExpiredEscrows(): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\ProcessExpiredVitrineEscrowsJob::class);
        return $job->handle();
    }

    public function rateListing(int $raterId, int $listingId, int $stars, string $comment = ''): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\RateVitrineListingJob::class);
        return $job->handle($raterId, $listingId, $stars, $comment);
    }

    public function reportListing(int $reporterId, int $listingId, string $reason, string $description = ''): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Vitrine\ReportVitrineListingJob::class);
        return $job->handle($reporterId, $listingId, $reason, $description);
    }

    public function searchVitrine(array $filters, int $limit, int $offset): array
    {
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
        return $this->listing->searchNative($q, $filters, $limit, $offset, $sortCol, $sortDir);
    }

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