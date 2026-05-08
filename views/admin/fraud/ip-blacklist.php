<?php
$pageTitle = 'مدیریت IP های مسدود';
ob_start();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>مدیریت IP های مسدود</h4>
        <button class="btn btn-primary" data-toggle="modal" data-target="#addIPModal">
            <i class="material-icons">add</i> افزودن IP
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
                            <th>IP Address</th>
                            <th>دلیل</th>
                            <th>مسدودکننده</th>
                            <th>خودکار</th>
                            <th>انقضا</th>
                            <th>تاریخ</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ips)): ?>
                            <tr>
                                <td colspan="7" class="text-center">هیچ IP مسدودی وجود ندارد</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ips as $ip): ?>
                            <tr>
                                <td><code><?= e($ip->ip_address) ?></code></td>
                                <td><?= e($ip->reason ?? '-') ?></td>
                                <td>
                                    <?php if ($ip->blocked_by): ?>
                                        <small>ادمین #<?= e($ip->blocked_by) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">-</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($ip->auto_blocked): ?>
                                        <span class="badge badge-warning">خودکار</span>
                                    <?php else: ?>
                                        <span class="badge badge-info">دستی</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($ip->expires_at): ?>
                                        <small><?= to_jalali($ip->expires_at) ?></small>
                                    <?php else: ?>
                                        <span class="badge badge-danger">دائمی</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= to_jalali($ip->created_at) ?></small></td>
                                <td>
                                    <form method="POST" action="<?= url('/admin/fraud/ip-unblock') ?>" style="display:inline;">
                                        <input type="hidden" name="id" value="<?= e($ip->id) ?>">
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

<!-- Modal افزودن IP -->
<div class="modal fade" id="addIPModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('/admin/fraud/ip-block') ?>">
                <div class="modal-header">
                    <h5 class="modal-title">افزودن IP به لیست سیاه</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>IP Address *</label>
                        <input type="text" name="ip" class="form-control" placeholder="192.168.1.1" required>
                    </div>
                    <div class="form-group">
                        <label>دلیل</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="دلیل مسدودسازی..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>مدت مسدودیت</label>
                        <select name="duration" class="form-control">
                            <option value="">دائمی</option>
                            <option value="3600">1 ساعت</option>
                            <option value="86400">1 روز</option>
                            <option value="604800">1 هفته</option>
                            <option value="2592000">1 ماه</option>
                        </select>
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
