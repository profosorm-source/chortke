<?php
$title = $title ?? 'آگهی‌های SEO';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-seo-ad.css') ?>">

<div class="page-header">
    <h4><i class="material-icons">campaign</i> آگهی‌های SEO من</h4>
    <a href="<?= url('/seo-ad/create') ?>" class="btn btn-primary">
        <i class="material-icons">add</i> ثبت آگهی جدید
    </a>
</div>

<div class="stats-row">
    <div class="stat-card stat-blue">
        <span class="stat-num"><?= $stats['ads']->total_ads ?? 0 ?></span>
        <span class="stat-lbl">کل آگهی‌ها</span>
    </div>
    <div class="stat-card stat-green">
        <span class="stat-num"><?= $stats['ads']->active_ads ?? 0 ?></span>
        <span class="stat-lbl">فعال</span>
    </div>
    <div class="stat-card stat-orange">
        <span class="stat-num"><?= number_format($stats['executions']->total_spent ?? 0) ?></span>
        <span class="stat-lbl">هزینه شده (تومان)</span>
    </div>
    <div class="stat-card stat-purple">
        <span class="stat-num"><?= number_format($stats['executions']->unique_workers ?? 0) ?></span>
        <span class="stat-lbl">کاربران یکتا</span>
    </div>
</div>

<?php if (empty($ads)): ?>
    <div class="empty-state">
        <i class="material-icons">campaign</i>
        <h5>هنوز آگهی ثبت نکرده‌اید</h5>
        <p>برای شروع تبلیغات SEO، اولین آگهی خود را ثبت کنید</p>
        <a href="<?= url('/seo-ad/create') ?>" class="btn btn-primary">ثبت آگهی جدید</a>
    </div>
<?php else: ?>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>عنوان</th>
                    <th>کلمه کلیدی</th>
                    <th>بودجه کل</th>
                    <th>باقیمانده</th>
                    <th>اجراها</th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ads as $ad): ?>
                <tr>
                    <td>
                        <a href="<?= url('/seo-ad/' . $ad->id) ?>" class="link-primary">
                            <?= e($ad->title) ?>
                        </a>
                    </td>
                    <td><span class="badge badge-info"><?= e($ad->keyword) ?></span></td>
                    <td><?= number_format($ad->budget) ?></td>
                    <td>
                        <?= number_format($ad->remaining_budget) ?>
                        <small class="text-muted">
                            (<?= round(($ad->remaining_budget / $ad->budget) * 100) ?>%)
                        </small>
                    </td>
                    <td><?= $ad->executions_count ?></td>
                    <td>
                        <?php
                        $statusLabels = [
                            'pending' => '<span class="badge badge-warning">در انتظار تایید</span>',
                            'active' => '<span class="badge badge-success">فعال</span>',
                            'paused' => '<span class="badge badge-secondary">متوقف</span>',
                            'rejected' => '<span class="badge badge-danger">رد شده</span>',
                            'exhausted' => '<span class="badge badge-dark">تمام شده</span>',
                        ];
                        echo $statusLabels[$ad->status] ?? $ad->status;
                        ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="<?= url('/seo-ad/' . $ad->id) ?>" class="btn btn-sm btn-info" title="جزئیات">
                                <i class="material-icons">visibility</i>
                            </a>
                            <?php if ($ad->status === 'active'): ?>
                                <button class="btn btn-sm btn-warning btn-pause" data-id="<?= $ad->id ?>" title="توقف">
                                    <i class="material-icons">pause</i>
                                </button>
                            <?php elseif ($ad->status === 'paused'): ?>
                                <button class="btn btn-sm btn-success btn-resume" data-id="<?= $ad->id ?>" title="ادامه">
                                    <i class="material-icons">play_arrow</i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-pause').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        fetch('<?= url('/seo-ad') ?>/' + id + '/pause', {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': '<?= csrf_token() ?>'}
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                notyf.success('آگهی متوقف شد');
                setTimeout(() => location.reload(), 800);
            } else {
                notyf.error('خطا');
            }
        });
    });
});

document.querySelectorAll('.btn-resume').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        fetch('<?= url('/seo-ad') ?>/' + id + '/resume', {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': '<?= csrf_token() ?>'}
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                notyf.success('آگهی فعال شد');
                setTimeout(() => location.reload(), 800);
            } else {
                notyf.error('خطا');
            }
        });
    });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>
