<?php

namespace App\Controllers\Admin;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketCategory;
use App\Services\TicketService;
use App\Controllers\Admin\BaseAdminController;

class TicketController extends BaseAdminController
{
    private Ticket $ticketModel;
    private TicketMessage $messageModel;
    private TicketCategory $categoryModel;
    private TicketService $ticketService;
    
    public function __construct(
        \App\Models\Ticket $ticketModel,
        \App\Models\TicketMessage $messageModel,
        \App\Models\TicketCategory $categoryModel,
        \App\Services\TicketService $ticketService)
    {
        parent::__construct();
        $this->ticketModel = $ticketModel;
        $this->messageModel = $messageModel;
        $this->categoryModel = $categoryModel;
        $this->ticketService = $ticketService;
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
        
        $page = (int) $this->request->get('page', 1);
        $perPage = 20;
        
        $tickets = $this->ticketModel->getForAdmin($filters, $page, $perPage);
        $total = $this->ticketModel->countForAdmin($filters);
        $totalPages = ceil($total / $perPage);
        
        // آمار
        $stats = $this->ticketModel->getStats();
        
        // دسته‌بندی‌ها
        $categories = $this->categoryModel->getAll();
        
        return view('admin/tickets/index', [
            'tickets' => $tickets,
            'stats' => $stats,
            'categories' => $categories,
            'filters' => $filters,
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
        $ticket = $this->ticketModel->findById($id);
        
        if (!$ticket) {
            session()->setFlash('error', 'تیکت یافت نشد.');
            return redirect('/admin/tickets');
        }
        
        $messages = $this->messageModel->getByTicketId($id);
        
        // علامت‌گذاری به عنوان خوانده شده
        $this->messageModel->markAsRead($id, true);
        
        return view('admin/tickets/show', [
            'ticket' => $ticket,
            'messages' => $messages
        ]);
    }
    
    /**
     * ارسال پاسخ
     */
    public function reply()
    {
                        
        $data = $this->request->json();
        
        $result = $this->ticketService->reply(
            (int) $data['ticket_id'],
            user_id(),
            $data['message'],
            true // isAdmin
        );
        
        return $this->response->json($result);
    }
    
    /**
     * تغییر وضعیت
     */
    public function changeStatus()
    {
                        
        $data = $this->request->json();
        $ticketId = (int) ($data['id'] ?? 0);
        $status = $data['status'] ?? '';
        
        if (!$ticketId || !$status) {
            return $this->response->json(['success' => false, 'message' => 'داده‌های ناقص.']);
        }
        
        if ($this->ticketModel->updateStatus($ticketId, $status)) {
            $this->logger->activity('ticket_status_changed', "وضعیت تیکت #{$ticketId} به {$status} تغییر کرد", user_id(), []);
            
            return $this->response->json([
                'success' => true,
                'message' => 'وضعیت تیکت تغییر کرد.'
            ]);
        }
        
        return $this->response->json(['success' => false, 'message' => 'خطا در تغییر وضعیت.']);
    }
    
    /**
     * تخصیص به ادمین
     */
    public function assign()
    {
                        
        $data = $this->request->json();
        $ticketId = (int) ($data['ticket_id'] ?? 0);
        $adminId = (int) ($data['admin_id'] ?? 0);
        
        if (!$ticketId) {
            return $this->response->json(['success' => false, 'message' => 'داده‌های ناقص.']);
        }
        
        if ($this->ticketModel->assign($ticketId, $adminId)) {
            return $this->response->json([
                'success' => true,
                'message' => 'تیکت تخصیص داده شد.'
            ]);
        }
        
        return $this->response->json(['success' => false, 'message' => 'خطا در تخصیص.']);
    }
}