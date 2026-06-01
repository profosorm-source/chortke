<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Services\SocialTask\SocialTaskService;
use App\Services\AdSystemManager;
use App\Models\Ads;

/**
 * AdsocialController - مدیریت کمپین‌های شبکه‌های اجتماعی (Advertiser) و انجام تسک‌ها (Worker)
 */
class AdsocialController extends BaseUserController
{
    private SocialTaskService $socialTaskService;
    private AdSystemManager $adManager;
    private Ads $adModel;
    public function __construct(
        SocialTaskService $socialTaskService,
        AdSystemManager $adManager,
        Ads $adModel
    , ?\App\Contracts\LoggerInterface $logger = null) {        $this->socialTaskService = $socialTaskService;
        $this->adManager = $adManager;
        $this->adModel = $adModel;

        parent::__construct(null, null, null, null, $logger);
    }

    /**
     * صفحه اصلی لیست تسک‌ها برای کسب درآمد توسط کاربر (Worker)
     */
    public function income(): void
    {
        $userId = (int)user_id();
        
        // بازیابی لیست تسک‌های سوشیال فعال با اعمال سیستم ضدتقلب
        $result = $this->socialTaskService->getTasksForExecutor($userId, [
            'sort' => $this->request->get('sort') ?? 'random'
        ], 20);
        
        // بازیابی آمار کلی فعالیت کاربر
        $stats = $this->socialTaskService->getExecutorStats($userId);

        view('user.adsocial.index', [
            'title' => 'Adsocial — تسک شبکه‌های اجتماعی',
            'tasks' => $result['tasks'] ?? [],
            'stats' => $stats,
            'restriction_level' => $result['restriction_level'] ?? 'clean'
        ]);
    }

    /**
     * آغاز اجرای یک تسک شبکه اجتماعی (Reserve Slot)
     */
    public function start(): void
    {
        try {
            $adId = (int)($this->request->body()['ad_id'] ?? 0);
            $userId = (int)user_id();

            $result = $this->socialTaskService->startExecution($userId, $adId, [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);

            $this->response->json($result);
        } catch (\Throwable $e) {
            $this->logger->error('adsocial.start.failed', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای غیرمنتظره رخ داد.']);
        }
    }

    /**
     * ثبت نهایی تسک و ارسال برای بررسی اتوماتیک
     */
    public function submit(): void
    {
        try {
            $userId = (int)user_id();
            $executionId = (int)$this->request->param('id');
            $payload = $this->request->body();

            $result = $this->socialTaskService->submitExecution($userId, $executionId, $payload);

            if ($this->request->isAjax()) {
                $this->response->json($result);
                return;
            }

            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
            redirect(url('/adsocial/income'));

        } catch (\Throwable $e) {
            $this->logger->error('adsocial.submit.failed', ['err' => $e->getMessage()]);
            
            if ($this->request->isAjax()) {
                $this->response->json(['success' => false, 'message' => 'خطای غیرمنتظره در سرور']);
                return;
            }
            $this->session->setFlash('error', 'خطای غیرمنتظره رخ داد. لطفاً بعداً تلاش کنید.');
            redirect(url('/adsocial/income'));
        }
    }

    /**
     * تاریخچه کارهای انجام شده توسط کاربر
     */
    public function history(): void
    {
        $userId = (int)user_id();
        $page = max(1, (int)($this->request->get('page') ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $history = $this->socialTaskService->getExecutorHistory($userId, $limit, $offset);

        view('user.adsocial.history', [
            'title' => 'تاریخچه Adsocial',
            'history' => $history,
            'page' => $page
        ]);
    }

    /**
     * لیست آگهی‌های ثبت شده توسط خود کاربر (Advertiser View)
     */
    public function myAds(): void
    {
        $userId = (int)user_id();
        $page = max(1, (int)($this->request->get('page') ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        // واکشی مستقیم از جدول Ads متمرکز
        $ads = $this->adModel->getByAdvertiser($userId, $limit, $offset);

        view('user.adsocial.my-ads', [
            'title' => 'آگهی‌های Adsocial من',
            'ads' => $ads,
            'page' => $page
        ]);
    }

    /**
     * فرم ایجاد آگهی جدید
     */
    public function create(): void
    {
        view('user.adsocial.create', [
            'title' => 'ثبت تبلیغ Adsocial',
            'platforms' => $this->platforms(),
            'taskTypes' => $this->taskTypes()
        ]);
    }

    /**
     * ذخیره تبلیغ جدید (Advertiser) با استفاده از سیستم یکپارچه آداپتور
     */
    public function store(): void
    {
        $body = $this->request->body();
        $userId = (int)user_id();

        // ۱. اعتبارسنجی مقدماتی کنترلر
        $allowedPlatforms = array_keys($this->platforms());
        $allowedTypes = array_keys($this->taskTypes());

        if (empty($body['platform']) || !in_array($body['platform'], $allowedPlatforms)) {
            $this->session->setFlash('error', 'پلتفرم انتخابی نامعتبر است.');
            redirect(url('/adsocial/advertise/create'));
            return;
        }

        if (empty($body['task_type']) || !in_array($body['task_type'], $allowedTypes)) {
            $this->session->setFlash('error', 'نوع تسک انتخابی نامعتبر است.');
            redirect(url('/adsocial/advertise/create'));
            return;
        }

        // ۲. آماده‌سازی اطلاعات و ارسال به مدیریت سیستم متمرکز
        $preparedData = [
            'platform' => $body['platform'],
            'task_type' => $body['task_type'],
            'title' => trim((string)($body['title'] ?? '')),
            'link' => trim((string)($body['target_url'] ?? '')), // نگاشت لینک
            'price_per_task' => (float)($body['price_per_task'] ?? $body['reward'] ?? 0),
            'total_count' => (int)($body['total_count'] ?? $body['max_slots'] ?? 1),
            'description' => trim((string)($body['description'] ?? ''))
        ];

        try {
            // فراخوانی آداپتور تخصصی AdSocialAdapter که پیشتر ایجاد کردیم
            $result = $this->adManager->create('social_task', $userId, $preparedData);

            if ($result['success']) {
                $this->session->setFlash('success', $result['message'] ?? 'تبلیغ با موفقیت ثبت شد.');
                redirect(url('/adsocial/advertise'));
            } else {
                $message = is_array($result['message']) ? implode(' | ', $result['message']) : $result['message'];
                $this->session->setFlash('error', $message);
                redirect(url('/adsocial/advertise/create'));
            }
        } catch (\Throwable $e) {
            $this->logger->error('adsocial.store.exception', ['err' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در ثبت تراکنش تبلیغ.');
            redirect(url('/adsocial/advertise/create'));
        }
    }

    /**
     * لغو کمپین و بازگشت باقیمانده بودجه
     */
    public function cancel(): void
    {
        try {
            $adId = (int)$this->request->param('id');
            $userId = (int)user_id();

            // برای لغو امن و متمرکز از سرویس اصلی استفاده می‌کنیم
            $result = $this->socialTaskService->adminCancelAd($userId, $adId);

            if ($this->request->isAjax()) {
                $this->response->json($result);
                return;
            }

            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
            redirect(url('/adsocial/advertise'));

        } catch (\Throwable $e) {
            $this->response->json(['success' => false, 'message' => 'خطا در لغو تبلیغ']);
        }
    }

    private function platforms(): array 
    { 
        return [
            'instagram' => 'اینستاگرام',
            'telegram' => 'تلگرام',
            'twitter' => 'توییتر / X',
            'tiktok' => 'تیک‌تاک'
        ]; 
    }

    private function taskTypes(): array 
    { 
        return [
            'follow' => 'فالو / سابسکرایب',
            'like' => 'لایک',
            'comment' => 'کامنت',
            'view' => 'بازدید',
            'share' => 'اشتراک‌گذاری',
            'join_channel' => 'عضویت در کانال',
            'join_group' => 'عضویت در گروه'
        ]; 
    }
}
