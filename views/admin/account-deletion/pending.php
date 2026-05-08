<?php
ob_start();
?>
<div class="admin-page-header">
    <h1>درخواست‌های حذف حساب</h1>
    <p class="text-muted">مدیریت درخواست‌های حذف حساب کاربران و اعمال حذف فوری یا لغو درخواست.</p>
</div>

<div class="card mb-4">
    <div class="card-body">
        <?php if (empty($pending_deletions)): ?>
            <div class="alert alert-info">در حال حاضر هیچ درخواست حذف معلقی وجود ندارد.</div>
        <?php else: ?>
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>کاربر</th>
                        <th>ایمیل</th>
                        <th>درخواست‌شده</th>
                        <th>انقضا</th>
                        <th>دلیل</th>
                        <th>اقدامات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_deletions as $deletion): ?>
                        <tr>
                            <td><?= e($deletion['username']) ?></td>
                            <td><?= e($deletion['email']) ?></td>
                            <td><?= jdate('Y/m/d H:i', strtotime($deletion['requested_at'])) ?></td>
                            <td><?= jdate('Y/m/d H:i', strtotime($deletion['expires_at'])) ?></td>
                            <td><?= e($deletion['reason'] ?? 'بدون توضیح') ?></td>
                            <td>
                                <form action="<?= url('/admin/account-deletion/force-delete') ?>" method="POST" style="display:inline-block;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= e($deletion['user_id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">حذف فوری</button>
                                </form>
                                <form action="<?= url('/admin/account-deletion/cancel') ?>" method="POST" style="display:inline-block; margin-right: 0.5rem;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= e($deletion['user_id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-secondary">لغو</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
