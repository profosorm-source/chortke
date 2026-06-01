<?php
// app/Controllers/User/SocialAccountController.php

namespace App\Controllers\User;

use App\Services\SocialAccountService;
use App\Services\UploadService;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;

class SocialAccountController extends BaseUserController
{
    private \App\Services\SocialAccountService $socialAccountService;
    private \App\Services\SocialAccountService $service;

    public function __construct(
        \App\Services\SocialAccountService $socialAccountService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->socialAccountService = $socialAccountService;
        $this->service = $socialAccountService;
    }

    /**
     * لیست حساب‌های کاربر
     */
    public function index()
    {
        $userId = user_id();
        $accounts = $this->service->getByUser($userId);

        $platforms = [
            'instagram' => 'اینستاگرام',
            'youtube'   => 'یوتیوب',
            'telegram'  => 'تلگرام',
            'tiktok'    => 'تیک‌تاک',
            'twitter'   => 'توییتر (X)',
        ];

        return view('user.social-accounts.index', [
            'accounts'  => $accounts,
            'platforms' => $platforms,
        ]);
    }

    /**
     * فرم ثبت حساب جدید
     */
    public function showCreate()
    {
        $platforms = [
            'instagram' => 'اینستاگرام',
            'youtube'   => 'یوتیوب',
            'telegram'  => 'تلگرام',
            'tiktok'    => 'تیک‌تاک',
            'twitter'   => 'توییتر (X)',
        ];

        return view('user.social-accounts.create', [
            'platforms' => $platforms,
        ]);
    }

    /**
     * ثبت حساب جدید — POST
     */
    public function store()
    {
                
        $data = $this->request->body();
        $validator = \Core\Validator::create($data, [
            'provider' => 'required|in:instagram,telegram,youtube,twitter',
            'provider_user_id' => 'required|string|min:3|max:100',
            'access_token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $this->session->setFlash('error', $errors[array_key_first($errors)][0] ?? 'اطلاعات ورودی نامعتبر است.');
            return redirect(url('/social-accounts/create'));
        }

        $result = $this->service->register(user_id(), $validator->data());
        ApiRateLimiter::enforce('social_account_add', (int)user_id(), true);

        if ($result['success']) {
            $this->session->setFlash('success', $result['message']);
            return redirect(url('/social-accounts'));
        }

        $this->session->setFlash('error', $result['message']);
        return redirect(url('/social-accounts/create'));
    }

    /**
     * فرم ویرایش
     */
    public function showEdit()
    {
                $id = (int) $this->request->param('id');

        $account = $this->service->find($id);
        if (!$account || $account->user_id !== user_id()) {
            $this->session->setFlash('error', 'حساب یافت نشد.');
            return redirect(url('/social-accounts'));
        }

        if ($account->status === 'verified') {
            $this->session->setFlash('error', 'حساب تایید‌شده قابل ویرایش نیست.');
            return redirect(url('/social-accounts'));
        }

        return view('user.social-accounts.edit', [
            'account' => $account,
        ]);
    }

    /**
     * بروزرسانی — POST
     */
    public function update()
    {
        $id = (int) $this->request->param('id');
        $data = $this->request->body();

        $validator = \Core\Validator::create($data, [
            'provider' => 'required|in:instagram,telegram,youtube,twitter',
            'provider_user_id' => 'required|string|min:3|max:100',
            'access_token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $this->session->setFlash('error', $errors[array_key_first($errors)][0] ?? 'اطلاعات ورودی نامعتبر است.');
            return redirect(url('/social-accounts/' . $id . '/edit'));
        }

        $result = $this->service->updateByUser($id, user_id(), $validator->data());

        if ($result['success']) {
            $this->session->setFlash('success', $result['message']);
            return redirect(url('/social-accounts'));
        }

        $this->session->setFlash('error', $result['message']);
        return redirect(url('/social-accounts/' . $id . '/edit'));
    }

    /**
     * حذف — Ajax
     */
    public function delete()
    {
                        $id = (int) $this->request->param('id');

        $result = $this->service->delete($id, user_id());

        return $this->response->json($result);
    }
}