<?php

namespace App\Controllers\Admin;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketCategory;
use App\Services\TicketService;
use App\Services\Search\SearchOrchestrator;
use App\Controllers\Admin\BaseAdminController;

class TicketController extends BaseAdminController
{
    private Ticket $ticketModel;
    private TicketMessage $messageModel;
    private TicketCategory $categoryModel;
    private TicketService $ticketService;
    private SearchOrchestrator $searchService;
    
    public function __construct(
        \App\Models\Ticket $ticketModel,
        \App\Models\TicketMessage $messageModel,
        \App\Models\TicketCategory $categoryModel,
        \App\Services\TicketService $ticketService,
        SearchOrchestrator $searchService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
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

        // 🛡️ HIGH-01: جلوگیری از دسترسی ادمین‌های عادی به تیکت‌های غیرمنتسب به خودشان یا فاقد انتساب (Fail-Closed)
        $adminId = user_id();
        $isAssignedToMe = ($ticket->assigned_to !== null && (int)$ticket->assigned_to === $adminId);
        if (!$isAssignedToMe) {
            try {
                $hasPermission = (bool) $this->policyService->authorizeById('tickets.view_all', $adminId);
            } catch (\Throwable $e) {
                $hasPermission = false; // Fail-Closed
            }
            if (!$hasPermission) {
                $this->logger->warning('unauthorized_ticket_access_attempt', [
                    'admin_id' => $adminId,
                    'ticket_id' => $id,
                    'ip' => $this->request->ip()
                ]);
                $this->session->setFlash('error', 'شما دسترسی مشاهده این تیکت را ندارید.');
                return redirect('/admin/tickets');
            }
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
        
        $rawMessage = trim($data['message'] ?? '');
        if (!$ticketId || empty($rawMessage)) {
            return $this->response->json(['success' => false, 'message' => 'ارسال پیام الزامی است.']);
        }

        // 🛡️ CRITICAL-05: کنترل طول داده ورودی بر روی داده خام قبل از ضدعفونی
        if (mb_strlen($rawMessage) > 5000) {
            return $this->response->json([
                'success' => false, 
                'message' => 'پیام نباید بیشتر از ۵۰۰۰ کاراکتر باشد'
            ], 422);
        }

        $adminId = user_id();
        
        // 🛡️ HIGH-02: Rate limiting سبک برای ادمین (مثلاً 100 پاسخ در ساعت)
        $rateLimiter = app(\Core\RateLimiter::class);
        $rateKey = "admin_ticket_reply:{$adminId}";
        if (!$rateLimiter->attempt($rateKey, 100, 3600)) {
            $this->logger->critical('admin_rate_limit_exceeded', ['admin_id' => $adminId]);
            return $this->response->json(['success' => false, 'message' => 'تعداد پاسخ‌های شما غیرعادی است'], 429);
        }

        // 🛡️ HIGH-08: ضدعفونی صریح پیام پاسخ ادمین جهت مقابله با حملات Stored XSS پس از ولیدیشن طول
        $message = htmlspecialchars($rawMessage, ENT_QUOTES, 'UTF-8', false);

        $ticket = $this->ticketService->getById($ticketId);
        if (!$ticket) {
            return $this->response->json([
                'success' => false, 
                'message' => 'تیکت یافت نشد.'
            ], 404);
        }
        
        // 🛡️ HIGH-03: Prevent admin IDOR by ensuring they own the ticket or have global permissions
        $isAssignedToMe = ($ticket->assigned_to !== null && (int)$ticket->assigned_to === $adminId);
        if (!$isAssignedToMe) {
            try {
                $hasPermission = (bool) $this->policyService->authorizeById('tickets.view_all', $adminId);
            } catch (\Throwable $e) {
                $hasPermission = false;
            }
            if (!$hasPermission) {
                return $this->response->json(['success' => false, 'message' => 'شما دسترسی لازم برای پاسخ به این تیکت را ندارید.'], 403);
            }
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
            
            // 🛡️ NEW-12: ثبت کامل ردپای حسابرسی ادمین به همراه هش پیام پیام جهت امنیت کامل حسابرسی
            $this->auditLog('ticket_admin_reply', 'ticket', $ticketId, null, [
                'message_length' => mb_strlen($rawMessage),
                'message_hash' => hash('sha256', $rawMessage), // 🛡️ MEDIUM-03: Hash the raw message for integrity verification

                'has_sanitized' => true,
                'ip_address' => $this->request->ip(),
                'user_agent' => substr($this->request->header('User-Agent') ?? '', 0, 255)
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
        if (!in_array($status, \App\Enums\TicketStatus::all(), true)) {
            return $this->response->json(['success' => false, 'message' => 'وضعیت نامعتبر است.']);
        }

        $ticket = $this->ticketService->getById($ticketId);
        if (!$ticket) {
            return $this->response->json(['success' => false, 'message' => 'تیکت یافت نشد.']);
        }
        
        // 🛡️ CRITICAL-03: Prevent admin IDOR by ensuring they own the ticket or have global permissions
        $adminId = user_id();
        $isAssignedToMe = ($ticket->assigned_to !== null && (int)$ticket->assigned_to === $adminId);
        if (!$isAssignedToMe) {
            try {
                $hasPermission = (bool) $this->policyService->authorizeById('tickets.view_all', $adminId);
            } catch (\Throwable $e) {
                $hasPermission = false;
            }
            if (!$hasPermission) {
                return $this->response->json(['success' => false, 'message' => 'شما دسترسی لازم برای تغییر وضعیت این تیکت را ندارید.'], 403);
            }
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

        // 🛡️ Item 9: بررسی اجازه کاربر جاری برای تخصیص تیکت
        if (!$this->policyService->authorizeById('tickets.assign', user_id())) {
            return $this->response->json([
                'success' => false, 
                'message' => 'دسترسی غیرمجاز برای تغییر تخصیص تیکت.'
            ], 403);
        }

        $data = $this->request->json();
        $ticketId = (int) ($data['ticket_id'] ?? 0);
        $adminId = (int) ($data['admin_id'] ?? 0);
        
        // 🛡️ LOW-05: اعتبارسنجی دقیق شناسه مدیر و ممانعت از ارجاعات نامعتبر
        if (!$ticketId || $adminId <= 0) {
            return $this->response->json(['success' => false, 'message' => 'شناسه تیکت یا شناسه مدیر نامعتبر است.']);
        }

        $ticket = $this->ticketService->getById($ticketId);
        if (!$ticket) {
            return $this->response->json(['success' => false, 'message' => 'تیکت یافت نشد.']);
        }

        $oldAdminId = (int)($ticket->assigned_to ?? 0);

        if (!$this->policyService->isAdminById($adminId)) {
            return $this->response->json(['success' => false, 'message' => 'شناسه مدیر نامعتبر است.']);
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

}