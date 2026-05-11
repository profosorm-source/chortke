<?php
// app/Controllers/User/SocialAccountController.php

namespace App\Controllers\User;

use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use App\Services\UploadService;
use Core\Validator;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;

class SocialAccountController extends BaseUserController
{
    private \App\Services\SocialAccountService $socialAccountService;
    private \App\Services\SocialAccountService $service;

    public function __construct(
        \App\Services\SocialAccountService $socialAccountService
    ) {
        parent::__construct();
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

        $validator = new Validator($data, [
            'platform'            => 'required|in:instagram,youtube,telegram,tiktok,twitter',
            'username'            => 'required|string|min:2|max:255',
            'profile_url'         => 'required|string|max:500',
            'follower_count'      => 'required|numeric|min:0',
            'following_count'     => 'numeric|min:0',
            'post_count'          => 'required|numeric|min:0',
            'account_age_months'  => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0] ?? 'اطلاعات ورودی نامعتبر است.');
            return redirect(url('/social-accounts/create'));
        }

        $result = $this->service->register(user_id(), $data);
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

        $validator = new Validator($data, [
            'username'            => 'required|string|min:2|max:255',
            'profile_url'         => 'required|string|max:500',
            'follower_count'      => 'required|numeric|min:0',
            'following_count'     => 'numeric|min:0',
            'post_count'          => 'required|numeric|min:0',
            'account_age_months'  => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0] ?? 'اطلاعات نامعتبر.');
            return redirect(url('/social-accounts/' . $id . '/edit'));
        }

        $result = $this->service->updateByUser($id, user_id(), $data);

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