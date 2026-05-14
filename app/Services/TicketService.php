<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\Notification\NotificationService;

class TicketService extends \App\Services\BaseService
{
    private Database $db;
    private Ticket $ticketModel;
    private TicketMessage $messageModel;
    private NotificationService $notificationService;
    private \Core\RateLimiter $rateLimiter; // 🛡️ مقابله با سوءاستفاده
    
    public function __construct(
        Ticket $ticketModel,
        TicketMessage $messageModel,
        Database $db,
        LoggerInterface $logger,
        NotificationService $notificationService,
        \Core\RateLimiter $rateLimiter // 🛡️
    ) {
        parent::__construct($logger);
        $this->ticketModel = $ticketModel;
        $this->messageModel = $messageModel;
        $this->db = $db;
        $this->notificationService = $notificationService;
        $this->rateLimiter = $rateLimiter;
    }
    
    /**
     * ایجاد تیکت جدید
     */
    public function create(int $userId, array $data): array
    {
        if (empty($data['subject']) || empty($data['message'])) {
            return [
                'success' => false,
                'message' => 'موضوع و متن پیام تیکت الزامی می‌باشند.'
            ];
        }

        // 🛡️ مقابله با سوءاستفاده: ارزیابی طول فیلدهای متنی
        $subjectLen = mb_strlen((string)$data['subject'], 'UTF-8');
        $messageLen = mb_strlen((string)$data['message'], 'UTF-8');
        
        if ($subjectLen > 150) {
            return ['success' => false, 'message' => 'موضوع تیکت نباید بیشتر از ۱۵۰ کاراکتر باشد.'];
        }
        if ($messageLen > 5000) {
            return ['success' => false, 'message' => 'متن پیام تیکت نباید بیشتر از ۵۰۰۰ کاراکتر باشد.'];
        }
        
        // 🛡️ مقابله با سوءاستفاده: ریت لیمیت ثبت تیکت جدید (حداکثر ۳ تیکت در ساعت جهت مقابله با اسپم ربات‌ها)
        $rateKey = "ticket_creation_limit:{$userId}";
        if (!$this->rateLimiter->attempt($rateKey, 3, 3600)) {
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
        $subject = htmlspecialchars(strip_tags($data['subject']), ENT_QUOTES, 'UTF-8');

        // Ported smart features: Detect dynamic priority if not explicitly set to High/Urgent
        $priority = $data['priority'] ?? 'normal';
        if ($priority === 'normal') {
            $priority = $this->detectPriority($subject . ' ' . $data['message'], $categoryId);
        }

        // Dynamic Metadata serialization
        $metadata = isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null;

        $this->db->beginTransaction();
        
        try {
            // ایجاد تیکت
            $ticketId = $this->ticketModel->create([
                'user_id' => $userId,
                'category_id' => $categoryId,
                'subject' => $subject,
                'priority' => $priority,
                'metadata' => $metadata
            ]);
            
            if (!$ticketId) {
                throw new \Exception('خطا در ایجاد تیکت');
            }
            
            // ایجاد پیام اول
            $this->messageModel->create([
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'message' => htmlspecialchars((string)$data['message'], ENT_QUOTES, 'UTF-8'),
                'attachments' => $data['attachments'] ?? [],
                'is_admin' => false
            ]);
            
            // لاگ نویسی دقیق بدون آرایه تکراری بی‌اثر
            $this->logger->activity('ticket_created', "تیکت جدید ایجاد شد: {$subject}", $userId, [
                'ticket_id' => $ticketId
            ]);
            
            // نوتیفیکیشن به ادمین
            $this->notificationService->sendToAdmins('info', 'تیکت جدید ثبت شد', "تیکت جدید ثبت شد: {$subject}", ['action_url' => "/admin/tickets/show/{$ticketId}"]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'ticket_id' => $ticketId,
                'message' => 'تیکت شما با موفقیت ثبت شد.'
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            
            return [
                'success' => false,
                'message' => 'خطا در ایجاد تیکت: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * ارسال پاسخ
     */
    public function reply(int $ticketId, int $userId, string $message, bool $isAdmin = false, array $attachments = []): array
    {
        $ticket = $this->ticketModel->findById($ticketId);
        
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
        $msgLen = mb_strlen($message, 'UTF-8');
        if ($msgLen > 5000) {
            return ['success' => false, 'message' => 'متن پاسخ نباید بیشتر از ۵۰۰۰ کاراکتر باشد.'];
        }
        
        // 🛡️ مقابله با سوءاستفاده: ریت لیمیت پاسخ‌ها (حداکثر ۱۰ پاسخ در ساعت برای کاربران عادی)
        if (!$isAdmin) {
            $rateKey = "ticket_reply_limit:{$userId}";
            if (!$this->rateLimiter->attempt($rateKey, 10, 3600)) {
                $this->logger->warning('ticket.reply.rate_limit_exceeded', ['user_id' => $userId, 'ticket_id' => $ticketId]);
                return [
                    'success' => false,
                    'message' => 'تعداد پیام‌های ارسالی شما بیش از حد مجاز ساعتی است. لطفا کمی صبر کنید.'
                ];
            }
        }
        
        $this->db->beginTransaction();
        
        try {
            // ایجاد پیام
            $this->messageModel->create([
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'message' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
                'attachments' => $attachments,
                'is_admin' => $isAdmin
            ]);
            
            // بروزرسانی تیکت
            $this->ticketModel->updateLastReply($ticketId, $isAdmin ? 'admin' : 'user');
            
            // نوتیفیکیشن صریح از طریق وابستگی تزریق شده سازنده (Constructor DI)
            if ($isAdmin) {
                $this->notificationService->send($ticket->user_id, 'info', "پاسخ جدید برای تیکت: {$ticket->subject}", "/tickets/show/{$ticketId}");
            } else {
                $this->notificationService->sendToAdmins('info', 'پاسخ جدید تیکت', "پاسخ جدید از کاربر در تیکت #{$ticketId}", ['action_url' => "/admin/tickets/show/{$ticketId}"]);
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'پاسخ شما ارسال شد.'
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            
            return [
                'success' => false,
                'message' => 'خطا: ' . $e->getMessage()
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

        // اعتبارسنجی اولیه متن
        if (empty(trim($data['description'] ?? ''))) {
            return ['success' => false, 'message' => 'توضیحات گزارش الزامی است'];
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
     * تشخیص هوشمند اولویت (Ported from BugReportService)
     */
    public function detectPriority(string $text, int $categoryId = 0): string
    {
        // تیکت‌های دسته‌بندی فنی معمولاً حساس‌ترند
        if ($categoryId === 4) { // Technical
             // keep normal for now, will inspect text
        }

        $desc = \mb_strtolower($text);
        $criticalKeywords = ['هک', 'نفوذ', 'دزدی', 'سرقت', 'پول', 'پرداخت نشد', 'موجودی کم شد', 'حساب خالی', 'برداشت نشد'];
        $highKeywords = ['خطا', 'ارور', 'کار نمیکنه', 'بسته میشه', 'لود نمیشه', 'سفید', 'خراب', 'مشکل جدی'];

        foreach ($criticalKeywords as $kw) {
            if (\mb_strpos($desc, $kw) !== false) {
                return 'urgent'; // equivalent to critical in Tickets
            }
        }

        foreach ($highKeywords as $kw) {
            if (\mb_strpos($desc, $kw) !== false) {
                return 'high';
            }
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
}
