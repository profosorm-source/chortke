<?php
ob_start();
?>
<div class="admin-page-header">
    <h1>تاریخچه حذف حساب</h1>
    <p class="text-muted">لیست حساب‌هایی که فرایند حذف آن‌ها تکمیل شده است.</p>
</div>

<div class="card mb-4">
    <div class="card-body">
        <?php if (empty($deleted_accounts)): ?>
            <div class="alert alert-info">هیچ حساب حذف‌شده‌ای ثبت نشده است.</div>
        <?php else: ?>
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>کاربر</th>
                        <th>ایمیل</th>
                        <th>درخواست</th>
                        <th>حذف</th>
                        <th>حذف توسط</th>
                        <th>دلیل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deleted_accounts as $account): ?>
                        <tr>
                            <td><?= e($account['username']) ?></td>
                            <td><?= e($account['email']) ?></td>
                            <td><?= jdate('Y/m/d H:i', strtotime($account['requested_at'])) ?></td>
                            <td><?= jdate('Y/m/d H:i', strtotime($account['deleted_at'])) ?></td>
                            <td><?= e($account['username'] ?? 'سیستم') ?></td>
                            <td><?= e($account['reason'] ?? 'بدون توضیح') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
