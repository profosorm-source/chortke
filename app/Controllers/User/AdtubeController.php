<?php
namespace App\Controllers\User;
use App\Services\AdTaskService;
use App\Services\TaskExecutionService;
use App\Models\Advertisement;
use App\Services\AdSystemManager;

class AdtubeController extends BaseUserController
{
    private AdTaskService $adTaskService;
    private TaskExecutionService $execService;
    private Advertisement $adModel;
    private AdSystemManager $adManager;

    public function __construct(AdTaskService $ads, TaskExecutionService $exec, Advertisement $ad, AdSystemManager $adManager)
    {
        parent::__construct();
        $this->adTaskService = $ads;
        $this->execService   = $exec;
        $this->adModel       = $ad;
        $this->adManager     = $adManager;
    }

    public function index(): void {
        $userId = (int)user_id();
        $tasks = $this->adTaskService->getActiveForExecutor($userId, 20);
        $stats = $this->execService->getUserStats($userId);
        view('user.adtube.index', ['title'=>'Adtube — کسب درآمد از یوتیوب','tasks'=>$tasks,'stats'=>$stats]);
    }

    public function income(): void { $this->index(); }

    public function start(): void {
        try {
            $this->response->json($this->execService->start((int)($this->request->body()['ad_id'] ?? 0), (int)user_id()));
        } catch (\Exception $e) {
            $this->logger->error('adtube.start.failed', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
        }
    }

    public function showExecute(): void {
        $userId = (int)user_id();
        $id = (int)$this->request->param('id');
        $execution = $this->execService->findForUser($id, $userId);
        if (!$execution) { redirect(url('/adtube')); return; }
        $task = $this->adModel->find((int)$execution->ad_id);
        view('user.adtube.execute', ['title'=>'تماشای ویدیو','execution'=>$execution,'task'=>$task]);
    }

    public function submit(): void {
        try {
            $r = $this->execService->submit((int)$this->request->param('id'), (int)user_id(), $this->request->body());
            if (is_ajax()) { $this->response->json($r); return; }
            $this->session->setFlash($r['success'] ? 'success' : 'error', $r['message']);
        } catch (\Exception $e) {
            $this->logger->error('adtube.submit.failed', ['err' => $e->getMessage()]);
            if (is_ajax()) { $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']); return; }
            $this->session->setFlash('error', 'خطای سیستمی.');
        }
        redirect(url('/adtube'));
    }

    public function history(): void {
        $page = max(1,(int)($this->request->get('page')??1));
        $history = $this->execService->getUserHistory((int)user_id(), 20, ($page-1)*20);
        view('user.adtube.history', ['title'=>'تاریخچه Adtube','history'=>$history,'page'=>$page]);
    }

    public function advertise(): void {
        $page = max(1,(int)($this->request->get('page')??1));
        $ads = $this->adModel->getByAdvertiser((int)user_id(), 20, ($page-1)*20);
        view('user.adtube.my-ads', ['title'=>'تبلیغات Adtube من','ads'=>$ads,'page'=>$page]);
    }

    public function myAds(): void { $this->advertise(); }

    public function create(): void {
        view('user.adtube.create', ['title'=>'ثبت تبلیغ ویدیوی یوتیوب']);
    }

    public function store(): void {
        try {
            $data = array_merge($this->request->body(), ['platform' => 'youtube', 'task_type' => 'view']);
            $r = $this->adTaskService->create((int)user_id(), $data);
            $this->session->setFlash($r['success'] ? 'success' : 'error', $r['success'] ? 'تبلیغ یوتیوب ثبت شد.' : ($r['message'] ?? 'خطا'));
            redirect($r['success'] ? url('/adtube/advertise') : url('/adtube/advertise/create'));
        } catch (\Exception $e) {
            $this->logger->error('adtube.store.failed', ['err' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطای سیستمی در ثبت تبلیغ.');
            redirect(url('/adtube/advertise/create'));
        }
    }

    public function showAd(): void {
        $userId = (int)user_id();
        $id = (int)$this->request->param('id');
        $ad = $this->adModel->find($id);
        if (!$ad||(int)$ad->advertiser_id!==$userId) { redirect(url('/adtube/advertise')); return; }
        view('user.adtube.show-ad', ['title'=>'جزئیات آگهی یوتیوب','ad'=>$ad,'stats'=>$this->execService->getAdStats($id)]);
    }

    public function pause(): void  { $this->changeStatus('paused'); }
    public function resume(): void { $this->changeStatus('active'); }
    private function changeStatus(string $s): void {
        $r = $this->adTaskService->changeStatus((int)$this->request->param('id'), (int)user_id(), $s);
        if (is_ajax()) { $this->response->json($r); return; }
        redirect(url('/adtube/advertise'));
    }
}
