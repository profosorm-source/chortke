<?php

namespace App\Controllers;

use App\Services\CaptchaService;
use App\Controllers\BaseController;

class TestCaptchaController extends BaseController
{
    private CaptchaService $captchaService;
    
    public function __construct(
        \App\Services\CaptchaService $captchaService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->captchaService = $captchaService;
    }
    
    /**
     * صفحه تست
     */
    public function index()
    {
        return view('test-captcha');
    }
    
    /**
     * بررسی CAPTCHA
     */
    public function verify(): void
{
    if (verify_captcha()) {
        $this->session->setFlash('success', '✅ CAPTCHA به درستی حل شد!');
    } else {
        $this->session->setFlash('error', '❌ CAPTCHA اشتباه است!');
    }

    redirect('test-captcha');
}
}