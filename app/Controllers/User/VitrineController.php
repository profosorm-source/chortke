<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Models\VitrineListing;
use App\Models\VitrineRequest;
use App\Services\VitrineService;
use App\Services\FeatureFlagService;

/**
 * VitrineController — پنل کاربری سرویس ویترین
 *
 * تمام آگهی‌ها متن‌محور هستند — هیچ تصویری پذیرفته نمی‌شود
 */
class VitrineController extends BaseUserController
{
    private VitrineService  $service;
    private FeatureFlagService $flags;

    public function __construct(
        VitrineService     $service,
        FeatureFlagService $flags
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->service      = $service;
        $this->flags        = $flags;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // لیست آگهی‌های فروش
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $filters = [
            'category'   => $this->request->get('category')   ?? '',
            'platform'   => $this->request->get('platform')   ?? '',
            'search'     => $this->request->get('search')      ?? '',
            'min_price'  => $this->request->get('min_price')   ?? '',
            'max_price'  => $this->request->get('max_price')   ?? '',
            'min_members'=> $this->request->get('min_members') ?? '',
            'sort'       => $this->request->get('sort')        ?? 'newest',
        ];

        $page     = max(1, (int) ($this->request->get('page') ?? 1));
        $perPage  = 20;
        
        $data = $this->service->getListings($filters, $perPage, ($page - 1) * $perPage);

        view('user.vitrine.index', array_merge($data, [
            'title'      => 'ویترین — بازار دیجیتال',
            'filters'    => $filters,
            'page'       => $page,
            'pages'      => (int) ceil($data['total'] / $perPage)
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // لیست درخواست‌های خریداران
    // ─────────────────────────────────────────────────────────────────────────

    public function wantedIndex(): void
    {
        $filters = [
            'category' => $this->request->get('category') ?? '',
            'platform' => $this->request->get('platform') ?? '',
            'search'   => $this->request->get('search')   ?? '',
        ];

        $page     = max(1, (int) ($this->request->get('page') ?? 1));
        $perPage  = 20;
        
        $data = $this->service->getWantedListings($filters, $perPage, ($page - 1) * $perPage);

        view('user.vitrine.wanted', array_merge($data, [
            'title'      => 'ویترین — خریداران (متقاضیان)',
            'filters'    => $filters,
            'page'       => $page
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // فرم ثبت آگهی
    // ─────────────────────────────────────────────────────────────────────────

    public function create(): void
    {
        $userId = (int) user_id();
        $check  = $this->service->canTrade($userId);
        if (!$check['ok']) {
            $this->session->setFlash('error', $check['message']);
            redirect(url('/vitrine'));
            exit;
        }

        view('user.vitrine.create', [
            'title'       => 'ثبت آگهی فروش در ویترین',
            'listingType' => 'sell',
            'categories'  => $this->listing->categories(),
            'platforms'   => $this->listing->platforms(),
        ]);
    }

    public function createWanted(): void
    {
        $userId = (int) user_id();
        $check  = $this->service->canTrade($userId);
        if (!$check['ok']) {
            $this->session->setFlash('error', $check['message']);
            redirect(url('/vitrine'));
            exit;
        }

        view('user.vitrine.create', [
            'title'       => 'ثبت درخواست خرید در ویترین',
            'listingType' => 'buy',
            'categories'  => $this->listing->categories(),
            'platforms'   => $this->listing->platforms(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ذخیره آگهی جدید
    // ─────────────────────────────────────────────────────────────────────────

    public function store(): void
    {
        $userId = (int) user_id();
        $data   = $this->request->body();

        $request = new \App\Validators\Requests\CreateVitrineListingRequest($data);

        if (!$request->validate()) {
            $errors = $request->errors();
            $firstError = reset($errors);
            $msg = is_array($firstError) ? reset($firstError) : $firstError;
            $this->session->setFlash('error', $msg ?: 'اطلاعات ورودی نامعتبر است.');
            redirect(url('/vitrine/' . (($data['listing_type'] ?? '') === 'buy' ? 'wanted/create' : 'sell/create')));
            return;
        }

        $validatedData = $request->validated();
        $price = (float)($validatedData['price_usdt'] ?? 0);
        $minPrice = (float)setting('vitrine_min_price', 1);
        $maxPrice = (float)setting('vitrine_max_price', 100000);

        if ($price < $minPrice || $price > $maxPrice) {
            $this->session->setFlash('error', "قیمت باید بین {$minPrice} و {$maxPrice} USDT باشد.");
            redirect(url('/vitrine/' . (($validatedData['listing_type'] ?? '') === 'buy' ? 'wanted/create' : 'sell/create')));
            return;
        }

        // ✅ Store raw data (Escaping should be done in the View layer)
        $result = $this->service->createListing($userId, [
            'listing_type'   => in_array($validatedData['listing_type'] ?? 'sell', ['sell', 'buy'], true) ? $validatedData['listing_type'] : 'sell',
            'category'       => $validatedData['category'] ?? '',
            'platform'       => $validatedData['platform'] ?? '',
            'title'          => trim($validatedData['title']),
            'description'    => trim($validatedData['description']),
            'specs'          => !empty($data['specs']) ? trim($data['specs']) : null,
            'username'       => !empty($data['username']) ? trim($data['username']) : null,
            'member_count'   => max(0, (int)($data['member_count'] ?? 0)),
            'creation_date'  => !empty($data['creation_date']) ? $data['creation_date'] : null,
            'price_usdt'     => $price,
            'min_price_usdt' => !empty($data['min_price_usdt']) ? max(0, (float)$data['min_price_usdt']) : null,
        ]);

        if ($result['success']) {
            $this->session->setFlash('success', 'آگهی شما ثبت شد و پس از بررسی توسط تیم ویترین منتشر می‌شود.');
            redirect(url('/vitrine/my-listings'));
        } else {
            $this->session->setFlash('error', $result['message'] ?? 'خطا در ثبت آگهی.');
            redirect(url('/vitrine/' . (($validatedData['listing_type'] ?? '') === 'buy' ? 'wanted/create' : 'sell/create')));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // صفحه آگهی
    // ─────────────────────────────────────────────────────────────────────────

    public function show(): void
    {
        $id      = (int) $this->request->param('id');
        $userId  = (int) user_id();
        
        $data = $this->service->getListingDetails($id, $userId);

        if (!$data || in_array($data['listing']->status, [
            VitrineListing::STATUS_REJECTED,
            VitrineListing::STATUS_CANCELLED,
        ])) {
            $this->session->setFlash('error', 'آگهی یافت نشد.');
            redirect(url('/vitrine'));
            exit;
        }

        view('user.vitrine.show', array_merge($data, [
            'title' => $data['listing']->title . ' — ویترین'
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // آگهی‌های من
    // ─────────────────────────────────────────────────────────────────────────

    public function myListings(): void
    {
        $userId = (int) user_id();
        $data   = $this->service->getUserDashboard($userId);

        view('user.vitrine.my-listings', array_merge($data, [
            'title' => 'آگهی‌های من — ویترین'
        ]));
    }

    public function myPurchases(): void
    {
        $userId = (int) user_id();
        $data   = $this->service->getUserPurchases($userId);

        view('user.vitrine.my-purchases', array_merge($data, [
            'title' => 'خریدهای من — ویترین'
        ]));
    }

    public function myRequests(): void
    {
        $userId   = (int) user_id();
        $requests = $this->requestModel->getByRequester($userId);

        view('user.vitrine.my-requests', [
            'title'    => 'درخواست‌های من — ویترین',
            'requests' => $requests,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // اقدامات AJAX
    // ─────────────────────────────────────────────────────────────────────────

    /** ثبت درخواست خرید با قیمت پیشنهادی */
    public function sendRequest(): void
    {
        $userId    = (int) user_id();
        $listingId = (int) $this->request->param('id');
        $data      = $this->request->body();

        $result = $this->service->sendRequest($userId, $listingId, [
            'offer_price' => !empty($data['offer_price']) ? (float) $data['offer_price'] : null,
            'message'     => trim($data['message'] ?? ''),
        ]);

        $this->response->json($result);
    }

    /** پذیرش درخواست توسط فروشنده */
    public function acceptRequest(): void
    {
        $userId    = (int) user_id();
        $requestId = (int) $this->request->param('rid');
        $result    = $this->service->acceptRequest($userId, $requestId);
        $this->response->json($result);
    }

    /** رد درخواست توسط فروشنده */
    public function rejectRequest(): void
    {
        $userId    = (int) user_id();
        $requestId = (int) $this->request->param('rid');
        $result    = $this->service->rejectRequest($userId, $requestId);
        $this->response->json($result);
    }

    /** قفل اسکرو — خریدار پرداخت می‌کند */
    public function buy(): void
    {
        $userId    = (int) user_id();
        $listingId = (int) $this->request->param('id');
        $result    = $this->service->lockEscrow($userId, $listingId);
        $this->response->json($result);
    }

    /** تایید دریافت توسط خریدار */
    public function confirmDelivery(): void
    {
        $userId    = (int) user_id();
        $listingId = (int) $this->request->param('id');
        $result    = $this->service->confirmDelivery($userId, $listingId);
        $this->response->json($result);
    }

    /** ثبت اختلاف */
    public function dispute(): void
    {
        $userId    = (int) user_id();
        $listingId = (int) $this->request->param('id');
        $reason    = trim($this->request->post('reason') ?? '');

        if (mb_strlen($reason) < 10) {
            $this->response->json(['success' => false, 'message' => 'لطفاً دلیل اختلاف را با جزئیات بنویسید (حداقل ۱۰ کاراکتر).']);
            return;
        }

        $result = $this->service->openDispute($userId, $listingId, $reason);
        $this->response->json($result);
    }

    /** علاقه‌مندی / نشانه‌گذاری */
    public function watch(): void
    {
        $userId    = (int) user_id();
        $listingId = (int) $this->request->param('id');
        
        $result = $this->service->toggleWatch($userId, $listingId);
        $this->response->json($result);
    }
}
