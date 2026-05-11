<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use App\Traits\Filterable;

/**
 * Ads Model - متمرکزکننده تمام انواع تبلیغات در سیستم
 */
class Ads extends Model
{
    use Filterable;

    protected static string $table = 'ads';
    protected static array $searchable = ['ads.title', 'ads.description'];

    protected static array $filterable = [
        'type' => '=',
        'status' => '=',
        'user_id' => '=',
        'a.type' => ['a.type', '='],
        'a.status' => ['a.status', '=']
    ];

    /**
     * دریافت بر اساس شناسه مالک تبلیغ (یکدست‌سازی به user_id)
     */
    public function getByAdvertiser(int $userId, int $limit = 20, int $offset = 0, string $type = null, ?string $status = null): array
    {
        $q = $this->db->table(static::$table)
            ->where('user_id', '=', $userId);
            
        $this->applyFilters($q, [
            'type' => $type,
            'status' => $status
        ]);

        return $q->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * لیست ادمین با فیلتر نوع و وضعیت
     */
    public function adminList(string $type = '', string $status = '', int $limit = 30, int $offset = 0): array
    {
        $q = $this->db->table(static::$table . ' as a')
            ->select('a.*', 'u.full_name as user_name', 'u.email as user_email')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id');
            
        $this->applyFilters($q, [
            'a.type' => $type,
            'a.status' => $status
        ]);
        
        return $q->orderBy('a.created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * شمارش ادمین با فیلتر نوع و وضعیت
     */
    public function adminCount(string $type = '', string $status = ''): int
    {
        $q = $this->db->table(static::$table);
            
        $this->applyFilters($q, [
            'type' => $type,
            'status' => $status
        ]);
        
        return $q->count();
    }

    /**
     * جستجوی تبلیغات SEO فعال
     */
    public function getActiveForSearch(string $keyword, int $limit = 5): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->table(static::$table)
            ->where('type', '=', 'seo')
            ->where('status', '=', 'active')
            ->where('remaining_budget', '>', 0)
            ->whereRaw('(deadline IS NULL OR deadline > ?)', [$now])
            ->where('keyword', 'LIKE', '%' . $keyword . '%')
            ->orderBy('price_per_click', 'DESC')
            ->orderBy('created_at', 'ASC')
            ->limit($limit)
            ->get();
    }

    /**
     * کسر هزینه هر کلیک یا اکشن به صورت اتمیک و امن از بودجه تبلیغ
     */
    public function deductClick(int $id, float $amount): bool
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT id, remaining_budget, status FROM `" . static::$table . "` WHERE id = ? AND status = 'active' FOR UPDATE");
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$row) {
                $this->db->rollBack();
                return false;
            }

            $currentRemaining = (float)$row->remaining_budget;
            if ($currentRemaining <= 0) {
                $this->db->prepare("UPDATE `" . static::$table . "` SET status = 'exhausted', updated_at = NOW() WHERE id = ?")->execute([$id]);
                $this->db->commit();
                return false;
            }

            $newRemaining = max(0.0, $currentRemaining - $amount);
            $newStatus = $newRemaining <= 0.0 ? 'exhausted' : 'active';

            $stmt = $this->db->prepare(
                "UPDATE `" . static::$table . "`
                 SET clicks_count = clicks_count + 1,
                     remaining_budget = ?,
                     status = ?,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $success = $stmt->execute([$newRemaining, $newStatus, $id]);

            if ($success) {
                $this->db->commit();
                return true;
            }

            $this->db->rollBack();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            return false;
        }
    }

    /**
     * دریافت بنرهای فعال بر اساس موقعیت (Placement)
     */
    public function getActiveBannersByPlacement(string $placement, int $limit = 5): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->table(static::$table)
            ->where('type', '=', 'banner')
            ->where('placement', '=', $placement)
            ->where('status', '=', 'active')
            ->whereRaw('(start_date IS NULL OR start_date <= ?)', [$now])
            ->whereRaw('(end_date IS NULL OR end_date >= ?)', [$now])
            ->orderByRaw('sort_order ASC, RAND()')
            ->limit($limit)
            ->get();
    }

    /**
     * افزایش شمارنده نمایش به صورت گروهی برای بهینه‌سازی عملکرد N+1
     */
    public function bulkIncrementImpressions(array $ids): bool
    {
        if (empty($ids)) return true;
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE `" . static::$table . "` 
                SET impressions = impressions + 1,
                    ctr = CASE WHEN impressions > 0 THEN ROUND((clicks / (impressions + 1)) * 100, 2) ELSE 0 END 
                WHERE id IN ($placeholders)";
                
        return $this->db->prepare($sql)->execute($ids);
    }

    /**
     * افزایش شمارنده نمایش (Impression) به صورت اتمیک
     */
    public function incrementImpression(int $id): bool
    {
        return $this->bulkIncrementImpressions([$id]);
    }

    /**
     * ثبت کلیک و آپدیت آمار CTR به صورت ترنزکشنال
     */
    public function registerInteractionClick(int $id, ?int $userId, string $ip): bool
    {
        try {
            $this->db->beginTransaction();

            // قفل ردیف برای ثبت کلیک
            $stmt = $this->db->prepare("SELECT id, clicks, impressions FROM `" . static::$table . "` WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $ad = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$ad) {
                $this->db->rollBack();
                return false;
            }

            // ثبت رکورد کلیک در لاگ سیستم
            $stmt = $this->db->prepare("INSERT INTO banner_clicks (banner_id, user_id, ip_address, clicked_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$id, $userId, $ip]);

            // بروزرسانی شمارنده کلی در جدول تبلیغات
            $newClicks = (int)$ad->clicks + 1;
            $newCtr = (int)$ad->impressions > 0 ? \round(($newClicks / (int)$ad->impressions) * 100, 2) : 0.0;

            $stmt = $this->db->prepare("UPDATE `" . static::$table . "` SET clicks = ?, ctr = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newClicks, $newCtr, $id]);

            $this->db->commit();
            return true;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    /**
     * دریافت لیست تسک‌های سفارشی در دسترس برای کاربران انجام‌دهنده (Worker)
     */
    public function getAvailableCustomTasks(int $workerId, array $filters = [], int $limit = 20, int $offset = 0, string $type = 'custom_task'): array
    {
        $now = date('Y-m-d H:i:s');
        $q = $this->db->table(static::$table . ' as a')
            ->select('a.*', 'u.full_name as creator_name', '(a.total_count - a.completed_count - a.pending_count) as remaining_slots')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.type', '=', $type)
            ->where('a.status', '=', 'active')
            ->where('a.user_id', '!=', $workerId)
            ->where('a.remaining_budget', '>', 0)
            ->whereRaw('(a.total_count - a.completed_count - a.pending_count) > 0')
            ->whereRaw('(a.end_date IS NULL OR a.end_date > ?)', [$now])
            ->whereNull('a.deleted_at');

        if (!empty($filters['task_type'])) {
            $q->where('a.task_type', '=', $filters['task_type']);
        }
        
        if (!empty($filters['platform'])) {
            $q->where('a.platform', '=', $filters['platform']);
        }

        return $q->orderBy('a.is_active', 'DESC') // First sticky/active
                 ->orderBy('a.created_at', 'DESC')
                 ->limit($limit)
                 ->offset($offset)
                 ->get();
    }

    /**
     * شمارش تسک‌های سفارشی فعال و در دسترس
     */
    public function countAvailableCustomTasks(int $workerId, array $filters = [], string $type = 'custom_task'): int
    {
        $now = date('Y-m-d H:i:s');
        $q = $this->db->table(static::$table)
            ->where('type', '=', $type)
            ->where('status', '=', 'active')
            ->where('user_id', '!=', $workerId)
            ->where('remaining_budget', '>', 0)
            ->whereRaw('(total_count - completed_count - pending_count) > 0')
            ->whereRaw('(end_date IS NULL OR end_date > ?)', [$now])
            ->whereNull('deleted_at');

        if (!empty($filters['task_type'])) {
            $q->where('task_type', '=', $filters['task_type']);
        }

        return $q->count();
    }

    /**
     * افزایش شمارنده در حال انجام (برای جلوگیری از دریافت بیش از ظرفیت)
     */
    public function incrementPendingCount(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE `" . static::$table . "` SET pending_count = pending_count + 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * کاهش شمارنده در حال انجام
     */
    public function decrementPendingCount(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE `" . static::$table . "` SET pending_count = GREATEST(0, pending_count - 1) WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * ثبت موفقیت‌آمیز تسک، کسر اتومیک بودجه و افزایش شمارنده تکمیل‌شده
     */
    public function incrementCustomTaskCompletion(int $id, float $costAmount, bool $shouldDecrementPending = true): bool
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT id, remaining_budget, total_budget, completed_count, pending_count, total_count, status FROM `" . static::$table . "` WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $task = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$task) {
                $this->db->rollBack();
                return false;
            }

            $newRemainingBudget = max(0.0, (float)$task->remaining_budget - $costAmount);
            $newCompletedCount = (int)$task->completed_count + 1;
            $newPendingCount = $shouldDecrementPending ? max(0, (int)$task->pending_count - 1) : (int)$task->pending_count;
            
            // بررسی وضعیت انقضا یا اتمام ظرفیت
            $newStatus = $task->status;
            if ($newRemainingBudget <= 0 || ($task->total_count > 0 && $newCompletedCount >= (int)$task->total_count)) {
                $newStatus = 'completed';
            }

            $stmt = $this->db->prepare(
                "UPDATE `" . static::$table . "`
                 SET completed_count = ?,
                     pending_count = ?,
                     remaining_budget = ?,
                     status = ?,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $success = $stmt->execute([$newCompletedCount, $newPendingCount, $newRemainingBudget, $newStatus, $id]);

            if ($success) {
                $this->db->commit();
                return true;
            }

            $this->db->rollBack();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            return false;
        }
    }

    /**
     * منقضی کردن تبلیغات قدیمی که بودجه ندارند یا تاریخشان گذشته است
     */
    public function expireOldAdvertisements(int $chunkSize = 1000): int
    {
        $totalExpired = 0;
        $maxIterations = 100;
        $now = date('Y-m-d H:i:s');
        
        for ($i = 0; $i < $maxIterations; $i++) {
            $sql = "UPDATE `" . static::$table . "` 
                    SET `status` = 'completed', `updated_at` = ? 
                    WHERE `status` = 'active' 
                      AND ((end_date IS NOT NULL AND end_date < ?) 
                           OR (remaining_count IS NOT NULL AND remaining_count <= 0) 
                           OR remaining_budget <= 0)
                    LIMIT ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$now, $now, $chunkSize]);
            
            $affected = $stmt->rowCount();
            $totalExpired += $affected;
            
            if ($affected < $chunkSize) {
                break;
            }
            
            usleep(50000); 
        }
        
        return $totalExpired;
    }
    public function taskTypes(): array
    {
        return [
            'signup'  => 'ثبت‌نام',
            'install' => 'نصب برنامه',
            'review'  => 'نظر دادن',
            'vote'    => 'رأی دادن',
            'follow'  => 'دنبال کردن',
            'join'    => 'عضویت',
            'custom'  => 'سفارشی',
        ];
    }

    public function proofTypes(): array
    {
        return [
            'screenshot' => 'اسکرین‌شات',
            'text'       => 'متن',
            'video'      => 'ویدیو',
            'code'       => 'کد رفرال',
            'file'       => 'فایل',
        ];
    }

    public function statusLabels(): array
    {
        return [
            'draft'          => 'پیشنویس',
            'pending_review' => 'در انتظار بررسی',
            'active'         => 'فعال',
            'paused'         => 'متوقف',
            'completed'      => 'تکمیل‌شده',
            'rejected'       => 'رد شده',
            'expired'        => 'منقضی',
        ];
    }

    public function statusClasses(): array
    {
        return [
            'draft'          => 'badge-secondary',
            'pending_review' => 'badge-warning',
            'active'         => 'badge-success',
            'paused'         => 'badge-info',
            'completed'      => 'badge-primary',
            'rejected'       => 'badge-danger',
            'expired'        => 'badge-danger',
        ];
    }

    public function submissionStatusLabels(): array
    {
        return [
            'in_progress' => 'در حال انجام',
            'submitted'   => 'ارسال شده',
            'approved'    => 'تایید شده',
            'rejected'    => 'رد شده',
            'expired'     => 'منقضی شده',
            'disputed'    => 'در اختلاف',
        ];
    }
}
