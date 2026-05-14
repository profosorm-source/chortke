<?php

namespace App\Controllers\Admin;

use App\Models\BannerPlacement;
use App\Controllers\Admin\BaseAdminController;

class PlacementController extends BaseAdminController
{
    private BannerPlacement $placement;

    public function __construct(BannerPlacement $placement)
    {
        parent::__construct();
        $this->placement = $placement;
    }

    public function index()
    {
        $placements = $this->placement->allWithBannerCount();
        return view('admin.banners.placements', compact('placements'));
    }

    public function toggle()
    {
        $id = (int)($_POST['id'] ?? 0);
        $placement = $this->placement->find($id);

        if ($placement) {
            $this->placement->update($id, ['is_active' => !$placement->is_active]);
            $_SESSION['success'] = 'وضعیت تغییر کرد';
        }

        return redirect('/admin/banners/placements');
    }

    public function update()
    {
        $id = (int)($_POST['id'] ?? 0);

        $data = [
            'max_banners' => (int)($_POST['max_banners'] ?? 5),
            'rotation_speed' => (int)($_POST['rotation_speed'] ?? 5000),
            'show_on_mobile' => (int)($_POST['show_on_mobile'] ?? 1),
            'show_on_desktop' => (int)($_POST['show_on_desktop'] ?? 1),
            'display_style' => $_POST['display_style'] ?? 'carousel',
            'auto_rotate' => (int)($_POST['auto_rotate'] ?? 1),
        ];

        $this->placement->update($id, $data);
        $_SESSION['success'] = 'تنظیمات بروزرسانی شد';
        return redirect('/admin/banners/placements');
    }
}
