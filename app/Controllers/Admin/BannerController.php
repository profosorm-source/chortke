<?php

namespace App\Controllers\Admin;

use App\Models\Banner;
use App\Models\BannerPlacement;
use App\Controllers\Admin\BaseAdminController;
use App\Services\UploadService;
use Core\Database;

class BannerController extends BaseAdminController
{
    private Banner $banner;
    private BannerPlacement $placement;
    private UploadService $uploadService;

    public function __construct(Banner $banner, BannerPlacement $placement, UploadService $uploadService)
    {
        parent::__construct();
        $this->banner = $banner;
        $this->placement = $placement;
        $this->uploadService = $uploadService;
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
            'search' => $_GET['search'] ?? null,
        ], fn($v) => $v !== null && $v !== '');

        $banners = $this->banner->all($filters, $perPage, ($page - 1) * $perPage);
        $total = $this->banner->count($filters);
        $placements = $this->placement->all();
        $stats = $this->banner->getStats();

        return view('admin.banners.index', compact('banners', 'placements', 'filters', 'stats', 'total', 'page', 'perPage'));
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
            'created_by' => user_id(),
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
        $this->banner->approve($id, user_id());
        $_SESSION['success'] = 'بنر تایید شد';
        return redirect('/admin/banners');
    }

    public function reject()
    {
        $id = (int)($_POST['id'] ?? 0);
        $reason = $_POST['reason'] ?? 'رد شد';
        $this->banner->reject($id, $reason);
        $_SESSION['success'] = 'بنر رد شد';
        return redirect('/admin/banners');
    }

    public function delete()
    {
        $id = (int)($_POST['id'] ?? 0);
        $this->banner->softDelete($id);
        $_SESSION['success'] = 'بنر حذف شد';
        return redirect('/admin/banners');
    }

    public function stats()
    {
        $stats = $this->banner->getStats();
        $placements = $this->placement->allWithBannerCount();
        return view('admin.banners.stats', compact('stats', 'placements'));
    }
}
