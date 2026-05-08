<?php
/**
 * آمار پیام‌های سیستمی
 */
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-6xl mx-auto">
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">آمار پیام‌ها</h1>
                    <p class="text-gray-600 mt-1">شاخص‌های کلیدی پیام‌رسانی کاربران</p>
                </div>
                <a href="/admin/messages/reports" class="text-blue-600 hover:text-blue-800">بازگشت به گزارش‌ها</a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border p-6">
                <p class="text-sm text-gray-600">تمام پیام‌ها</p>
                <p class="text-3xl font-bold text-gray-900 mt-3"><?php echo $stats['total_messages']; ?></p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-6">
                <p class="text-sm text-gray-600">گزارش‌های پیام</p>
                <p class="text-3xl font-bold text-gray-900 mt-3"><?php echo $stats['total_reports']; ?></p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-6">
                <p class="text-sm text-gray-600">گزارش‌های در انتظار</p>
                <p class="text-3xl font-bold text-gray-900 mt-3"><?php echo $stats['pending_reports']; ?></p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-lg shadow-sm border p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">مسدودهای فعلی</h2>
                <p class="text-sm text-gray-600">تعداد کاربران مسدود شده تاکنون: <?php echo $stats['total_blocks']; ?></p>
                <p class="text-sm text-gray-600 mt-2">پیام‌های امروز: <?php echo $stats['today_messages']; ?></p>
                <p class="text-sm text-gray-600">گزارش‌های امروز: <?php echo $stats['today_reports']; ?></p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">برترین گزارش‌دهندگان</h2>
                <?php if (empty($top_reporters)): ?>
                    <p class="text-sm text-gray-500">هیچ گزارشی ثبت نشده است.</p>
                <?php else: ?>
                    <ul class="space-y-3 text-sm text-gray-700">
                        <?php foreach ($top_reporters as $reporter): ?>
                            <li class="flex justify-between">
                                <span><?php echo e($reporter['name']); ?></span>
                                <span class="font-semibold"><?php echo $reporter->count; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
