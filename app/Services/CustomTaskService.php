<?php

declare(strict_types=1);

namespace App\Services;

use App\Validators\Requests\CreateCustomTaskRequest;
use App\Validators\Requests\SubmitCustomTaskProofRequest;
use App\Validators\Requests\RateCustomTaskRequest;
use App\Exceptions\BusinessException;

/**
 * CustomTaskService - Section 8.6 Refactor (فاز ۲)
 *
 * تغییرات:
 * - استفاده از Request classes برای Input Validation
 * - اضافه شدن Guard methods برای Business Validation
 * - validator قدیمی CustomTaskValidator deprecated شد
 */
class CustomTaskService extends BaseService
{
    // Constructor و properties قبلی بدون تغییر

    /**
     * Business Guard برای ایجاد تسک سفارشی
     */
    public function guardCanCreateTask(int $creatorId, array $data): void
    {
        $request = new CreateCustomTaskRequest($data);
        if (!$request->validate()) {
            throw new BusinessException('اطلاعات تسک نامعتبر است: ' . json_encode($request->errors()));
        }

        $validated = $request->validated();

        if ($creatorId <= 0) {
            throw new BusinessException('کاربر سازنده نامعتبر است');
        }

        // چک بودجه و سایر قوانین بیزینسی
        $totalCost = (float)$validated['price_per_task'] * (int)$validated['total_quantity'];
        $userBalance = $this->walletService->getBalance($creatorId, $validated['currency']);

        if ($userBalance < $totalCost) {
            throw new BusinessException('موجودی کافی برای ایجاد این تسک نیست');
        }

        $this->logger->info('custom_task.guard.create.passed', [
            'creator_id' => $creatorId,
            'title' => $validated['title']
        ]);
    }

    /**
     * Business Guard برای ارسال مدرک
     */
    public function guardCanSubmitProof(int $workerId, array $data): void
    {
        $request = new SubmitCustomTaskProofRequest($data);
        if (!$request->validate()) {
            throw new BusinessException('مدرک ارسال شده نامعتبر است');
        }

        // قوانین بیزینسی اضافی (deadline, duplicate proof, rate limit و ...)
        $this->logger->info('custom_task.guard.submit_proof.passed', [
            'worker_id' => $workerId,
            'submission_id' => $data['task_execution_id'] ?? 0
        ]);
    }

    /**
     * Business Guard برای ثبت امتیاز
     */
    public function guardCanRateSubmission(int $raterId, array $data): void
    {
        $request = new RateCustomTaskRequest($data);
        if (!$request->validate()) {
            throw new BusinessException('اطلاعات امتیازدهی نامعتبر است');
        }

        $this->logger->info('custom_task.guard.rate.passed', [
            'rater_id' => $raterId,
            'execution_id' => $data['execution_id'] ?? 0,
            'rating' => $data['rating'] ?? 0
        ]);
    }

    // بقیه متدهای سرویس (createTask, submitProof, rateSubmission, reviewSubmission, ...)
    // حالا باید از Guardهای بالا استفاده کنند (در cleanup بعدی تکمیل می‌شود)

    // ... (بقیه کد فایل بدون تغییر اساسی)
}
