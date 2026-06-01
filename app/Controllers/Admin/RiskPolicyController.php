<?php

namespace App\Controllers\Admin;

use App\Controllers\Admin\BaseAdminController;
use App\Services\AntiFraud\RiskPolicyService;

class RiskPolicyController extends BaseAdminController
{
    private RiskPolicyService $policyService;

    public function __construct(RiskPolicyService $policyService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->policyService = $policyService;
    }

    public function index(): void
    {
        $this->view('admin.risk-policies.index', [
            'policies' => $this->policyService->getPoliciesWithDefaults(),
        ]);
    }

    public function update(): void
    {
        if (!$this->request->isPost()) {
            redirect('/admin/risk-policies');
        }

        $domain = trim((string)$this->request->post('domain', ''));
        $keyName = trim((string)$this->request->post('key_name', ''));
        $value = $this->request->post('value', '');
        $valueType = trim((string)$this->request->post('value_type', 'string'));
        $description = trim((string)$this->request->post('description', ''));

        if ($domain === '' || $keyName === '') {
            redirect('/admin/risk-policies')
                ->with('error', 'دامنه و کلید الزامی است.');
        }

        $adminId = $this->session->get('user_id');
        $ok = $this->policyService->set(
            $domain,
            $keyName,
            $value,
            $valueType,
            $adminId ? (int)$adminId : null,
            $description
        );

        if ($ok) {
            redirect('/admin/risk-policies')
                ->with('success', 'تنظیمات با موفقیت ذخیره شد.');
        }

        redirect('/admin/risk-policies')
            ->with('error', 'خطا در ذخیره تنظیمات.');
    }
}