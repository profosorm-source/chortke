<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use Core\Controller;
use Core\Database;
use Core\Logger;
use Core\Response;
use App\Services\MessageModerationService;
use App\Models\InteractionModel;

/**
 * MessageModerationController
 * مدیریت و مدرسیون پیام‌های کاربران
 */
class MessageModerationController extends Controller
{
    protected MessageModerationService $moderationService;

    public function __construct(MessageModerationService $moderationService)
    {
        $this->moderationService = $moderationService;
    }

    /**
     * لیست گزارش‌های پیام
     * @return void
     */
    public function reports(): void
    {
        $status = request()->input('status', 'pending');
        $page   = (int) request()->input('page', 1);
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        // دریافت گزارش‌ها
        $reports = $this->db->table('message_reports')
            ->join('direct_messages', 'message_reports.message_id', '=', 'direct_messages.id')
            ->join('users', 'message_reports.reporter_id', '=', 'users.id')
            ->select([
                'message_reports.id',
                'message_reports.message_id',
                'message_reports.reason',
                'message_reports.status',
                'message_reports.created_at',
                'direct_messages.message',
                'direct_messages.sender_id',
                'direct_messages.recipient_id',
                'users.name as reporter_name',
                'users.email as reporter_email'
            ])
            ->when($status !== 'all', function($q) use ($status) {
                return $q->where('message_reports.status', '=', $status);
            })
            ->orderBy('message_reports.created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        // تعداد کل
        $total = $this->db->table('message_reports')
            ->when($status !== 'all', function($q) use ($status) {
                return $q->where('status', '=', $status);
            })
            ->count();

        view('admin/messages/reports', [
            'reports'      => $reports,
            'status'       => $status,
            'page'         => $page,
            'total'        => $total,
            'per_page'     => $limit,
            'total_pages'  => ceil($total / $limit)
        ]);
    }

    /**
     * نمایش جزئیات گزارش
     * @return void
     */
    public function show(): void
    {
        $id = (int) request()->param('id');

        $report = $this->db->table('message_reports')
            ->join('direct_messages', 'message_reports.message_id', '=', 'direct_messages.id')
            ->select([
                'message_reports.*',
                'direct_messages.*'
            ])
            ->where('message_reports.id', '=', $id)
            ->first();

        if (!$report) {
            response()->json(['error' => 'گزارش یافت نشد'], 404);
            return;
        }

        // دریافت کل پیام‌های این کاربر برای تاریخچه
        $user_messages = $this->db->table('direct_messages')
            ->where('sender_id', '=', $report['sender_id'])
            ->select(['id', 'message', 'created_at', 'recipient_id'])
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->get();

        view('admin/messages/report-detail', [
            'report'          => $report,
            'user_messages'   => $user_messages
        ]);
    }

    /**
     * تایید گزارش و اقدام
     * @return void
     */
    public function approve(): void
    {
        if (!request()->isPost()) {
            response()->json(['error' => 'Method not allowed'], 405);
            return;
        }

        $id     = (int) request()->input('report_id');
        $action = request()->input('action', 'warn');

        $report = $this->db->table('message_reports')
            ->where('id', '=', $id)
            ->first();

        if (!$report) {
            response()->json(['error' => 'گزارش یافت نشد'], 404);
            return;
        }

        try {
            // بروزرسانی وضعیت گزارش
            $this->db->table('message_reports')
                ->where('id', '=', $id)
                ->update([
                    'status'     => 'resolved',
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now()
                ]);

            // اقدام بر حسب نوع
            switch ($action) {
                case 'warn':
                    $this->warnUser($report['sender_id']);
                    break;
                case 'delete':
                    $this->deleteMessage($report['message_id']);
                    break;
                case 'ban':
                    $this->banUser($report['sender_id']);
                    break;
            }

            $this->logger->info('Message report approved', [
                'report_id' => $id,
                'action'   => $action,
                'admin_id' => auth()->id()
            ]);

            response()->json(['success' => true, 'message' => 'گزارش تایید شد']);
        } catch (\Exception $e) {
            $this->logger->error('Failed to approve message report', ['error' => $e->getMessage()]);
            response()->json(['error' => 'خطا در پردازش'], 500);
        }
    }

    /**
     * رد کردن گزارش
     * @return void
     */
    public function dismiss(): void
    {
        if (!request()->isPost()) {
            response()->json(['error' => 'Method not allowed'], 405);
            return;
        }

        $id = (int) request()->input('report_id');

        $this->db->table('message_reports')
            ->where('id', '=', $id)
            ->update([
                'status'     => 'dismissed',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now()
            ]);

        $this->logger->info('Message report dismissed', [
            'report_id' => $id,
            'admin_id' => auth()->id()
        ]);

        response()->json(['success' => true, 'message' => 'گزارش رد شد']);
    }

    /**
     * حذف پیام
     * @param int $messageId
     * @return void
     */
    protected function deleteMessage(int $messageId): void
    {
        $this->db->table('direct_messages')
            ->where('id', '=', $messageId)
            ->update([
                'message'    => '[پیام حذف‌شده توسط مدیریت]',
                'deleted_at' => now(),
                'deleted_by' => 'admin'
            ]);
    }

    /**
     * هشدار به کاربر
     * @param int $userId
     * @return void
     */
    protected function warnUser(int $userId): void
    {
        $this->db->table('users')
            ->where('id', '=', $userId)
            ->update([
                'warning_count' => $this->db->raw('warning_count + 1')
            ]);
    }

    /**
     * مسدود کردن کاربر
     * @param int $userId
     * @return void
     */
    protected function banUser(int $userId): void
    {
        $this->db->table('users')
            ->where('id', '=', $userId)
            ->update([
                'status' => 'banned',
                'banned_reason' => 'Inappropriate messaging'
            ]);
    }

    /**
     * لیست کاربران مسدود
     * @return void
     */
    public function blockedUsers(): void
    {
        $page   = (int) request()->input('page', 1);
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $blocked = $this->db->table('user_blocks')
            ->join('users', 'user_blocks.blocker_id', '=', 'users.id')
            ->join('users as blocked_user', 'user_blocks.blocked_id', '=', 'blocked_user.id')
            ->select([
                'user_blocks.id',
                'user_blocks.reason',
                'user_blocks.created_at',
                'users.name as blocker_name',
                'blocked_user.name as blocked_name',
                'blocked_user.email'
            ])
            ->orderBy('user_blocks.created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $total = $this->db->table('user_blocks')->count();

        view('admin/messages/blocked-users', [
            'blocked'      => $blocked,
            'page'         => $page,
            'total'        => $total,
            'per_page'     => $limit,
            'total_pages'  => ceil($total / $limit)
        ]);
    }

    /**
     * آمار پیام‌های سیستم
     * @return void
     */
    public function stats(): void
    {
        $stats = [
            'total_messages'   => $this->db->table('direct_messages')->count(),
            'total_reports'    => $this->db->table('message_reports')->count(),
            'pending_reports'  => $this->db->table('message_reports')
                ->where('status', '=', 'pending')
                ->count(),
            'total_blocks'     => $this->db->table('user_blocks')->count(),
            'today_messages'   => $this->db->table('direct_messages')
                ->where('created_at', '>=', date('Y-m-d 00:00:00'))
                ->count(),
            'today_reports'    => $this->db->table('message_reports')
                ->where('created_at', '>=', date('Y-m-d 00:00:00'))
                ->count()
        ];

        // نسبت بزرگ‌ترین گزارش‌دهندگان
        $top_reporters = $this->db->table('message_reports')
            ->join('users', 'message_reports.reporter_id', '=', 'users.id')
            ->select(['users.name', 'users.id', $this->db->raw('COUNT(*) as count')])
            ->groupBy('reporter_id')
            ->orderBy('count', 'DESC')
            ->limit(5)
            ->get();

        view('admin/messages/stats', [
            'stats'        => $stats,
            'top_reporters' => $top_reporters
        ]);
    }
}
