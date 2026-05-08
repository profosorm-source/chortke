<?php
// views/admin/task-rechecks/index.php
$title = 'بررسی مجدد تسک‌ها';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-task-rechecks.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">verified</i> بررسی مجدد تسک‌ها</h4>
</div>

<div class="alert-box alert-info mb-15">
    <i class="material-icons">info</i>
    <div>
        این بخش تسک‌هایی را نشان می‌دهد که هر ۷ روز بررسی می‌شوند.
        اگر کاربر هنوز فالو/سابسکرایب دارد → <strong>تایید</strong>.
        اگر آنفالو کرده → <strong>جریمه</strong> و بازگشت پول به سفارش‌دهنده.
    </div>
</div>

<div class="filter-card">
    <form method="GET" action="<?= url('/admin/task-rechecks') ?>" class="filter-form">
        <select name="status" class="form-control-sm">
            <option value="">همه</option>
            <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار</option>
            <option value="passed" <?= ($filters['status'] ?? '') === 'passed' ? 'selected' : '' ?>>تایید</option>
            <option value="failed" <?= ($filters['status'] ?? '') === 'failed' ? 'selected' : '' ?>>شکست</option>
        </select>
        <button type="submit" class="btn btn-sm btn-primary"><i class="material-icons">search</i></button>
    </form>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>تسک</th>
                <th>انجام‌دهنده</th>
                <th>جریمه</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rechecks as $rc): ?>
                <tr>
                    <td><?= e($rc->id) ?></td>
                    <td><?= e($rc->ad_title ?? '—') ?></td>
                    <td><?= e($rc->executor_name ?? '—') ?></td>
                    <td><?= $rc->penalty_amount > 0 ? number_format($rc->penalty_amount) : '—' ?></td>
                    <td>
                        <?php
                        $rcLabels = ['pending' => 'در انتظار', 'passed' => 'تایید', 'failed' => 'شکست'];
                        $rcBadges = ['pending' => 'warning', 'passed' => 'success', 'failed' => 'danger'];
                        ?>
                        <span class="badge badge-<?= $rcBadges[$rc->status] ?? 'secondary' ?>"><?= e($rcLabels[$rc->status] ?? $rc->status) ?></span>
                    </td>
                    <td><?= to_jalali($rc->created_at) ?></td>
                    <td>
                        <?php if ($rc->status === 'pending'): ?>
                            <button class="btn btn-xs btn-success btn-rc-pass" data-id="<?= e($rc->id) ?>">
                                <i class="material-icons">check</i> هنوز فالو دارد
                            </button>
                            <button class="btn btn-xs btn-danger btn-rc-fail" data-id="<?= e($rc->id) ?>">
                                <i class="material-icons">close</i> آنفالو کرده
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
document.querySelectorAll('.btn-rc-pass').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        Swal.fire({title:'تایید',text:'کاربر هنوز فالو/سابسکرایب دارد؟',icon:'question',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'انصراف',confirmButtonColor:'#4caf50'})
        .then(r=>{if(r.isConfirmed){fetch(`<?=url('/admin/task-rechecks')?>/${id}/pass`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?=csrf_token()?>'},body:JSON.stringify({_csrf_token:'<?=csrf_token()?>'})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),800);}else notyf.error(d.message);});}});
    });
});

document.querySelectorAll('.btn-rc-fail').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        Swal.fire({title:'شکست',text:'کاربر آنفالو کرده؟ جریمه اعمال و پول بازگشت داده می‌شود.',icon:'warning',showCancelButton:true,confirmButtonText:'بله، جریمه',cancelButtonText:'انصراف',confirmButtonColor:'#f44336'})
        .then(r=>{if(r.isConfirmed){fetch(`<?=url('/admin/task-rechecks')?>/${id}/fail`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?=csrf_token()?>'},body:JSON.stringify({_csrf_token:'<?=csrf_token()?>'})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),800);}else notyf.error(d.message);});}});
    });
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>