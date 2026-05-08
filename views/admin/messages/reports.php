<?php
/**
 * لیست گزارش‌های پیام
 */
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">گزارش‌های پیام</h1>
                    <p class="text-gray-600 mt-1">مدیریت گزارش‌های نامناسب یا تخلفات</p>
                </div>
                <div class="flex gap-2">
                    <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        آمار
                    </button>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow-sm border p-4 mb-6">
            <div class="flex gap-4">
                <div class="flex-1">
                    <select id="status-filter" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="all">همه وضعیت‌ها</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>در انتظار</option>
                        <option value="investigating">بررسی‌ در دست</option>
                        <option value="resolved">حل‌شده</option>
                        <option value="dismissed">رد‌شده</option>
                    </select>
                </div>
                <input type="text" placeholder="جستجو..." class="border border-gray-300 rounded-lg px-3 py-2 w-64">
                <button class="px-4 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg">جستجو</button>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border p-4">
                <p class="text-sm text-gray-600">کل گزارش‌ها</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">145</p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-4">
                <p class="text-sm text-gray-600">در انتظار</p>
                <p class="text-2xl font-bold text-red-600 mt-1">32</p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-4">
                <p class="text-sm text-gray-600">بررسی‌ درحال</p>
                <p class="text-2xl font-bold text-yellow-600 mt-1">18</p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-4">
                <p class="text-sm text-gray-600">حل‌شده</p>
                <p class="text-2xl font-bold text-green-600 mt-1">95</p>
            </div>
        </div>

        <!-- Reports Table -->
        <div class="bg-white rounded-lg shadow-sm border overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="px-6 py-3 text-right text-sm font-semibold text-gray-900">شناسه</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold text-gray-900">گزارش‌دهنده</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold text-gray-900">دلیل</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold text-gray-900">وضعیت</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold text-gray-900">تاریخ</th>
                        <th class="px-6 py-3 text-right text-sm font-semibold text-gray-900">اقدام</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                هیچ گزارشی یافت نشد
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reports as $report): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm text-gray-900">#<?php echo $report['id']; ?></td>
                                <td class="px-6 py-4 text-sm">
                                    <div>
                                        <p class="font-medium text-gray-900"><?php echo e($report['reporter_name']); ?></p>
                                        <p class="text-gray-500"><?php echo e($report['reporter_email']); ?></p>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900"><?php echo $report['reason']; ?></td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium
                                        <?php
                                        switch($report['status']) {
                                            case 'pending': echo 'bg-red-100 text-red-800'; break;
                                            case 'investigating': echo 'bg-yellow-100 text-yellow-800'; break;
                                            case 'resolved': echo 'bg-green-100 text-green-800'; break;
                                            case 'dismissed': echo 'bg-gray-100 text-gray-800'; break;
                                        }
                                        ?>
                                    ">
                                        <?php echo $report['status']; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <?php echo format_time($report['created_at']); ?>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <a href="/admin/messages/reports/<?php echo $report['id']; ?>" class="text-blue-600 hover:text-blue-800 font-medium">
                                        بررسی
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="mt-6 flex justify-center gap-2">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $status; ?>" class="px-3 py-2 border rounded-lg hover:bg-gray-50">قبلی</a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&status=<?php echo $status; ?>" 
                       class="px-3 py-2 border rounded-lg <?php echo $i === $page ? 'bg-blue-600 text-white' : 'hover:bg-gray-50'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $status; ?>" class="px-3 py-2 border rounded-lg hover:bg-gray-50">بعدی</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('status-filter').addEventListener('change', (e) => {
    window.location.href = '?status=' + e.target.value;
});
</script>