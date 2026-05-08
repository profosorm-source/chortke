<?php
$title = 'بررسی واریز دستی';
$layout = 'admin';
ob_start();
$deposit = $deposit ?? null;
$user = $user ?? null;
$card = $card ?? null;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">بررسی واریز دستی #<?= (int)($deposit->id ?? 0) ?></h4>
    <a href="<?= url('/admin/manual-deposits') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons align-middle">arrow_forward</i> بازگشت
    </a>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">اطلاعات کاربر</h6></div>
            <div class="card-body">
                <p><strong>نام:</strong> <?= e($user->full_name ?? '—') ?></p>
                <p><strong>ایمیل:</strong> <?= e($user->email ?? '—') ?></p>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">جزئیات واریز</h6></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="text-muted small">مبلغ:</label>
                        <p class="fs-5 fw-bold text-success"><?= number_format((float)($deposit->amount ?? 0)) ?> <small>تومان</small></p>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small">وضعیت:</label>
                        <p>
                            <?php
                            $stMap=['pending'=>['در انتظار','warning'],'approved'=>['تأیید شده','success'],'rejected'=>['رد شده','danger']];
                            $si=$stMap[$deposit->status??'']??[$deposit->status??'—','secondary'];
                            ?>
                            <span class="badge bg-<?= e($si[1]) ?>"><?= e($si[0]) ?></span>
                        </p>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small">کارت مبدأ:</label>
                        <p class="font-monospace"><?= e($deposit->card_number ?? '—') ?></p>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small">تاریخ واریز:</label>
                        <p><?= $deposit->deposit_date ? to_jalali($deposit->deposit_date) : '—' ?></p>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small">شماره پیگیری:</label>
                        <p class="font-monospace"><?= e($deposit->reference_number ?? '—') ?></p>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small">تاریخ ثبت درخواست:</label>
                        <p><?= to_jalali($deposit->created_at) ?></p>
                    </div>
                </div>

                <?php if (!empty($deposit->receipt_path)): ?>
                <div class="mt-3">
                    <label class="text-muted small">رسید واریز:</label>
                    <div class="mt-1">
                        <?php $ext = strtolower(pathinfo($deposit->receipt_path, PATHINFO_EXTENSION)); ?>
                        <?php if (in_array($ext,['jpg','jpeg','png','gif','webp'])): ?>
                            <img src="<?= url('/file/view/' . ltrim($deposit->receipt_path,'/')) ?>"
                                 class="img-fluid rounded" style="max-height:300px">
                        <?php else: ?>
                            <a href="<?= url('/file/view/' . ltrim($deposit->receipt_path,'/')) ?>"
                               class="btn btn-sm btn-outline-primary" target="_blank">
                                <i class="material-icons align-middle">download</i> دانلود رسید
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (($deposit->status ?? '') === 'pending'): ?>
        <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">عملیات</h6></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">یادداشت ادمین (اختیاری):</label>
                    <input type="text" id="adminNote" class="form-control" placeholder="یادداشت...">
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-success" onclick="doApprove()">
                        <i class="material-icons align-middle">check_circle</i> تأیید و اعتبارگذاری
                    </button>
                    <button class="btn btn-danger" onclick="showReject()">
                        <i class="material-icons align-middle">cancel</i> رد واریز
                    </button>
                </div>
                <div id="rejectBox" style="display:none;" class="mt-3">
                    <textarea id="rejectReason" class="form-control" rows="2" placeholder="دلیل رد..."></textarea>
                    <button class="btn btn-danger btn-sm mt-2" onclick="doReject()">تأیید رد</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const dId = <?= (int)($deposit->id ?? 0) ?>;
const csrf = '<?= csrf_token() ?>';
const base = '<?= url('/admin/manual-deposits') ?>';

function doApprove() {
    if (!confirm('آیا از تأیید این واریز مطمئنید؟')) return;
    const note = document.getElementById('adminNote').value;
    fetch(`${base}/${dId}/approve`, {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
        body: JSON.stringify({note})
    }).then(r=>r.json()).then(res=>{
        if(res.success) location.href=base; else alert(res.message||'خطا');
    });
}

function showReject() { document.getElementById('rejectBox').style.display='block'; }

function doReject() {
    const reason = document.getElementById('rejectReason').value;
    fetch(`${base}/${dId}/reject`, {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},
        body: JSON.stringify({reason})
    }).then(r=>r.json()).then(res=>{
        if(res.success) location.href=base; else alert(res.message||'خطا');
    });
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
