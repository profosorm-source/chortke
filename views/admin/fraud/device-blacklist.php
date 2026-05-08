<?php
$pageTitle = 'مدیریت دستگاه‌های مسدود';
ob_start();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>مدیریت دستگاه‌های مسدود</h4>
        <button class="btn btn-primary" data-toggle="modal" data-target="#addDeviceModal">
            <i class="material-icons">add</i> افزودن دستگاه
        </button>
    </div>

    <?php if (app()->session->getFlash('success')): ?>
        <div class="alert alert-success"><?= app()->session->getFlash('success') ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Fingerprint</th>
                            <th>دلیل</th>
                            <th>مسدودکننده</th>
                            <th>خودکار</th>
                            <th>انقضا</th>
                            <th>تاریخ</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($devices)): ?>
                            <tr>
                                <td colspan="7" class="text-center">هیچ دستگاه مسدودی وجود ندارد</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($devices as $device): ?>
                            <tr>
                                <td><code title="<?= e($device->fingerprint) ?>"><?= substr(e($device->fingerprint), 0, 16) ?>...</code></td>
                                <td><?= e($device->reason ?? '-') ?></td>
                                <td>
                                    <?php if ($device->blocked_by): ?>
                                        <small>ادمین #<?= e($device->blocked_by) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">-</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($device->auto_blocked): ?>
                                        <span class="badge badge-warning">خودکار</span>
                                    <?php else: ?>
                                        <span class="badge badge-info">دستی</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($device->expires_at): ?>
                                        <small><?= to_jalali($device->expires_at) ?></small>
                                    <?php else: ?>
                                        <span class="badge badge-danger">دائمی</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= to_jalali($device->created_at) ?></small></td>
                                <td>
                                    <form method="POST" action="<?= url('/admin/fraud/device-unblock') ?>" style="display:inline;">
                                        <input type="hidden" name="id" value="<?= e($device->id) ?>">
                                        <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('آیا مطمئن هستید؟')">
                                            رفع مسدودیت
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal افزودن دستگاه -->
<div class="modal fade" id="addDeviceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('/admin/fraud/device-block') ?>">
                <div class="modal-header">
                    <h5 class="modal-title">افزودن دستگاه به لیست سیاه</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Fingerprint *</label>
                        <input type="text" name="fingerprint" class="form-control" placeholder="SHA-256 hash" required>
                        <small class="form-text text-muted">می‌توانید از لیست Fingerprint های مشکوک در داشبورد کپی کنید</small>
                    </div>
                    <div class="form-group">
                        <label>دلیل</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="دلیل مسدودسازی..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">انصراف</button>
                    <button type="submit" class="btn btn-danger">مسدود کن</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once view_path('layouts.admin');
?>
