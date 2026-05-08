<?php
/**
 * لیست اعتراضات برای ادمین
 */
?>

<div class="admin-container">
    <div class="admin-header">
        <h1 class="admin-title">مدیریت اعتراضات</h1>
        <p class="admin-subtitle">بررسی و پاسخ به اعتراضات کاربران</p>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['total_appeals'] ?? 0; ?></div>
                <div class="stat-label">کل اعتراضات</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <svg class="w-8 h-8 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                <div class="stat-label">در انتظار بررسی</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['under_review'] ?? 0; ?></div>
                <div class="stat-label">در حال بررسی</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['approved'] ?? 0; ?></div>
                <div class="stat-label">تأیید شده</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['rejected'] ?? 0; ?></div>
                <div class="stat-label">رد شده</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['auto_decided'] ?? 0; ?></div>
                <div class="stat-label">تصمیم خودکار</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title">فیلترها</h3>
        </div>
        <div class="card-body">
            <form method="GET" class="filters-form">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="form-label">وضعیت</label>
                        <select name="status" class="form-select">
                            <option value="">همه</option>
                            <option value="pending" <?php echo ($filters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>در انتظار</option>
                            <option value="under_review" <?php echo ($filters['status'] ?? '') === 'under_review' ? 'selected' : ''; ?>>در حال بررسی</option>
                            <option value="approved" <?php echo ($filters['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>تأیید شده</option>
                            <option value="rejected" <?php echo ($filters['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>رد شده</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">اولویت</label>
                        <select name="priority" class="form-select">
                            <option value="">همه</option>
                            <option value="urgent" <?php echo ($filters['priority'] ?? '') === 'urgent' ? 'selected' : ''; ?>>فوری</option>
                            <option value="high" <?php echo ($filters['priority'] ?? '') === 'high' ? 'selected' : ''; ?>>بالا</option>
                            <option value="medium" <?php echo ($filters['priority'] ?? '') === 'medium' ? 'selected' : ''; ?>>متوسط</option>
                            <option value="low" <?php echo ($filters['priority'] ?? '') === 'low' ? 'selected' : ''; ?>>پایین</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">نوع اعتراض</label>
                        <select name="type" class="form-select">
                            <option value="">همه</option>
                            <option value="fraud_suspension">تعلیق حساب</option>
                            <option value="kyc_rejection">رد KYC</option>
                            <option value="order_dispute">اختلاف سفارش</option>
                            <option value="verification_rejection">رد تأیید</option>
                            <option value="account_limitation">محدودیت حساب</option>
                            <option value="payment_dispute">اختلاف پرداخت</option>
                            <option value="other">سایر</option>
                        </select>
                    </div>

                    <div class="flex items-end">
                        <button type="submit" class="btn btn-primary">فیلتر</button>
                        <a href="/admin/appeals" class="btn btn-secondary mr-2">پاک کردن</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Appeals List -->
    <div class="admin-card">
        <div class="card-header">
            <h3 class="card-title">لیست اعتراضات</h3>
        </div>
        <div class="card-body">
            <?php if (empty($appeals)): ?>
                <div class="empty-state">
                    <svg class="empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <h4 class="empty-title">هیچ اعتراضی یافت نشد</h4>
                    <p class="empty-message">در حال حاضر هیچ اعتراضی برای بررسی وجود ندارد.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>کاربر</th>
                                <th>نوع اعتراض</th>
                                <th>عنوان</th>
                                <th>وضعیت</th>
                                <th>اولویت</th>
                                <th>تاریخ ثبت</th>
                                <th>پیوست</th>
                                <th>اقدامات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appeals as $appeal): ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?php echo substr($appeal['username'], 0, 1); ?>
                                            </div>
                                            <div>
                                                <div class="user-name"><?php echo e($appeal['username']); ?></div>
                                                <div class="user-email"><?php echo e($appeal['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php
                                            $typeLabels = [
                                                'fraud_suspension' => 'تعلیق حساب',
                                                'kyc_rejection' => 'رد KYC',
                                                'order_dispute' => 'اختلاف سفارش',
                                                'verification_rejection' => 'رد تأیید',
                                                'account_limitation' => 'محدودیت حساب',
                                                'payment_dispute' => 'اختلاف پرداخت',
                                                'other' => 'سایر'
                                            ];
                                            echo $typeLabels[$appeal['appeal_type']] ?? $appeal['appeal_type'];
                                            ?>
                                        </span>
                                    </td>
                                    <td><?php echo e(substr($appeal['title'], 0, 50)); ?></td>
                                    <td>
                                        <?php
                                        $statusClasses = [
                                            'pending' => 'badge-warning',
                                            'under_review' => 'badge-info',
                                            'approved' => 'badge-success',
                                            'rejected' => 'badge-danger'
                                        ];
                                        $statusLabels = [
                                            'pending' => 'در انتظار',
                                            'under_review' => 'در حال بررسی',
                                            'approved' => 'تأیید شده',
                                            'rejected' => 'رد شده'
                                        ];
                                        ?>
                                        <span class="badge <?php echo $statusClasses[$appeal['status']] ?? 'badge-secondary'; ?>">
                                            <?php echo $statusLabels[$appeal['status']] ?? $appeal['status']; ?>
                                        </span>
                                        <?php if ($appeal['auto_decision']): ?>
                                            <br><small class="text-muted">خودکار</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $priorityClasses = [
                                            'urgent' => 'badge-danger',
                                            'high' => 'badge-warning',
                                            'medium' => 'badge-info',
                                            'low' => 'badge-secondary'
                                        ];
                                        $priorityLabels = [
                                            'urgent' => 'فوری',
                                            'high' => 'بالا',
                                            'medium' => 'متوسط',
                                            'low' => 'پایین'
                                        ];
                                        ?>
                                        <span class="badge <?php echo $priorityClasses[$appeal['priority']] ?? 'badge-secondary'; ?>">
                                            <?php echo $priorityLabels[$appeal['priority']] ?? $appeal['priority']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $appeal['created_at']; ?></td>
                                    <td>
                                        <?php if ($appeal['attachment_count'] > 0): ?>
                                            <span class="badge badge-info"><?php echo $appeal['attachment_count']; ?> فایل</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="/admin/appeals/<?php echo $appeal['id']; ?>" class="btn btn-sm btn-primary">
                                                مشاهده
                                            </a>
                                            <?php if ($appeal['status'] === 'pending'): ?>
                                                <button onclick="quickAction(<?php echo $appeal['id']; ?>, 'under_review')" class="btn btn-sm btn-info">
                                                    بررسی
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if (isset($pagination)): ?>
                    <div class="pagination">
                        <?php echo $pagination; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function quickAction(appealId, action) {
    if (confirm('آیا مطمئن هستید؟')) {
        fetch(`/admin/appeals/${appealId}/quick-action`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo csrf_token(); ?>'
            },
            body: JSON.stringify({ action: action })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('خطا: ' + (data.message || 'عملیات ناموفق'));
            }
        })
        .catch(error => {
            alert('خطا در ارتباط با سرور');
        });
    }
}
</script>