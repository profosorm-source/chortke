<?php

namespace App\Controllers\User;

use App\Models\KYCVerification;
use App\Services\KYCService;
use App\Services\UploadService;
use Core\Validator;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;

class KYCController extends BaseUserController
{
    private KYCService      $kycService;
    private KYCVerification $kycModel;
    private UploadService   $uploadService;

    public function __construct(
        KYCVerification $kycModel,
        KYCService      $kycService,
        UploadService   $uploadService
    ) {
        parent::__construct();
        $this->kycModel   = $kycModel;
        $this->kycService = $kycService;
        $this->uploadService = $uploadService;
    }

    /**
     * صفحه اصلی احراز هویت (داشبورد + وضعیت)
     */
    public function index()
    {
        $userId = $this->userId();
        $kyc    = $this->kycModel->findByUserId($userId);

        return view('user/kyc/index', [
            'title'      => 'احراز هویت',
            'kyc'        => $kyc,
            'canSubmit'  => $this->kycService->canSubmitKYC($userId),
            'appName'    => config('app.name', 'سایت'),
            'todayJalali'=> to_jalali(date('Y-m-d')),
        ]);
    }

    /**
     * صفحه آپلود مدارک
     */
    public function upload()
    {
        $userId    = $this->userId();
        $canSubmit = $this->kycService->canSubmitKYC($userId);

        return view('user/kyc/upload', [
            'title'      => 'آپلود مدارک احراز هویت',
            'canSubmit'  => $canSubmit['can'],
            'error'      => $canSubmit['reason'] ?? null,
            'appName'    => config('app.name', 'سایت'),
            'todayJalali'=> to_jalali(date('Y-m-d')),
        ]);
    }

    /**
     * ثبت درخواست احراز هویت
     */
    public function submit()
    {
        $userId = $this->userId();

        ApiRateLimiter::enforce('kyc_submit', $userId, is_ajax());

        $data = $this->request->all();

        $validator = new Validator($data, [
            'national_code' => 'required|digits:10',
            'birth_date'    => 'required',
        ]);

        if ($validator->fails()) {
            session()->setFlash('errors', $validator->errors());
            return redirect('/kyc/upload');
        }

        if (
            empty($_FILES['verification_image']) ||
            $_FILES['verification_image']['error'] !== UPLOAD_ERR_OK
        ) {
            session()->setFlash('errors', [
                'verification_image' => ['تصویر احراز هویت الزامی است'],
            ]);
            return redirect('/kyc/upload');
        }

        // استفاده از UploadService (Sprint 6)
        $uploadResult = $this->uploadService->upload(
            $_FILES['verification_image'],
            'kyc-verification',
            ['jpg', 'png', 'jpeg'],
            5 * 1024 * 1024
        );

        if (!$uploadResult['success']) {
            session()->setFlash('errors', [
                'verification_image' => [$uploadResult['message']],
            ]);
            return redirect('/kyc/upload');
        }

        $result = $this->kycService->submitKYC(
            $userId,
            [
                'national_code' => trim($data['national_code']),
                'birth_date'    => $data['birth_date'],
            ],
            $uploadResult['path']  // اب UploadService سے secured path
        );

        if ($result['success']) {
            $userName = user()->full_name ?? 'کاربر';

            notify_admins(
                'kyc_submitted',
                'درخواست احراز هویت جدید',
                'کاربر ' . $userName . ' درخواست احراز هویت ثبت کرده است',
                url('/admin/kyc/review/' . $result['kyc_id']),
                ['user_id' => $userId, 'kyc_id' => $result['kyc_id']]
            );

            session()->setFlash('success', $result['message']);
            return redirect('/kyc');
        }

        session()->setFlash('error', $result['message']);
        return redirect('/kyc/upload');
    }

    /**
     * وضعیت KYC (AJAX یا redirect به index)
     */
    public function status()
    {
        $userId = $this->userId();
        $kyc    = $this->kycModel->findByUserId($userId);

        if (!$kyc) {
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => 'درخواستی یافت نشد'], 404);
                return;
            }
            return redirect(url('/kyc'));
        }

        $statusLabels = [
            'pending'      => 'در انتظار بررسی',
            'under_review' => 'در حال بررسی',
            'verified'     => 'تأیید شده',
            'rejected'     => 'رد شده',
            'expired'      => 'منقضی شده',
        ];

        if (is_ajax()) {
            $this->response->json([
                'success' => true,
                'kyc'     => [
                    'status'           => $kyc->status,
                    'status_label'     => $statusLabels[$kyc->status] ?? $kyc->status,
                    'submitted_at'     => $kyc->submitted_at,
                    'verified_at'      => $kyc->verified_at,
                    'rejection_reason' => $kyc->rejection_reason,
                ],
            ]);
            return;
        }

        // درخواست معمولی → به index برگردان (اطلاعات در index نمایش داده می‌شود)
        return redirect(url('/kyc'));
    }
}
