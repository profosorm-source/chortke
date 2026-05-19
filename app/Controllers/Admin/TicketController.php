<?php

namespace App\Controllers\Admin;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketCategory;
use App\Services\TicketService;
use App\Services\AdvancedSearchService;
use App\Controllers\Admin\BaseAdminController;

class TicketController extends BaseAdminController
{
    private Ticket $ticketModel;
    private TicketMessage $messageModel;
    private TicketCategory $categoryModel;
    private TicketService $ticketService;
    private AdvancedSearchService $searchService;
    
    public function __construct(
        \App\Models\Ticket $ticketModel,
        \App\Models\TicketMessage $messageModel,
        \App\Models\TicketCategory $categoryModel,
        \App\Services\TicketService $ticketService,
        AdvancedSearchService $searchService)
    {
        parent::__construct();
        $this->ticketModel = $ticketModel;
        $this->messageModel = $messageModel;
        $this->categoryModel = $categoryModel;
        $this->ticketService = $ticketService;
        $this->searchService = $searchService;
    }
    
    /**
     * لیست تیکت‌ها
     */
    public function index()
    {
        $filters = [
            'status' => $this->request->get('status', ''),
            'priority' => $this->request->get('priority', ''),
            'category_id' => $this->request->get('category_id', ''),
            'assigned_to' => $this->request->get('assigned_to', '')
        ];
        
        $search = trim($this->request->get('search') ?? '');
        $page = (int) $this->request->get('page', 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        
        // استفاده از TicketService برای دریافت تیکت‌ها
        if (!empty($search)) {
            $result = $this->searchService->searchTickets($search, $filters, $perPage, $offset);
            $tickets = $result['items'] ?? [];
            $total = $result['total'] ?? 0;
        } else {
            $result = $this->ticketService->listForAdmin($filters, $page, $perPage);
            $tickets = $result['tickets'] ?? [];
            $total = $result['total'] ?? 0;
        }

        $totalPages = ceil($total / $perPage);
        
        // آمار از Service
        $stats = $this->ticketService->getStats();
        
        // دسته‌بندی‌ها از Service
        $categories = $this->ticketService->getCategories();
        
        return view('admin/tickets/index', [
            'tickets' => $tickets,
            'stats' => $stats,
            'categories' => $categories,
            'filters' => $filters,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total
        ]);
    }
    
    /**
     * نمایش تیکت
     */
    public function show(int $id)
    {
        $ticket = $this->ticketService->getById($id);
        
        if (!$ticket) {
            $this->session->setFlash('error', 'تیکت یافت نشد.');
            return redirect('/admin/tickets');
        }
        
        $messages = $this->ticketService->getMessages($id);
        
        // علامت‌گذاری به عنوان خوانده شده
        $this->ticketService->markAsRead($id, true);
        
        return view('admin/tickets/show', [
            'ticket' => $ticket,
            'messages' => $messages
        ]);
    }
    
    public function reply()
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $data = $this->request->json();
        $ticketId = (int) ($data['ticket_id'] ?? 0);
        $message = trim($data['message'] ?? '');

        if (!$ticketId || empty($message)) {
            return $this->response->json(['success' => false, 'message' => 'ارسال پیام الزامی است.']);
        }

        // 🛡️ NEW-13: جلوگیری از حملات Stored XSS و کنترل طول داده ورودی
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        if (mb_strlen($message) > 5000) {
            return $this->response->json([
                'success' => false, 
                'message' => 'پیام نباید بیشتر از ۵۰۰۰ کاراکتر باشد'
            ], 422);
        }

        $ticket = $this->ticketService->getById($ticketId);
        if (!$ticket) {
            return $this->response->json([
                'success' => false, 
                'message' => 'تیکت یافت نشد.'
            ], 404);
        }
        
        $result = $this->ticketService->reply(
            $ticketId,
            user_id(),
            $message,
            true // isAdmin
        );

        if ($result['success'] ?? false) {
            $this->logger->activity('ticket_admin_reply', 
                "ادمین پاسخ داد به تیکت #{$ticketId}", 
                user_id(), 
                ['ticket_id' => $ticketId, 'message_length' => mb_strlen($message)]
            );
            
            // 🛡️ NEW-12: ثبت کامل ردپای حسابرسی ادمین
            $this->auditLog('ticket_admin_reply', 'ticket', $ticketId, null, [
                'message' => $message
            ]);
        }
        
        return $this->response->json($result);
    }
    
    public function changeStatus()
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $data = $this->request->json();
        $ticketId = (int) ($data['id'] ?? 0);
        $status = $data['status'] ?? '';
        
        if (!$ticketId || !$status) {
            return $this->response->json(['success' => false, 'message' => 'داده‌های ناقص.']);
        }

        // H-02: Status Whitelist Validation
        $allowedStatuses = ['open', 'answered', 'in_progress', 'on_hold', 'closed'];
        if (!in_array($status, $allowedStatuses, true)) {
            return $this->response->json(['success' => false, 'message' => 'وضعیت نامعتبر است.']);
        }

        $ticket = $this->ticketService->getById($ticketId);
        if (!$ticket) {
            return $this->response->json(['success' => false, 'message' => 'تیکت یافت نشد.']);
        }
        
        $oldStatus = $ticket->status;
        
        if ($this->ticketService->updateStatus($ticketId, $status, user_id())) {
            $this->logger->activity('ticket_status_changed', "وضعیت تیکت #{$ticketId} به {$status} تغییر کرد", user_id(), []);
            
            // 🛡️ NEW-12: ثبت کامل ردپای حسابرسی ادمین
            $this->auditLog('ticket_status_changed', 'ticket', $ticketId, 
                ['status' => $oldStatus],
                ['status' => $status]
            );
            
            return $this->response->json([
                'success' => true,
                'message' => 'وضعیت تیکت تغییر کرد.'
            ]);
        }
        
        return $this->response->json(['success' => false, 'message' => 'خطا در تغییر وضعیت.']);
    }
    
    public function assign()
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $data = $this->request->json();
        $ticketId = (int) ($data['ticket_id'] ?? 0);
        $adminId = (int) ($data['admin_id'] ?? 0);
        
        if (!$ticketId) {
            return $this->response->json(['success' => false, 'message' => 'داده‌های ناقص.']);
        }

        $ticket = $this->ticketService->getById($ticketId);
        if (!$ticket) {
            return $this->response->json(['success' => false, 'message' => 'تیکت یافت نشد.']);
        }

        $oldAdminId = (int)($ticket->assigned_to ?? 0);

        if ($adminId > 0) {
            if (!$this->policyService->isAdminById($adminId)) {
                return $this->response->json(['success' => false, 'message' => 'شناسه مدیر نامعتبر است.']);
            }
        } else {
            // صریحاً unassign را مدیریت کن
            $adminId = 0;
        }
        
        if ($this->ticketService->assignTo($ticketId, $adminId)) {
            $this->logger->activity('ticket_assigned', "تیکت #{$ticketId} به مدیر {$adminId} تخصیص داده شد", user_id(), []);
            
            // 🛡️ NEW-12: ثبت کامل ردپای حسابرسی ادمین
            $this->auditLog('ticket_assigned', 'ticket', $ticketId, 
                ['assigned_to' => $oldAdminId],
                ['assigned_to' => $adminId]
            );

            return $this->response->json([
                'success' => true,
                'message' => 'تیکت تخصیص داده شد.'
            ]);
        }
        
        return $this->response->json(['success' => false, 'message' => 'خطا در تخصیص.']);
    }

    /**
     * 🛡️ NEW-12: ثبت ردپای حسابرسی تغییرات و عملیات حساس ادمین‌ها در دیتابیس
     */
    private function auditLog(string $action, string $entityType, int $entityId, ?array $oldValues, ?array $newValues): void
    {
        try {
            db()->query(
                "INSERT INTO admin_audit_log (admin_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, session_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    user_id(),
                    $action,
                    $entityType,
                    $entityId,
                    $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                    $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                    $this->request->ip(),
                    $this->request->userAgent() ?: 'unknown',
                    session_id() ?: ''
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('admin.audit_log.failed', ['error' => $e->getMessage()]);
        }
    }
}