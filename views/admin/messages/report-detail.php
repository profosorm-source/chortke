<?php
/**
 * جزئیات گزارش پیام
 */
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">جزئیات گزارش پیام</h1>
                    <p class="text-gray-600 mt-1">بررسی و مدیریت گزارش پیام توسط تیم پشتیبانی</p>
                </div>
                <a href="/admin/messages/reports" class="text-blue-600 hover:text-blue-800">بازگشت به گزارش‌ها</a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 bg-white rounded-lg shadow-sm border p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">متن پیام</h2>
                <div class="prose prose-sm text-gray-800">
                    <p><?php echo nl2br(e($report['message'])); ?></p>
                </div>
                <div class="mt-6 space-y-3 text-sm text-gray-600">
                    <div>گزارش‌دهنده: <?php echo e($report['reporter_name']); ?> (<?php echo e($report['reporter_email']); ?>)</div>
                    <div>وضعیت گزارش: <?php echo e($report['status']); ?></div>
                    <div>دلیل: <?php echo e($report['reason']); ?></div>
                    <div>ارسال شده در: <?php echo format_time($report['created_at']); ?></div>
                </div>

                <div class="mt-6 flex gap-2">
                    <form method="post" action="/admin/messages/reports/approve">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                        <button name="action" value="warn" class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg">هشدار</button>
                        <button name="action" value="delete" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">حذف پیام</button>
                        <button name="action" value="ban" class="bg-gray-800 hover:bg-black text-white px-4 py-2 rounded-lg">بلاک کاربر</button>
                    </form>
                </div>

                <div class="mt-4">
                    <form method="post" action="/admin/messages/reports/dismiss">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                        <button class="bg-gray-200 hover:bg-gray-300 text-gray-900 px-4 py-2 rounded-lg">رد گزارش</button>
                    </form>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">پیام‌های اخیر کاربر</h2>
                <div class="space-y-3 text-sm text-gray-700">
                    <?php if (empty($user_messages)): ?>
                        <p class="text-gray-500">هیچ پیام اخیر یافت نشد.</p>
                    <?php else: ?>
                        <?php foreach ($user_messages as $message): ?>
                            <div class="border rounded-lg p-3 bg-gray-50">
                                <p class="text-sm text-gray-900"><?php echo e(substr($message['message'], 0, 120)); ?></p>
                                <div class="text-xs text-gray-500 mt-2">
                                    به کاربر #<?php echo $message['recipient_id']; ?> در <?php echo format_time($message['created_at']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
