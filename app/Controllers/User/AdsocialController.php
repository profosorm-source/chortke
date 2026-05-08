<?php
namespace App\Controllers\User;

use App\Services\AdTaskService;
use App\Services\TaskExecutionService;
use App\Models\Advertisement;
use App\Services\AdSystemManager;

class AdsocialController extends BaseUserController
{
    private AdTaskService $adTaskService;
    private TaskExecutionService $execService;
    private Advertisement $adModel;
    private AdSystemManager $adManager;

    public function __construct(AdTaskService $adTaskService, TaskExecutionService $execService, Advertisement $adModel, AdSystemManager $adManager)
    {
        parent::__construct();
        $this->adTaskService = $adTaskService;
        $this->execService   = $execService;
        $this->adModel       = $adModel;
        $this->adManager     = $adManager;
    }

    // کسب درآمد
    public function income(): void
    {
        $userId = (int)user_id();
        $tasks  = $this->adTaskService->getActiveForExecutor($userId, 30);
        $stats  = $this->execService->getUserStats($userId);
        view('user.adsocial.index', ['title'=>'Adsocial — تسک شبکه اجتماعی','tasks'=>$tasks,'stats'=>$stats]);
    }

    public function start(): void
    {
        try {
            $adId = (int)($this->request->body()['ad_id'] ?? 0);
            $this->response->json($this->execService->start($adId, (int)user_id()));
        } catch (\Exception $e) {
            $this->logger->error('adsocial.start.failed', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی. لطفاً دوباره تلاش کنید.']);
        }
    }

    public function showExecute(): void
    {
        $userId = (int)user_id();
        $id = (int)$this->request->param('id');
        $execution = $this->execService->findForUser($id, $userId);
        if (!$execution) { redirect(url('/adsocial')); return; }
        $task = $this->adModel->find((int)$execution->ad_id);
        view('user.adsocial.execute', ['title'=>'انجام تسک','execution'=>$execution,'task'=>$task]);
    }

    public function submit(): void
    {
        try {
            $result = $this->execService->submit((int)$this->request->param('id'), (int)user_id(), $this->request->body());
            if (is_ajax()) { $this->response->json($result); return; }
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Exception $e) {
            $this->logger->error('adsocial.submit.failed', ['err' => $e->getMessage()]);
            if (is_ajax()) { $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']); return; }
            $this->session->setFlash('error', 'خطای سیستمی. لطفاً دوباره تلاش کنید.');
        }
        redirect(url('/adsocial'));
    }

    public function history(): void
    {
        $userId = (int)user_id();
        $page = max(1,(int)($this->request->get('page')??1));
        $history = $this->execService->getUserHistory($userId, 20, ($page-1)*20);
        view('user.adsocial.history', ['title'=>'تاریخچه Adsocial','history'=>$history,'page'=>$page]);
    }

    // تبلیغات
    public function myAds(): void
    {
        $userId = (int)user_id();
        $page = max(1,(int)($this->request->get('page')??1));
        $ads = $this->adModel->getByAdvertiser($userId, 20, ($page-1)*20);
        view('user.adsocial.my-ads', ['title'=>'آگهی‌های Adsocial من','ads'=>$ads,'page'=>$page]);
    }

    public function create(): void
    {
        view('user.adsocial.create', ['title'=>'ثبت تبلیغ Adsocial','platforms'=>$this->platforms(),'taskTypes'=>$this->taskTypes()]);
    }

    public function store(): void
    {
        $body = $this->request->body();

        // اعتبارسنجی
        $allowed_platforms = ['instagram','telegram','youtube','twitter','tiktok'];
        $allowed_types     = ['follow','like','comment','view','share','subscribe','join_channel','join_group','story_view'];

        if (empty($body['platform']) || !in_array($body['platform'], $allowed_platforms)) {
            $this->session->setFlash('error', 'پلتفرم انتخابی معتبر نیست.');
            redirect(url('/adsocial/advertise/create')); return;
        }
        if (empty($body['task_type']) || !in_array($body['task_type'], $allowed_types)) {
            $this->session->setFlash('error', 'نوع تسک معتبر نیست.');
            redirect(url('/adsocial/advertise/create')); return;
        }
        if (empty($body['target_url'])) {
            $this->session->setFlash('error', 'لینک هدف الزامی است.');
            redirect(url('/adsocial/advertise/create')); return;
        }
        if (empty($body['title']) || mb_strlen($body['title']) < 3) {
            $this->session->setFlash('error', 'عنوان حداقل ۳ کاراکتر باید باشد.');
            redirect(url('/adsocial/advertise/create')); return;
        }
        if (empty($body['reward']) || (float)$body['reward'] < 0.01) {
            $this->session->setFlash('error', 'پاداش معتبر نیست.');
            redirect(url('/adsocial/advertise/create')); return;
        }
        if (empty($body['max_slots']) || (int)$body['max_slots'] < 1) {
            $this->session->setFlash('error', 'تعداد کاربر مورد نیاز معتبر نیست.');
            redirect(url('/adsocial/advertise/create')); return;
        }

        $data = array_merge($body, ['platform_type' => 'social']);
        try {
            $result = $this->adTaskService->create((int)user_id(), $data);
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['success'] ? 'تبلیغ ثبت شد.' : ($result['message'] ?? 'خطا'));
        } catch (\Exception $e) {
            $this->logger->error('adsocial.store.failed', ['err' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطای سیستمی در ثبت تبلیغ.');
            redirect(url('/adsocial/advertise/create')); return;
        }
        redirect($result['success'] ? url('/adsocial/advertise') : url('/adsocial/advertise/create'));
    }

    public function show(): void
    {
        $userId = (int)user_id();
        $id = (int)$this->request->param('id');
        $ad = $this->adModel->find($id);
        if (!$ad||(int)$ad->advertiser_id!==$userId) { redirect(url('/adsocial/advertise')); return; }
        $executions = $this->execService->getByAd($id, 20, 0);
        view('user.adsocial.show', ['title'=>'مدیریت آگهی','ad'=>$ad,'executions'=>$executions,'platforms'=>$this->platforms()]);
    }

    public function pause(): void  { $this->toggleStatus('paused'); }
    public function resume(): void { $this->toggleStatus('active'); }
    public function cancel(): void { $this->toggleStatus('cancelled'); }
    private function toggleStatus(string $s): void {
        $r = $this->adTaskService->changeStatus((int)$this->request->param('id'), (int)user_id(), $s);
        if (is_ajax()) { $this->response->json($r); return; }
        $this->session->setFlash($r['success']?'success':'error', $r['message']);
        redirect(url('/adsocial/advertise'));
    }

    public function showReview(): void {
        $exec = $this->execService->findWithAd((int)$this->request->param('id'));
        if (!$exec||(int)$exec->advertiser_id!==(int)user_id()) { redirect(url('/adsocial/advertise')); return; }
        view('user.adsocial.review', ['title'=>'بررسی مدرک','exec'=>$exec]);
    }

    public function approveExecution(): void {
        $r = $this->execService->approveByAdvertiser((int)$this->request->param('id'), (int)user_id());
        if (is_ajax()) { $this->response->json($r); return; }
        $this->session->setFlash($r['success']?'success':'error', $r['message']);
        redirect(url('/adsocial/advertise'));
    }

    public function rejectExecution(): void {
        $reason = trim($this->request->post('reason')??'');
        if (!$reason) { $this->response->json(['success'=>false,'message'=>'دلیل رد الزامی است']); return; }
        $r = $this->execService->rejectByAdvertiser((int)$this->request->param('id'), (int)user_id(), $reason);
        if (is_ajax()) { $this->response->json($r); return; }
        $this->session->setFlash($r['success']?'success':'error', $r['message']);
        redirect(url('/adsocial/advertise'));
    }

    private function platforms(): array { return ['instagram'=>'اینستاگرام','telegram'=>'تلگرام','youtube'=>'یوتیوب','twitter'=>'توییتر/X','tiktok'=>'تیک‌تاک']; }
    private function taskTypes(): array { return ['follow'=>'فالو','like'=>'لایک','comment'=>'کامنت','view'=>'بازدید','share'=>'اشتراک']; }
}
