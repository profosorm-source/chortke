<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Contracts\NotificationServiceInterface;

class TicketService
{

    private Ticket $ticketModel;
    private TicketMessage $messageModel;
    private \Core\EventDispatcher $events;
    private \Core\RateLimiter $rateLimiter;
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    
    private \App\Contracts\ValidatorFactoryInterface $validatorFactory;
    private \Core\TransactionWrapper $transactionWrapper;
    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\ValidatorFactoryInterface $validatorFactory,
        \Core\TransactionWrapper $transactionWrapper,
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Ticket $ticketModel,
        TicketMessage $messageModel,
        \Core\RateLimiter $rateLimiter,
        ?\App\Services\Shared\IdempotencyService $idempotencyService = null
    ) {        $this->validatorFactory = $validatorFactory;
        $this->transactionWrapper = $transactionWrapper;
        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;

        
        $this->ticketModel = $ticketModel;
        $this->messageModel = $messageModel;
        $this->events = $this->eventDispatcher;
        $this->rateLimiter = $rateLimiter;
        $this->idempotencyService = $idempotencyService ?? \Core\Container::getInstance()->make(\App\Services\Shared\IdempotencyService::class);
    }
    
    /**
     * Business Guard برای ایجاد تیکت (Single Source of Truth)
     */
    public function guardCanCreateTicket(int $userId, array $data): void
    {
        if ($userId <= 0) {
            throw new \App\Exceptions\BusinessException('شناسه کاربر نامعتبر است');
        }

        $mergedData = array_merge([
            'category' => 'technical',
            'priority' => 'normal',
            'idempotency_key' => 'ticket_init_' . $userId . '_' . time(),
        ], $data);

        $rules = [
            'subject'        => 'required|string|min:5|max:200',
            'message'        => 'required|string|min:20|max:5000',
            'category'       => 'required|in:technical,billing,account,other',
            'priority'       => 'required|in:low,normal,high,urgent',
            'idempotency_key' => 'required|string|min:10|max:128',
        ];

        try {
            $this->validate($mergedData, $rules, [], true);
        } catch (\Core\Exceptions\ValidationException $e) {
            throw new \App\Exceptions\BusinessException($this->formatValidationErrors($e->getErrors()));
        }
    }

    /**
     * ایجاد تیکت جدید
     */
    public function create(int $userId, array $data): array
    {
        try {
            $this->guardCanCreateTicket($userId, $data);
        } catch (\App\Exceptions\BusinessException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
        
                // 🛡️ مقابله با سوءاستفاده: ریت لیمیت با استفاده از سرویس استاندارد
        if (!$this->rateLimiter->attempt("ticket_creation:{$userId}", 3, 3600)) {
            $this->logger->warning('ticket.rate_limit_exceeded', ['user_id' => $userId]);
            return [
                'success' => false,
                'message' => 'شما اخیراً تیکت‌های زیادی ایجاد کرده‌اید. لطفاً کمی صبر کرده و مجدداً امتحان کنید.'
            ];
        }

        $categoryId = isset($data['category_id']) ? (int)$data['category_id'] : 0;
        if ($categoryId <= 0) {
            return [
                'success' => false,
                'message' => 'انتخاب دسته‌بندی تیکت الزامی است.'
            ];
        }

        // ضدعفونی موضوع جهت مقابله با حملات XSS
        $subject = htmlspecialchars(strip_tags($data['subject']), ENT_QUOTES, 'UTF-8', false);

        // Ported smart features: Detect dynamic priority if not explicitly set to High/Urgent
        $priority = $data['priority'] ?? 'normal';
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }
        if ($priority === 'normal') {
            $priority = $this->detectPriority($subject . ' ' . $data['message'], $categoryId);
        }

        $idempotencyKey = $data['idempotency_key'] ?? 'ticket_init_' . $userId . '_' . time();

        return $this->idempotencyService->executeWithTransaction(
            'ticket.create',
            $userId,
            $data,
            function() use ($userId, $categoryId, $subject, $priority, $data) {
                // Ensure idempotency wrappers are executing inside transaction
                $ticketId = $this->ticketModel->create([
                    'user_id'     => $userId,
                    'category_id' => $categoryId,
                    'subject'     => $subject,
                    'priority'    => $priority,
                    'status'      => 'open',
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);

                if (!$ticketId) {
                    throw new \RuntimeException('خطا در ثبت تیکت جدید.');
                }

                $msgData = [
                    'ticket_id' => $ticketId,
                    'user_id'   => $userId,
                    'message'   => htmlspecialchars(strip_tags($data['message']), ENT_QUOTES, 'UTF-8', false),
                    'is_admin'  => 0,
                    'created_at'=> date('Y-m-d H:i:s'),
                ];
                
                if (!$this->messageModel->create($msgData)) {
                    throw new \RuntimeException('خطا در ثبت پیام تیکت.');
                }

                $this->logger->info('ticket.created', [
                    'ticket_id' => $ticketId,
                    'user_id'   => $userId,
                    'priority'  => $priority
                ]);

                // ارسال رویداد پس از موفقیت کامل
                $this->events->dispatch('ticket.created', ['ticket_id' => $ticketId, 'user_id' => $userId]);

                return [
                    'success' => true,
                    'message' => 'تیکت شما با موفقیت ثبت شد.',
                    'ticket_id' => $ticketId
                ];
            },
            $idempotencyKey
        );
    }
    
    /**
     * ارسال پاسخ
     */
    public function reply(int $ticketId, int $userId, string $message, bool $isAdmin = false, array $attachments = []): array
    {
                // 🛡️ ریت لیمیت استاندارد برای جلوگیری از اسپم پاسخ
        if (!$isAdmin) {
            if (!$this->rateLimiter->attempt("ticket_reply:{$userId}", 5, 3600)) {
                $this->logger->warning('ticket.reply.rate_limit_exceeded', ['user_id' => $userId, 'ticket_id' => $ticketId]);
                return [
                    'success' => false,
                    'message' => 'تعداد پیام‌های ارسالی شما بیش از حد مجاز ساعتی است. لطفا کمی صبر کنید.'
                ];
            }
        }

        // 🛡️ Item 6: Pessimistic Locking inside Transaction
        $explicitKey = null; // Let IdempotencyService compute key from payload

        try {
            return $this->idempotencyService->executeWithTransaction(
                'ticket.reply',
                $userId,
                ['ticket_id' => $ticketId, 'message' => $message],
                function() use ($ticketId, $userId, $message, $isAdmin, $attachments) {
                $ticket = $this->db->fetch("SELECT * FROM tickets WHERE id = ? FOR UPDATE", [$ticketId]);
                
                if (!$ticket) {
                    return ['success' => false, 'message' => 'تیکت یافت نشد.'];
                }
                
                // بررسی دسترسی
                if (!$isAdmin && (int)$ticket->user_id !== $userId) {
                    return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
                }
                
                // بررسی وضعیت
                if ($ticket->status === 'closed' && !$isAdmin) {
                    return ['success' => false, 'message' => 'تیکت بسته شده است.'];
                }

                // 🛡️ مقابله با سوءاستفاده: ارزیابی طول پیام
                $errors = $this->validate(['message' => $message], ['message' => 'required|string|max:5000'], [], false);
                if ($errors) {
                    return ['success' => false, 'message' => 'متن پاسخ نباید بیشتر از ۵۰۰۰ کاراکتر باشد.'];
                }

                // ایجاد پیام
                $this->messageModel->create([
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'message' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8', false),
                    'attachments' => $attachments,
                    'is_admin' => $isAdmin
                ]);
                
                // بروزرسانی تیکت
                $this->ticketModel->updateLastReply($ticketId, $isAdmin ? 'admin' : 'user');
                
                // صدور رویداد ارسال پاسخ - دیبانس در لیسنر انجام خواهد شد
                $this->events->dispatchAsync('ticket.replied', [
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'is_admin' => $isAdmin,
                    'subject' => $ticket->subject
                ]);
                
                return [
                    'success' => true,
                    'message' => 'پاسخ با موفقیت ثبت شد.'
                ];
            }, $explicitKey);
            
        } catch (\Exception $e) {
            $this->logger->error('ticket.reply.failed', [
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'خطایی در ارسال پاسخ رخ داد. لطفاً دوباره تلاش کنید.'
            ];
        }
    }
    
    /**
     * دریافت تیکت‌های یک کاربر با صفحه بندی
     */
    public function getUserTickets(int $userId, ?string $status = null, int $page = 1, int $perPage = 20): array
    {
        return $this->ticketModel->getUserTickets($userId, $status, $page, $perPage);
    }

    

    /**
     * ثبت تخصصی گزارش باگ به عنوان یک تیکت با متادیتا
     */
    public function submitBugReport(int $userId, array $data, ?UploadService $uploadService = null): array
    {
        // بررسی محدودیت روزانه
        $sqlCount = "SELECT COUNT(*) as cnt FROM tickets WHERE user_id = ? AND category_id = 4 AND created_at >= DATE(NOW())";
        $row = $this->db->query($sqlCount, [$userId])->fetch(\PDO::FETCH_OBJ);
        if ($row && (int)$row->cnt >= 2) {
            return ['success' => false, 'message' => 'شما امروز حداکثر تعداد گزارش مجاز (2 بار) را ثبت کرده‌اید.'];
        }

        // H-05: Description Length Validation (10 to 5000 chars)
        $errors = $this->validate($data, ['description' => 'required|string|min:10|max:5000'], [], false);
        if ($errors) {
            return ['success' => false, 'message' => 'توضیحات گزارش نامعتبر است. حداقل ۱۰ و حداکثر ۵۰۰۰ کاراکتر مجاز است.'];
        }

        // استخراج متادیتا از مرورگر
        $browserInfo = $this->parseBrowser($data['user_agent'] ?? null);

        // ترکیب متادیتا
        $ticketData = [
            'subject' => '[گزارش باگ] ' . \mb_strimwidth($data['page_title'] ?? 'بدون عنوان', 0, 50, '...'),
            'message' => $data['description'],
            'category_id' => 4, // دسته‌بندی فنی
            'metadata' => [
                'page_url' => $data['page_url'] ?? null,
                'page_title' => $data['page_title'] ?? null,
                'bug_category' => $data['category'] ?? 'other',
                'browser' => $browserInfo['browser'] ?? null,
                'os' => $browserInfo['os'] ?? null,
                'screen_resolution' => $data['screen_resolution'] ?? null,
                'device_fingerprint' => $data['device_fingerprint'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'legacy_attachment' => $data['screenshot'] ?? null
            ]
        ];

        // استفاده از ساخت استاندارد تیکت (حاوی تشخیص اولویت و تراکنش اتوماتیک)
        return $this->create($userId, $ticketData);
    }

    /**
     * دریافت گزارش‌های باگ به فرمت Legacy برای عدم تخریب Viewها
     */
    public function getBugReports(int $userId, int $perPage = 15, int $offset = 0): array
    {
        $sql = "SELECT t.id, t.priority, t.status, t.created_at, t.metadata,
                       tm.message as description,
                       (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) - 1 as comment_count
                FROM tickets t
                LEFT JOIN ticket_messages tm ON tm.ticket_id = t.id AND tm.id = (SELECT MIN(id) FROM ticket_messages WHERE ticket_id = t.id)
                WHERE t.user_id = ? AND t.category_id = 4
                ORDER BY t.created_at DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ);

        // مجازی‌سازی پروپرتی‌ها برای سازگاری با ویوها
        foreach ($results as $row) {
            $meta = json_decode($row->metadata ?? '{}', true);
            $row->category = $meta['bug_category'] ?? 'other';
            $row->page_url = $meta['page_url'] ?? '';
            $row->screenshot_path = $meta['legacy_attachment'] ?? null;
        }

        return $results;
    }

    /**
     * دریافت جزئیات گزارش باگ به صورت تکی
     */
    public function findBugReport(int $id): ?object
    {
        $sql = "SELECT t.*, tm.message as description
                FROM tickets t
                LEFT JOIN ticket_messages tm ON tm.ticket_id = t.id AND tm.id = (SELECT MIN(id) FROM ticket_messages WHERE ticket_id = t.id)
                WHERE t.id = ? AND t.category_id = 4";
        
        $stmt = $this->db->query($sql, [$id]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        if (!$row) return null;

        // نگاشت مجدد
        $meta = json_decode($row->metadata ?? '{}', true);
        $row->category = $meta['bug_category'] ?? 'other';
        $row->page_url = $meta['page_url'] ?? '';
        $row->screenshot_path = $meta['legacy_attachment'] ?? null;
        $row->admin_note = null; // یا می‌توانید آخرین پیام ادمین را اینجا بگذارید

        return $row;
    }

    /**
     * دریافت لیست گزارش‌ها برای ادمین از طریق سیستم تیکت
     */
    public function getAdminBugReports(array $filters, int $page, int $perPage): array
    {
        // اطمینان از اینکه فقط دسته‌بندی فنی نمایش داده می‌شود
        $filters['category_id'] = 4;

        $items = $this->ticketModel->getForAdmin($filters, $page, $perPage);

        // مجازی‌سازی برای ویوهای ادمین
        foreach ($items as $item) {
            $meta = json_decode($item->metadata ?? '{}', true);
            $item->category = $meta['bug_category'] ?? 'other';
            $item->page_url = $meta['page_url'] ?? '';
            $item->is_suspicious = false; // deprecated logically
            $item->description = 'Ticket description available inside show'; // Not strictly needed for index view usually
        }

        return $items;
    }

    /**
     * شمارش کل آیتم‌ها برای ادمین
     */
    public function countAdminBugReports(array $filters): int
    {
        $filters['category_id'] = 4;
        return $this->ticketModel->countForAdmin($filters);
    }

    /**
     * استخراج آمار تخصصی تیکت‌های باگ برای داشبورد مدیریت
     */
    public function getAdminBugStats(): array
    {
        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as `open`,
                    SUM(CASE WHEN status = 'in_progress' OR status = 'pending' THEN 1 ELSE 0 END) as `in_progress`,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as `closed`,
                    SUM(CASE WHEN priority = 'urgent' OR priority = 'high' THEN 1 ELSE 0 END) as `urgent`
                FROM tickets WHERE category_id = 4";

        $row = $this->db->query($sql)->fetch(\PDO::FETCH_ASSOC);
        return $row ?: ['total' => 0, 'open' => 0, 'in_progress' => 0, 'closed' => 0, 'urgent' => 0];
    }

    /**
     * دریافت پیام‌های متوالی (بجز پیام اول که خود توضیحات است)
     */
    public function getBugReportComments(int $id): array
    {
        $sql = "SELECT tm.*, u.full_name as user_full_name,
                       IF(tm.is_admin = 1, 'admin', 'user') as user_type
                FROM ticket_messages tm
                JOIN users u ON tm.user_id = u.id
                WHERE tm.ticket_id = ?
                AND tm.id > (SELECT MIN(id) FROM ticket_messages WHERE ticket_id = ?)
                ORDER BY tm.created_at ASC";

        $stmt = $this->db->query($sql, [$id, $id]);
        $comments = $stmt->fetchAll(\PDO::FETCH_OBJ);

        foreach($comments as $c) {
             $c->comment = $c->message;
             $c->attachment_path = null; // if needed
        }
        return $comments;
    }

    /**
     * بروزرسانی وضعیت تیکت توسط ادمین
     */
    public function updateStatus(int $ticketId, string $status, int $adminId): bool
    {
        $this->db->beginTransaction();

        try {
            return $this->getTransactionWrapper()->runWithRetry(function() use ($ticketId, $status, $adminId) {
                // ✅ قفل بدبینانه برای جلوگیری از Race Condition
                $ticket = $this->db->query(
                    "SELECT id, status FROM tickets WHERE id = ? FOR UPDATE",
                    [$ticketId]
                )->fetch(\PDO::FETCH_OBJ);
    
                if (!$ticket) {
                    return false;
                }
    
                // 🛡️ RC-05: بررسی کنید که آیا وضعیت واقعاً تغییر کرده است تا از ثبت تاریخچه تکراری جلوگیری شود
                if ($ticket->status === $status) {
                    return true;
                }
    
                $oldStatus = $ticket->status;
    
                $ok = $this->ticketModel->updateStatus($ticketId, $status);
                if ($ok) {
                    // ثبت در تاریخچه تغییرات وضعیت تیکت
                    $this->db->table('ticket_status_history')->insert([
                        'ticket_id' => $ticketId,
                        'old_status' => $oldStatus,
                        'new_status' => $status,
                        'changed_by' => $adminId,
                        'changed_at' => date('Y-m-d H:i:s')
                    ]);
    
                    $this->logger->activity('ticket_status_updated', "وضعیت تیکت #{$ticketId} از {$oldStatus} به {$status} تغییر یافت", $adminId, [
                        'old_status' => $oldStatus,
                        'new_status' => $status
                    ]);
                }
    
                return $ok;
            });
        } catch (\Exception $e) {
            $this->logger->error('ticket.status.update.failed', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * بروزرسانی اولویت تیکت توسط ادمین
     */
    public function updatePriority(int $ticketId, string $priority, int $adminId): bool
    {
        try {
            return $this->getTransactionWrapper()->runWithRetry(function() use ($ticketId, $priority, $adminId) {
                // ✅ قفل بدبینانه برای جلوگیری از Race Condition (TOCTOU)
                $ticket = $this->db->query(
                    "SELECT id, priority FROM tickets WHERE id = ? FOR UPDATE",
                    [$ticketId]
                )->fetch(\PDO::FETCH_OBJ);
    
                if (!$ticket) {
                    return false;
                }
    
                if ($ticket->priority === $priority) {
                    return true;
                }
    
                $oldPriority = $ticket->priority;
    
                $ok = $this->ticketModel->update($ticketId, ['priority' => $priority]);
                if ($ok) {
                    $this->logger->activity('ticket_priority_updated', "اولویت تیکت #{$ticketId} از {$oldPriority} به {$priority} تغییر یافت", $adminId, [
                        'old_priority' => $oldPriority,
                        'new_priority' => $priority
                    ]);
                }
    
                return $ok;
            });
        } catch (\Exception $e) {
            $this->logger->error('ticket.priority.update.failed', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 🛡️ BLF-07: تخصیص تیکت به ادمین به همراه ثبت تاریخچه تغییرات و ارسال نوتیفیکیشن
     */
    public function assignTo(int $ticketId, int $newAdminId): bool
    {
        try {
            $this->getTransactionWrapper()->runWithRetry(function() use ($ticketId, $newAdminId) {
                // دریافت تیکت جهت بررسی تخصیص قبلی
                $ticket = $this->ticketModel->findById($ticketId);
                if (!$ticket) {
                    throw new \Exception('Ticket not found');
                }
                
                $oldAdminId = $ticket->assigned_to ? (int)$ticket->assigned_to : null;
                
                // تخصیص تیکت به ادمین جدید
                $ok = $this->ticketModel->assign($ticketId, $newAdminId);
                if (!$ok) {
                    throw new \Exception('Failed to assign ticket');
                }
                
                // ثبت در جدول تاریخچه تغییر تخصیص
                $this->db->table('ticket_assignment_history')->insert([
                    'ticket_id' => $ticketId,
                    'old_admin_id' => $oldAdminId,
                    'new_admin_id' => $newAdminId > 0 ? $newAdminId : null,
                    'changed_by' => user_id() > 0 ? user_id() : 1,
                    'changed_at' => date('Y-m-d H:i:s')
                ]);
                
                // صدور رویداد تخصیص تیکت
                $this->events->dispatchAsync('ticket.assigned', [
                    'ticket_id' => $ticketId,
                    'old_admin_id' => $oldAdminId,
                    'new_admin_id' => $newAdminId,
                    'subject' => $ticket->subject
                ]);
                
                // ثبت در لاگ فعالیت سیستم
                $this->logger->activity('ticket_assigned', "تیکت #{$ticketId} به مدیر #{$newAdminId} تخصیص یافت", user_id() ?: 1, [
                    'ticket_id' => $ticketId,
                    'old_admin_id' => $oldAdminId,
                    'new_admin_id' => $newAdminId
                ]);
            });
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('ticket.assign.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * بستن تیکت
     */
    public function close(int $ticketId, int $userId, bool $isAdmin = false): array
    {
        $ticket = $this->ticketModel->findById($ticketId);
        
        if (!$ticket) {
            return ['success' => false, 'message' => 'تیکت یافت نشد.'];
        }
        
        // بررسی دسترسی
        if (!$isAdmin && (int)$ticket->user_id !== $userId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        
        if ($this->ticketModel->updateStatus($ticketId, 'closed')) {
            $this->logger->activity('ticket_closed', "تیکت #{$ticketId} بسته شد", $userId, []);
            
            return [
                'success' => true,
                'message' => 'تیکت بسته شد.'
            ];
        }
        
        return ['success' => false, 'message' => 'خطا در بستن تیکت.'];
    }

    /**
     * تشخیص هوشمند اولویت بدون کوئری دیتابیس جهت مقابله با SQL Injection
     */
    public function detectPriority(string $text, int $categoryId = 0): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        
        // Keyword-based detection
        $urgentKeywords = ['فوری', 'اورژانس', 'بحرانی', 'خراب شد', 'کار نمیکند', 'هک', 'امنیتی', 'سرقت', 'پول', 'پرداخت نشد', 'کلاهبرداری', 'فیشینگ', 'واریز نشد'];
        $highKeywords = ['مهم', 'سریع', 'مشکل دارد', 'خطا', 'ارور', 'کار نمیکنه', 'خراب', 'باگ', 'bug', 'error', 'قطعی'];
        
        foreach ($urgentKeywords as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                return 'urgent';
            }
        }
        
        foreach ($highKeywords as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                return 'high';
            }
        }
        
        // Category-based priority (safe - no SQL)
        $criticalCategories = [1, 2, 3, 4];
        if (in_array($categoryId, $criticalCategories, true)) {
            return 'high';
        }
        
        return 'normal';
    }

    /**
     * تشخیص ساختار یافته مرورگر و پلتفرم برای درج در متادیتا
     */
    public function parseBrowser(?string $ua): array
    {
        if (!$ua) {
            return ['browser' => null, 'os' => null];
        }

        $browser = 'Unknown';
        $os = 'Unknown';

        if (\preg_match('/Edg[e]?\/(\S+)/i', $ua)) {
            $browser = 'Edge';
        } elseif (\preg_match('/OPR\/(\S+)/i', $ua)) {
            $browser = 'Opera';
        } elseif (\preg_match('/Chrome\/(\S+)/i', $ua)) {
            $browser = 'Chrome';
        } elseif (\preg_match('/Firefox\/(\S+)/i', $ua)) {
            $browser = 'Firefox';
        } elseif (\preg_match('/Safari\/(\S+)/i', $ua) && !\preg_match('/Chrome/i', $ua)) {
            $browser = 'Safari';
        }

        if (\preg_match('/Windows NT/i', $ua)) {
            $os = 'Windows';
        } elseif (\preg_match('/Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (\preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        } elseif (\preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (\preg_match('/iPhone|iPad/i', $ua)) {
            $os = 'iOS';
        }

        return ['browser' => $browser, 'os' => $os];
    }

    /**
     * جستجوی سریع تیکت‌ها (یکپارچه برای مدیریت و داشبورد کاربر)
     */
    public function quickSearchTickets(string $term, ?int $userId = null, int $limit = 5): array
    {
        $query = $this->ticketModel->query();

        // ۱. تفکیک سطح دسترسی (Scope Selection)
        if ($userId !== null) {
            // داشبورد کاربر: فیلتر امن روی شناسه کاربر و انتخاب فیلدهای سبک
            $query->select('id', 'subject', 'status', 'priority', 'created_at')
                  ->where('user_id', '=', $userId);
        } else {
            // پنل ادمین: الحاق کاربر برای نمایش اطلاعات فرستنده
            $query->select('tickets.id', 'tickets.subject', 'tickets.status', 'tickets.created_at', 'u.full_name', 'u.email')
                  ->leftJoin('users as u', 'u.id', '=', 'tickets.user_id');
        }

        // ۲. اعمال هوشمند جستجوهای ثبت شده در مدل
        $this->ticketModel->applySearch($query, $term);

        // ۳. فیلتر الحاقی برای ایمیل و آیدی دقیق
        if (!empty($term)) {
            $term = trim($term);
            $escaped = addcslashes($term, '%_');
            $like = "%{$escaped}%";
            $query->where(function($sub) use ($like, $term, $userId) {
                $sub->orWhere('tickets.subject', 'LIKE', $like);
                
                if ($userId === null) {
                    $sub->orWhere('u.email', 'LIKE', $like);
                    if (\is_numeric($term)) {
                        $sub->orWhere('tickets.id', '=', (int)$term);
                    }
                }
            });
        }

        return $query->orderBy('tickets.created_at', 'DESC')
                     ->limit($limit)
                     ->get() ?? [];
    }

    public function searchTicketsAdmin(string $q, array $filters, int $limit, int $offset): array
    {
        $query = $this->ticketModel->query()
            ->select('tickets.*', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'tickets.user_id');

        if (!empty($q)) {
            $like = "%{$q}%";
            $query->where(function($sub) use ($like, $q) {
                $sub->where('tickets.subject', 'LIKE', $like)
                    ->orWhere('tickets.id', '=', $q)
                    ->orWhere('u.email', 'LIKE', $like);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('tickets.status', '=', e($filters['status'], ENT_QUOTES, 'UTF-8'));
        }
        if (!empty($filters['priority'])) {
            $query->where('tickets.priority', '=', e($filters['priority'], ENT_QUOTES, 'UTF-8'));
        }
        if (!empty($filters['category_id'])) {
            $query->where('tickets.category_id', '=', (int)$filters['category_id']);
        }

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy('tickets.created_at', 'DESC')
                                     ->limit($limit)->offset($offset)->get() ?? []
        ];
    }

    /**
     * پیدا کردن پیام مرتبط با فایل پیوست
     */
    public function getAttachmentMessage(string $filename): ?object
    {
        return $this->db->query(
            "SELECT tm.ticket_id, tm.user_id, tm.is_admin
             FROM ticket_messages tm
             WHERE JSON_CONTAINS(tm.attachments, JSON_QUOTE(?), '$[*].path')",
            [$filename]
        )->fetch(\PDO::FETCH_OBJ) ?: null;
    }
}
