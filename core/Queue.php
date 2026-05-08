<?php

declare(strict_types=1);

namespace Core;

use DateTime;

/**
 * Queue System - سیستم صف مبتنی بر دیتابیس
 *
 * جدول queues:
 * - id (primary key)
 * - queue (نام صف)
 * - payload (داده JSON)
 * - attempts (تعداد تلاش)
 * - reserved_at (زمان رزرو)
 * - available_at (زمان قابل دسترس)
 * - created_at
 */
class Queue
{
    private Database $db;
    private string $defaultQueue = 'default';
    private int $maxAttempts = 3;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * اضافه کردن job به صف
     */
    public function push(string $job, array $data = [], string $queue = null, int $delay = 0): bool
    {
        $queue = $queue ?: $this->defaultQueue;
        $availableAt = $delay > 0 ? time() + $delay : time();

        $result = $this->db->table('queues')->insert([
            'queue' => $queue,
            'payload' => json_encode([
                'job' => $job,
                'data' => $data
            ]),
            'attempts' => 0,
            'available_at' => date('Y-m-d H:i:s', $availableAt),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return (bool)$result;
    }

    /**
     * گرفتن job از صف
     */
    public function pop(string $queue = null): ?array
    {
        $queue = $queue ?: $this->defaultQueue;

        // پیدا کردن job قابل اجرا
        $job = $this->db->table('queues')
            ->where('queue', '=', $queue)
            ->where('attempts', '<', $this->maxAttempts)
            ->where('available_at', '<=', date('Y-m-d H:i:s'))
            ->whereNull('reserved_at')
            ->orderBy('created_at', 'ASC')
            ->first();

        if (!$job) {
            return null;
        }

        // رزرو job
        $this->db->table('queues')
            ->where('id', '=', $job->id)
            ->update([
                'reserved_at' => date('Y-m-d H:i:s'),
                'attempts' => $job->attempts + 1
            ]);

        return [
            'id' => $job->id,
            'job' => json_decode($job->payload, true)['job'],
            'data' => json_decode($job->payload, true)['data'],
            'attempts' => $job->attempts + 1
        ];
    }

    /**
     * حذف job از صف (پس از اجرای موفق)
     */
    public function delete(int $id): bool
    {
        $result = $this->db->table('queues')
            ->where('id', '=', $id)
            ->delete();

        return $result > 0;
    }

    /**
     * بازگرداندن job به صف (برای retry)
     */
    public function release(int $id, int $delay = 0): bool
    {
        $availableAt = $delay > 0 ? time() + $delay : time();

        $result = $this->db->table('queues')
            ->where('id', '=', $id)
            ->update([
                'reserved_at' => null,
                'available_at' => date('Y-m-d H:i:s', $availableAt)
            ]);

        return $result > 0;
    }

    /**
     * پاک کردن jobهای قدیمی
     */
    public function clean(int $days = 7): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $this->db->table('queues')
            ->where('created_at', '<', $cutoff)
            ->delete();
    }

    /**
     * شمارش jobهای موجود در صف
     */
    public function size(string $queue = null): int
    {
        $queue = $queue ?: $this->defaultQueue;

        return (int) $this->db->table('queues')
            ->where('queue', '=', $queue)
            ->count();
    }
}