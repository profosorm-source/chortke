<?php
ob_start();
?>

<div class="row g-4 mb-4">
    <!-- کل کاربران -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <span class="material-icons">people</span>
            <div class="stat-value"><?= number_format($stats['total_users'] ?? 0) ?></div>
            <div class="stat-label">کل کاربران</div>
        </div>
    </div>
    
    <!-- کاربران امروز -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
            <span class="material-icons">person_add</span>
            <div class="stat-value"><?= number_format($stats['today_users'] ?? 0) ?></div>
            <div class="stat-label">ثبت‌نام امروز</div>
        </div>
    </div>
    
    <!-- کاربران فعال -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
            <span class="material-icons">check_circle</span>
            <div class="stat-value"><?= number_format($stats['active_users'] ?? 0) ?></div>
            <div class="stat-label">کاربران فعال</div>
        </div>
    </div>
    
    <!-- کاربران بن شده -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
            <span class="material-icons">block</span>
            <div class="stat-value"><?= number_format($stats['banned_users'] ?? 0) ?></div>
            <div class="stat-label">کاربران بن شده</div>
        </div>
    </div>
</div>

<!-- کاربران اخیر -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>کاربران اخیر</span>
        <a href="<?= url('/admin/users') ?>" class="btn btn-sm btn-primary">مشاهده همه</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>نام و نام خانوادگی</th>
                        <th>ایمیل</th>
                        <th>موبایل</th>
                        <th>نقش</th>
                        <th>وضعیت</th>
                        <th>تاریخ ثبت‌نام</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentUsers)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                هیچ کاربری یافت نشد
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentUsers as $userItem): ?>
                            <tr>
                                <td><?= e($userItem['id']) ?></td>
                                <td><?= e($userItem['first_name']) ?> <?= e($userItem['last_name']) ?></td>
                                <td><?= e($userItem['email']) ?></td>
                                <td><?= e($userItem['mobile']) ?></td>
                                <td>
                                    <?php
                                    $roleColors = [
                                        'admin' => 'danger',
                                        'support' => 'warning',
                                        'user' => 'secondary'
                                    ];
                                    $roleNames = [
                                        'admin' => 'مدیر',
                                        'support' => 'پشتیبان',
                                        'user' => 'کاربر'
                                    ];
                                    $roleColor = $roleColors[$userItem['role']] ?? 'secondary';
                                    $roleName = $roleNames[$userItem['role']] ?? 'کاربر';
                                    ?>
                                    <span class="badge bg-<?= e($roleColor) ?>"><?= e($roleName) ?></span>
                                </td>
                                <td>
                                    <?php
                                    $statusColors = [
                                        'active' => 'success',
                                        'inactive' => 'secondary',
                                        'suspended' => 'warning',
                                        'banned' => 'danger'
                                    ];
                                    $statusNames = [
                                        'active' => 'فعال',
                                        'inactive' => 'غیرفعال',
                                        'suspended' => 'تعلیق',
                                        'banned' => 'بن شده'
                                    ];
                                    $statusColor = $statusColors[$userItem['status']] ?? 'secondary';
                                    $statusName = $statusNames[$userItem['status']] ?? 'نامشخص';
                                    ?>
                                    <span class="badge bg-<?= e($statusColor) ?>"><?= e($statusName) ?></span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= jdate('Y/m/d H:i', strtotime($userItem['created_at'])) ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>