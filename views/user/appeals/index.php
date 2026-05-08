<?php
$title = $title ?? 'لیست اعتراضات کاربر';
$layout='user';
 ob_start();
?>
<div class="container mx-auto px-4 py-8">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">اعتراضات من</h1>
                    <p class="text-gray-600 mt-1">پیگیری وضعیت اعتراضات و درخواست‌های شما</p>
                </div>
                <a href="/appeals/create" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium">
                    اعتراض جدید
                </a>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow-sm border p-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-3 rounded-full">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm text-gray-600">کل اعتراضات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo e($stats['total_appeals'] ?? 0); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border p-4">
                <div class="flex items-center">
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm text-gray-600">در انتظار</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo e($stats['pending'] ?? 0); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border p-4">
                <div class="flex items-center">
                    <div class="bg-green-100 p-3 rounded-full">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm text-gray-600">تأیید شده</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo e($stats['approved'] ?? 0); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border p-4">
                <div class="flex items-center">
                    <div class="bg-red-100 p-3 rounded-full">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm text-gray-600">رد شده</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo e($stats['rejected'] ?? 0); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Appeals List -->
        <div class="bg-white rounded-lg shadow-sm border">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">لیست اعتراضات</h2>
            </div>

            <div class="divide-y divide-gray-200">
                <?php if (empty($appeals)): ?>
                    <div class="p-8 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">هیچ اعتراضی یافت نشد</h3>
                        <p class="mt-1 text-sm text-gray-500">شما هنوز هیچ اعتراضی ثبت نکرده‌اید.</p>
                        <div class="mt-6">
                            <a href="/appeals/create" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium">
                                ثبت اولین اعتراض
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($appeals as $appeal): ?>
                        <div class="p-6 hover:bg-gray-50">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center">
                                        <h3 class="text-lg font-medium text-gray-900">
                                            <?php echo e($appeal['title']); ?>
                                        </h3>
                                        <span class="mr-3 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php
                                            switch ($appeal['status']) {
                                                case 'pending':
                                                    echo 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                case 'under_review':
                                                    echo 'bg-blue-100 text-blue-800';
                                                    break;
                                                case 'approved':
                                                    echo 'bg-green-100 text-green-800';
                                                    break;
                                                case 'rejected':
                                                    echo 'bg-red-100 text-red-800';
                                                    break;
                                                default:
                                                    echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php
                                            $statusLabels = [
                                                'pending' => 'در انتظار',
                                                'under_review' => 'در حال بررسی',
                                                'approved' => 'تأیید شده',
                                                'rejected' => 'رد شده'
                                            ];
                                            echo e($statusLabels[$appeal['status']] ?? $appeal['status']);
                                            ?>
                                        </span>
                                        <?php if ($appeal['auto_decision']): ?>
                                            <span class="mr-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                تصمیم خودکار
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="mt-1 text-sm text-gray-600">
                                        <?php echo e(substr($appeal['description'], 0, 150)); ?>
                                        <?php if (strlen($appeal['description']) > 150): ?>...<?php endif; ?>
                                    </p>
                                    <div class="mt-2 flex items-center text-sm text-gray-500">
                                        <span>نوع: <?php echo e($appeal['appeal_type']); ?></span>
                                        <span class="mx-2">•</span>
                                        <span><?php echo e($appeal['created_at']); ?></span>
                                        <?php if ($appeal['attachment_count'] > 0): ?>
                                            <span class="mx-2">•</span>
                                            <span><?php echo e($appeal['attachment_count']); ?> پیوست</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3 rtl:space-x-reverse">
                                    <a href="/appeals/<?php echo e($appeal['id']); ?>" class="text-blue-600 hover:text-blue-900 text-sm font-medium">
                                        مشاهده جزئیات
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>