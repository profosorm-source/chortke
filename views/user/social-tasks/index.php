<?php
$title  = $title  ?? 'تسک‌های شبکه اجتماعی';
$layout = 'user';
ob_start();
$tasks       = $tasks       ?? [];
$trust_score = $trust_score ?? 50;
$filters     = $filters     ?? [];
$platforms   = $platforms   ?? [];
$task_types  = $task_types  ?? [];
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-ad-tasks.css') ?>">

<div class="page-header d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="material-icons align-middle me-1">share</i> تسک‌های اجتماعی</h4>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary fs-6" title="امتیاز اعتماد شما">
            <i class="material-icons" style="font-size:14px;vertical-align:middle;">verified_user</i>
            Trust: <?= number_format($trust_score, 0) ?>
        </span>
        <a href="<?= url('/social-tasks/dashboard') ?>" class="btn btn-outline-secondary btn-sm">داشبورد من</a>
    </div>
</div>

<form method="GET" action="<?= url('/social-tasks') ?>" class="card card-body mb-3 p-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label form-label-sm mb-1">پلتفرم</label>
            <select name="platform" class="form-select form-select-sm">
                <option value="">همه</option>
                <?php foreach ($platforms as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= ($filters['platform'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label form-label-sm mb-1">نوع تسک</label>
            <select name="task_type" class="form-select form-select-sm">
                <option value="">همه</option>
                <?php foreach ($task_types as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= ($filters['task_type'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label form-label-sm mb-1">مرتب‌سازی</label>
            <select name="sort" class="form-select form-select-sm">
                <option value="random"     <?= ($filters['sort'] ?? 'random') === 'random'    ? 'selected' : '' ?>>تصادفی</option>
                <option value="price_desc" <?= ($filters['sort'] ?? '') === 'price_desc'      ? 'selected' : '' ?>>بیشترین پاداش</option>
                <option value="price_asc"  <?= ($filters['sort'] ?? '') === 'price_asc'       ? 'selected' : '' ?>>کمترین پاداش</option>
                <option value="newest"     <?= ($filters['sort'] ?? '') === 'newest'          ? 'selected' : '' ?>>جدیدترین</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label form-label-sm mb-1">جستجو</label>
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="عنوان تسک..." value="<?= e($filters['search'] ?? '') ?>">
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-primary btn-sm w-100">
                <i class="material-icons" style="font-size:16px;">search</i>
            </button>
        </div>
    </div>
</form>

<?php if (empty($tasks)): ?>
    <div class="empty-state text-center py-5">
        <i class="material-icons text-muted" style="font-size:48px;">inbox</i>
        <h5 class="mt-2">تسک فعالی یافت نشد</h5>
        <p class="text-muted">لطفاً بعداً مراجعه کنید یا فیلترها را تغییر دهید.</p>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($tasks as $task): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge bg-info text-dark"><?= e($task->platform ?? '') ?></span>
                            <span class="badge bg-secondary"><?= e($task->task_type ?? '') ?></span>
                        </div>
                        <h6 class="card-title"><?= e(mb_substr($task->title ?? '', 0, 60)) ?></h6>
                        <?php if (!empty($task->description)): ?>
                            <p class="card-text text-muted small"><?= e(mb_substr($task->description, 0, 80)) ?>...</p>
                        <?php endif; ?>
                        <?php if (!empty($task->target_username)): ?>
                            <div class="text-muted small mb-2">
                                <i class="material-icons align-middle" style="font-size:14px;">person</i>
                                @<?= e($task->target_username) ?>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div>
                                <div class="fw-bold text-success">
                                    <?= number_format($task->display_reward ?? $task->reward ?? 0) ?> تومان
                                </div>
                                <small class="text-muted">
                                    امتیاز تبلیغ‌دهنده: <?= number_format($task->advertiser_trust ?? 50, 0) ?>
                                </small>
                            </div>
                            <button class="btn btn-primary btn-sm btn-start-task"
                                    data-ad-id="<?= (int)$task->id ?>">
                                شروع
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-start-task').forEach(btn => {
    btn.addEventListener('click', async function () {
        this.disabled = true;
        this.textContent = '...';
        try {
            const res = await fetch('<?= url('/social-tasks/start') ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': '<?= csrf_token() ?>'},
                body: JSON.stringify({ad_id: parseInt(this.dataset.adId)})
            });
            const data = await res.json();
            if (data.success) {
                window.location.href = '<?= url('/social-tasks') ?>/' + data.execution_id + '/execute';
            } else {
                alert(data.message || 'خطا در شروع تسک');
                this.disabled = false;
                this.textContent = 'شروع';
            }
        } catch (e) {
            alert('خطای اتصال');
            this.disabled = false;
            this.textContent = 'شروع';
        }
    });
});
</script>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>
