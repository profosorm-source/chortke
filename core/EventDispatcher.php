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
    private array $bootstrapListeners = [];
    private Queue $queue;

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
     * ثبت Listener
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
        if (!empty($this->bootstrapListeners)) {
            $this->listeners = $this->bootstrapListeners;
        }
    }

    /**
     * ارسال رویداد
     */
    public function dispatch($eventName, $event = null)
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }
        
        // اگر Event شیء نبود، آن را به آرایه تبدیل کن
        if (!$event instanceof Event) {
            $event = new GenericEvent($event);
        }
        
        foreach ($this->listeners[$eventName] as $item) {
            $listener = $item['listener'];
            
            // اجرای Listener
            if (is_callable($listener)) {
                $listener($event);
            } elseif (is_string($listener) && class_exists($listener)) {
                $listenerInstance = new $listener();
                if (method_exists($listenerInstance, 'handle')) {
                    $listenerInstance->handle($event);
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
        
        if (function_exists('logger')) {
            logger()->info('event.dispatched', [
                'channel'      => 'event',
                'event_name'   => $eventName,
                'data_preview' => $preview,
                'data_size'    => $encoded !== false ? strlen($encoded) : null,
            ]);
        }
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
            'event_class' => get_class($event)
        ], $queue);

        // لاگ
        if (function_exists('logger')) {
            logger()->info('event.queued', [
                'channel' => 'event',
                'event_name' => $eventName,
                'queue' => $queue
            ]);
        }
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
            if (function_exists('logger')) {
                logger()->warning('event.queue.missing_payload', [
                    'job_id' => $job['id'] ?? null,
                    'payload' => $payload,
                ]);
            }
            return;
        }

        // بازسازی Event object
        if ($eventClass && class_exists($eventClass)) {
            $event = new $eventClass($eventData);
        } else {
            $event = new GenericEvent($eventData);
        }

        // dispatch عادی
        $this->dispatch($eventName, $event);
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