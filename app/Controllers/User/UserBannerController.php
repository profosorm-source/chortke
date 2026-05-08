<?php
namespace App\Controllers\User;
use App\Models\UserBannerRequest;
use App\Services\WalletService;
use App\Services\UploadService;

class UserBannerController extends BaseUserController
{
    private WalletService $wallet;
    private UploadService $upload;
    private UserBannerRequest $bannerRequestModel;

    public function __construct(WalletService $w, UploadService $u, UserBannerRequest $bannerRequestModel) { parent::__construct(); $this->wallet=$w; $this->upload=$u; $this->bannerRequestModel = $bannerRequestModel; }

    public function index(): void {
        $userId = (int)user_id();
        $banners = \App\Models\UserBannerRequest::getByUser($userId);
        view('user.user-banners.index', ['title'=>'بنرهای تبلیغاتی من','banners'=>$banners,'price'=>(float)feature_config('banner_pricing', 'price_per_day', 0)]);
    }

    public function create(): void {
        $placements = \App\Models\BannerPlacement::getActive();
        view('user.user-banners.create', ['title'=>'ثبت درخواست بنر','placements'=>$placements,'pricePerDay'=>(float)feature_config('banner_pricing', 'price_per_day', 0)]);
    }

    public function store(): void {
        $userId = (int)user_id();
        $data = $this->request->body();
        $days = max(1,(int)($data['days']??1));
        $pricePerDay = (float)feature_config('banner_pricing', 'price_per_day', 0);
        $total = $pricePerDay * $days;

        if (!empty($_FILES['image']['name'])) {
            $up = $this->upload->uploadFile($_FILES['image'], 'user-banner');
            if ($up['success']) $data['image_path'] = $up['path'];
        }

        if ($total>0) {
            $debit = $this->wallet->debit($userId,$total,'irt','user_banner',"درخواست بنر {$days} روزه");
            if (!$debit['success']) { $this->session->setFlash('error',$debit['message']??'موجودی کافی نیست.'); redirect(url('/my-banners/create')); return; }
        }

        // ذخیره درخواست
        $saved = $this->bannerRequestModel->create(
            array_merge($data, [
                'user_id' => $userId,
                'days' => $days,
                'total_price' => $total,
                'status' => 'pending',
            ])
        );
        if ($saved) { $this->session->setFlash('success','درخواست بنر ثبت شد.'); redirect(url('/my-banners')); }
        else { if ($total>0) $this->wallet->credit($userId,$total,'irt','banner_refund','برگشت هزینه بنر'); $this->session->setFlash('error','خطا در ثبت.'); redirect(url('/my-banners/create')); }
    }

    public function show(): void {
        $id = (int)$this->request->param('id');
        $banner = $this->bannerRequestModel->find($id);
        if (!$banner||(int)$banner->user_id!==(int)user_id()) { redirect(url('/my-banners')); return; }
        view('user.user-banners.show', ['title'=>'جزئیات بنر','banner'=>$banner]);
    }

    public function cancel(): void {
        $id = (int)$this->request->param('id');
        $banner = $this->bannerRequestModel->find($id);
        if (!$banner||(int)$banner->user_id!==(int)user_id()||$banner->status!=='pending') { $this->response->json(['success'=>false,'message'=>'عملیات غیرمجاز.']); return; }
        $ok = $this->bannerRequestModel->updateStatus($id,'cancelled');
        if ($ok && $banner->total_price>0) $this->wallet->credit((int)user_id(),(float)$banner->total_price,'irt','banner_refund',"لغو بنر #{$id}");
        $this->response->json(['success'=>$ok]);
    }
}
