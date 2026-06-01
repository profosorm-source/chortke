<?php

namespace App\Controllers\Admin;

use App\Services\BannerService;
use App\Controllers\Admin\BaseAdminController;
use App\Services\UploadService;
use App\Services\Search\SearchOrchestrator;

class BannerController extends BaseAdminController
{
    private BannerService $bannerService;
    private UploadService $uploadService;
    private SearchOrchestrator $searchService;
    private \App\Models\Ads $banner;
    private \App\Models\BannerPlacement $placement;

    public function __construct(
        BannerService $bannerService, 
        UploadService $uploadService, 
        SearchOrchestrator $searchService,
        \App\Models\Ads $banner,
        \App\Models\BannerPlacement $placement
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->bannerService = $bannerService;
        $this->uploadService = $uploadService;
        $this->searchService = $searchService;
        $this->banner = $banner;
        $this->placement = $placement;
    }

    public function index()
    {
        $page = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;

        $filters = array_filter([
            'placement' => $this->request->get('placement'),
            'banner_type' => $this->request->get('banner_type'),
            'category' => $this->request->get('category'),
            'is_active' => $this->request->get('is_active'),
            'status' => $this->request->get('status'),
        ], fn($v) => $v !== null && $v !== '');

        $search = trim($this->request->get('search', ''));
        $offset = ($page - 1) * $perPage;

        // Use service for search
        $result = $this->searchService->searchBanners($search, $filters, $perPage, $offset);
        $banners = $result['items'] ?? [];
        $total = $result['total'] ?? 0;
                     
        // Use service to get placements
        $placements = $this->bannerService->getAllPlacements();
        
        // Get stats from service
        $stats = $this->bannerService->getStats();

        return view('admin.banners.index', compact('banners', 'placements', 'filters', 'stats', 'total', 'page', 'perPage', 'search'));
    }

    public function create()
    {
        $placements = $this->placement->all();
        return view('admin.banners.create', compact('placements'));
    }

    public function store()
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $input = $this->request->all();
        $request = new \App\Validators\Requests\CreateBannerRequest($input);

        if (!$request->validate()) {
            $errors = $request->errors();
            $firstError = reset($errors);
            $msg = is_array($firstError) ? reset($firstError) : $firstError;
            $this->session->setFlash('error', $msg ?: 'اطلاعات ورودی نامعتبر است.');
            return redirect('/admin/banners/create');
        }

        $validatedData = $request->validated();
        $title = trim($validatedData['title']);
        $placement = trim($validatedData['placement']);

        // استفاده از UploadService (Sprint 6)
        $imagePath = null;
        if ($this->request->hasFile('image')) {
            $file = $this->request->file('image');
            $result = $this->uploadService->upload($file, 'banners', ['jpg', 'png', 'webp', 'gif'], 5 * 1024 * 1024);
            if ($result['success']) {
                $imagePath = $result['path'];
            } else {
                $this->session->setFlash('error', 'خرابی در آپلود تصویر: ' . $result['message']);
                return redirect('/admin/banners/create');
            }
        }

        $link = $validatedData['link'] ?? '';

        $data = [
            'type' => 'banner', // اجبار نوع متمرکز
            'title' => $title,
            'image_path' => $imagePath,
            'link' => $link,
            'placement' => $placement,
            'banner_type' => $this->request->input('banner_type', 'system'),
            'category' => $this->request->input('category'),
            'sort_order' => (int)$this->request->input('sort_order', 0),
            'is_active' => (int)$this->request->input('is_active', 1),
            'start_date' => $this->request->input('start_date'),
            'end_date' => $this->request->input('end_date'),
            'target' => $this->request->input('target', '_blank'),
            'alt_text' => $this->request->input('alt_text'),
            'user_id' => user_id(), // نگاشت یکدست به user_id
            'status' => 'active'
        ];

        $id = $this->banner->create($data);

        $this->session->setFlash('success', 'بنر ایجاد شد');
        return redirect('/admin/banners');
    }

    public function edit()
    {
        $id = (int)$this->request->get('id', 0);
        $banner = $this->banner->find($id);

        if (!$banner) {
            $this->session->setFlash('error', 'بنر یافت نشد');
            return redirect('/admin/banners');
        }

        $placements = $this->placement->all();
        return view('admin.banners.edit', compact('banner', 'placements'));
    }

    public function update()
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $id = (int)$this->request->input('id', 0);
        $banner = $this->banner->find($id);

        if (!$banner || $banner->type !== 'banner') {
            $this->session->setFlash('error', 'بنر یافت نشد');
            return redirect('/admin/banners');
        }

        $input = $this->request->all();
        $request = new \App\Validators\Requests\CreateBannerRequest($input);

        if (!$request->validate()) {
            $errors = $request->errors();
            $firstError = reset($errors);
            $msg = is_array($firstError) ? reset($firstError) : $firstError;
            $this->session->setFlash('error', $msg ?: 'اطلاعات ورودی نامعتبر است.');
            return redirect('/admin/banners/edit?id=' . $id);
        }

        $validatedData = $request->validated();
        $link = $validatedData['link'] ?? '';

        // استفاده از UploadService (Sprint 6)
        $imagePath = null;
        if ($this->request->hasFile('image')) {
            $file = $this->request->file('image');
            $result = $this->uploadService->upload($file, 'banners', ['jpg', 'png', 'webp', 'gif'], 5 * 1024 * 1024);
            if ($result['success']) {
                $imagePath = $result['path'];
            } else {
                $this->session->setFlash('error', 'خرابی در آپلود تصویر: ' . $result['message']);
                return redirect('/admin/banners/edit?id=' . $id);
            }
        }

        $data = [
            'title' => trim($validatedData['title']),
            'link' => $link,
            'placement' => trim($validatedData['placement']),
            'category' => $this->request->input('category'),
            'sort_order' => (int)$this->request->input('sort_order', 0),
            'is_active' => (int)$this->request->input('is_active', 1),
            'start_date' => $this->request->input('start_date'),
            'end_date' => $this->request->input('end_date'),
            'target' => $this->request->input('target', '_blank'),
            'alt_text' => $this->request->input('alt_text'),
        ];

        if ($imagePath) {
            $data['image_path'] = $imagePath;
        }

        $this->banner->update($id, $data);

        $this->session->setFlash('success', 'بنر بروزرسانی شد');
        return redirect('/admin/banners');
    }

    public function approve()
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $id = (int)$this->request->input('id', 0);
        $banner = $this->banner->find($id);

        if (!$banner || $banner->type !== 'banner') {
            $this->session->setFlash('error', 'بنر یافت نشد');
            return redirect('/admin/banners');
        }

        // آپدیت مستقیم و صریح به کمک متدهای پیش‌فرض Core
        $this->banner->update($id, [
            'status' => 'active',
            'approved_at' => date('Y-m-d H:i:s'),
            'is_active' => 1
        ]);
        $this->session->setFlash('success', 'بنر تایید شد');
        return redirect('/admin/banners');
    }

    public function reject()
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $id = (int)$this->request->input('id', 0);
        $banner = $this->banner->find($id);

        if (!$banner || $banner->type !== 'banner') {
            $this->session->setFlash('error', 'بنر یافت نشد');
            return redirect('/admin/banners');
        }

        $reason = $this->request->input('reason', 'رد شد');
        $this->banner->update($id, [
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'is_active' => 0
        ]);
        $this->session->setFlash('success', 'بنر رد شد');
        return redirect('/admin/banners');
    }

    public function delete()
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $id = (int)$this->request->input('id', 0);
        $banner = $this->banner->find($id);

        if (!$banner || $banner->type !== 'banner') {
            $this->session->setFlash('error', 'بنر یافت نشد');
            return redirect('/admin/banners');
        }

        // استفاده از مکانیزم قدرتمند داخلی softDelete کلاس Core\Model
        $this->banner->delete($id);
        $this->session->setFlash('success', 'بنر حذف شد');
        return redirect('/admin/banners');
    }

    public function stats()
    {
        // استفاده از BannerService برای دریافت آمار بنرها
        $stats = $this->bannerService->getStats();
        $placements = $this->placement->allWithBannerCount();
        return view('admin.banners.stats', compact('stats', 'placements'));
    }
}
