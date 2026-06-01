<?php
namespace Core;

/**
 * Event Dispatcher
 * 
 * مدیریت رویدادها و شنوندگان
 */
class EventDispatcher
{
    private static $instance = null;
    private $listeners = [];
    private $patternListeners = [];  // Store pattern-based listeners for wildcard support
    private array $bootstrapListeners = [];
    private Queue $queue;
    private ?\App\Services\AuditTrail $auditTrail = null;

    public function __construct(Queue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * دریافت Instance (Singleton)
     * Queue را از Container تزریق شده دریافت می‌کند
     */
    /**
     * دریافت Instance (Singleton)
     * M22 Fix: واگذاری و تکیه صددرصدی به Container رسمی پروژه برای تزریق وابستگی‌ها (Pure DI)
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $container = Container::getInstance();
            
            if ($container->has(self::class)) {
                self::$instance = $container->make(self::class);
            } else {
                // ساخت داینامیک با کانتینر و ثبت به عنوان تک‌عضو (Singleton) سراسری
                $instance = $container->make(self::class);
                $container->instance(self::class, $instance);
                self::$instance = $instance;
            }
        }
        
        return self::$instance;
    }

    /**
     * ثبت Listener (مستقیم بر اساس نام دقیق رویداد)
     */
    public function listen($eventName, $listener, $priority = 0)
    {
        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }

        // بررسی یکتا بودن Listener برای جلوگیری از تجمع حافظه (Memory Leak)
        foreach ($this->listeners[$eventName] as $existing) {
            if ($existing['listener'] === $listener) {
                return; // از قبل ثبت شده است، دوباره ثبت نکن
            }
        }
        
        $this->listeners[$eventName][] = [
            'listener' => $listener,
            'priority' => $priority
        ];
        
        // مرتب‌سازی بر اساس اولویت
        usort($this->listeners[$eventName], function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
    }

    /**
     * ثبت Listener بر اساس الگوی wildcard (مثل: wallet.*, *.revenue.*)
     * از fnmatch() استفاده می‌کند برای مطابقت‌دهی الگو.
     * Listeners منطبق فقط برای رویدادهای جدید اجرا می‌شوند، نه برای قدیمی‌ها.
     */
    public function listenPattern(string $pattern, $listener, $priority = 0)
    {
        if (!isset($this->patternListeners[$pattern])) {
            $this->patternListeners[$pattern] = [];
        }

        foreach ($this->patternListeners[$pattern] as $existing) {
            if ($existing['listener'] === $listener) {
                return;
            }
        }

        $this->patternListeners[$pattern][] = [
            'listener' => $listener,
            'priority' => $priority
        ];

        usort($this->patternListeners[$pattern], function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
    }

    /**
     * ثبت شنوندگان پایه به عنوان مرجع برای ریست کردن (Snapshot)
     */
    public function snapshotBootstrapState(): void
    {
        $this->bootstrapListeners = $this->listeners;
    }

    /**
     * ریست کردن تمامی شنونده‌ها به حالت پیش‌فرضِ اولیه‌ی بوت‌استرپ
     */
    public function restoreBootstrapState(): void
    {
        // بازگردانی شنونده‌ها به حالت اولیه (حذف هرگونه Closure اضافه شده در Job)
        $this->listeners = $this->bootstrapListeners;
        
        // نمی‌خواهیم الگوی listeners را ریست کنیم؛ آنها runtime pattern listeners هستند
        // و باید برای تمام درخواست‌های بعدی فعال بمانند.
        // $this->patternListeners = [];
        
        // پاکسازی وابستگی‌های کش شده داخل این سینگلتون برای جلوگیری از نشت مموری
        $this->auditTrail = null;
    }

    /**
     * ارسال رویداد
     */
    public function dispatch($eventName, $event = null)
    {
        // جمع‌آوری تمام Listeners: هم دقیق و هم الگو‌based
        $allListeners = [];

        // Exact listeners
        if (isset($this->listeners[$eventName])) {
            $allListeners = array_merge($allListeners, $this->listeners[$eventName]);
        }

        // Pattern-based listeners (خیلی جستجو می‌شود نه efficient نیست اما انعطاف‌پذیر)
        foreach ($this->patternListeners as $pattern => $listeners) {
            if (fnmatch($pattern, $eventName)) {
                $allListeners = array_merge($allListeners, $listeners);
            }
        }

        // اگر هیچ Listener نبود
        if (empty($allListeners)) {
            return;
        }

        // اگر Event شیء نبود، آن را به آرایه تبدیل کن
        if (!$event instanceof Event) {
            $event = new GenericEvent($event);
        }
        
        foreach ($allListeners as $item) {
            $listener = $item['listener'];
            
            $startTime = microtime(true);
            try {
                // اجرای Listener
                if (is_callable($listener)) {
                    $listener($event);
                } elseif (is_string($listener) && class_exists($listener)) {
                    $container = Container::getInstance();
                    $listenerInstance = $container->has($listener) ? $container->make($listener) : new $listener();
                    if (method_exists($listenerInstance, 'handle')) {
                        $listenerInstance->handle($event);
                    }
                } elseif (is_array($listener) && isset($listener[0]) && is_string($listener[0]) && class_exists($listener[0])) {
                    $container = Container::getInstance();
                    $listenerInstance = $container->has($listener[0]) ? $container->make($listener[0]) : new $listener[0]();
                    $methodName = $listener[1] ?? 'handle';
                    if (method_exists($listenerInstance, $methodName)) {
                        $listenerInstance->$methodName($event);
                    }
                }
            } catch (\Throwable $e) {
                $listenerName = is_string($listener) ? $listener : (is_array($listener) && isset($listener[0]) && is_string($listener[0]) ? $listener[0] : 'closure');
                
                // 1. لاگ کردن در سامانه لاگ اصلی (ایمن شده در برابر خطای خود لاگر)
                try {
                    if (isset($this->logger)) { $this->logger->error('event.listener_failed', [
                            'event' => $eventName,
                            'listener' => $listenerName,
                            'error' => $e->getMessage()
                        ]); }
                } catch (\Throwable $logEx) {
                    error_log("Failed to write to primary logger: " . $logEx->getMessage());
                }

                // 2. ثبت در Dead-Letter / Event Failure Log اختصاصی دیتابیس برای بازیابی و تحلیل
                try {
                    $container = Container::getInstance();
                    if ($container->has(Database::class)) {
                        $db = $container->make(Database::class);
                        $db->execute("
                            INSERT INTO event_failures (event_name, listener, payload, error_message, failed_at)
                            VALUES (?, ?, ?, ?, NOW())
                        ", [
                            $eventName,
                            $listenerName,
                            json_encode($event->getData(), JSON_UNESCAPED_UNICODE),
                            $e->getMessage() . "\n" . $e->getTraceAsString()
                        ]);
                    }
                } catch (\Throwable $dbEx) {
                    // در صورت خطای دیتابیس، مانع از انتشار بقیه لیسنرها نشود (ایزولاسیون کامل)
                    error_log("Failed to write to event_failures table: " . $dbEx->getMessage());
                }
            }

            $duration = microtime(true) - $startTime;
            if ($duration > 5.0) {
                try {
                    if (isset($this->logger)) { $this->logger->warning('event.listener_timeout', [
                            'event' => $eventName,
                            'listener' => is_string($listener) ? $listener : 'closure',
                            'duration' => round($duration, 2) . 's',
                            'threshold' => '5.0s'
                        ]); }
                } catch (\Throwable $timeoutLogEx) {
                    error_log("Failed to log listener timeout warning: " . $timeoutLogEx->getMessage());
                }
            }
            
            // بررسی توقف انتشار
            if ($event->isPropagationStopped()) {
                break;
            }
        }
        
        // M23 Fix: سانسور هوشمند و ایمن سازی اطلاعات حساس قبل از تبدیل به JSON جهت ثبت در لاگ سیستم
        $rawPayload = $event->getData();
        $maskedPayload = is_array($rawPayload) ? $this->maskSensitiveData($rawPayload) : $rawPayload;
        
        $encoded = json_encode($maskedPayload, JSON_UNESCAPED_UNICODE);
        $preview = $encoded !== false ? mb_substr($encoded, 0, 2000) : null;
        
        try {
            if (isset($this->logger)) { $this->logger->info('event.dispatched', [
                    'channel'      => 'event',
                    'event_name'   => $eventName,
                    'data_preview' => $preview,
                    'data_size'    => $encoded !== false ? strlen($encoded) : null,
                ]); }
        } catch (\Throwable $infoLogEx) {
            error_log("Failed to log event dispatched info: " . $infoLogEx->getMessage());
        }

        $this->auditDispatchedEvent($eventName, $event);
    }

    private function auditDispatchedEvent(string $eventName, Event $event): void
    {
        try {
            // Avoid auditing audit-record events themselves to prevent recursion/duplication
            if ($event instanceof \App\Events\AuditRecordedEvent || $eventName === \App\Events\AuditRecordedEvent::class) {
                return;
            }

            $auditTrail = $this->resolveAuditTrail();
            if ($auditTrail === null) {
                return;
            }

            $normalizedEventName = $this->normalizeEventName($eventName);
            $eventData = $event->getData();
            $userId = null;
            $actorId = null;

            if (is_array($eventData)) {
                $userId = $eventData['user_id'] ?? $eventData['userId'] ?? $eventData['user'] ?? null;
                $actorId = $eventData['actor_id'] ?? $eventData['actorId'] ?? $eventData['admin_id'] ?? $eventData['adminId'] ?? null;
            }

            $auditTrail->record(
                $normalizedEventName,
                is_int($userId) ? $userId : null,
                [
                    'event_class' => get_class($event),
                    'event_data' => $eventData,
                    'source' => 'event_dispatcher',
                    '_dispatched_at' => date('Y-m-d H:i:s')
                ],
                is_int($actorId) ? $actorId : null
            );
        } catch (\Throwable $e) {
            if (isset($this->logger)) { $this->logger->warning('event.audit.record_failed', [
                    'event_name' => $eventName,
                    'error' => $e->getMessage(),
                ]); }
        }
    }

    private function resolveAuditTrail(): ?\App\Services\AuditTrail
    {
        if ($this->auditTrail !== null) {
            return $this->auditTrail;
        }

        try {
            $container = Container::getInstance();
            if ($container->has(\App\Services\AuditTrail::class)) {
                $this->auditTrail = $container->make(\App\Services\AuditTrail::class);
                return $this->auditTrail;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    private function normalizeEventName(string $eventName): string
    {
        if (!class_exists($eventName)) {
            return $eventName;
        }

        $parts = explode('\\', $eventName);
        $className = end($parts);
        $name = preg_replace('/Event$/', '', $className);
        $name = preg_replace('/([a-z])([A-Z])/', '$1.$2', $name);
        return strtolower($name);
    }

    /**
     * ارسال رویداد به صورت async (از طریق Queue)
     */
    public function dispatchAsync(string $eventName, $event = null, string $queue = 'default'): void
    {
        // اگر Event شیء نبود، آن را به آرایه تبدیل کن
        if (!$event instanceof Event) {
            $event = new GenericEvent($event);
        }

        // اضافه کردن به Queue
        $this->queue->push('dispatch_event', [
            'event_name' => $eventName,
            'event_data' => $event->getData(),
            'event_class' => get_class($event),
            'serialized_event' => serialize($event)
        ], $queue);

        // لاگ
        if (isset($this->logger)) { $this->logger->info('event.queued', [
                'channel' => 'event',
                'event_name' => $eventName,
                'queue' => $queue
            ]); }
    }

    /**
     * پردازش رویداد از Queue
     */
    public function processQueuedEvent(array $job): void
    {
        $payload = $job['data'] ?? [];
        $eventName = $payload['event_name'] ?? null;
        $eventData = $payload['event_data'] ?? null;
        $eventClass = $payload['event_class'] ?? null;

        if ($eventName === null) {
            try {
                if (isset($this->logger)) { $this->logger->warning('event.queue.missing_payload', [
                        'job_id' => $job['id'] ?? null,
                        'payload' => $payload,
                    ]); }
            } catch (\Throwable $logEx) {
                error_log("Failed to log missing payload warning: " . $logEx->getMessage());
            }
            return;
        }

        try {
            $serializedEvent = $payload['serialized_event'] ?? null;
            $event = null;

            // بازسازی Event object با اولویت استفاده از دیتای سریالایز شده
            if ($serializedEvent !== null) {
                $event = unserialize($serializedEvent);
            } elseif ($eventClass && class_exists($eventClass)) {
                // برای سازگاری با جاب‌های قدیمی در صف که سریالایز کامل نشدند
                // اگر Event Typed است ولی متد بازسازی اختصاصی ندارد، ممکن است با ارور مواجه شود
                if (method_exists($eventClass, 'fromPayload')) {
                    $event = $eventClass::fromPayload($eventData);
                } else {
                    try {
                        $event = new $eventClass($eventData);
                    } catch (\TypeError $e) {
                        // Fallback اگر Typed Event بود و آرایه را به عنوان آرگومان اول قبول نکرد
                        $event = new GenericEvent($eventData);
                    }
                }
            } else {
                $event = new GenericEvent($eventData);
            }

            if (!$event instanceof Event) {
                throw new \Exception("Reconstructed object is not an instance of Core\\Event");
            }

            // dispatch عادی
            $this->dispatch($eventName, $event);

        } catch (\Throwable $e) {
            $listenerName = 'queue_worker_reconstruction';

            // 1. لاگ کردن در سامانه لاگ اصلی (ایمن شده در برابر خطای خود لاگر)
            try {
                if (isset($this->logger)) { $this->logger->error('event.queue.reconstruction_failed', [
                        'event' => $eventName,
                        'job_id' => $job['id'] ?? null,
                        'error' => $e->getMessage()
                    ]); }
            } catch (\Throwable $logEx) {
                error_log("Failed to write queue reconstruction failure to primary logger: " . $logEx->getMessage());
            }

            // 2. ثبت در Dead-Letter / Event Failure Log اختصاصی دیتابیس
            try {
                $container = Container::getInstance();
                if ($container->has(Database::class)) {
                    $db = $container->make(Database::class);
                    $db->execute("
                        INSERT INTO event_failures (event_name, listener, payload, error_message, failed_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ", [
                        $eventName,
                        $listenerName,
                        json_encode($eventData ?? $payload, JSON_UNESCAPED_UNICODE),
                        $e->getMessage() . "\n" . $e->getTraceAsString()
                    ]);
                }
            } catch (\Throwable $dbEx) {
                error_log("Failed to write queue reconstruction failure to event_failures table: " . $dbEx->getMessage());
            }
        }
    }

    /**
     * حذف Listener
     */
    public function forget($eventName)
    {
        unset($this->listeners[$eventName]);
    }

    /**
     * دریافت تمام Listeners
     */
    public function getListeners($eventName = null)
    {
        if ($eventName === null) {
            return $this->listeners;
        }
        
        return $this->listeners[$eventName] ?? [];
    }

    /**
     * جلوگیری از Clone
     */
    private function __clone() {}

    /**
     * جلوگیری از Unserialize
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * M23 Fix: شناسایی و سانسور کردن اطلاعات حساس به صورت بازگشتی جهت امنیت در فایل لاگ
     */
    private function maskSensitiveData(array $data): array
    {
        $sensitivePatterns = ['password', 'pwd', 'token', 'cvv', 'secret', 'card', 'pin', 'pan', 'key', 'auth', 'credential', 'ssn'];
        $result = [];
        
        foreach ($data as $key => $value) {
            $isSensitive = false;
            $keyStr = (string)$key;
            
            foreach ($sensitivePatterns as $pattern) {
                if (stripos($keyStr, $pattern) !== false) {
                    $isSensitive = true;
                    break;
                }
            }
            
            if ($isSensitive) {
                $result[$key] = '******** (masked)';
            } elseif (is_array($value)) {
                $result[$key] = $this->maskSensitiveData($value);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
}

/**
 * Generic Event (برای رویدادهای ساده)
 */
class GenericEvent extends Event
{
    // فقط از کلاس پایه استفاده می‌کند
}