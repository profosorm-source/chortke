<?php
// views/admin/task-executions/index.php
$title = 'اجرای تسک‌ها';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-task-executions.css') ?>">


<div class="page-header">
    <h4><i class="material-icons">fact_check</i> اجرای تسک‌ها</h4>
</div>

<div class="filter-card">
    <form method="GET" action="<?= url('/admin/task-executions') ?>" class="filter-form">
        <select name="status" class="form-control-sm">
            <option value="">همه</option>
            <option value="started" <?= ($filters['status'] ?? '') === 'started' ? 'selected' : '' ?>>شروع‌شده</option>
            <option value="submitted" <?= ($filters['status'] ?? '') === 'submitted' ? 'selected' : '' ?>>ارسال‌شده</option>
            <option value="approved" <?= ($filters['status'] ?? '') === 'approved' ? 'selected' : '' ?>>تایید</option>
            <option value="rejected" <?= ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>رد</option>
            <option value="expired" <?= ($filters['status'] ?? '') === 'expired' ? 'selected' : '' ?>>منقضی</option>
            <option value="disputed" <?= ($filters['status'] ?? '') === 'disputed' ? 'selected' : '' ?>>اختلاف</option>
        </select>
        <input type="text" name="search" class="form-control-sm" placeholder="جستجو..." value="<?= e($filters['search'] ?? '') ?>">
        <button type="submit" class="btn btn-sm btn-primary"><i class="material-icons">search</i></button>
    </form>
    <span class="filter-count"><?= number_format($total) ?> مورد</span>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>عنوان تسک</th>
                <th>انجام‌دهنده</th>
                <th>حساب اجتماعی</th>
                <th>پلتفرم</th>
                <th>پاداش</th>
                <th>وضعیت</th>
                <th>تقلب</th>
                <th>تاریخ</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($executions as $exec): ?>
                <tr>
                    <td><?= e($exec->id) ?></td>
                    <td><?= e($exec->ad_title ?? '—') ?></td>
                    <td><?= e($exec->executor_name ?? '') ?></td>
                    <td><?= $exec->social_username ? '@' . e($exec->social_username) : '—' ?></td>
                    <td><span class="badge-sm platform-<?= e($exec->ad_platform ?? '') ?>"><?= e(social_platform_label($exec->ad_platform ?? '')) ?></span></td>
                    <td><?= number_format($exec->reward_amount) ?></td>
                    <td><span class="badge badge-<?= e(task_execution_status_badge($exec->status)) ?>"><?= e(task_execution_status_label($exec->status)) ?></span></td>
                    <td>
                        <?php if ($exec->fraud_score > 0): ?>
                            <span class="fraud-score <?= $exec->fraud_score >= 50 ? 'fraud-high' : 'fraud-low' ?>">
                                <?= e(round($exec->fraud_score)) ?>
                            </span>
                        <?php else: ?>
                            <span class="fraud-ok">0</span>
                        <?php endif; ?>
                    </td>
                    <td><?= to_jalali($exec->created_at) ?></td>
                    <td>
                        <a href="<?= url('/admin/task-executions/' . $exec->id) ?>" class="btn btn-xs btn-outline-secondary">
                            <i class="material-icons">visibility</i>
                        </a>
                        <?php if ($exec->status === 'submitted'): ?>
                            <button class="btn btn-xs btn-success btn-approve-ex" data-id="<?= e($exec->id) ?>"><i class="material-icons">check</i></button>
                            <button class="btn btn-xs btn-danger btn-reject-ex" data-id="<?= e($exec->id) ?>"><i class="material-icons">close</i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        $allowedQs = array_filter([
            'status'   => e($_GET['status']   ?? '', ENT_QUOTES, 'UTF-8'),
            'platform' => e($_GET['platform'] ?? '', ENT_QUOTES, 'UTF-8'),
            'search'   => e($_GET['search']   ?? '', ENT_QUOTES, 'UTF-8'),
        ]);
        for ($i = 1; $i <= $totalPages; $i++):
            $qs = $allowedQs;
            $qs['page'] = $i;
        ?>
            <a href="<?= url('/admin/task-executions?' . http_build_query($qs)) ?>" class="page-link <?= $i===$page?'active':'' ?>"><?= e($i) ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-approve-ex').forEach(btn=>{btn.addEventListener('click',function(){const id=this.dataset.id;Swal.fire({title:'تایید',text:'تسک تایید و پاداش پرداخت شود؟',icon:'question',showCancelButton:true,confirmButtonText:'تایید',cancelButtonText:'انصراف',confirmButtonColor:'#4caf50'}).then(r=>{if(r.isConfirmed){fetch(`<?=url('/admin/task-executions')?>/${id}/approve`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?=csrf_token()?>'},body:JSON.stringify({_csrf_token:'<?=csrf_token()?>'})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),800);}else notyf.error(d.message);});}});});});

document.querySelectorAll('.btn-reject-ex').forEach(btn=>{btn.addEventListener('click',function(){const id=this.dataset.id;Swal.fire({title:'رد',input:'textarea',inputLabel:'دلیل رد:',showCancelButton:true,confirmButtonText:'رد',cancelButtonText:'انصراف',confirmButtonColor:'#f44336',inputValidator:v=>{if(!v)return'دلیل الزامی';}}).then(r=>{if(r.isConfirmed){fetch(`<?=url('/admin/task-executions')?>/${id}/reject`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?=csrf_token()?>'},body:JSON.stringify({reason:r.value,_csrf_token:'<?=csrf_token()?>'})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),800);}else notyf.error(d.message);});}});});});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>