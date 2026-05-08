<?php
/**
 * لیست کاربران مسدود
 */
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-6xl mx-auto">
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">کاربران مسدود</h1>
                    <p class="text-gray-600 mt-1">مدیریت بین کاربران و بررسی بلاک‌ها</p>
                </div>
                <a href="/admin/messages/reports" class="text-blue-600 hover:text-blue-800">بازگشت به گزارش‌ها</a>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border overflow-hidden">
            <table class="w-full text-right">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="px-6 py-3 text-sm font-semibold text-gray-900">#</th>
                        <th class="px-6 py-3 text-sm font-semibold text-gray-900">مسدود کننده</th>
                        <th class="px-6 py-3 text-sm font-semibold text-gray-900">مسدود شده</th>
                        <th class="px-6 py-3 text-sm font-semibold text-gray-900">دلیل</th>
                        <th class="px-6 py-3 text-sm font-semibold text-gray-900">تاریخ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($blocked)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-gray-500">هیچ رکوردی یافت نشد.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($blocked as $item): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm text-gray-900"><?php echo $item['id']; ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?php echo e($item['blocker_name']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?php echo e($item['blocked_name']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-700"><?php echo e($item['reason']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-500"><?php echo format_time($item['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="mt-6 flex justify-center gap-2">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>" class="px-3 py-2 border rounded-lg hover:bg-gray-50">قبلی</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>" class="px-3 py-2 border rounded-lg <?php echo $i === $page ? 'bg-blue-600 text-white' : 'hover:bg-gray-50'; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>" class="px-3 py-2 border rounded-lg hover:bg-gray-50">بعدی</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
