<?php

namespace App\Controllers\User;

use App\Services\SocialTask\SocialTaskService;
use App\Validators\Requests\CreateSocialTaskRequest;
use App\Validators\Requests\ExecuteSocialTaskRequest;
use App\Validators\Requests\RateSocialTaskRequest;
use App\Controllers\User\BaseUserController;

class SocialTaskController extends BaseUserController
{
    private SocialTaskService $socialTaskService;

    public function __construct(SocialTaskService $socialTaskService)
    {
        parent::__construct();
        $this->socialTaskService = $socialTaskService;
    }

    public function create()
    {
        $request = new CreateSocialTaskRequest($this->request->all());

        if (!$request->validate()) {
            return $this->json(['success' => false, 'errors' => $request->errors()], 422);
        }

        $result = $this->socialTaskService->createTask($this->userId(), $request->validated());

        return $this->json($result, $result['success'] ? 200 : 422);
    }

    public function execute()
    {
        $request = new ExecuteSocialTaskRequest($this->request->all());

        if (!$request->validate()) {
            return $this->json(['success' => false, 'errors' => $request->errors()], 422);
        }

        $result = $this->socialTaskService->executeTask($this->userId(), $request->validated());

        return $this->json($result, $result['success'] ? 200 : 422);
    }

    public function rate()
    {
        $request = new RateSocialTaskRequest($this->request->all());

        if (!$request->validate()) {
            return $this->json(['success' => false, 'errors' => $request->errors()], 422);
        }

        $result = $this->socialTaskService->rateExecution($this->userId(), $request->validated());

        return $this->json($result, $result['success'] ? 200 : 422);
    }

    // سایر متدها (index, myTasks, etc.) در صورت نیاز در دور بعدی بهبود می‌یابند
}
