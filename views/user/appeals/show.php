<?php
$title = $title ?? 'نمایش جزئیات اعتراض';
$layout='user';
 ob_start();
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
            <div class="flex items-center justify-between">
                <a href="/appeals" class="text-gray-400 hover:text-gray-600 ml-4">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </a>
                <div class="flex-1">
                    <h1 class="text-2xl font-bold text-gray-900"><?php echo e($appeal['title']); ?></h1>
                    <div class="flex items-center mt-2">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
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
                                'pending' => 'در انتظار بررسی',
                                'under_review' => 'در حال بررسی',
                                'approved' => 'تأیید شده',
                                'rejected' => 'رد شده'
                            ];
                            echo $statusLabels[$appeal['status']] ?? $appeal['status'];
                            ?>
                        </span>
                        <?php if ($appeal['auto_decision']): ?>
                            <span class="mr-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                تصمیم خودکار
                            </span>
                        <?php endif; ?>
                        <span class="mr-4 text-sm text-gray-500">
                            ثبت شده در: <?php echo e($appeal['created_at']); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Appeal Details -->
                <div class="bg-white rounded-lg shadow-sm border">
                    <div class="p-6 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">جزئیات اعتراض</h2>
                    </div>
                    <div class="p-6">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">نوع اعتراض</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo e($appeal['appeal_type']); ?></dd>
                            </div>
                            <?php if ($appeal['reference_id']): ?>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">شماره مرجع</dt>
                                    <dd class="mt-1 text-sm text-gray-900"><?php echo e($appeal['reference_id']); ?></dd>
                                </div>
                            <?php endif; ?>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">توضیحات</dt>
                                <dd class="mt-1 text-sm text-gray-900 whitespace-pre-line"><?php echo e($appeal['description']); ?></dd>
                            </div>
                            <?php if ($appeal['decision']): ?>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">تصمیم نهایی</dt>
                                    <dd class="mt-1 text-sm text-gray-900"><?php echo e($appeal['decision']); ?></dd>
                                </div>
                                <?php if ($appeal['decision_at']): ?>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">تاریخ تصمیم</dt>
                                        <dd class="mt-1 text-sm text-gray-900"><?php echo e($appeal['decision_at']); ?></dd>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>

                <!-- Attachments -->
                <?php if (!empty($attachments)): ?>
                    <div class="bg-white rounded-lg shadow-sm border">
                        <div class="p-6 border-b border-gray-200">
                            <h2 class="text-lg font-semibold text-gray-900">پیوست‌ها</h2>
                        </div>
                        <div class="p-6">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <?php foreach ($attachments as $attachment): ?>
                                    <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                                        <div class="flex-shrink-0">
                                            <?php if (strpos($attachment['mime_type'], 'image/') === 0): ?>
                                                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                </svg>
                                            <?php elseif ($attachment['mime_type'] === 'application/pdf'): ?>
                                                <svg class="w-8 h-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                </svg>
                                            <?php else: ?>
                                                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                </svg>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mr-3 flex-1 min-w-0">
                                            <p class="text-sm font-medium text-gray-900 truncate">
                                                <?php echo e($attachment['original_name']); ?>
                                            </p>
                                            <p class="text-sm text-gray-500">
                                                <?php echo e(number_format($attachment['file_size'] / 1024, 1)); ?> KB
                                            </p>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <a href="/uploads/appeals/<?php echo e($attachment['filename']); ?>" target="_blank"
                                               class="text-blue-600 hover:text-blue-900 text-sm font-medium">
                                                دانلود
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Responses -->
                <?php if (!empty($responses)): ?>
                    <div class="bg-white rounded-lg shadow-sm border">
                        <div class="p-6 border-b border-gray-200">
                            <h2 class="text-lg font-semibold text-gray-900">پاسخ‌ها و پیگیری</h2>
                        </div>
                        <div class="divide-y divide-gray-200">
                            <?php foreach ($responses as $response): ?>
                                <div class="p-6">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0">
                                            <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                                                <span class="text-sm font-medium text-blue-600">
                                                    <?php echo substr($response['admin_username'] ?? 'ادمین', 0, 1); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="mr-4 flex-1">
                                            <div class="flex items-center">
                                                <h4 class="text-sm font-medium text-gray-900">
                                                    پاسخ از <?php echo e($response['admin_username'] ?? 'ادمین'); ?>
                                                </h4>
                                                <span class="mr-2 text-sm text-gray-500">
                                                    <?php echo e($response['created_at']); ?>
                                                </span>
                                            </div>
                                            <p class="mt-2 text-sm text-gray-700 whitespace-pre-line">
                                                <?php echo e($response['response']); ?>
                                            </p>
                                            <?php if ($response['status_change']): ?>
                                                <div class="mt-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    وضعیت تغییر کرد به: <?php echo e($statusLabels[$response['status_change']] ?? $response['status_change']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Status Timeline -->
                <div class="bg-white rounded-lg shadow-sm border p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">وضعیت اعتراض</h3>
                    <div class="space-y-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                                    <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="mr-4">
                                <p class="text-sm font-medium text-gray-900">ثبت اعتراض</p>
                                <p class="text-sm text-gray-500"><?php echo e($appeal['created_at']); ?></p>
                            </div>
                        </div>

                        <?php if (in_array($appeal['status'], ['under_review', 'approved', 'rejected'])): ?>
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                    </div>
                                </div>
                                <div class="mr-4">
                                    <p class="text-sm font-medium text-gray-900">بررسی توسط ادمین</p>
                                    <p class="text-sm text-gray-500"><?php echo $appeal['updated_at'] ?? 'در حال بررسی'; ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (in_array($appeal['status'], ['approved', 'rejected'])): ?>
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 <?php echo $appeal['status'] === 'approved' ? 'bg-green-100' : 'bg-red-100'; ?> rounded-full flex items-center justify-center">
                                        <?php if ($appeal['status'] === 'approved'): ?>
                                            <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        <?php else: ?>
                                            <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="mr-4">
                                    <p class="text-sm font-medium text-gray-900">
                                        <?php echo e($appeal['status'] === 'approved' ? 'تأیید اعتراض' : 'رد اعتراض'); ?>
                                    </p>
                                    <p class="text-sm text-gray-500"><?php echo e($appeal['decision_at'] ?? ''); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-white rounded-lg shadow-sm border p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">اقدامات</h3>
                    <div class="space-y-3">
                        <a href="/appeals/create" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium text-center block">
                            اعتراض جدید
                        </a>
                        <a href="/support" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg font-medium text-center block">
                            تماس با پشتیبانی
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>