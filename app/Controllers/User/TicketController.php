<?php

namespace App\Controllers\User;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketCategory;
use App\Services\TicketService;
use App\Services\UploadService;
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
        \App\Services\UploadService $uploadService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
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
        // 🛡️ MEDIUM-01: Rate limit on read endpoint to prevent metadata scraping and DOS
        rate_limit('social', 'ticket_list', "user_" . user_id());
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
        $this->validateCsrf();
        $userId = user_id();

        $data = $this->request->all();
        $data['message'] = trim($data['message'] ?? '');
        
        // Validation
        $validator = $this->validatorFactory()->make($data, [
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
            
            if (count($files) > 5) {
                session()->setFlash('error', 'حداکثر ۵ فایل مجاز است.');
                return redirect('/tickets/create');
            }
            
            foreach ($files as $file) {
                // 🛡️ CRITICAL-02: Validate Magic Bytes using finfo to strictly enforce allowed formats
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
                    $this->logger->warning('ticket.attachment.invalid_magic_bytes', ['user_id' => $userId, 'mime' => $mimeType]);
                    session()->setFlash('error', 'فایل ' . htmlspecialchars($file['name'] ?? 'نامعتبر', ENT_QUOTES, 'UTF-8') . ' نوع یا محتوای نامعتبری دارد.');
                    return redirect('/tickets/create');
                }

                $uploadResult = $this->uploadService->upload(
                    $file,
                    'ticket_attachments',
                    ['image/jpeg', 'image/png'],
                    3 * 1024 * 1024 // 3MB
                );
                
                if (!$uploadResult['success']) {
                    $this->logger->warning('ticket.attachment.failed', [
                        'user_id' => $userId,
                        'error' => $uploadResult['message'] ?? 'Unknown upload error'
                    ]);
                    session()->setFlash('error', 'خطا در آپلود فایل پیوست. لطفاً دوباره امتحان کنید.');
                    return redirect('/tickets/create');
                }

                if (!$this->uploadService->getPath($uploadResult['path'])) {
                    $this->logger->warning('ticket.attachment.invalid_path', ['user_id' => $userId, 'path' => $uploadResult['path']]);
                    session()->setFlash('error', 'مسیر فایل پیوست نامعتبر است.');
                    return redirect('/tickets/create');
                }
                
                // 🛡️ MEDIUM-02: فیلتر کاراکترهای نام پیوست با Regex و basename جهت ارتقای امنیت نام فایل
                $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name'] ?? 'attachment'));
                $attachments[] = [
                    'name' => htmlspecialchars($cleanName, ENT_QUOTES, 'UTF-8'),
                    'path' => $uploadResult['path']
                ];
            }
        }
        
        $data['attachments'] = $attachments;
        
        // ایجاد تیکت
        $result = $this->ticketService->create($userId, $data);
        
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
        $this->validateCsrf();
        $userId = user_id();

        // پشتیبانی از هر دو حالت: JSON ساده و FormData (با فایل پیوست)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $data = $this->request->json();
        } elseif (str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $data = $this->request->all();
        } else {
            $this->response->json([
                'success' => false,
                'message' => 'نوع محتوا پشتیبانی نمی‌شود.'
            ], 415);
            return;
        }

        $validator = $this->validatorFactory()->make($data, [
            'ticket_id' => 'required|integer',
            'message'   => 'required|min:5|max:5000'
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => 'پیام نامعتبر است.',
                'errors'  => $validator->errors()
            ]);
            return;
        }

        // 🛡️ CRITICAL-01: Sanitization is cleanly handled in TicketService to prevent double encoding
        $messageRaw = trim($data['message'] ?? '');
        $message = $messageRaw;

        // 🛡️ IDOR Check: بررسی مالکیت تیکت قبل از پاسخ
        $ticketId = (int)($data['ticket_id'] ?? 0);
        $ticket = $this->ticketService->getById($ticketId);
        if (!$ticket || (int)$ticket->user_id !== $userId) {
            $this->response->json([
                'success' => false, 
                'message' => 'دسترسی غیرمجاز.'
            ], 403);
            return;
        }

        // 🛡️ MEDIUM-02: Prevent users from replying to already closed tickets directly at the controller edge
        if ($ticket->status === 'closed') {
            $this->response->json([
                'success' => false,
                'message' => 'امکان ارسال پاسخ برای تیکت بسته شده وجود ندارد.'
            ], 403);
            return;
        }

        // پردازش فایل‌های پیوست با استفاده از Request Wrapper
        $attachments = [];
        $attachmentsFile = $this->request->file('attachments');
        if ($attachmentsFile && !empty($attachmentsFile['name'])) {
            $filesCount = is_array($attachmentsFile['name']) ? count($attachmentsFile['name']) : 1;
            if ($filesCount > 5) {
                $this->response->json([
                    'success' => false,
                    'message' => 'حداکثر ۵ فایل مجاز است.'
                ], 400);
                return;
            }

            // نرمال سازی فایل ها جهت پردازش صحیح تک فایل یا چند فایل
            $normalizedFiles = [];
            if (!is_array($attachmentsFile['name'])) {
                $normalizedFiles[] = [
                    'name'     => $attachmentsFile['name'],
                    'type'     => $attachmentsFile['type'],
                    'tmp_name' => $attachmentsFile['tmp_name'],
                    'error'    => $attachmentsFile['error'],
                    'size'     => $attachmentsFile['size'],
                ];
            } else {
                foreach ($attachmentsFile['name'] as $key => $name) {
                    $normalizedFiles[] = [
                        'name'     => $attachmentsFile['name'][$key],
                        'type'     => $attachmentsFile['type'][$key],
                        'tmp_name' => $attachmentsFile['tmp_name'][$key],
                        'error'    => $attachmentsFile['error'][$key],
                        'size'     => $attachmentsFile['size'][$key],
                    ];
                }
            }

            foreach ($normalizedFiles as $file) {
                if ($file['error'] === UPLOAD_ERR_OK) {
                    // 🛡️ HIGH-02: Validate Magic Bytes using finfo in reply attachments as well
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);
                    
                    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
                        $this->logger->warning('ticket.reply.attachment.invalid_magic_bytes', ['user_id' => $userId, 'mime' => $mimeType]);
                        continue;
                    }

                    $uploadResult = $this->uploadService->upload(
                        $file,
                        'ticket_attachments',
                        ['image/jpeg', 'image/png'],
                        3 * 1024 * 1024
                    );
                    if (!$uploadResult['success']) {
                        $this->logger->warning('ticket.reply.attachment.failed', [
                            'user_id' => $userId,
                            'error' => $uploadResult['message'] ?? 'Unknown upload error'
                        ]);
                        continue;
                    }
                    
                    // 🛡️ MEDIUM-02: فیلتر کاراکترهای نام پیوست با Regex و basename جهت ارتقای امنیت نام فایل
                    $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name'] ?? 'attachment'));
                    $attachments[] = [
                        'name' => htmlspecialchars($cleanName, ENT_QUOTES, 'UTF-8'),
                        'path' => $uploadResult['path'],
                    ];
                }
            }
        }

        $result = $this->ticketService->reply(
            $ticketId,
            $userId,
            $message,
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
        $attachment = $this->ticketService->getAttachmentMessage($filename);
        
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