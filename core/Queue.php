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

    public function pop(string $queue = null): ?array
    {
        $queue = $queue ?: $this->defaultQueue;

        try {
            $this->db->beginTransaction();

            $nowStr = date('Y-m-d H:i:s');

            // SELECT ... FOR UPDATE قفل امن برای جلوگیری از همپوشانی در سیستم‌های توزیع شده
            $job = $this->db->selectOne(
                "SELECT * FROM queues 
                 WHERE queue = :queue 
                   AND attempts < :max_attempts 
                   AND available_at <= :now 
                   AND reserved_at IS NULL 
                 ORDER BY created_at ASC 
                 LIMIT 1 FOR UPDATE",
                [
                    'queue' => $queue,
                    'max_attempts' => $this->maxAttempts,
                    'now' => $nowStr
                ]
            );

            if (!$job) {
                $this->db->commit();
                return null;
            }

            $reservedAt = date('Y-m-d H:i:s');
            $newAttempts = (int)$job->attempts + 1;

            $this->db->execute(
                "UPDATE queues 
                 SET reserved_at = :reserved_at, attempts = :attempts 
                 WHERE id = :id",
                [
                    'reserved_at' => $reservedAt,
                    'attempts' => $newAttempts,
                    'id' => $job->id
                ]
            );

            $this->db->commit();

            $payload = json_decode($job->payload, true) ?? [];

            return [
                'id' => (int)$job->id,
                'job' => $payload['job'] ?? '',
                'data' => $payload['data'] ?? [],
                'attempts' => $newAttempts
            ];
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
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