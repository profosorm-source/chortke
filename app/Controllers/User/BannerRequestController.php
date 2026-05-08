<?php

namespace App\Controllers\User;

use App\Models\Banner;
use App\Models\BannerPlacement;
use App\Controllers\User\BaseUserController;
use App\Services\UploadService;

class BannerRequestController extends BaseUserController
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
        $userId = user_id();
        $banners = $this->banner->all(['user_id' => $userId], 50, 0);
        return view('user.banner-request', compact('banners'));
    }

    public function create()
    {
        $placements = $this->placement->all(['is_active' => 1]);
        return view('user.banner-create', compact('placements'));
    }

    public function store()
    {
        $userId = user_id();
        $title = $_POST['title'] ?? '';
        $placement = $_POST['placement'] ?? '';
        $bannerType = $_POST['banner_type'] ?? 'user';
        $category = $_POST['category'] ?? null;
        $durationDays = (int)($_POST['duration_days'] ?? 7);

        if (empty($title) || empty($placement)) {
            $_SESSION['error'] = 'عنوان و جایگاه الزامی است';
            return redirect('/banner-request/create');
        }

        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $result = $this->uploadService->upload($_FILES['image'], 'banner-requests', ['jpg', 'png', 'webp', 'gif'], 2 * 1024 * 1024);
            if (!$result['success']) {
                $_SESSION['error'] = 'خرابی در آپلود: ' . $result['message'];
                return redirect('/banner-request/create');
            }
            $imagePath = $result['path'];
        } else {
            $_SESSION['error'] = 'تصویر الزامی است';
            return redirect('/banner-request/create');
        }

        $pricePerDay = ($bannerType === 'startup' && $category === 'startup') ? 500 : 2000;
        $totalPrice = $pricePerDay * $durationDays;

        if ($bannerType === 'startup' && $durationDays === 7) {
            $totalPrice = 0;
        }

        $data = [
            'title' => $title,
            'image_path' => $imagePath,
            'link' => $_POST['link'] ?? null,
            'placement' => $placement,
            'banner_type' => $bannerType,
            'category' => $category,
            'user_id' => $userId,
            'duration_days' => $durationDays,
            'price' => $totalPrice,
            'is_active' => 0,
            'alt_text' => $_POST['alt_text'] ?? null,
        ];

        $this->banner->create($data);

        $_SESSION['success'] = 'درخواست ثبت شد و در انتظار تایید است';
        return redirect('/banner-request');
    }

    private function uploadImage($file): ?string
    {
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $uploadDir = dirname(__DIR__, 3) . '/public/uploads/banners/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return null;
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            return null;
        }

        $filename = uniqid('user_banner_') . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            return '/uploads/banners/' . $filename;
        }

        return null;
    }
}
