<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DirectMessage;
use Core\Redis;
use App\Services\SettingService;

use App\Contracts\LoggerInterface;
/**
 * DirectMessageService - سیستم پیام‌رسانی مستقیم
 *
 * قابلیت‌ها:
 * - ارسال/دریافت پیام‌های مستقیم
 * - رمزنگاری پیام‌های حساس
 * - نمایش وضعیت (typing indicator)
 * - وضعیت خواندن پیام
 * - پیوست‌های فایل
 * - واکنش‌های emoji
 * - Conversation management
 */
class DirectMessageService extends \App\Services\BaseService
{
    private DirectMessage $directMessageModel;
    private Redis $redis;
    private SettingService $settingService;

    // محدودیت‌های سرویس
    private const MAX_MESSAGE_LENGTH = 5000;
    private const MAX_ATTACHMENT_SIZE = 10 * 1024 * 1024; // 10MB
    private const MAX_ATTACHMENTS_PER_MESSAGE = 5;
    private const MESSAGE_RETENTION_DAYS = 90;
    private const TYPING_INDICATOR_TIMEOUT = 3; // ثانیه

    // کلیدهای Redis
    private const CONVERSATION_PREFIX = 'conversation:';
    private const TYPING_PREFIX = 'typing:';
    private const UNREAD_PREFIX = 'unread:';

    public function __construct(DirectMessage $directMessageModel, LoggerInterface $logger, Redis $redis, SettingService $settingService)
    {
        parent::__construct($logger);
        $this->directMessageModel = $directMessageModel;
        $this->redis = $redis;
        $this->settingService = $settingService;
    }

    /**
     * ارسال پیام جدید
     */
    public function sendMessage(
        int $senderId,
        int $recipientId,
        string $message,
        ?array $attachments = null,
        ?bool $isEncrypted = false
    ): array {
        try {
            // اعتبارسنجی
            if (empty(trim($message))) {
                return ['error' => 'پیام نمی‌تواند خالی باشد'];
            }

            $maxLength = (int)$this->settingService->get('dm_max_message_length', self::MAX_MESSAGE_LENGTH);
            if (mb_strlen($message) > $maxLength) {
                return ['error' => sprintf('پیام نباید بیش از %d کاراکتر باشد', $maxLength)];
            }

            if ($senderId === $recipientId) {
                return ['error' => 'نمی‌توانید برای خودتان پیام بفرستید'];
            }

            // بررسی مسدودی
            if ($this->isBlocked($senderId, $recipientId)) {
                return ['error' => 'این کاربر شما را مسدود کرده است'];
            }

            // بررسی محدودیت سرعت (rate limiting)
            if (!$this->checkRateLimit($senderId)) {
                return ['error' => 'خیلی سریع پیام فرستادید. لطفاً یکی دو ثانیه صبر کنید'];
            }

            // 🛡️ امنیت و فیلترینگ هوشمند (حذف راه‌های ارتباطی خارج سایت)
            if ($this->containsForbiddenContent($message)) {
                $this->logger->warning('message.blocked.content', ['user_id' => $senderId, 'message' => $message]);
                return ['error' => 'ارسال هرگونه شماره تماس، آیدی شبکه‌های اجتماعی یا لینک خارجی خلاف قوانین است و مسدود شد.'];
            }

            $this->directMessageModel->beginTransaction();

            // ثبت پیام
            $messageId = $this->directMessageModel->createMessage(
                $senderId,
                $recipientId,
                $isEncrypted ? $this->encryptMessage($message) : $message,
                (bool)$isEncrypted
            );

            if (!$messageId) {
                throw new \Exception('Unable to create direct message');
            }

            // پیوست‌ها
            if (!empty($attachments)) {
                $this->directMessageModel->addAttachments($messageId, $attachments);
            }

            // بروزرسانی conversation
            $this->directMessageModel->updateConversation($senderId, $recipientId, $messageId);

            // شمارشگر پیام‌های خوانده نشده
            $this->redis->incr(self::UNREAD_PREFIX . $recipientId . ':' . $senderId);

            $this->directMessageModel->commit();

            $this->logger->info('message.sent', [
                'message_id' => $messageId,
                'sender_id' => $senderId,
                'recipient_id' => $recipientId
            ]);

            return [
                'success' => true,
                'message_id' => $messageId,
                'created_at' => date('Y-m-d H:i:s')
            ];

        } catch (\Exception $e) {
            $this->directMessageModel->rollback();
            $this->logger->error('message.send.failed', ['error' => $e->getMessage()]);
            return ['error' => 'خطا در ارسال پیام'];
        }
    }

    /**
     * دریافت پیام‌های conversation
     */
    public function getConversation(
        int $userId,
        int $otherUserId,
        int $limit = 50,
        int $offset = 0
    ): array {
        $messages = $this->directMessageModel->getConversation($userId, $otherUserId, $limit, $offset);

        // mark as read
        $this->directMessageModel->markAsRead($userId, $otherUserId);
        $this->redis->del(self::UNREAD_PREFIX . $userId . ':' . $otherUserId);

        return array_map(function($msg) {
            return [
                'id' => $msg->id,
                'sender_id' => $msg->sender_id,
                'sender_name' => $msg->sender_name,
                'message' => $msg->is_encrypted ? $this->decryptMessage($msg->message) : $msg->message,
                'is_encrypted' => (bool)$msg->is_encrypted,
                'attachment_count' => $msg->attachment_count,
                'created_at' => $msg->created_at,
                'read_at' => $msg->read_at
            ];
        }, array_reverse($messages));
    }

    /**
     * لیست conversations کاربر
     */
    public function getConversations(int $userId, int $limit = 20, int $offset = 0): array
    {
        $conversations = $this->directMessageModel->getConversations($userId, $limit, $offset);

        return array_map(function($conv) {
            return [
                'user_id' => $conv->user_id,
                'user_name' => $conv->full_name,
                'user_avatar' => $conv->avatar,
                'last_message' => $conv->last_message,
                'last_message_at' => $conv->last_message_at,
                'unread_count' => (int)($conv->unread_count ?? 0)
            ];
        }, $conversations);
    }

    /**
     * دریافت اطلاعات کاربر
     */
    public function getUserInfo(int $userId): ?array
    {
        $user = $this->directMessageModel->getUserInfo($userId);

        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'avatar' => $user->avatar,
            'is_online' => (bool) $user->is_online
        ];
    }

    /**
     * Typing indicator - نمایش "در حال نوشتن"
     */
    public function setTyping(int $userId, int $recipientId, bool $isTyping = true): void
    {
        $key = self::TYPING_PREFIX . $recipientId . ':' . $userId;

        if ($isTyping) {
            $this->redis->setex($key, self::TYPING_INDICATOR_TIMEOUT, '1');
        } else {
            $this->redis->del($key);
        }
    }

    /**
     * چک کردن کسانی که در حال نوشتن هستند
     */
    public function getTypingUsers(int $userId): array
    {
        $pattern = self::TYPING_PREFIX . $userId . ':*';
        // ✅ Using scanKeys() instead of keys() for performance
        $keys = $this->redis->scanKeys($pattern);

        $typingUsers = [];
        foreach ($keys as $key) {
            $userId = explode(':', $key)[2];
            $typingUsers[] = (int)$userId;
        }

        return $typingUsers;
    }

    /**
     * پاک کردن پیام
     */
    public function deleteMessage(int $messageId, int $userId): bool
    {
        try {
            $message = $this->directMessageModel->findMessageById($messageId);

            if (!$message || ($message->sender_id !== $userId && $message->recipient_id !== $userId)) {
                return false;
            }

            $deleted = $this->directMessageModel->softDeleteMessage($messageId, $userId);

            if ($deleted) {
                $this->logger->info('message.deleted', ['message_id' => $messageId, 'user_id' => $userId]);
            }

            return $deleted;

        } catch (\Exception $e) {
            $this->logger->error('message.delete.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * واکنش emoji
     */
    public function addReaction(int $messageId, int $userId, string $emoji): bool
    {
        try {
            return $this->directMessageModel->addReaction($messageId, $userId, $emoji);
        } catch (\Exception $e) {
            $this->logger->error('reaction.add.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * رمزنگاری پیام
     */
    private function encryptMessage(string $message): string
    {
        // استفاده از encryption ساده برای نمونه
        // در تولید، باید از یک روش قوی استفاده شود
        return base64_encode($message);
    }

    /**
     * رفع رمزنگاری پیام
     */
    private function decryptMessage(string $encrypted): string
    {
        try {
            return base64_decode($encrypted);
        } catch (\Exception $e) {
            return '[رمزنگاری شده - نمی‌توان رمزگشایی کرد]';
        }
    }

    /**
     * بررسی مسدودی
     */
    private function isBlocked(int $userId, int $blockedUserId): bool
    {
        return $this->directMessageModel->isBlocked($userId, $blockedUserId);
    }

    /**
     * چک rate limiting
     */
    private function checkRateLimit(int $userId): bool
    {
        $key = 'rate_limit:messages:' . $userId;
        $currentCount = (int)($this->redis->get($key) ?? 0);

        $limit = (int)$this->settingService->get('dm_rate_limit_per_min', 10);
        if ($currentCount >= $limit) { // دینامیک پیام در دقیقه
            return false;
        }

        $this->redis->incr($key);
        $this->redis->expire($key, 60);

        return true;
    }

    /**
     * تعداد پیام‌های خوانده نشده
     */
    public function getUnreadCount(int $userId, ?int $fromUserId = null): int
    {
        if ($fromUserId) {
            $key = self::UNREAD_PREFIX . $userId . ':' . $fromUserId;
            return (int)($this->redis->get($key) ?? 0);
        }

        return $this->directMessageModel->countUnread($userId);
    }

    /**
     * 🛡️ بررسی محتوای ممنوعه (لینک، شماره، آیدی)
     */
    private function containsForbiddenContent(string $msg): bool
    {
        $msg = mb_strtolower($msg, 'UTF-8');
        // تبدیل اعداد فارسی و عربی به انگلیسی برای تشخیص دقیق‌تر
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $english = ['0','1','2','3','4','5','6','7','8','9'];
        $msg = str_replace($persian, $english, $msg);
        $msg = str_replace($arabic, $english, $msg);

        $patterns = [
            'url'    => '/https?:\/\/[^\s]+|\b[a-z0-9.-]+\.(ir|com|org|net|biz|info|me|online|tk)\b/i', // آدرس‌های وب
            'phone'  => '/(\+?98|0)?9\d{9}/', // شماره موبایل ایران
            'generic'=> '/\d{10,12}/', // اعداد متوالی شبیه شماره تماس
            'email'  => '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', // ایمیل
            'id'     => '/@[a-z0-9_]{4,}/i', // آیدی شبکه‌ها مثل تلگرام
            'tme'    => '/t\.me\/|instagram\.com\//i', // دامنه‌های خاص شبکه اجتماعی
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $msg)) {
                return true;
            }
        }
        return false;
    }
}
