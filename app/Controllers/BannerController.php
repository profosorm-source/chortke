<?php

namespace App\Controllers;

use App\Services\BannerService;
use App\Controllers\BaseController;

class BannerController extends BaseController
{
    private \App\Services\BannerService $bannerService;
    public function __construct(
        \App\Services\BannerService $bannerService
    )
    {
        parent::__construct();
        $this->bannerService = $bannerService;
    }

    /**
     * ثبت کلیک بنر (برای همه کاربران - حتی مهمان)
     */
    public function click()
    {
                        $id = (int)$this->request->param('id');

        $service = $this->bannerService;
        $result = $service->trackClick($id);

        if ($result['success'] && !empty($result['redirect'])) {
            return redirect($result['redirect']);
        }

        return redirect(url('/'));
    }
}