<?php

namespace App\Controllers\User;

use App\Models\InfluencerModel;
use App\Models\StoryOrder;
use App\Models\Dispute;

use App\Services\InfluencerService;
use App\Services\Shared\DisputeService;
use App\Services\ScoreService;
use App\Services\UploadService;
use App\Services\VerificationService;
use Core\Logger;
use Core\Database;

class InfluencerController extends BaseUserController
{
    private InfluencerModel           $profileModel;
    private StoryOrder                  $orderModel;
    private Dispute           $disputeModel;
    private InfluencerModel             $reputationModel;
    private InfluencerService       $promotionService;
    private DisputeService              $disputeService;
    private ScoreService $scoreService;
    private VerificationService         $verificationService;
    private UploadService               $upload;
    private Logger                      $logger;

    public function __construct(
        InfluencerModel           $profileModel,
        StoryOrder                  $orderModel,
        Dispute           $disputeModel,
        InfluencerModel             $reputationModel,
        InfluencerService       $promotionService,
        DisputeService              $disputeService,
        ScoreService $scoreService,
        VerificationService         $verificationService,
        UploadService               $upload,
        Logger                      $logger
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->profileModel        = $profileModel;
        $this->orderModel          = $orderModel;
        $this->disputeModel        = $disputeModel;
        $this->reputationModel     = $reputationModel;
        $this->promotionService    = $promotionService;
        $this->disputeService      = $disputeService;
        $this->scoreService = $scoreService;
        $this->verificationService = $verificationService;
        $this->upload              = $upload;
        $this->logger              = $logger;
    }

    // ══════════════════════════════════════════════════════
    //  پروفایل اینفلوئنسر
    // ══════════════════════════════════════════════════════

    public function myProfile(): void
    {
        $userId  = (int) user_id();
        $profile = $this->profileModel->findByUserId($userId);
        $stats   = $profile ? $this->scoreService->getInfluencerStats((int)$profile->id) : null;
        $orders  = $profile ? $this->orderModel->getByInfluencer((int)$profile->user_id, null, 5, 0) : [];
        $verificationStatus = null;
        $verificationCode   = null;

        if ($profile) {
            $verificationStatus = $this->verificationService->getVerificationStatus((int)$profile->id);

            if (in_array($verificationStatus['status'], ['not_started', 'expired'], true)) {
                $generate = $this->verificationService->generateVerificationCode((int)$profile->id);
                if ($generate['ok']) {
                    $verificationCode = $generate['code'];
                    if (empty($profile->verification_code) || $profile->verification_code !== $verificationCode) {
                        $this->profileModel->update((int)$profile->id, ['verification_code' => $verificationCode]);
                        $profile = $this->profileModel->find((int)$profile->id);
                    }
                    $verificationStatus['status'] = 'pending';
                    $verificationStatus['code'] = $verificationCode;
                }
            } else {
                $verificationCode = $verificationStatus['code'] ?? $profile->verification_code ?? null;
            }
        }

        view('user.influencer.my-profile', [
            'title'              => 'پروفایل اینفلوئنسر',
            'profile'            => $profile,
            'stats'              => $stats,
            'orders'             => $orders,
            'platforms'          => $this->platforms(),
            'verificationStatus' => $verificationStatus,
            'verificationCode'   => $verificationCode,
        ]);
    }

    public function register(): void
    {
        $userId = (int) user_id();
        view('user.influencer.register', [
            'title'      => 'ثبت پیج اینفلوئنسر',
            'existing'   => $this->profileModel->findByUserId($userId),
            'categories' => $this->profileModel->categories(),
            'platforms'  => $this->platforms(),
            'priceFields'=> $this->priceFields(),
        ]);
    }

    /**
     * ذخیره پروفایل
     * ✅ File upload validation + ownership check
     */
    public function storeProfile(): void
    {
        $userId = (int) user_id();
        $data   = $this->request->body();

        $request = new \App\Validators\Requests\StoreInfluencerProfileRequest($data);

        if (!$request->validate()) {
            $errors = $request->errors();
            $firstError = reset($errors);
            $msg = is_array($firstError) ? reset($firstError) : $firstError;
            $this->session->setFlash('error', $msg ?: 'اطلاعات ورودی نامعتبر است.');
            redirect(url('/influencer/register'));
            return;
        }

        $validatedData = $request->validated();

        // ✅ File upload validation
        if (!empty($_FILES['profile_image']['name'])) {
            $validation = $this->validateProfileImage($_FILES['profile_image']);
            if (!$validation['valid']) {
                $this->session->setFlash('error', $validation['error']);
                redirect(url('/influencer/register'));
                return;
            }
            
            $up = $this->upload->upload($_FILES['profile_image'], 'influencer');
            if ($up['success']) {
                $validatedData['profile_image'] = $up['path'];
            } else {
                $this->session->setFlash('error', $up['error'] ?? 'خطا در آپلود تصویر.');
                redirect(url('/influencer/register'));
                return;
            }
        }

        $existing = $this->profileModel->findByUserId($userId);
        $platform = $validatedData['platform'] ?? 'instagram';

        // Filter and whitelist safe fields to prevent mass assignment (C-12)
        $allowed = ['platform', 'username', 'bio', 'category', 'follower_count', 'profile_image'];
        $clean = array_intersect_key($validatedData, array_flip($allowed));

        $merged   = array_merge($clean, $this->extractPrices($data, $platform), ['user_id' => $userId]);

        try {
            if ($existing) {
                // Only allow update if owner
                if ((int)$existing->user_id !== $userId) {
                    $this->logger->warning('Unauthorized profile update attempt', [
                        'user_id' => $userId,
                        'profile_owner' => $existing->user_id,
                    ]);
                    http_response_code(403);
                    $this->response->json(['success' => false, 'message' => 'Unauthorized'], 403);
                    return;
                }

                if (in_array($existing->status, ['rejected'])) {
                    $merged['status'] = 'pending';
                    $merged['rejection_reason'] = null;
                }
                $ok  = $this->profileModel->update((int)$existing->id, $merged);
                $msg = $ok ? 'پروفایل بروزرسانی شد.' : 'خطا در بروزرسانی.';
            } else {
                $profile = $this->profileModel->create($merged);
                $ok  = $profile ? true : false;
                $msg = $ok ? 'پروفایل ثبت شد. منتظر تایید ادمین باشید.' : 'خطا در ثبت.';
            }

            if (!$ok) {
                $this->logger->error('influencer.store.failed', ['user_id' => $userId]);
            }

            $this->session->setFlash($ok ? 'success' : 'error', $msg);
        } catch (\Exception $e) {
            $this->logger->error('influencer.store.exception', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->session->setFlash('error', 'خطای سیستمی.');
        }

        redirect(url('/influencer/my-profile'));
    }

    /**
     * ✅ File upload validation method
     */
  private function validateProfileImage(array $file): array
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['valid' => false, 'error' => 'فایل آپلود نشد'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
        return ['valid' => false, 'error' => 'فقط JPG, PNG و WebP مجاز هستند'];
    }

    $maxSize = 2 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return ['valid' => false, 'error' => 'حجم فایل بیشتر از 2MB است'];
    }

    return ['valid' => true];
}
    

    /**
     * ثبت لینک پست تایید مالکیت
     */
    public function submitVerification(): void
    {
        $userId  = (int) user_id();
        $postUrl = \trim($this->request->post('post_url') ?? '');

        if (empty($postUrl)) {
            $this->session->setFlash('error', 'لینک پست الزامی است.');
            redirect(url('/influencer'));
            return;
        }

        $profile = $this->profileModel->findByUserId($userId);
        if (!$profile) {
            $this->session->setFlash('error', 'پروفایل اینفلوئنسر یافت نشد.');
            redirect(url('/influencer/register'));
            return;
        }

        $status = $this->verificationService->getVerificationStatus((int)$profile->id);
        if (in_array($status['status'], ['not_started', 'expired'], true)) {
            $generate = $this->verificationService->generateVerificationCode((int)$profile->id);
            if (!$generate['ok']) {
                $this->session->setFlash('error', $generate['error'] ?? 'خطا در تولید کد تایید.');
                redirect(url('/influencer'));
                return;
            }

            if (empty($profile->verification_code) || $profile->verification_code !== $generate['code']) {
                $this->profileModel->update((int)$profile->id, ['verification_code' => $generate['code']]);
                $profile = $this->profileModel->find((int)$profile->id);
            }
        }

        $result = $this->verificationService->submitVerificationProof((int)$profile->id, $userId, $postUrl);
        if ($result['ok']) {
            $this->profileModel->update((int)$profile->id, [
                'status' => 'pending_admin_review',
                'verification_post_url' => $postUrl,
            ]);
        }

        $this->session->setFlash($result['ok'] ? 'success' : 'error', $result['message'] ?? 'خطا در ثبت لینک تایید.');
        redirect(url('/influencer'));
    }

    // ══════════════════════════════════════════════════════
    //  سفارش‌های دریافتی (اینفلوئنسر)
    // ══════════════════════════════════════════════════════

    public function myOrders(): void
    {
        $userId  = (int) user_id();
        $profile = $this->profileModel->findByUserId($userId);

        if (!$profile) {
            $this->session->setFlash('error', 'ابتدا پیج خود را ثبت کنید.');
            redirect(url('/influencer/register'));
            return;
        }

        $page   = \max(1, (int)($this->request->get('page') ?? 1));
        $orders = $this->orderModel->getByInfluencer(
            $userId, null, 20, ($page - 1) * 20
        );

        view('user.influencer.my-orders', [
            'title'        => 'سفارش‌های دریافتی',
            'profile'      => $profile,
            'orders'       => $orders,
            'page'         => $page,
            'statusLabels' => $this->orderModel->statusLabels(),
            'statusClasses'=> $this->orderModel->statusClasses(),
        ]);
    }

    public function respondOrder(): void
    {
        try {
            $id     = (int)($this->request->param('id') ?? 0);
            $action = $this->request->post('action') ?? '';
            $reason = $this->request->post('reason') ?? null;

            $result = $this->promotionService->respondToOrder($id, (int)user_id(), $action, $reason);

            if (is_ajax()) { $this->response->json($result); return; }
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Exception $e) {
            $this->logger->error('influencer.respondOrder', ['err' => $e->getMessage()]);
            if (is_ajax()) { $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']); return; }
            $this->session->setFlash('error', 'خطای سیستمی.');
        }
        redirect(url('/influencer/orders'));
    }

    public function submitProof(): void
    {
        try {
            $id        = (int)($this->request->param('id') ?? 0);
            $proofData = [
                'proof_link'  => \trim($this->request->post('proof_link') ?? ''),
                'proof_notes' => \trim($this->request->post('proof_notes') ?? ''),
            ];

            if (!empty($_FILES['proof_screenshot']['name'])) {
                $up = $this->upload->upload($_FILES['proof_screenshot'], 'inf-proof');
                if ($up['success']) $proofData['proof_screenshot'] = $up['path'];
            }

            $result = $this->promotionService->submitProof($id, (int)user_id(), $proofData);

            if (is_ajax()) { $this->response->json($result); return; }
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Exception $e) {
            $this->logger->error('influencer.submitProof', ['err' => $e->getMessage()]);
            if (is_ajax()) { $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']); return; }
            $this->session->setFlash('error', 'خطای سیستمی.');
        }
        redirect(url('/influencer/orders'));
    }

    // ══════════════════════════════════════════════════════
    //  پنل اختلاف (هر دو طرف)
    // ══════════════════════════════════════════════════════

    public function disputePanel(): void
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $userId  = (int) user_id();
        $order   = $this->orderModel->find($orderId);

        if (!$order) {
            $this->session->setFlash('error', 'سفارش یافت نشد.');
            redirect(url('/influencer/orders'));
            return;
        }

        // هر دو طرف می‌توانند این صفحه را ببینند
        $isInfluencer = (int)$order->influencer_user_id === $userId;
        $isCustomer   = (int)$order->customer_id === $userId;

        if (!$isInfluencer && !$isCustomer) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            redirect(url('/influencer/orders'));
            return;
        }

        $dispute  = $this->disputeModel->findByOrderId($orderId);
        $messages = $dispute ? $this->disputeModel->getMessages((int)$dispute->id) : [];
        $role     = $isInfluencer ? 'influencer' : 'customer';

        view('user.influencer.dispute-panel', [
            'title'    => 'پنل اختلاف',
            'order'    => $order,
            'dispute'  => $dispute,
            'messages' => $messages,
            'role'     => $role,
            'userId'   => $userId,
        ]);
    }

    public function sendDisputeMsg(): void
    {
        try {
            $orderId   = (int)($this->request->param('id') ?? 0);
            $userId    = (int) user_id();
            $order     = $this->orderModel->find($orderId);

            if (!$order) { $this->response->json(['success' => false, 'message' => 'سفارش یافت نشد.']); return; }

            $role    = (int)$order->influencer_user_id === $userId ? 'influencer' : 'customer';
            $dispute = $this->disputeModel->findByOrderId($orderId);
            if (!$dispute) { $this->response->json(['success' => false, 'message' => 'اختلاف یافت نشد.']); return; }

            $message    = \trim($this->request->post('message') ?? '');
            $attachment = null;

            if (!empty($_FILES['attachment']['name'])) {
                $up = $this->upload->upload($_FILES['attachment'], 'dispute-evidence');
                if ($up['success']) $attachment = $up['path'];
            }

            $result = $this->disputeService->sendMessage((int)$dispute->id, $userId, $role, $message, $attachment);
            $this->response->json($result);
        } catch (\Exception $e) {
            $this->logger->error('influencer.sendDisputeMsg', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
        }
    }

    public function escalateDispute(): void
    {
        try {
            $orderId = (int)($this->request->param('id') ?? 0);
            $userId  = (int) user_id();
            $dispute = $this->disputeModel->findByOrderId($orderId);

            if (!$dispute) { $this->response->json(['success' => false, 'message' => 'اختلاف یافت نشد.']); return; }

            $result = $this->disputeService->escalateToAdmin((int)$dispute->id, $userId);
            $this->response->json($result);
        } catch (\Exception $e) {
            $this->logger->error('influencer.escalateDispute', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
        }
    }

    public function resolveDisputePeer(): void
    {
        try {
            $orderId    = (int)($this->request->param('id') ?? 0);
            $userId     = (int) user_id();
            $resolution = \trim($this->request->post('resolution') ?? '');
            $verdict    = \trim($this->request->post('verdict') ?? 'favor_influencer');
            $dispute    = $this->disputeModel->findByOrderId($orderId);

            if (!$dispute) { $this->response->json(['success' => false, 'message' => 'اختلاف یافت نشد.']); return; }

            $result = $this->disputeService->resolveByAgreement((int)$dispute->id, $userId, $resolution, $verdict);
            $this->response->json($result);
        } catch (\Exception $e) {
            $this->logger->error('influencer.resolveDisputePeer', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
        }
    }

    // ══════════════════════════════════════════════════════
    //  تبلیغ‌دهنده — لیست اینفلوئنسرها
    // ══════════════════════════════════════════════════════

    public function advertise(): void
    {
        $filters = [
            'platform' => $this->request->get('platform') ?? '',
            'category' => $this->request->get('category') ?? '',
            'search'   => $this->request->get('search') ?? '',
            'min_followers' => $this->request->get('min_followers') ?? '',
            'max_price'     => $this->request->get('max_price') ?? '',
            'sort'          => $this->request->get('sort') ?? 'priority',
        ];

        $page     = \max(1, (int)($this->request->get('page') ?? 1));
        $limit    = 12;
        $profiles = $this->profileModel->getVerified($filters, $filters['sort'], $limit, ($page - 1) * $limit);
        $total    = $this->profileModel->countVerified($filters);

        // آمار رتبه برای هر پروفایل
        $statsMap = [];
        foreach ($profiles as $p) {
            $statsMap[(int)$p->id] = $this->scoreService->getInfluencerStats((int)$p->id);
        }

        view('user.influencer.ads', [
            'title'      => 'انتخاب اینفلوئنسر',
            'profiles'   => $profiles,
            'statsMap'   => $statsMap,
            'total'      => $total,
            'page'       => $page,
            'pages'      => (int)\ceil($total / $limit),
            'filters'    => $filters,
            'categories' => $this->profileModel->categories(),
            'platforms'  => $this->platforms(),
        ]);
    }

    public function createOrder(): void
    {
        $influencerId = (int)($this->request->get('influencer_id') ?? 0);
        $profile      = $influencerId ? $this->profileModel->find($influencerId) : null;
        $stats        = $profile ? $this->scoreService->getInfluencerStats((int)$profile->id) : null;

        view('user.influencer.create-order', [
            'title'      => 'ثبت سفارش تبلیغ',
            'profile'    => $profile,
            'stats'      => $stats,
            'platforms'  => $this->platforms(),
        ]);
    }

    public function storeOrder(): void
    {
        $userId       = (int) user_id();
        $influencerId = (int)($this->request->post('influencer_id') ?? 0);
        $data         = $this->request->body();

        $validator = \Core\Validator::create($data, [
            'influencer_id' => 'required|numeric|min:1',
            'order_type' => 'required|in:story,post',
            'duration_hours' => 'required|numeric|min:1',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $firstError = reset($errors);
            $msg = is_array($firstError) ? reset($firstError) : $firstError;
            $this->session->setFlash('error', $msg ?: 'اطلاعات ورودی نامعتبر است.');
            redirect(url('/influencer/ads/create?influencer_id=' . $influencerId));
            return;
        }

        $validatedData = $validator->data();

        try {
            if (!empty($_FILES['brief_file']['name'])) {
                $up = $this->upload->upload($_FILES['brief_file'], 'inf-brief');
                if ($up['success']) $validatedData['media_path'] = $up['path'];
            }

            $result = $this->promotionService->createOrder($userId, $influencerId, $validatedData);
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
            redirect($result['success']
                ? url('/influencer/ads/my-orders')
                : url('/influencer/ads/create?influencer_id=' . $influencerId)
            );
        } catch (\Exception $e) {
            $this->logger->error('influencer.storeOrder', ['err' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطای سیستمی در ثبت سفارش.');
            redirect(url('/influencer/ads'));
        }
    }

    public function myPlacedOrders(): void
    {
        $userId = (int) user_id();
        $page   = \max(1, (int)($this->request->get('page') ?? 1));
        $orders = $this->orderModel->getByCustomer($userId, null, 20, ($page - 1) * 20);

        view('user.influencer.my-placed-orders', [
            'title'        => 'سفارش‌های تبلیغ من',
            'orders'       => $orders,
            'page'         => $page,
            'statusLabels' => $this->orderModel->statusLabels(),
            'statusClasses'=> $this->orderModel->statusClasses(),
        ]);
    }

    /**
     * تایید buyer — سفارش درست انجام شده
     */
    public function buyerConfirm(): void
    {
        try {
            $orderId = (int)($this->request->param('id') ?? 0);
            $result  = $this->promotionService->buyerConfirm($orderId, (int)user_id());

            if (is_ajax()) { $this->response->json($result); return; }
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Exception $e) {
            $this->logger->error('influencer.buyerConfirm', ['err' => $e->getMessage()]);
            if (is_ajax()) { $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']); return; }
            $this->session->setFlash('error', 'خطای سیستمی.');
        }
        redirect(url('/influencer/ads/my-orders'));
    }

    /**
     * اعتراض buyer → شروع peer resolution
     */
    public function buyerDispute(): void
    {
        try {
            $orderId = (int)($this->request->param('id') ?? 0);
            $reason  = \trim($this->request->post('reason') ?? '');
            $userId  = (int) user_id();

            if (empty($reason)) {
                $this->response->json(['success' => false, 'message' => 'دلیل اعتراض الزامی است.']);
                return;
            }

            // مرحله اول: buyer_dispute روی order
            $r1 = $this->promotionService->buyerDispute($orderId, $userId, $reason);
            if (!$r1['success']) { $this->response->json($r1); return; }

            // مرحله دوم: باز کردن پرونده اختلاف
            $r2 = $this->disputeService->openDispute($orderId, $userId, $reason);
            $this->response->json($r2);
        } catch (\Exception $e) {
            $this->logger->error('influencer.buyerDispute', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
        }
    }

    // ══════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════

    private function platforms(): array
    {
        return ['instagram' => 'اینستاگرام', 'telegram' => 'تلگرام'];
    }

    private function priceFields(): array
    {
        return [
            'instagram' => [
                'story_price_24h' => 'استوری ۲۴ ساعته',
                'post_price_24h'  => 'پست ۲۴ ساعته',
                'post_price_48h'  => 'پست ۴۸ ساعته',
                'post_price_72h'  => 'پست ۷۲ ساعته',
            ],
            'telegram' => [
                'sponsored_post_price' => 'پست اسپانسری',
                'pin_price'            => 'پین پیام',
                'forward_price'        => 'فوروارد پیام',
            ],
        ];
    }

    private function extractPrices(array $d, string $platform): array
    {
        $out = [
            'story_price_24h'      => 0, 'post_price_24h'  => 0,
            'post_price_48h'       => 0, 'post_price_72h'  => 0,
            'sponsored_post_price' => 0, 'pin_price'       => 0,
            'forward_price'        => 0,
        ];
        foreach ($this->priceFields()[$platform] ?? [] as $k => $_) {
            $out[$k] = (float)($d[$k] ?? 0);
        }
        return $out;
    }
}
