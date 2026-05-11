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
    private Queue $queue;

    public function __construct(Queue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * دریافت Instance (Singleton)
     * Queue را از Container تزریق شده دریافت می‌کند
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            // Container Dependency Injection: Queue خودکار resolve می‌شود
            $container = Container::getInstance();
            if (!$container->has(Queue::class)) {
                $container->singleton(Queue::class, function($c) {
                    return new Queue();
                });
            }
            $queue = $container->get(Queue::class);
            self::$instance = new self($queue);
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
        
        // لاگ رویداد
$data = $event->getData();
$encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
$preview = $encoded !== false ? mb_substr($encoded, 0, 2000) : null;

if (function_exists('logger')) {
            logger()->info('event.dispatched', [
                'channel' => 'event',
                'event_name' => $eventName,
                'data_preview' => $preview,
                'data_size' => $encoded !== false ? strlen($encoded) : null,
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
}

/**
 * Generic Event (برای رویدادهای ساده)
 */
class GenericEvent extends Event
{
    // فقط از کلاس پایه استفاده می‌کند
}