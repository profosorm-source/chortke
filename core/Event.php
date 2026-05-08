<?php
namespace Core;

/**
 * Event Class
 * 
 * کلاس پایه برای رویدادها
 */
abstract class Event
{
    protected $data = [];
    protected $stopped = false;

    public function __construct($data = [])
    {
        $this->data = $data;
    }

    /**
     * دریافت داده
     */
    public function getData($key = null)
    {
        if ($key === null) {
            return $this->data;
        }
        
        return $this->data[$key] ?? null;
    }

    /**
     * تنظیم داده
     */
    public function setData($key, $value)
    {
        $this->data[$key] = $value;
    }

    /**
     * توقف انتشار
     */
    public function stopPropagation()
    {
        $this->stopped = true;
    }

    /**
     * بررسی توقف
     */
    public function isPropagationStopped()
    {
        return $this->stopped;
    }
}