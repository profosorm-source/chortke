<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Services\SocialTask\SocialTaskService;
use App\Models\SocialTaskModel;
use App\Models\Ads;
use App\Services\AdSystemManager;

class AdtubeController extends BaseUserController
{
    private SocialTaskService $socialTaskService;
    private SocialTaskModel $socialTaskModel;
    private Ads $adModel;
    private AdSystemManager $adManager;
    public function __construct(
        SocialTaskService $socialTaskService,
        SocialTaskModel $socialTaskModel,
        Ads $adModel,
        AdSystemManager $adManager
    , ?\App\Contracts\LoggerInterface $logger = null) {        $this->socialTaskService = $socialTaskService;
        $this->socialTaskModel = $socialTaskModel;
        $this->adModel = $adModel;
        $this->adManager = $adManager;

        parent::__construct(null, null, null, null, $logger);
    }

    /**
     * داشبورد انجام‌دهنده تبلیغات ویدیویی یوتیوب (Executor/Worker)
     */
    public function index(): void
    {
        $userId = (int)user_id();
        
        // فراخوانی سرویس متمرکز سوشیال با فیلتر انحصاری یوتیوب
        $result = $this->socialTaskService->getTasksForExecutor($userId, [
            'platform' => 'youtube'
        ], 20);

        $tasks = $result['tasks'] ?? [];
        $stats = $this->socialTaskService->getExecutorStats($userId);

        view('user.adtube.index', [
            'title' => 'AdTube — کسب درآمد از یوتیوب',
            'tasks' => $tasks,
            'stats' => $stats,
            'trust_score' => $result['trust_score'] ?? 50
        ]);
    }

    public function income(): void
    {
        $this->index();
    }

    /**
     * شروع فرآیند اجرای یک تسک ویدیویی
     */
    public function start(): void
    {
        try {
            $body = $this->request->body();
            $adId = (int)($body['ad_id'] ?? $this->request->param('id'));
            $userId = (int)user_id();

            if (!$adId) {
                 $this->response->json(['success' => false, 'message' => 'شناسه ویدیو نامعتبر است.']);
                 return;
            }

            $context = [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ];

            // استفاده از خط لوله اجرایی ایمن SocialTaskService
            $result = $this->socialTaskService->startExecution($userId, $adId, $context);
            $this->response->json($result);

        } catch (\Exception $e) {
            $this->logger->error('adtube.start.failed', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطا در شروع تماشای ویدیو.']);
        }
    }

    /**
     * صفحه پخش ویدیو و ثبت اثبات نهایی توسط کاربر
     */
    public function showExecute(): void
    {
        $userId = (int)user_id();
        $execId = (int)$this->request->param('id');

        // دریافت اطلاعات اجرای تسک به همراه جزئیات آگهی (از طریق مدل پیوندی)
        $execution = $this->socialTaskModel->getExecutionWithAd($execId, $userId);

        if (!$execution) {
            $this->session->setFlash('error', 'اجرای مورد نظر یافت نشد.');
            redirect(url('/adtube'));
            return;
        }

        // این آبجکت مستقیماً حاوی ad_title و reward است
        view('user.adtube.execute', [
            'title' => 'تماشای ویدیو و کسب درآمد',
            'execution' => $execution,
            'task' => $execution // پاس دادن آبجکت جهت سازگاری با View
        ]);
    }

    /**
     * ثبت و ارسال نهایی نتایج اجرا (Submission)
     */
    public function submit(): void
    {
        try {
            $execId = (int)$this->request->param('id');
            $userId = (int)user_id();
            $body = $this->request->body();

            // هدایت مستقیم اطلاعات به لایه ضدتقلب و پردازش SocialTask
            $result = $this->socialTaskService->submitExecution($execId, $userId, $body);

            if (is_ajax()) {
                $this->response->json($result);
                return;
            }

            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
            redirect(url('/adtube'));

        } catch (\Exception $e) {
            $this->logger->error('adtube.submit.failed', ['err' => $e->getMessage()]);
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => 'خطای سیستمی در ثبت نهایی.']);
                return;
            }
            $this->session->setFlash('error', 'خطای غیرمنتظره رخ داد.');
            redirect(url('/adtube'));
        }
    }

    /**
     * تاریخچه فعالیت کاربر در شبکه ویدیویی
     */
    public function history(): void
    {
        $userId = (int)user_id();
        $page = max(1, (int)($this->request->get('page') ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $history = $this->socialTaskService->getExecutorHistory($userId, $limit, $offset);

        view('user.adtube.history', [
            'title' => 'تاریخچه کسب درآمد AdTube',
            'history' => $history,
            'page' => $page
        ]);
    }

    /**
     * پنل سفارش‌دهنده تبلیغات (Advertiser panel)
     */
    public function advertise(): void
    {
        $userId = (int)user_id();
        $page = max(1, (int)($this->request->get('page') ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        // بازخوانی آگهی‌های ثبت شده توسط کاربر از نوع 'adtube'
        $ads = $this->adModel->getByAdvertiser($userId, $limit, $offset, 'adtube');

        view('user.adtube.my-ads', [
            'title' => 'تبلیغات ویدیویی ثبت شده',
            'ads' => $ads,
            'page' => $page
        ]);
    }

    public function myAds(): void
    {
        $this->advertise();
    }

    /**
     * ایجاد آگهی ویدیویی جدید
     */
    public function create(): void
    {
        view('user.adtube.create', [
            'title' => 'ثبت آگهی جدید یوتیوب'
        ]);
    }

    /**
     * ذخیره و فعالسازی تراکنش مالی آگهی
     */
    public function store(): void
    {
        try {
            $userId = (int)user_id();
            $data = $this->request->body();

            // تعیین هویت نوع تبلیغ برای مسیریابی در AdSystemManager
            $data['platform'] = 'youtube';
            $data['task_type'] = 'view';

            // ایجاد از طریق درگاه امن و متمرکز
            $result = $this->adManager->create('adtube', $userId, $data);

            if ($result['success']) {
                $this->session->setFlash('success', 'آگهی ویدیویی با موفقیت در سیستم ثبت و بودجه آن تخصیص یافت.');
                redirect(url('/adtube/ads'));
            } else {
                $msg = $result['message'] ?? 'خطا در پردازش اطلاعات.';
                if (!empty($result['errors']) && is_array($result['errors'])) {
                     $msg .= " (" . implode("، ", $result['errors']) . ")";
                }
                $this->session->setFlash('error', $msg);
                redirect(url('/adtube/ads/create'));
            }

        } catch (\Exception $e) {
            $this->logger->error('adtube.store.failed', ['err' => $e->getMessage()]);
            $this->session->setFlash('error', 'متاسفانه در تراکنش بانکی سامانه خطایی رخ داد.');
            redirect(url('/adtube/ads/create'));
        }
    }

    /**
     * نمایش آمار و جزئیات آگهی برای سفارش‌دهنده
     */
    public function showAd(): void
    {
        $userId = (int)user_id();
        $adId = (int)$this->request->param('id');

        $ad = $this->adModel->find($adId);

        if (!$ad || (int)$ad->user_id !== $userId) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز یا آگهی نامعتبر.');
            redirect(url('/adtube/ads'));
            return;
        }

        // بازیابی تحلیل‌های حرفه‌ای از SocialTask Engine
        $stats = $this->socialTaskService->getAdvertiserAdStats($userId, $adId);

        view('user.adtube.show-ad', [
            'title' => 'آمار نهایی تبلیغ یوتیوب',
            'ad' => $ad,
            'stats' => $stats
        ]);
    }

    /**
     * تغییر وضعیت سریع آگهی (توقف موقت / فعال‌سازی)
     */
    public function pause(): void
    {
        $this->changeAdStatus('paused');
    }

    public function resume(): void
    {
        $this->changeAdStatus('active');
    }

    private function changeAdStatus(string $status): void
    {
        try {
            $adId = (int)$this->request->param('id');
            $userId = (int)user_id();

            $ad = $this->adModel->find($adId);
            if (!$ad || (int)$ad->user_id !== $userId) {
                 throw new \Exception('دسترسی غیرمجاز');
            }

            $success = $this->adModel->update($adId, [
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $resp = [
                'success' => $success,
                'message' => $success ? 'تغییر وضعیت با موفقیت انجام شد.' : 'خطا در بروزرسانی آگهی.'
            ];

            if (is_ajax()) {
                $this->response->json($resp);
                return;
            }

            $this->session->setFlash($success ? 'success' : 'error', $resp['message']);
            redirect(url('/adtube/ads'));

        } catch (\Exception $e) {
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => $e->getMessage()]);
                return;
            }
            $this->session->setFlash('error', 'درخواست ناموفق بود.');
            redirect(url('/adtube/ads'));
        }
    }
}
