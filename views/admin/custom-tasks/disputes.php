<?php
$title = 'مدیریت اختلاف‌ها';
$layout = 'admin';
ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <h4 class="page-title mb-0"><i class="material-icons text-danger">gavel</i> مدیریت اختلاف‌ها</h4>
        <a href="<?= url('/admin/custom-tasks') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت
        </a>
    </div>
</div>

<!-- فیلتر -->
<div class="card mt-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <select name="status" class="form-select form-select-sm" style="width:auto;">
                <option value="">همه</option>
                <option value="open" <?= ($filters['status'] ?? '') === 'open' ? 'selected' : '' ?>>باز</option>
                <option value="under_review" <?= ($filters['status'] ?? '') === 'under_review' ? 'selected' : '' ?>>در حال بررسی</option>
                <option value="resolved" <?= ($filters['status'] ?? '') === 'resolved' ? 'selected' : '' ?>>حل‌شده</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">فیلتر</button>
            <span class="text-muted ms-auto" style="font-size:12px;"><?= number_format($total) ?> مورد</span>
        </form>
    </div>
</div>

<div class="card mt-3 mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:12px;">
                <thead>
                    <tr><th>#</th><th>وظیفه</th><th>ثبت‌کننده</th><th>نقش</th><th>دلیل</th><th>وضعیت</th><th>تاریخ</th><th>عملیات</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($disputes)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">اختلافی یافت نشد.</td></tr>
                    <?php else: ?>
                    <?php
                    $dStatusLabels = ['open'=>'باز','under_review'=>'در بررسی','resolved'=>'حل‌شده','closed'=>'بسته'];
                    $dStatusClasses = ['open'=>'badge-danger','under_review'=>'badge-warning','resolved'=>'badge-success','closed'=>'badge-secondary'];
                    $roleLabels = ['worker'=>'کارمند','advertiser'=>'تبلیغ‌دهنده'];
                    ?>
                    <?php foreach ($disputes as $idx => $d): ?>
                    <tr>
                        <td class="text-muted"><?= (($page - 1) * 30) + $idx + 1 ?></td>
                        <td style="max-width:150px;font-size:11px;"><?= e(\mb_substr($d->task_title ?? '—', 0, 30)) ?></td>
                        <td style="font-size:11px;"><?= e($d->raiser_name ?? '—') ?></td>
                        <td><span class="badge bg-secondary" style="font-size:9px;"><?= e($roleLabels[$d->raised_by_role] ?? $d->raised_by_role) ?></span></td>
                        <td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?= e(\mb_substr($d->reason, 0, 60)) ?></td>
                        <td><span class="badge <?= $dStatusClasses[$d->status] ?? '' ?>"><?= e($dStatusLabels[$d->status] ?? $d->status) ?></span></td>
                        <td style="font-size:10px;"><?= to_jalali($d->created_at ?? '') ?></td>
                        <td>
                            <?php if (\in_array($d->status, ['open', 'under_review'])): ?>
                            <button class="btn btn-sm btn-primary btn-resolve" data-id="<?= e($d->id) ?>" data-reason="<?= e($d->reason) ?>">
                                <i class="material-icons" style="font-size:14px;">gavel</i> داوری
                            </button>
                            <?php elseif ($d->status === 'resolved'): ?>
                            <span class="text-muted" style="font-size:10px;">
                                <?php
                                $decisionLabels = ['worker_wins'=>'حق با کارمند','advertiser_wins'=>'حق با تبلیغ‌دهنده','split'=>'تساوی'];
                                echo $decisionLabels[$d->admin_decision] ?? '—';
                                ?>
                            </span>
                            <?php endif; ?>
                        </td>
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
                <a class="page-link" href="<?= url('/admin/custom-tasks/disputes?page=' . $i . '&status=' . e($filters['status'] ?? '')) ?>"><?= e($i) ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-resolve').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var disputeId = this.dataset.id;
            var reason = this.dataset.reason;

            Swal.fire({
                title: 'داوری اختلاف',
                html: '<div style="text-align:right;direction:rtl;font-size:13px;">' +
                      '<p><strong>دلیل اختلاف:</strong> ' + reason + '</p>' +
                      '<hr>' +
                      '<div class="mb-2"><label class="form-label">تصمیم:</label>' +
                      '<select id="swal-decision" class="form-select form-select-sm">' +
                      '<option value="worker_wins">حق با کارمند (پرداخت به کارمند + جریمه تبلیغ‌دهنده)</option>' +
                      '<option value="advertiser_wins">حق با تبلیغ‌دهنده (جریمه کارمند)</option>' +
                      '<option value="split">تساوی (بدون جریمه)</option>' +
                      '</select></div>' +
                      '<div class="mb-2"><label class="form-label">مبلغ جریمه (اختیاری):</label>' +
                      '<input type="number" id="swal-penalty" class="form-control form-control-sm" value="0" min="0"></div>' +
                      '<div class="mb-2"><label class="form-label">توضیحات ادمین:</label>' +
                      '<textarea id="swal-note" class="form-control form-control-sm" rows="2"></textarea></div>' +
                      '</div>',
                showCancelButton: true,
                confirmButtonText: 'ثبت تصمیم',
                cancelButtonText: 'انصراف',
                width: 500,
                preConfirm: function() {
                    return {
                        decision: document.getElementById('swal-decision').value,
                        penalty: parseFloat(document.getElementById('swal-penalty').value) || 0,
                        note: document.getElementById('swal-note').value
                    };
                }
            }).then(function(result) {
                if (result.isConfirmed) {
                    fetch('<?= url('/admin/custom-tasks/disputes/resolve') ?>', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
                        body: JSON.stringify({
                            csrf_token: '<?= csrf_token() ?>',
                            dispute_id: disputeId,
                            decision: result.value.decision,
                            note: result.value.note,
                            penalty_amount: result.value.penalty
                        })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var notyf = new Notyf({duration: 3000, position: {x:'left',y:'top'}});
                        if (data.success) { notyf.success(data.message); setTimeout(function() { location.reload(); }, 1500); }
                        else notyf.error(data.message);
                    });
                }
            });
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>