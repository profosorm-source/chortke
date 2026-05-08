<?php
$layout = 'user';
$ads    = $ads  ?? [];
$page   = $page ?? 1;
ob_start();
?>
<div class="page-header d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="material-icons align-middle me-1">campaign</i> آگهی‌های من</h4>
    <div class="d-flex gap-2">
        <a href="<?= url('/social-ads/dashboard') ?>" class="btn btn-outline-secondary btn-sm">داشبورد</a>
        <a href="<?= url('/social-ads/create') ?>" class="btn btn-primary btn-sm">+ ثبت آگهی</a>
    </div>
</div>

<?= flash_message() ?>

<?php if (empty($ads)): ?>
    <div class="text-center py-5">
        <i class="material-icons text-muted" style="font-size:48px;">campaign</i>
        <h5 class="mt-2">هنوز آگهی ندارید</h5>
        <a href="<?= url('/social-ads/create') ?>" class="btn btn-primary mt-2">اولین آگهی را ثبت کنید</a>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($ads as $ad): ?>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <span class="badge bg-info text-dark me-1"><?= e($ad->platform ?? '') ?></span>
                                <span class="badge bg-secondary"><?= e($ad->task_type ?? '') ?></span>
                            </div>
                            <?php $status = $ad->status ?? 'draft'; ?>
                            <span class="badge bg-<?= $status==='active'?'success':($status==='paused'?'warning':'secondary') ?>">
                                <?= $status==='active'?'فعال':($status==='paused'?'متوقف':$status) ?>
                            </span>
                        </div>
                        <h6 class="card-title"><?= e($ad->title ?? '') ?></h6>
                        <div class="row text-center g-2 mt-1">
                            <div class="col-4">
                                <div class="text-muted" style="font-size:11px;">اجرا شده</div>
                                <div class="fw-bold"><?= number_format($ad->total_executions ?? 0) ?></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted" style="font-size:11px;">تأیید شده</div>
                                <div class="fw-bold text-success"><?= number_format($ad->approved_count ?? 0) ?></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted" style="font-size:11px;">باقیمانده</div>
                                <div class="fw-bold"><?= number_format($ad->remaining_slots ?? 0) ?></div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="fw-bold text-success"><?= number_format($ad->reward ?? 0) ?> تومان / نفر</div>
                            <div class="d-flex gap-1">
                                <a href="<?= url('/social-ads/'.($ad->id??'')) ?>" class="btn btn-outline-primary btn-sm">جزئیات</a>
                                <?php if ($status === 'active'): ?>
                                    <button class="btn btn-outline-warning btn-sm btn-toggle-status"
                                            data-id="<?= (int)$ad->id ?>" data-action="pause">توقف</button>
                                <?php elseif ($status === 'paused'): ?>
                                    <button class="btn btn-outline-success btn-sm btn-toggle-status"
                                            data-id="<?= (int)$ad->id ?>" data-action="resume">فعال</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex justify-content-between mt-3">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>" class="btn btn-outline-secondary btn-sm">قبلی</a>
        <?php else: ?><span></span><?php endif; ?>
        <?php if (count($ads) === 20): ?>
            <a href="?page=<?= $page+1 ?>" class="btn btn-outline-secondary btn-sm">بعدی</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-toggle-status').forEach(btn => {
    btn.addEventListener('click', async function () {
        const id = this.dataset.id;
        const action = this.dataset.action;
        const res = await fetch(`<?= url('/social-ads') ?>/${id}/${action}`, {
            method: 'POST',
            headers: {'X-CSRF-Token': '<?= csrf_token() ?>'}
        });
        const data = await res.json();
        if (data.success) location.reload();
        else alert(data.message || 'خطا');
    });
});
</script>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
