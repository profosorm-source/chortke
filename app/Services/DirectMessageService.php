<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DirectMessage;
use Core\Redis;
use Core\Database;
use App\Services\Settings\AppSettings;
use App\Services\User\UserSettingsService;
use App\Validators\Requests\SendDirectMessageRequest;

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
class DirectMessageService
{
    private DirectMessage $directMessageModel;

    private AppSettings $appSettings;

    private UserSettingsService $userSettingsService;

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

    private \Core\Redis $redis;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Redis $redis,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        DirectMessage $directMessageModel,
        AppSettings $appSettings,
        UserSettingsService $userSettingsService
    )
    {        $this->redis = $redis;
        $this->db = $db;
        $this->logger = $logger;

        
        $this->directMessageModel = $directMessageModel;
        $this->appSettings = $appSettings;
        $this->userSettingsService = $userSettingsService;
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
            $request = new SendDirectMessageRequest([
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'message' => $message,
                'attachments' => $attachments,
                'is_encrypted' => $isEncrypted,
            ]);

            if (!$request->validate()) {
                return ['error' => $this->formatValidationErrors($request->errors())];
            }

            $maxLength = (int)$this->appSettings->get('dm_max_message_length', self::MAX_MESSAGE_LENGTH);
            if (mb_strlen($message) > $maxLength) {
                return ['error' => sprintf('پیام نباید بیش از %d کاراکتر باشد', $maxLength)];
            }

            if ($senderId === $recipientId) {
                return ['error' => 'نمی‌توانید برای خودتان پیام بفرستید'];
            }

            // 🛡️ CRIT-12: بررسی تنظیمات حریم خصوصی
            $allowMessages = $this->userSettingsService->get($recipientId, 'allow_messages', true);

            // 🛡️ BLF-01: بررسی وجود کاربر مقصد و مسدودی دوطرفه با جلوگیری از User Enumeration و برابر شدن زمان پاسخ
            $recipient = $this->directMessageModel->getUserInfo($recipientId);
            $isBlocked = false;
            if ($recipient) {
                $isBlocked = $this->isBlocked($senderId, $recipientId) || $this->isBlocked($recipientId, $senderId);
            }
            
            usleep(random_int(10000, 50000)); // همیشه تاخیر

            if (!$recipient || $isBlocked || !$allowMessages) {
                return ['error' => 'امکان ارسال پیام بین شما و این کاربر وجود ندارد'];
            }

            // 🛡️ CRIT-05: جلوگیری از Race Condition در بررسی محدودیت سرعت پیام‌های رمزشده با Lua Script
            if ($isEncrypted) {
                $encKey = 'rate_limit:messages:encrypted:' . $senderId;
                $lua = <<<LUA
local current = redis.call('INCR', KEYS[1])
if current == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
if current > tonumber(ARGV[2]) then
    return 0
end
return 1
LUA;
                $allowed = $this->redis->eval($lua, [$encKey, 60, 3], 1);
                if (!$allowed) {
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

            // 🛡️ CRITICAL-14: فرار دادن پیام جهت مقابله با حملات Stored XSS پیش از هرگونه رمزگذاری
            $sanitizedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

            // ثبت پیام
            $messageId = $this->directMessageModel->createMessage(
                $senderId,
                $recipientId,
                $isEncrypted ? $this->encryptMessage($sanitizedMessage) : $sanitizedMessage,
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
                    
                    // 🛡️ HIGH-06: بررسی وجود و Magic Bytes فایل
                    $filePath = \function_exists('storage_path') ? storage_path($attachment['path']) : base_path('storage/' . $attachment['path']);
                    if (!file_exists($filePath)) {
                        $this->logger->error('attachment_file_missing', ['path' => $attachment['path']]);
                        return ['error' => 'فایل پیوست در سرور یافت نشد'];
                    }

                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $filePath);
                    finfo_close($finfo);
                    
                    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
                        return ['error' => 'نوع فایل پیوست نامعتبر است'];
                    }
                }
                $this->directMessageModel->addAttachments($messageId, $attachments);
            }

            // 🛡️ HIGH-04: Pessimistic Locking برای جلوگیری از race condition در آپدیت conversation
            $user1 = min($senderId, $recipientId);
            $user2 = max($senderId, $recipientId);
            $this->db->query(
                "SELECT id FROM user_conversations WHERE user1_id = ? AND user2_id = ? FOR UPDATE",
                [$user1, $user2]
            );

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
                    'error_type' => get_class($redisEx)
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
            $this->logger->error('message.send.failed', ['error_type' => get_class($e)]);
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

        $lastSeenKey = "last_seen:{$userId}:{$otherUserId}";
        $currentTime = time();
        $this->redis->setex($lastSeenKey, 60, (string)$currentTime);

        return array_map(function($msg) {
            $msgContent = $msg->message;
            if ($msg->is_encrypted) {
                try {
                    // 🛡️ CRIT-04: اعمال htmlspecialchars بر روی پیام رمزگشایی شده برای مقابله با XSS
                    $msgContent = htmlspecialchars($this->decryptMessage($msg->message), ENT_QUOTES, 'UTF-8');
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
     * علامت‌گذاری پیام‌ها به عنوان خوانده شده
     */
    public function markAsRead(int $userId, int $otherUserId): void
    {
        $unreadCountKey = self::UNREAD_PREFIX . $userId . ':' . $otherUserId;
        if ($this->directMessageModel->hasConversation($userId, $otherUserId)) {
            $this->directMessageModel->markAsRead($userId, $otherUserId);
            $this->redis->del($unreadCountKey);
        }
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

            $emoji = trim($emoji);
            if ($emoji === '' || mb_strlen($emoji, 'UTF-8') > 8) {
                return false;
            }

            if (!preg_match('/^(?:[\x{1F300}-\x{1F6FF}\x{1F900}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F1E6}-\x{1F1FF}\x{FE0F}\x{200D}])+$/u', $emoji)) {
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
        $encryptionKey = $this->appSettings->get('dm_encryption_key');
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
            $encryptionKey = $this->appSettings->get('dm_encryption_key');
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
        $count = $this->incrementRedisCounterWithExpire($key, 60);
        
        // حداکثر 30 پیام در دقیقه
        if ($count > 30) {
            $this->redis->decr($key);
            return false;
        }
        
        return true;
    }

    /**
     * Increment a Redis counter and set TTL only on the first increment.
     */
    private function incrementRedisCounterWithExpire(string $key, int $ttl): int
    {
        try {
            $script = <<<'LUA'
local count = redis.call('INCR', KEYS[1])
if count == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return count
LUA;
            $result = $this->redis->eval($script, [$key, $ttl], 1);
            return is_int($result) ? $result : (int)$result;
        } catch (\Throwable $e) {
            $this->logger->warning('direct_message.redis.counter.failed', [
                'key' => $key,
                'ttl' => $ttl,
                'error' => $e->getMessage()
            ]);
            return 1;
        }
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
        
        // ✅ بررسی کلمات ممنوعه
        $bannedWords = ['viagra', 'casino', 'porn', 'bet', 'قمار', 'شرط‌بندی', 'کازینو'];
        foreach ($bannedWords as $word) {
            if (stripos($message, $word) !== false) {
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
