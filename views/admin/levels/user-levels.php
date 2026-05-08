<?php
$title = 'تاریخچه تغییرات سطح کاربران';
$layout = 'admin';
ob_start();
$typeLabels = ['upgrade'=>'ارتقا','downgrade'=>'سقوط','purchase'=>'خرید','expire'=>'انقضا','admin'=>'ادمین','reset'=>'ریست'];
$typeClasses = ['upgrade'=>'badge-success','downgrade'=>'badge-danger','purchase'=>'badge-info','expire'=>'badge-warning','admin'=>'badge-secondary','reset'=>'badge-secondary'];
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <h4 class="page-title mb-0"><i class="material-icons text-primary">history</i> تاریخچه تغییرات سطح</h4>
        <a href="<?= url('/admin/levels') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت
        </a>
    </div>
</div>

<!-- فیلتر -->
<div class="card mt-3">
    <div class="card-body">
        <form method="GET" action="<?= url('/admin/levels/history') ?>">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <select name="change_type" class="form-select form-select-sm">
                        <option value="">همه انواع</option>
                        <?php foreach ($typeLabels as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= ($filters['change_type'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <select name="to_level" class="form-select form-select-sm">
                        <option value="">همه سطوح</option>
                        <?php foreach ($levels as $l): ?>
                        <option value="<?= e($l->slug) ?>" <?= ($filters['to_level'] ?? '') === $l->slug ? 'selected' : '' ?>><?= e($l->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <input type="number" name="user_id" class="form-control form-control-sm" placeholder="شناسه کاربر" value="<?= e($filters['user_id'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-2">
                    <button type="submit" class="btn btn-primary btn-sm">فیلتر</button>
                    <a href="<?= url('/admin/levels/history') ?>" class="btn btn-outline-secondary btn-sm">پاکسازی</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card mt-3 mb-4">
    <div class="card-header d-flex justify-content-between">
        <h6 class="card-title mb-0">تاریخچه</h6>
        <span class="badge bg-info"><?= number_format($total) ?> رکورد</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:12px;">
                <thead>
                    <tr><th>#</th><th>کاربر</th><th>از سطح</th><th>به سطح</th><th>نوع</th><th>دلیل</th><th>تاریخ</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">رکوردی یافت نشد.</td></tr>
                    <?php else: ?>
                    <?php foreach ($items as $idx => $h): ?>
                    <tr>
                        <td><?= (($page - 1) * 30) + $idx + 1 ?></td>
                        <td>
                            <a href="<?= url('/admin/users/' . $h->user_id . '/edit') ?>" style="font-size:12px;">
                                <?= e($h->user_name ?? '—') ?>
                            </a>
                        </td>
                        <td><?= e($h->from_level_name ?? $h->from_level ?? '—') ?></td>
                        <td><strong><?= e($h->to_level_name ?? $h->to_level) ?></strong></td>
                        <td><span class="badge <?= $typeClasses[$h->change_type] ?? '' ?>"><?= e($typeLabels[$h->change_type] ?? $h->change_type) ?></span></td>
                        <td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?= e($h->reason ?? '—') ?></td>
                        <td style="font-size:10px;"><?= to_jalali($h->created_at ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-center">
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($i = 1; $i <= \min($pages, 20); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= url('/admin/levels/history?page=' . $i . '&change_type=' . e($filters['change_type'] ?? '') . '&to_level=' . e($filters['to_level'] ?? '') . '&user_id=' . e($filters['user_id'] ?? '')) ?>"><?= e($i) ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>