<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DirectMessage;
use Core\Redis;
use Core\Database;
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
    private Database $db;

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

    public function __construct(DirectMessage $directMessageModel, LoggerInterface $logger, Redis $redis, SettingService $settingService, Database $db)
    {
        parent::__construct($logger);
        $this->directMessageModel = $directMessageModel;
        $this->redis = $redis;
        $this->settingService = $settingService;
        $this->db = $db;
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

            // 🛡️ BLF-01: بررسی مسدودی دوطرفه
            if ($this->isBlocked($senderId, $recipientId) || $this->isBlocked($recipientId, $senderId)) {
                return ['error' => 'امکان ارسال پیام بین شما و این کاربر وجود ندارد'];
            }

            // 🛡️ BLF-02: بررسی محدودیت سرعت پیام‌های رمزشده (اتمیک جهت جلوگیری از Race Condition)
            if ($isEncrypted) {
                $encKey = 'rate_limit:messages:encrypted:' . $senderId;
                $count = (int)$this->redis->incr($encKey);
                if ($count === 1) {
                    $this->redis->expire($encKey, 60);
                }
                if ($count > 3) { // حداکثر 3 پیام رمزگذاری شده در دقیقه
                    $this->redis->decr($encKey);
                    return ['error' => 'محدودیت ارسال پیام‌های رمزشده (حداکثر ۳ در دقیقه). لطفاً کمی صبر کنید'];
                }
            }

            // بررسی محدودیت سرعت (rate limiting) معمولی
            if (!$this->checkRateLimit($senderId)) {
                return ['error' => 'خیلی سریع پیام فرستادید. لطفاً یکی دو ثانیه صبر کنید'];
            }

            // 🛡️ امنیت و فیلترینگ هوشمند (حذف راه‌های ارتباطی خارج سایت)
            if ($this->containsForbiddenContent($message)) {
                $this->logger->warning('message.blocked.content', [
                    'user_id' => $senderId, 
                    'message_hash' => hash('sha256', $message),
                    'message_length' => mb_strlen($message)
                ]);
                return ['error' => 'ارسال هرگونه شماره تماس، آیدی شبکه‌های اجتماعی یا لینک خارجی خلاف قوانین است و مسدود شد.'];
            }

            $this->db->beginTransaction();

            // ثبت پیام
            $messageId = $this->directMessageModel->createMessage(
                $senderId,
                $recipientId,
                $isEncrypted ? $this->encryptMessage($message) : htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
                (bool)$isEncrypted
            );

            if (!$messageId) {
                throw new \Exception('Unable to create direct message');
            }

            // 🛡️ HIGH-06: اعتبارسنجی کامل پیوست‌ها در لایه سرویس
            if (!empty($attachments)) {
                if (count($attachments) > self::MAX_ATTACHMENTS_PER_MESSAGE) {
                    return ['error' => sprintf('حداکثر %d پیوست مجاز است', self::MAX_ATTACHMENTS_PER_MESSAGE)];
                }
                foreach ($attachments as $attachment) {
                    $size = (int)($attachment['size'] ?? 0);
                    if ($size > self::MAX_ATTACHMENT_SIZE) {
                        return ['error' => sprintf('حجم پیوست نمی‌تواند بیش از %d مگابایت باشد', self::MAX_ATTACHMENT_SIZE / (1024 * 1024))];
                    }
                    if (empty($attachment['name']) || empty($attachment['path'])) {
                        return ['error' => 'ساختار پیوست نامعتبر است'];
                    }
                }
                $this->directMessageModel->addAttachments($messageId, $attachments);
            }

            // بروزرسانی conversation
            $this->directMessageModel->updateConversation($senderId, $recipientId, $messageId);

            $this->db->commit();

            // 🛡️ RC-01: عملیات Redis خارج از بلاک تراکنش دیتابیس اجرا شود
            try {
                // شمارشگر پیام‌های خوانده نشده
                $this->redis->incr(self::UNREAD_PREFIX . $recipientId . ':' . $senderId);
            } catch (\Exception $redisEx) {
                $this->logger->warning('message.sent.redis_failed', [
                    'message_id' => $messageId,
                    'error' => $redisEx->getMessage()
                ]);
            }

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
            $this->db->rollBack();
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

        // 🛡️ RC-02 & BLF-03: بررسی خوانده شدن پیام‌ها به صورت Idempotent
        $lastSeenKey = "last_seen:{$userId}:{$otherUserId}";
        $lastSeen = (int)($this->redis->get($lastSeenKey) ?? 0);
        $currentTime = time();
        $unreadCountKey = self::UNREAD_PREFIX . $userId . ':' . $otherUserId;
        $unreadCount = (int)($this->redis->get($unreadCountKey) ?? 0);

        if ($unreadCount > 0 || ($currentTime - $lastSeen > 5)) {
            // 🛡️ HIGH-07: تایید صریح وجود مکالمه فعال قبل از علامت‌گذاری به عنوان خوانده شده جهت ممانعت از نوشتن‌های اضافه در دیتابیس
            if ($this->directMessageModel->hasConversation($userId, $otherUserId)) {
                $this->directMessageModel->markAsRead($userId, $otherUserId);
                $this->redis->del($unreadCountKey);
                $this->redis->setex($lastSeenKey, 60, (string)$currentTime);
            }
        }

        return array_map(function($msg) {
            $msgContent = $msg->message;
            if ($msg->is_encrypted) {
                try {
                    $msgContent = $this->decryptMessage($msg->message);
                } catch (\Throwable $e) {
                    $msgContent = '[رمزگشایی ناموفق]';
                }
            }
            return [
                'id' => $msg->id,
                'sender_id' => $msg->sender_id,
                'sender_name' => $msg->sender_name,
                'message' => $msgContent,
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
            // 🛡️ PRIV-01: مخفی کردن متن پیام‌های رمزنگاری شده و محدود کردن طول متن
            $lastMessage = $conv->last_message ?? '';
            $isEncrypted = (bool)($conv->is_encrypted ?? false);

            if ($isEncrypted) {
                $lastMessage = '[پیام رمزشده]';
            } else {
                $lastMessage = mb_substr($lastMessage, 0, 50) . (mb_strlen($lastMessage) > 50 ? '...' : '');
            }

            return [
                'user_id' => $conv->user_id,
                'full_name' => $conv->full_name,
                'avatar' => $conv->avatar,
                'last_message' => $lastMessage,
                'is_encrypted' => $isEncrypted,
                'last_message_at' => $conv->last_message_at,
                'unread_count' => (int)($conv->unread_count ?? 0)
            ];
        }, $conversations);
    }

    /**
     * دریافت اطلاعات کاربر
     */
    public function getUserInfo(int $userId, ?int $requesterId = null): ?array
    {
        $user = $this->directMessageModel->getUserInfo($userId);

        usleep(random_int(10000, 50000)); // 10-50ms random delay

        if (!$user) {
            return null;
        }

        // 🛡️ PRIV-02: ممانعت از افشای آنلاین بودن کاربر مگر اینکه مکالمه قبلی بین آن‌ها وجود داشته باشد
        $isOnline = false;
        if ($requesterId && $requesterId !== $userId) {
            if ($this->directMessageModel->hasConversation($requesterId, $userId)) {
                $isOnline = (bool) $user->is_online;
            }
        } elseif ($requesterId === $userId) {
            $isOnline = (bool) $user->is_online;
        }

        // 🛡️ BLF-06: بررسی مسدودی در اطلاعات کاربر دریافتی
        $isBlocked = false;
        if ($requesterId !== null) {
            $isBlocked = $this->isBlocked($userId, $requesterId) || $this->isBlocked($requesterId, $userId);
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'avatar' => $user->avatar,
            'is_online' => $isOnline,
            'is_blocked' => $isBlocked
        ];
    }

    /**
     * Typing indicator - نمایش "در حال نوشتن"
     */
    public function setTyping(int $userId, int $recipientId, bool $isTyping = true): void
    {
        // 🛡️ بررسی اینکه آیا مکالمه فعال مجاز است قبل از ثبت
        $hasConversation = $this->directMessageModel->hasConversation($userId, $recipientId);
        if (!$hasConversation) {
            return;
        }

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
        
        // 🛡️ PERF-03: محدود کردن تعداد اسکن در ردیس جهت پیشگیری از حملات منع سرویس
        $keys = $this->redis->scanKeys($pattern, 10, 10);

        $typingUsers = [];
        foreach ($keys as $key) {
            $otherUserId = (int)explode(':', $key)[2];
            
            if ($this->redis->get($key) === '1') {
                // 🛡️ BLF-05: بررسی کنید که مکالمه فعال بین دو کاربر واقعاً وجود داشته باشد
                $hasConversation = $this->directMessageModel->hasConversation($userId, $otherUserId);
                if ($hasConversation) {
                    $typingUsers[] = $otherUserId;
                }
            }
        }

        return $typingUsers;
    }

    /**
     * بررسی وجود مکالمه فعال بین دو کاربر
     */
    public function hasConversation(int $userId, int $otherUserId): bool
    {
        return $this->directMessageModel->hasConversation($userId, $otherUserId);
    }

    /**
     * پاک کردن پیام
     */
    public function deleteMessage(int $messageId, int $userId): bool
    {
        try {
            $message = $this->directMessageModel->findMessageById($messageId);

            // 🛡️ HIGH-05: بررسی هویت فرستنده جهت ممانعت از حذف پیام‌های طرف مقابل (IDOR)
            if (!$message || (int)$message->sender_id !== $userId) {
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
            $message = $this->directMessageModel->findMessageById($messageId);
            if (!$message || ((int)$message->sender_id !== $userId && (int)$message->recipient_id !== $userId)) {
                return false;
            }
            return $this->directMessageModel->addReaction($messageId, $userId, $emoji);
        } catch (\Exception $e) {
            $this->logger->error('reaction.add.failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * رمزنگاری پیام با استفاده از Sodium
     */
    private function encryptMessage(string $message): string
    {
        // 🛡️ HIGH-15: ممانعت از ذخیره‌سازی پیام‌ها با کلیدهای پیش‌فرض موقت و بازگشت خطای امنیتی صریح
        $encryptionKey = $this->settingService->get('dm_encryption_key');
        if (!$encryptionKey) {
            $this->logger->critical('dm.encryption.key.missing', ['error' => 'Settings key dm_encryption_key is missing']);
            throw new \RuntimeException('تنظیمات کلید رمزنگاری پیام‌ها یافت نشد.');
        }
        
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = sodium_crypto_secretbox($message, $nonce, base64_decode($encryptionKey));
        
        return base64_encode($nonce . $encrypted);
    }

    /**
     * رفع رمزنگاری پیام با استفاده از Sodium
     */
    private function decryptMessage(string $encrypted): string
    {
        try {
            // 🛡️ HIGH-15: ممانعت از بازگشایی پیام‌ها با کلیدهای پیش‌فرض موقت و بازگشت خطای امنیتی صریح
            $encryptionKey = $this->settingService->get('dm_encryption_key');
            if (!$encryptionKey) {
                $this->logger->critical('dm.encryption.key.missing', ['error' => 'Settings key dm_encryption_key is missing']);
                throw new \RuntimeException('تنظیمات کلید رمزنگاری پیام‌ها یافت نشد.');
            }
            
            $decoded = base64_decode($encrypted);
            if ($decoded === false) {
                return '[خطا در دیکریپت]';
            }
            $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
            $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
            
            $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, base64_decode($encryptionKey));
            
            return $decrypted !== false ? $decrypted : '[خطا در دیکریپت]';
        } catch (\Exception $e) {
            $this->logger->error('message.decrypt.failed', ['error' => $e->getMessage()]);
            return '[خطا در دیکریپت]';
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
        $key = 'rate_limit:messages:send:' . $userId;
        
        // 🛡️ MED-14: استفاده از منطق افزایش اتمیک در ردیس جهت مسدودسازی کامل شرایط رقابتی (TOCTOU)
        $count = (int)$this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, 60);
        }
        
        // حداکثر 30 پیام در دقیقه
        if ($count > 30) {
            $this->redis->decr($key);
            return false;
        }
        
        return true;
    }

    public function getUnreadCount(int $userId, ?int $fromUserId = null): int
    {
        // Try Redis first
        $redisAvailable = false;
        try {
            $redisAvailable = $this->redis && $this->redis->isAvailable();
        } catch (\Throwable $e) {}

        if ($fromUserId) {
            if ($redisAvailable) {
                try {
                    $key = self::UNREAD_PREFIX . $userId . ':' . $fromUserId;
                    return (int)($this->redis->get($key) ?? 0);
                } catch (\Exception $e) {
                    $this->logger->warning('unread.redis.failed', ['error' => $e->getMessage()]);
                }
            }
            return $this->directMessageModel->countUnread($userId, $fromUserId);
        }

        // Cache در memory
        static $cache = [];
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }
        
        if ($redisAvailable) {
            $pattern = self::UNREAD_PREFIX . $userId . ':*';
            try {
                $keys = $this->redis->scanKeys($pattern, 100, 50);
                
                $total = 0;
                foreach ($keys as $key) {
                    $total += (int)($this->redis->get($key) ?? 0);
                }
                
                $cache[$userId] = $total;
                return $total;
            } catch (\Exception $e) {
                $this->logger->warning('unread.redis.failed', ['error' => $e->getMessage()]);
            }
        }
        
        // Fallback to database
        $count = $this->directMessageModel->countUnread($userId);
        $cache[$userId] = $count;
        
        return $count;
    }

    /**
     * 🛡️ بررسی محتوای ممنوعه (لینک، شماره، آیدی)
     */
    private function containsForbiddenContent(string $message): bool
    {
        // Normalize
        $normalized = $this->normalizeUnicode($message);
        $normalizedEng = $this->convertToEnglishDigits($normalized);
        
        $cleaned = preg_replace('/[\s\-\._]+/', '', $normalized);
        $cleanedEng = $this->convertToEnglishDigits($cleaned);
        
        $patternsWithBoundaries = [
            '/\b0?9\d{9}\b/u',  // Mobile
            '/@[a-zA-Z0-9_]{3,}/u',  // Username
            '/\b(telegram|whatsapp|instagram|viber|rubika|gap|eitaa|soroush|bale)\b/iu',
            '/(https?|hxxp|h\[tt\]p):\/\//iu',
            '/\b[a-z0-9\-]+\.(com|ir|org|net|co|me|io)\b/iu',
            '/\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b/iu', // Email
        ];
        
        foreach ($patternsWithBoundaries as $pattern) {
            if (preg_match($pattern, $normalizedEng)) {
                return true;
            }
        }
        
        $patternsWithoutBoundaries = [
            '/0?9\d{9}/u',
            '/@[a-zA-Z0-9_]{3,}/u',
            '/(telegram|whatsapp|instagram|viber|rubika|gap|eitaa|soroush|bale)/iu',
            '/(https?|hxxp|h\[tt\]p)/iu',
            '/[a-z0-9\-]+\.(com|ir|org|net|co|me|io)/iu',
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/iu',
        ];
        
        foreach ($patternsWithoutBoundaries as $pattern) {
            if (preg_match($pattern, $cleanedEng)) {
                return true;
            }
        }
        
        return false;
    }

    private function normalizeUnicode(string $text): string
    {
        // 🛡️ HIGH-16: فیلتر کردن بای‌پس‌های هوموگرافیک و یونیکد با تجزیه نویسه‌های یونیکد به اشکال سازگار و استاندارد دکامپوز شده
        if (class_exists('\Normalizer')) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KD) ?: $text;
        }
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);
        $text = str_replace(['＠', '．'], ['@', '.'], $text);
        return $text;
    }

    private function convertToEnglishDigits(string $text): string
    {
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $english = ['0','1','2','3','4','5','6','7','8','9'];
        
        $text = str_replace($persian, $english, $text);
        $text = str_replace($arabic, $english, $text);
        return $text;
    }
}
