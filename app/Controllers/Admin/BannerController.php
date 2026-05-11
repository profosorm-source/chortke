<?php

namespace App\Controllers\Admin;

use App\Models\Ads;
use App\Models\BannerPlacement;
use App\Controllers\Admin\BaseAdminController;
use App\Services\UploadService;
use App\Services\AdvancedSearchService;
use Core\Database;

class BannerController extends BaseAdminController
{
    private Ads $banner;
    private BannerPlacement $placement;
    private UploadService $uploadService;
    private AdvancedSearchService $searchService;

    public function __construct(Ads $banner, BannerPlacement $placement, UploadService $uploadService, AdvancedSearchService $searchService)
    {
        parent::__construct();
        $this->banner = $banner;
        $this->placement = $placement;
        $this->uploadService = $uploadService;
        $this->searchService = $searchService;
    }

    public function index()
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;

        $filters = array_filter([
            'placement' => $_GET['placement'] ?? null,
            'banner_type' => $_GET['banner_type'] ?? null,
            'category' => $_GET['category'] ?? null,
            'is_active' => $_GET['is_active'] ?? null,
            'status' => $_GET['status'] ?? null,
        ], fn($v) => $v !== null && $v !== '');

        $search = trim($_GET['search'] ?? '');
        $offset = ($page - 1) * $perPage;

        // استفاده از AdvancedSearchService برای جستجو
        if (!empty($search)) {
            $result = $this->searchService->searchBanners($search, $filters, $perPage, $offset);
            $banners = $result['items'] ?? [];
            $total = $result['total'] ?? 0;
        } else {
            // اگر جستجو نباشد، از QueryBuilder استفاده شود
            $q = $this->banner->db->table('ads')
                ->where('type', '=', 'banner')
                ->whereNull('deleted_at');

            if (!empty($filters['placement'])) $q->where('placement', '=', $filters['placement']);
            if (!empty($filters['category'])) $q->where('category', '=', $filters['category']);
            if (isset($filters['is_active'])) $q->where('is_active', '=', (int)$filters['is_active']);
            if (!empty($filters['status'])) $q->where('status', '=', $filters['status']);

            $total = $q->count();
            $banners = $q->orderBy('sort_order', 'ASC')
                         ->orderBy('created_at', 'DESC')
                         ->limit($perPage)
                         ->offset($offset)
                         ->get();
        }
                     
        $placements = $this->placement->all();
        
        // آمار یکدست بنرها
        $stats = [
            'total' => $this->banner->db->table('ads')->where('type', '=', 'banner')->whereNull('deleted_at')->count(),
            'active' => $this->banner->db->table('ads')->where('type', '=', 'banner')->where('status', '=', 'active')->whereNull('deleted_at')->count(),
        ];

        return view('admin.banners.index', compact('banners', 'placements', 'filters', 'stats', 'total', 'page', 'perPage', 'search'));
    }

    public function create()
    {
        $placements = $this->placement->all();
        return view('admin.banners.create', compact('placements'));
    }

    public function store()
    {
        $title = $_POST['title'] ?? '';
        $placement = $_POST['placement'] ?? '';

        if (empty($title) || empty($placement)) {
            $_SESSION['error'] = 'عنوان و جایگاه الزامی است';
            return redirect('/admin/banners/create');
        }

        // استفاده از UploadService (Sprint 6)
        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $result = $this->uploadService->upload($_FILES['image'], 'banners', ['jpg', 'png', 'webp', 'gif'], 5 * 1024 * 1024);
            if ($result['success']) {
                $imagePath = $result['path'];
            } else {
                $_SESSION['error'] = 'خرابی در آپلود تصویر: ' . $result['message'];
                return redirect('/admin/banners/create');
            }
        }

        $data = [
            'type' => 'banner', // اجبار نوع متمرکز
            'title' => $title,
            'image_path' => $imagePath,
            'link' => $_POST['link'] ?? null,
            'placement' => $placement,
            'banner_type' => $_POST['banner_type'] ?? 'system',
            'category' => $_POST['category'] ?? null,
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'is_active' => (int)($_POST['is_active'] ?? 1),
            'start_date' => $_POST['start_date'] ?? null,
            'end_date' => $_POST['end_date'] ?? null,
            'target' => $_POST['target'] ?? '_blank',
            'alt_text' => $_POST['alt_text'] ?? null,
            'user_id' => user_id(), // نگاشت یکدست به user_id
            'status' => 'active'
        ];

        $id = $this->banner->create($data);

        $_SESSION['success'] = 'بنر ایجاد شد';
        return redirect('/admin/banners');
    }

    public function edit()
    {
        $id = (int)($_GET['id'] ?? 0);
        $banner = $this->banner->find($id);

        if (!$banner) {
            $_SESSION['error'] = 'بنر یافت نشد';
            return redirect('/admin/banners');
        }

        $placements = $this->placement->all();
        return view('admin.banners.edit', compact('banner', 'placements'));
    }

    public function update()
    {
        $id = (int)($_POST['id'] ?? 0);

        // استفاده از UploadService (Sprint 6)
        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $result = $this->uploadService->upload($_FILES['image'], 'banners', ['jpg', 'png', 'webp', 'gif'], 5 * 1024 * 1024);
            if ($result['success']) {
                $imagePath = $result['path'];
            } else {
                $_SESSION['error'] = 'خرابی در آپلود تصویر: ' . $result['message'];
                return redirect('/admin/banners/edit?id=' . $id);
            }
        }

        $data = [
            'title' => $_POST['title'] ?? '',
            'link' => $_POST['link'] ?? null,
            'placement' => $_POST['placement'] ?? '',
            'category' => $_POST['category'] ?? null,
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'is_active' => (int)($_POST['is_active'] ?? 1),
            'start_date' => $_POST['start_date'] ?? null,
            'end_date' => $_POST['end_date'] ?? null,
            'target' => $_POST['target'] ?? '_blank',
            'alt_text' => $_POST['alt_text'] ?? null,
        ];

        if ($imagePath) {
            $data['image_path'] = $imagePath;
        }

        $this->banner->update($id, $data);

        $_SESSION['success'] = 'بنر بروزرسانی شد';
        return redirect('/admin/banners');
    }

    public function approve()
    {
        $id = (int)($_POST['id'] ?? 0);
        // آپدیت مستقیم و صریح به کمک متدهای پیش‌فرض Core
        $this->banner->update($id, [
            'status' => 'active',
            'approved_at' => date('Y-m-d H:i:s'),
            'is_active' => 1
        ]);
        $_SESSION['success'] = 'بنر تایید شد';
        return redirect('/admin/banners');
    }

    public function reject()
    {
        $id = (int)($_POST['id'] ?? 0);
        $reason = $_POST['reason'] ?? 'رد شد';
        $this->banner->update($id, [
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'is_active' => 0
        ]);
        $_SESSION['success'] = 'بنر رد شد';
        return redirect('/admin/banners');
    }

    public function delete()
    {
        $id = (int)($_POST['id'] ?? 0);
        // استفاده از مکانیزم قدرتمند داخلی softDelete کلاس Core\Model
        $this->banner->delete($id);
        $_SESSION['success'] = 'بنر حذف شد';
        return redirect('/admin/banners');
    }

    public function stats()
    {
        $stats = [
            'total' => $this->banner->db->table('ads')->where('type', '=', 'banner')->whereNull('deleted_at')->count(),
            'active' => $this->banner->db->table('ads')->where('type', '=', 'banner')->where('status', '=', 'active')->whereNull('deleted_at')->count(),
        ];
        $placements = $this->placement->allWithBannerCount();
        return view('admin.banners.stats', compact('stats', 'placements'));
    }
}
