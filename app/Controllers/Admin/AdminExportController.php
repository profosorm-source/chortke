<?php

namespace App\Controllers\Admin;

use App\Services\ExportService;
use Core\Logger;
use App\Services\AuditTrail;
use App\Controllers\Admin\BaseAdminController;

/**
 * AdminExportController - مرکز خروجی‌گیری ادمین
 */
class AdminExportController extends BaseAdminController
{
    private ExportService $exportService;
    private AuditTrail $auditTrail;
    

   public function __construct(ExportService $exportService, AuditTrail $auditTrail, ?\App\Contracts\LoggerInterface $logger = null)
{
    parent::__construct(null, null, null, null, $logger);
    $this->requirePermission('super_admin');
    $this->exportService = $exportService;
    $this->auditTrail = $auditTrail;
}

    /** صفحه اصلی Export */
    public function index(): void
    {
        view('admin.export.index', ['title' => 'خروجی‌گیری داده']);
    }

    /** خروجی کاربران */
    public function users(): void
{
    $filters = $this->filters();

    $this->auditTrail->record(
    'admin.export',
    null,
    ['type' => 'users', 'filters' => $filters],
    user_id()
);

    $this->exportService->exportUsers($filters);
}

public function transactions(): void
{
    $filters = $this->filters();

    $this->auditTrail->record(
    'admin.export',
    null,
    ['type' => 'transactions', 'filters' => $filters],
    user_id()
);

    $this->exportService->exportTransactionsStream($filters);
}

public function withdrawals(): void
{
    $filters = $this->filters();

    $this->auditTrail->record(
    'admin.export',
    null,
    ['type' => 'withdrawals', 'filters' => $filters],
    user_id()
);

    $this->exportService->exportWithdrawalsStream($filters);
}

public function auditTrail(): void
{
    $filters = $this->filters();

    $this->auditTrail->record(
    'admin.export',
    null,
    ['type' => 'audit_trail', 'filters' => $filters],
    user_id()
);

    $this->exportService->exportAuditTrail($filters);
}

    private function filters(): array
    {
        return array_filter([
            'from' => $this->request->get('from'),
            'to' => $this->request->get('to'),
            'status' => $this->request->get('status'),
            'user_id' => $this->request->get('user_id'),
            'type' => $this->request->get('type'),
        ]);
    }
}
