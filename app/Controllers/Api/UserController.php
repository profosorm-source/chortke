<?php

namespace App\Controllers\Api;

use App\Models\Notification;
use App\Services\ApiRateLimiter;

/**
 * API\UserController - پروفایل و اطلاعات کاربر
 *
 * GET  /api/v1/user/profile          → اطلاعات پروفایل
 * GET  /api/v1/user/notifications    → اعلان‌ها
 * POST /api/v1/user/notifications/read → خواندن اعلان
 */
class UserController extends BaseApiController
{
    private Notification $notifModel;

    public function __construct(
        \App\Models\Notification $notifModel
    , ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->notifModel = $notifModel;
    }

    /** پروفایل کاربر */
    public function profile(): never
    {
        $user = $this->currentUser();

        $this->success([
            'id'            => $user->id,
            'full_name'     => $user->full_name,
            'email'         => $user->email,
            'mobile'        => $user->mobile,
            'referral_code' => $user->referral_code,
            'kyc_status'    => $user->kyc_status ?? 'none',
            'tier_level'    => $user->tier_level ?? 'silver',
            'is_verified'   => (bool)($user->email_verified_at ?? false),
            'created_at'    => $user->created_at,
        ]);
    }

    /** لیست اعلان‌ها */
    public function notifications(): never
    {
        $userId            = $this->userId();
        [$page, $perPage, $offset] = $this->paginationParams(20);

        $onlyUnread = (bool)($this->request->get('unread') ?? false);

        $items = $this->notifModel->getUserNotifications($userId, $onlyUnread, $perPage, $offset);
        $total = $this->notifModel->countUserNotifications($userId, $onlyUnread);

        $items = array_map(fn($n) => [
            'id'         => $n->id,
            'title'      => $n->title,
            'message'    => $n->message,
            'type'       => $n->type,
            'is_read'    => (bool)$n->is_read,
            'created_at' => $n->created_at,
        ], $items);

        $this->paginated($items, $total, $page, $perPage);
    }

    /** خواندن اعلان */
    public function markRead(): never
    {
        $userId = $this->userId();
        $id     = (int)($this->request->get('id') ?? 0);

        if (!$id) {
            // خواندن همه
            $this->notifModel->markAllAsRead($userId);
            $this->success(null, 'همه اعلان‌ها خوانده شدند');
        }

        $this->notifModel->markAsRead($id, $userId);
        $this->success(null, 'اعلان خوانده شد');
    }
}
