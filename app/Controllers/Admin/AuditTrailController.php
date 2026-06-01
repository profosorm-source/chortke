<?php

namespace App\Controllers\Admin;

use Core\Logger;
use App\Services\AuditTrail;
use App\Services\ExportService;
use App\Services\Search\SearchOrchestrator;
use App\Models\AuditEvent;
use App\Controllers\Admin\BaseAdminController;

/**
 * AuditTrailController - مدیریت Audit Trail
 */
class AuditTrailController extends BaseAdminController
{
    private Logger $logger;
    private ExportService $exportService;
    private AuditTrail $auditTrail;
    private AuditEvent $auditEventModel;
    private SearchOrchestrator $searchService;

    public function __construct(
        ExportService $exportService,
        Logger $logger,
        AuditTrail $auditTrail,
        AuditEvent $auditEventModel,
        SearchOrchestrator $searchService
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->exportService = $exportService;
        $this->logger = $logger;
        $this->auditTrail = $auditTrail;
        $this->auditEventModel = $auditEventModel;
        $this->searchService = $searchService;
    }

    /**
     * لیست رویدادها
     */
    public function index()
    {
        try {
            $page = max(1, (int)($this->request->get('page') ?? 1));
            $event = $this->request->get('event');
            $userId = $this->request->get('user_id') ? (int)$this->request->get('user_id') : null;
            $search = trim($this->request->get('search') ?? '');
            $dateFrom = $this->request->get('date_from');
            $dateTo = $this->request->get('date_to');
            $perPage = min(max(1, (int)($this->request->get('per_page') ?? 50)), 100);
            $offset = ($page - 1) * $perPage;

            $filters = [];
            if (!empty($event)) {
                $filters['action'] = $event;
            }
            if (!empty($dateFrom)) {
                $filters['date_from'] = $dateFrom;
            }
            if (!empty($dateTo)) {
                $filters['date_to'] = $dateTo;
            }

            // استفاده یکپارچه از سرویس AuditTrail برای بارگذاری و جستجوی دقیق
            $result = $this->auditTrail->getAll(
                page: $page,
                perPage: $perPage,
                event: $event ?: null,
                userId: $userId,
                search: !empty($search) ? $search : null,
                dateFrom: $dateFrom ?: null,
                dateTo: $dateTo ?: null
            );
            
            // Unify response compatibility from Model result wrapper
            $events = $result['rows'] ?? [];
            $total = $result['total'] ?? 0;

            $eventTypes = $this->auditTrail->getEventTypes();

            return view('admin.audit-trail.index', [
                'user' => auth()->user(),
                'title' => 'Audit Trail',
                'events' => $events,
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => ceil($total / $perPage),
                'eventTypes' => $eventTypes,
                'search' => $search,
                'filters' => [
                    'event' => $event,
                    'user_id' => $userId,
                    'search' => $search,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ],
            ]);

        } catch (\Exception $e) {
    $this->logger->error('audit_trail.index.failed', [
        'channel' => 'admin_audit',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return view('errors.500');
}
    }

    /**
     * مشاهده جزئیات رویداد
     */
    public function show()
    {
        try {
            $id = (int)$this->request->param('id');
            
            $event = $this->auditEventModel->findById($id);
            
            if (!$event) {
                return view('errors.404');
            }

            return view('admin.audit-trail.show', [
                'user' => auth()->user(),
                'title' => 'جزئیات رویداد',
                'event' => $event,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('audit_trail.show.failed', [
                'channel' => 'admin_audit',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'id' => $id ?? null,
            ]);
            return view('errors.500');
        }
    }

    /**
     * آمار
     */
    public function stats()
    {
        try {
            $dateFrom = $this->request->get('date_from') ?: date('Y-m-d', strtotime('-30 days'));
            $dateTo = $this->request->get('date_to') ?: date('Y-m-d');

            $stats = $this->auditTrail->getStats($dateFrom, $dateTo);
            $eventTypes = $this->auditTrail->getEventTypes();

            return view('admin.audit-trail.stats', [
                'user' => auth()->user(),
                'title' => 'آمار Audit Trail',
                'stats' => $stats,
                'eventTypes' => $eventTypes,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('audit_trail.stats.failed', [
                'error' => $e->getMessage()
            ]);
            return view('errors.500');
        }
    }

    /**
     * تاریخچه کاربر
     */
    public function userHistory()
    {
        try {
            $userId = (int)$this->request->param('user_id');
            // 🚀 BUG FIX [L-04]: Cap limit to prevent memory exhaustion
            $limit = min(max(1, (int)($this->request->get('limit') ?? 100)), 500);

            $history = $this->auditTrail->getForUser($userId, $limit);

            return view('admin.audit-trail.user-history', [
                'user' => auth()->user(),
                'title' => 'تاریخچه کاربر',
                'history' => $history,
                'userId' => $userId,
            ]);

        } catch (\Exception $e) {
    $this->logger->error('audit_trail.user_history.failed', [
        'channel' => 'admin_audit',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'user_id' => $userId ?? null,
    ]);
    return view('errors.500');
}
    }

    /**
     * Export
     */
    public function export()
{
    try {
        $filters = array_filter([
            'event' => $this->request->get('event'),
            'from' => $this->request->get('from'),
            'to' => $this->request->get('to'),
            'user_id' => $this->request->get('user_id'),
            // 🚀 BUG FIX [H-07]: Max rows for export to prevent DoS
            'limit' => 5000, 
        ]);

        $this->exportService->exportAuditTrail($filters);

        $this->auditTrail->record(
    'admin.export',
    null,
    ['type' => 'audit_trail', 'filters' => $filters],
    user_id()
);

    } catch (\Exception $e) {
    $this->logger->error('audit_trail.export.failed', [
        'channel' => 'admin_audit',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);


        $this->session->setFlash('error', 'خطا در ایجاد خروجی');
        return redirect('/admin/audit-trail');
    }
}
}
