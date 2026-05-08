<?php
$title = $title ?? 'تسک‌های SEO';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-seo.css') ?>">

<div class="page-header">
    <h4><i class="material-icons">work</i> تسک‌های SEO</h4>
    <a href="<?= url('/seo/history') ?>" class="btn btn-secondary">
        <i class="material-icons">history</i> تاریخچه
    </a>
</div>

<div class="stats-row">
    <div class="stat-card stat-green">
        <span class="stat-num"><?= $stats['total']->completed ?? 0 ?></span>
        <span class="stat-lbl">تکمیل شده</span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-num"><?= number_format($stats['total']->total_earned ?? 0) ?></span>
        <span class="stat-lbl">کل درآمد (تومان)</span>
    </div>
    <div class="stat-card stat-orange">
        <span class="stat-num"><?= $stats['today'] ?? 0 ?></span>
        <span class="stat-lbl">امروز</span>
    </div>
    <div class="stat-card stat-purple">
        <span class="stat-num"><?= round($stats['total']->avg_score ?? 0, 1) ?></span>
        <span class="stat-lbl">میانگین امتیاز</span>
    </div>
</div>

<div class="search-box">
    <form method="GET" action="<?= url('/seo') ?>">
        <input type="text" name="search" class="form-control" 
               placeholder="جستجوی کلمه کلیدی..." value="<?= e($search ?? '') ?>">
        <button type="submit" class="btn btn-primary">
            <i class="material-icons">search</i>
        </button>
    </form>
</div>

<div class="alert-box alert-info mb-15">
    <i class="material-icons">info</i>
    <div>
        <strong>راهنما:</strong> تسک را انتخاب کنید. سایت هدف در یک صفحه جدید باز می‌شود. 
        با صفحه تعامل کنید و پس از مدت کافی، پاداش متناسب با کیفیت تعامل شما محاسبه می‌شود.
    </div>
</div>

<?php if (empty($ads)): ?>
    <div class="empty-state">
        <i class="material-icons">inbox</i>
        <h5>تسک فعالی وجود ندارد</h5>
        <p>لطفاً بعداً مراجعه کنید</p>
    </div>
<?php else: ?>
    <div class="seo-grid">
        <?php foreach ($ads as $ad): ?>
            <div class="seo-card" data-id="<?= $ad->id ?>">
                <div class="seo-header">
                    <h6><?= e($ad->title) ?></h6>
                    <span class="badge badge-success">فعال</span>
                </div>
                
                <div class="seo-keyword">
                    <i class="material-icons">search</i>
                    <span><?= e($ad->keyword) ?></span>
                </div>
                
                <div class="seo-meta">
                    <span><i class="material-icons">link</i> <?= e(parse_url($ad->site_url, PHP_URL_HOST)) ?></span>
                    <span><i class="material-icons">timer</i> ~<?= $ad->target_duration ?>ثانیه</span>
                </div>
                
                <div class="seo-payout">
                    <div class="payout-range">
                        <span class="min"><?= number_format($ad->min_payout) ?></span>
                        <span class="sep">تا</span>
                        <span class="max"><?= number_format($ad->max_payout) ?></span>
                        <small>تومان</small>
                    </div>
                    <small class="text-muted">بر اساس امتیاز</small>
                </div>
                
                <div class="seo-info">
                    <small>
                        <i class="material-icons">stars</i>
                        حداقل امتیاز: <?= $ad->min_score ?>
                    </small>
                    <small>
                        <i class="material-icons">people</i>
                        حداکثر <?= $ad->max_per_day ?> نفر/روز
                    </small>
                </div>
                
                <button class="btn btn-primary btn-block btn-start-task" 
                        data-id="<?= $ad->id ?>" 
                        data-title="<?= e($ad->title) ?>">
                    <i class="material-icons">play_arrow</i> شروع تسک
                </button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-start-task').forEach(btn => {
    btn.addEventListener('click', function() {
        const adId = this.dataset.id;
        const title = this.dataset.title;
        
        Swal.fire({
            title: 'شروع تسک',
            html: `<p>تسک: <strong>${title}</strong></p>
                   <p style="font-size:12px;color:#666;">
                   صفحه هدف باز شده و باید با آن تعامل کنید. پاداش شما بر اساس کیفیت تعامل محاسبه می‌شود.
                   </p>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'شروع',
            cancelButtonText: 'انصراف',
            confirmButtonColor: '#4caf50'
        }).then(result => {
            if (result.isConfirmed) {
                fetch('<?= url('/seo/start') ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                    },
                    body: JSON.stringify({
                        ad_id: adId,
                        _csrf_token: '<?= csrf_token() ?>'
                    })
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        notyf.success(d.message);
                        if (d.execution && d.execution.id) {
                            setTimeout(() => {
                                window.location.href = '<?= url('/seo') ?>/' + d.execution.id + '/execute';
                            }, 800);
                        }
                    } else {
                        notyf.error(d.message);
                    }
                })
                .catch(() => notyf.error('خطا در ارتباط با سرور'));
            }
        });
    });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>
