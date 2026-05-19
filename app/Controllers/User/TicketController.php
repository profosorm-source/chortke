<?php

namespace App\Controllers\User;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketCategory;
use App\Services\TicketService;
use App\Services\UploadService;
use Core\Validator;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;

class TicketController extends BaseUserController
{
    private Ticket $ticketModel;
    private TicketMessage $messageModel;
    private TicketCategory $categoryModel;
    private TicketService $ticketService;
    private UploadService $uploadService;
    
    public function __construct(
        \App\Models\Ticket $ticketModel,
        \App\Models\TicketMessage $messageModel,
        \App\Models\TicketCategory $categoryModel,
        \App\Services\TicketService $ticketService,
        \App\Services\UploadService $uploadService)
    {
        parent::__construct();
        $this->ticketModel = $ticketModel;
        $this->messageModel = $messageModel;
        $this->categoryModel = $categoryModel;
        $this->ticketService = $ticketService;
        $this->uploadService = $uploadService;
    }
    
    /**
     * لیست تیکت‌ها
     */
    public function index()
    {
        $userId = user_id();
        
        $status = $this->request->get('status', '');
        $page = (int) $this->request->get('page', 1);
        $perPage = 20;
        
        $result = $this->ticketService->listUserTickets($userId, $status, $page, $perPage);
        $tickets = $result['tickets'] ?? [];
        $total = $result['total'] ?? 0;
        $totalPages = ceil($total / $perPage);
        
        // شمارش خوانده نشده
        $unreadCount = $this->ticketService->countUnread($userId, false);
        
        return view('user/tickets/index', [
            'tickets' => $tickets,
            'status' => $status,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'unreadCount' => $unreadCount,
            'user' => user()
        ]);
    }
    
    /**
     * فرم ایجاد تیکت
     */
    public function create()
    {
        $categories = $this->ticketService->getCategories();
        
        return view('user/tickets/create', [
            'categories' => $categories,
            'user' => user()
        ]);
    }
    
    /**
     * ذخیره تیکت جدید
     */
    public function store()
    {
        $userId = user_id();

        // Rate Limiting - محدودیت ایجاد تیکت
        try {
            rate_limit('social', 'ticket_create', "user_{$userId}");
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                session()->setFlash('error', $e->getMessage());
                return redirect('/tickets/create');
            }
        }

        $data = $this->request->all();
        
        // Validation
        $validator = new Validator($data, [
            'category_id' => 'required|integer',
            'subject' => 'required|min:5|max:150',
            'message' => 'required|min:10|max:5000',
            'priority' => 'required|in:low,normal,high,urgent'
        ]);
        
        if ($validator->fails()) {
            session()->setFlash('error', 'لطفاً تمام فیلدها را به درستی پر کنید.');
            session()->setFlash('errors', $validator->errors());
            session()->setFlash('old', $data);
            return redirect('/tickets/create');
        }
        
        // آپلود فایل
        $attachments = [];
        
        if ($this->request->hasFile('attachments')) {
            $files = $this->request->file('attachments');
            
            // اگر یک فایل است، آن را آرایه کنید
            if (!is_array($files) || !isset($files[0])) {
                $files = [$files];
            }
            
            foreach ($files as $file) {
                $uploadResult = $this->uploadService->upload(
                    $file,
                    'ticket_attachments',
                    ['image/jpeg', 'image/png'],
                    3 * 1024 * 1024 // 3MB
                );
                
                if ($uploadResult['success']) {
                    $attachments[] = [
                        'name' => $file['name'] ?? 'attachment',
                        'path' => $uploadResult['path']
                    ];
                }
            }
        }
        
        $data['attachments'] = $attachments;
        
        // ایجاد تیکت
        $result = $this->ticketService->create(user_id(), $data);
        ApiRateLimiter::enforce('ticket_create', (int)user_id(), true);
        
        if ($result['success']) {
            session()->setFlash('success', $result['message']);
            return redirect('/tickets/show/' . $result['ticket_id']);
        }
        
        session()->setFlash('error', $result['message']);
        session()->setFlash('old', $data);
        return redirect('/tickets/create');
    }
    
    /**
     * نمایش تیکت
     */
    public function show(int $id)
    {
        $userId = user_id();
        
        $ticket = $this->ticketService->getById($id);
        
        if (!$ticket) {
            $this->session->setFlash('error', 'تیکت یافت نشد.');
            return redirect('/tickets');
        }

        if ((int)$ticket->user_id !== $userId) {
            $this->session->setFlash('error', 'شما به این تیکت دسترسی ندارید.');
            return redirect('/tickets');
        }
        
        $messages = $this->ticketService->getMessages($id);
        
        // علامت‌گذاری به عنوان خوانده شده
        $this->ticketService->markAsRead($id, false);
        
        return view('user/tickets/show', [
            'ticket' => $ticket,
            'messages' => $messages,
            'user' => user()
        ]);
    }
    
    /**
     * ارسال پاسخ
     */
    public function reply(): void
    {
        $userId = user_id();

        // Rate Limiting - محدودیت پاسخ به تیکت
        try {
            rate_limit('social', 'ticket_reply', "user_{$userId}");
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->response->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 429);
                return;
            }
        }

        // پشتیبانی از هر دو حالت: JSON ساده و FormData (با فایل پیوست)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $data = $this->request->json();
        } else {
            $data = $this->request->all();
        }

        $validator = new Validator($data, [
            'ticket_id' => 'required|integer',
            'message'   => 'required|min:5'
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => 'پیام نامعتبر است.',
                'errors'  => $validator->errors()
            ]);
            return;
        }

        // پردازش فایل‌های پیوست
        $attachments = [];
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $key => $name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $file = [
                        'name'     => $_FILES['attachments']['name'][$key],
                        'type'     => $_FILES['attachments']['type'][$key],
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$key],
                        'error'    => $_FILES['attachments']['error'][$key],
                        'size'     => $_FILES['attachments']['size'][$key],
                    ];
                    $uploadResult = $this->uploadService->upload(
                        $file,
                        'ticket_attachments',
                        ['image/jpeg', 'image/png'],
                        3 * 1024 * 1024
                    );
                    if ($uploadResult['success']) {
                        $attachments[] = [
                            'name' => $name,
                            'path' => $uploadResult['path'],
                        ];
                    }
                }
            }
        }

        $result = $this->ticketService->reply(
            (int) $data['ticket_id'],
            user_id(),
            $data['message'],
            false,
            $attachments
        );

        $this->response->json($result);
    }

    /**
     * بستن تیکت
     */
    public function close(): void
    {
        $data     = $this->request->json();
        $ticketId = (int) ($data['id'] ?? 0);

        if (!$ticketId) {
            $this->response->json(['success' => false, 'message' => 'شناسه نامعتبر است.']);
            return;
        }

        $result = $this->ticketService->close($ticketId, user_id(), false);

        $this->response->json($result);
    }

    /**
     * دانلود پیوست تیکت با بررسی دسترسی و احراز هویت (مبارزه با IDOR)
     */
    public function downloadAttachment(string $filename): void
    {
        $this->requireAuth();
        
        // ۱. ضدعفونی نام فایل پیوست
        $filename = basename($filename);
        
        // ۲. پیدا کردن پیام مرتبط با فایل پیوست در دیتابیس
        $attachment = db()->query(
            "SELECT tm.ticket_id, tm.user_id, tm.is_admin
             FROM ticket_messages tm
             WHERE JSON_CONTAINS(tm.attachments, JSON_QUOTE(?), '$[*].path')",
            [$filename]
        )->fetch(\PDO::FETCH_OBJ);
        
        if (!$attachment) {
            $this->response->json(['success' => false, 'message' => 'فایل یافت نشد.'], 404);
            return;
        }
        
        $ticket = $this->ticketService->getById((int)$attachment->ticket_id);
        if (!$ticket) {
            $this->response->json(['success' => false, 'message' => 'تیکت یافت نشد.'], 404);
            return;
        }
        
        // ۳. بررسی دسترسی کاربر
        $userId = user_id();
        $isAdmin = function_exists('is_admin') ? is_admin() : false;
        
        if (!$isAdmin && (int)$ticket->user_id !== $userId) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
            return;
        }
        
        // ۴. خواندن و سرو فایل به صورت ایمن
        $path = base_path("storage/uploads/ticket_attachments/{$filename}");
        if (!file_exists($path)) {
            $this->response->json(['success' => false, 'message' => 'فایل در سرور یافت نشد.'], 404);
            return;
        }
        
        // پاک کردن هرگونه بافر خروجی
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: ' . mime_content_type($path));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}