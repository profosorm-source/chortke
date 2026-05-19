<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\User\BaseUserController;
use App\Services\DirectMessageService;

/**
 * MessageController - مدیریت پیام‌های مستقیم کاربران
 */
class MessageController extends BaseUserController
{
    private DirectMessageService $messageService;
    private \App\Services\UploadService $uploadService;

    public function __construct(
        DirectMessageService $messageService,
        \App\Services\UploadService $uploadService,
        \Core\Session $session,
        \Core\Request $request,
        \Core\Response $response,
        \App\Services\Shared\PolicyService $policyService,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Auth\AuthService $authService,
        \App\Services\User\UserService $userService,
        \App\Services\CaptchaService $captchaService
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger, $authService, $userService, $captchaService);
        $this->messageService = $messageService;
        $this->uploadService = $uploadService;
    }

    /**
     * لیست conversations
     */
    public function index(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();
            $page = (int)($this->request->query('page') ?? 1);
            $limit = 20;
            $offset = ($page - 1) * $limit;

            $conversations = $this->messageService->getConversations($userId, $limit, $offset);
            $unreadTotal = $this->messageService->getUnreadCount($userId);

            $this->view('user/messages/index', [
                'conversations' => $conversations,
                'unread_total' => $unreadTotal,
                'page' => $page
            ]);

        } catch (\Exception $e) {
            $this->logger->error('messages.index.failed', ['error' => $e->getMessage()]);
            $this->response->error('خطا در بارگذاری پیام‌ها');
        }
    }

    /**
     * نمایش conversation
     */
    public function show(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();
            $otherUserId = (int)$this->request->param('id');
            $page = (int)($this->request->query('page') ?? 1);
            $limit = 50;
            $offset = ($page - 1) * $limit;

            if ($userId === $otherUserId) {
                $this->response->error('نمی‌توانید برای خودتان پیام بفرستید');
                return;
            }

            $otherUser = $this->messageService->getUserInfo($otherUserId, $userId);

            if (!$otherUser) {
                $this->response->error('مکالمه‌ای با کاربر مورد نظر یافت نشد.', [], 404);
                return;
            }

            // 🛡️ HIGH-06: جلوگیری از نمایش مکالمه‌های خالی یا ثبت سوابق نامطلوب بدون تاریخچه معتبر بین طرفین
            if (!$this->messageService->hasConversation($userId, $otherUserId)) {
                $this->response->error('مکالمه‌ای با کاربر مورد نظر یافت نشد.', [], 404);
                return;
            }

            $messages = $this->messageService->getConversation(
                $userId,
                $otherUserId,
                $limit,
                $offset
            );

            $this->view('user/messages/show', [
                'messages' => $messages,
                'other_user' => $otherUser,
                'page' => $page
            ]);

        } catch (\Exception $e) {
            $this->logger->error('messages.show.failed', ['error' => $e->getMessage()]);
            $this->response->error('خطا در بارگذاری conversation');
        }
    }

    /**
     * ارسال پیام
     */
    public function send(): void
    {
        $this->requireAuth();
        $this->validateCsrf();

        try {
            $userId = $this->userId();
            $recipientId = (int)$this->request->input('recipient_id');
            $message = trim($this->request->input('message'));
            $isEncrypted = (bool)$this->request->input('is_encrypted', false);

            // اعتبارسنجی
            if (empty($message)) {
                $this->jsonError('پیام نمی‌تواند خالی باشد', [], 422);
                return;
            }

            if (mb_strlen($message) > 5000) {
                $this->jsonError('متن پیام نمی‌تواند بیش از ۵۰۰۰ کاراکتر باشد', [], 422);
                return;
            }

            // Attachments
            $attachments = [];
            if ($this->request->hasFiles('attachments')) {
                $attachments = $this->handleAttachments($this->request->files('attachments'));
            }

            // ارسال پیام
            $result = $this->messageService->sendMessage(
                $userId,
                $recipientId,
                $message,
                $attachments,
                $isEncrypted
            );

            if (isset($result['error'])) {
                $this->jsonError($result['error'], [], 422);
                return;
            }

            $this->logger->info('message.sent_by_user', [
                'user_id' => $userId,
                'recipient_id' => $recipientId,
                'message_id' => $result['message_id']
            ]);

            $this->jsonSuccess('', $result);

        } catch (\Exception $e) {
            $this->logger->error('message.send.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا در ارسال پیام', [], 500);
        }
    }

    /**
     * typing indicator
     */
    public function setTyping(): void
    {
        $this->requireAuth();
        $this->validateCsrf();

        try {
            $userId = $this->userId();
            $recipientId = (int)$this->request->input('recipient_id');
            $isTyping = (bool)$this->request->input('is_typing', true);

            // 🛡️ NEW-15: اعمال محدودیت نرخ بروزرسانی وضعیت تایپ (محدودیت ۲۰ درخواست در دقیقه جهت جلوگیری از فالس پوزیتیو)
            $rateLimiter = app(\Core\RateLimiter::class);
            $rateLimitId = "typing_limit:" . $userId;
            if (!$rateLimiter->attempt($rateLimitId, 20, 60, true)) {
                $this->jsonError('تعداد درخواست‌های تایپ بیش از حد مجاز است.', [], 429);
                return;
            }

            $this->messageService->setTyping($userId, $recipientId, $isTyping);

            $this->jsonSuccess('', ['ok' => true]);
        } catch (\Exception $e) {
            $this->logger->error('typing.set.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا', [], 500);
        }
    }

    /**
     * دریافت typing users
     */
    public function getTypingUsers(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();

            $typingUsers = $this->messageService->getTypingUsers($userId);

            $this->jsonSuccess('', [
                'typing_users' => $typingUsers,
                'count' => count($typingUsers)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('typing.get.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا', [], 500);
        }
    }

    /**
     * حذف پیام
     */
    public function delete(): void
    {
        $this->requireAuth();

        try {
            // CORE-036: CSRF Protection
            $this->validateCsrf();

            $userId = $this->userId();
            $messageId = (int)$this->request->param('id');

            $success = $this->messageService->deleteMessage($messageId, $userId);

            if (!$success) {
                $this->jsonError('پیام یافت نشد', [], 404);
                return;
            }

            $this->jsonSuccess('پیام با موفقیت حذف شد', ['ok' => true]);
        } catch (\Exception $e) {
            $this->logger->error('message.delete.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا در حذف پیام', [], 500);
        }
    }

    /**
     * اضافه کردن reaction
     */
    public function addReaction(): void
    {
        $this->requireAuth();
        $this->validateCsrf();

        try {
            $userId = $this->userId();
            $messageId = (int)$this->request->param('id');
            $emoji = trim((string)$this->request->input('emoji'));

            // 🛡️ NEW-14: اعتبارسنجی ورودی واکنش‌ها بر اساس لیست سفید اموجی‌های مجاز
            $allowedEmojis = ['👍', '❤️', '😂', '😮', '😢', '🙏', '👏', '🎉'];
            if (!in_array($emoji, $allowedEmojis, true)) {
                $this->jsonError('واکنش نامعتبر است.', [], 422);
                return;
            }

            $success = $this->messageService->addReaction($messageId, $userId, $emoji);

            if (!$success) {
                $this->jsonError('خطا در اضافه کردن reaction', [], 422);
                return;
            }

            $this->jsonSuccess('واکنش با موفقیت ثبت شد', ['ok' => true]);
        } catch (\Exception $e) {
            $this->logger->error('reaction.add.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا', [], 500);
        }
    }

    /**
     * مدیریت پیوست‌ها با استفاده از UploadService
     */
    private function handleAttachments(array $files): array
    {
        $attachments = [];
        
        // اگر چند فایل باشد
        if (isset($files['name']) && !is_array($files['name'])) {
            $files = [$files];
        }

        foreach ($files as $file) {
            $result = $this->uploadService->upload(
                $file, 
                'messages', 
                ['jpg', 'png', 'jpeg', 'pdf', 'zip'], 
                10 * 1024 * 1024 // 10MB
            );

            if ($result['success']) {
                $attachments[] = [
                    'filename' => $file['name'],
                    'file_path' => $result['path'],
                    'file_size' => $file['size'],
                    'mime_type' => $file['type']
                ];
            }
        }

        return $attachments;
    }

    /**
     * دریافت تعداد پیام‌های خوانده نشده
     */
    public function getUnreadCount(): void
    {
        $this->requireAuth();

        try {
            $userId = $this->userId();
            $count = $this->messageService->getUnreadCount($userId);

            $this->jsonSuccess('', ['count' => $count]);
        } catch (\Exception $e) {
            $this->logger->error('unread.count.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا', [], 500);
        }
    }

    /**
     * علامت‌گذاری پیام‌ها به عنوان خوانده شده (HIGH-03)
     */
    public function markRead(): void
    {
        $this->requireAuth();
        $this->validateCsrf();

        try {
            $otherUserId = (int)$this->request->input('user_id');
            if ($otherUserId <= 0) {
                $this->jsonError('شناسه کاربر نامعتبر است', [], 400);
                return;
            }

            $this->messageService->markAsRead($this->userId(), $otherUserId);
            $this->jsonSuccess('پیام‌ها به عنوان خوانده شده علامت‌گذاری شدند');
        } catch (\Exception $e) {
            $this->logger->error('mark.read.failed', ['error' => $e->getMessage()]);
            $this->jsonError('خطا در علامت‌گذاری پیام‌ها', [], 500);
        }
    }

}