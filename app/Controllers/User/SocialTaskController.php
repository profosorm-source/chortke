<?php

namespace App\Controllers\User;

use App\Services\SocialTask\SocialTaskService;
use App\Validators\Requests\CreateSocialTaskRequest;
use App\Validators\Requests\ExecuteSocialTaskRequest;
use App\Controllers\User\BaseUserController;

class SocialTaskController extends BaseUserController
{
    private SocialTaskService $socialTaskService;

    public function __construct(SocialTaskService $socialTaskService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
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
        $input = $this->request->all();

        $validator = \Core\Validator::create($input, [
            'execution_id' => 'required|integer|min:1',
            'rating'       => 'required|integer|min:1|max:5',
            'review_text'  => 'nullable|string|min:10|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $result = $this->socialTaskService->rateExecution($this->userId(), $validator->data());

        return $this->json($result, $result['success'] ? 200 : 422);
    }

    // سایر متدها (index, myTasks, etc.) در صورت نیاز در دور بعدی بهبود می‌یابند
}
