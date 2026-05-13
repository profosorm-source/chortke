<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\LogService;
use App\Controllers\Admin\BaseAdminController;

/**
 * LogController — فقط نمایش و فیلتر گزارش‌ها
 * 
 * همه منطق در LogService است
 * این کنترلر فقط query param ها را می‌گیرد و نتیجه را نمایش می‌دهد
 */
class LogController extends BaseAdminController
{
    private LogService $logService;

    public function __construct(LogService $logService)
    {
        parent::__construct();
        $this->logService = $logService;
    }

    /**
     * داشبورد لاگ‌ها (نمای کلی)
     */
    public function index(): void
    {
        $type = $this->request->get('type', LogService::TYPE_ACTIVITY);
        
        view('admin/logs/index', [
            'title' => 'مدیریت لاگ‌ها',
            'activeType' => $type,
            'types' => $this->getLogTypes(),
        ]);
    }

    /**
     * لاگ‌های فعالیت کاربران
     */
    public function activity(): void
    {
        $filters = [
            'type' => null, // audit از AuditTrailController می‌آید
            'user_id'   => $this->request->get('user_id') ? (int) $this->request->get('user_id') : null,
            'action'    => $this->request->get('action'),
            'search'    => $this->request->get('search'),
            'date_from' => $this->request->get('date_from'),
            'date_to'   => $this->request->get('date_to'),
        ];

        $page = max(1, (int) $this->request->get('page', 1));
        $perPage = 50;

        $result = $this->logService->query($filters, $page, $perPage);

        view('admin/logs/activity', [
            'title' => 'لاگ‌های فعالیت',
            'logs' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'totalPages' => $result['totalPages'],
            'filters' => $filters,
        ]);
    }

    /**
     * لاگ‌های Audit Trail
     */
    public function audit(): void
    {
        $filters = [
            'type'      => null, // audit از مسیر AuditTrailController مدیریت می‌شود
            'event'     => $this->request->get('event'),
            'user_id'   => $this->request->get('user_id') ? (int) $this->request->get('user_id') : null,
            'search'    => $this->request->get('search'),
            'date_from' => $this->request->get('date_from'),
            'date_to'   => $this->request->get('date_to'),
        ];

        $page = max(1, (int) $this->request->get('page', 1));
        $perPage = 50;

        $result = $this->logService->query($filters, $page, $perPage);

        view('admin/logs/audit', [
            'title' => 'Audit Trail',
            'logs' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'totalPages' => $result['totalPages'],
            'filters' => $filters,
        ]);
    }

    /**
     * لاگ‌های امنیتی
     */
    public function security(): void
    {
        $filters = [
            'type'      => LogService::TYPE_SECURITY,
            'level'     => $this->request->get('level'),
            'user_id'   => $this->request->get('user_id') ? (int) $this->request->get('user_id') : null,
            'search'    => $this->request->get('search'),
            'date_from' => $this->request->get('date_from'),
            'date_to'   => $this->request->get('date_to'),
        ];

        $page = max(1, (int) $this->request->get('page', 1));
        $perPage = 50;

        $result = $this->logService->query($filters, $page, $perPage);

        view('admin/logs/security', [
            'title' => 'لاگ‌های امنیتی',
            'logs' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'totalPages' => $result['totalPages'],
            'filters' => $filters,
        ]);
    }

    /**
     * لاگ‌های سیستمی
     */
    public function system(): void
    {
        $filters = [
            'type'      => LogService::TYPE_SYSTEM,
            'level'     => $this->request->get('level'),
            'search'    => $this->request->get('search'),
            'date_from' => $this->request->get('date_from'),
            'date_to'   => $this->request->get('date_to'),
        ];

        $page = max(1, (int) $this->request->get('page', 1));
        $perPage = 50;

        $result = $this->logService->query($filters, $page, $perPage);

        view('admin/logs/system', [
            'title' => 'لاگ‌های سیستمی',
            'logs' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'totalPages' => $result['totalPages'],
            'filters' => $filters,
        ]);
    }

    /**
     * مشاهده جزئیات یک لاگ
     */
    public function show(): void
    {
        $type = $this->request->get('type', LogService::TYPE_ACTIVITY);
        $id = (int) $this->request->get('id');

        if ($id <= 0) {
            $this->session->setFlash('error', 'شناسه لاگ نامعتبر است.');
            redirect('/admin/logs/' . $type);
            return;
        }

        // TODO: باید متدی برای دریافت یک لاگ خاص در LogService اضافه شود
        
        view('admin/logs/show', [
            'title' => 'جزئیات لاگ',
            'type' => $type,
            'id' => $id,
        ]);
    }

    /**
     * پاک‌سازی لاگ‌های قدیمی
     */
    public function cleanup(): void
    {
        if ($this->request->getMethod() !== 'POST') {
            redirect('/admin/logs');
            return;
        }

        $days = (int) $this->request->post('days', 90);

        if ($days < 30) {
            $this->session->setFlash('error', 'حداقل 30 روز باید باقی بماند.');
            redirect('/admin/logs');
            return;
        }

        $results = $this->logService->cleanup($days);

        $message = sprintf(
    'پاک‌سازی انجام شد: %d فعالیت',
    $results['activity_logs'] ?? 0
);

        $this->session->setFlash('success', $message);
        redirect('/admin/logs');
    }

    /**
     * Export لاگ‌ها
     */
    public function export(): void
    {
        $type = $this->request->get('type', LogService::TYPE_ACTIVITY);
        $format = $this->request->get('format', 'csv'); // csv, json, xlsx

        $filters = [
            'type' => $type,
            'date_from' => $this->request->get('date_from'),
            'date_to' => $this->request->get('date_to'),
        ];

        $result = $this->logService->query($filters, 1, 10000); // Max 10k rows

        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="logs_' . date('Y-m-d') . '.json"');
            echo json_encode($result['rows'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="logs_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
            
            // Headers
            if (!empty($result['rows'])) {
                fputcsv($output, array_keys($result['rows'][0]));
                foreach ($result['rows'] as $row) {
                    fputcsv($output, $row);
                }
            }
            
            fclose($output);
            exit;
        }

        $this->session->setFlash('error', 'فرمت نامعتبر است.');
        redirect('/admin/logs');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * دریافت لیست انواع لاگ
     */
    private function getLogTypes(): array
    {
        $types = [
    LogService::TYPE_SYSTEM => 'لاگ‌های سیستم',
    LogService::TYPE_ACTIVITY => 'لاگ‌های فعالیت',
    LogService::TYPE_SECURITY => 'لاگ‌های امنیتی',
    LogService::TYPE_PERFORMANCE => 'لاگ‌های عملکرد',
];
    }
}

