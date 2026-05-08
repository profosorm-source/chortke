<?php
$title = 'بررسی واریز رمزارز';
$layout = 'admin';
ob_start();
$deposit = $deposit ?? null;
$user = $user ?? null;
$explorerUrl = $explorerUrl ?? null;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">بررسی واریز USDT #<?= (int)($deposit->id ?? 0) ?></h4>
    <a href="<?= url('/admin/crypto-deposits') ?>" class="btn btn-outline-secondary btn-sm">
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
                <p><strong>KYC:</strong>
                    <?php $kyc=$user->kyc_status??'none'; ?>
                    <span class="badge bg-<?= $kyc==='verified'?'success':'warning' ?>">
                        <?= $kyc==='verified'?'تأیید شده':'در انتظار' ?>
                    </span>
                </p>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">جزئیات تراکنش</h6></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="text-muted small">مقدار (USDT):</label>
                        <p class="fs-5 fw-bold text-primary"><?= number_format((float)($deposit->amount ?? $deposit->declared_amount ?? 0), 4) ?></p>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small">شبکه:</label>
                        <p><span class="badge bg-info"><?= strtoupper(e($deposit->network ?? '—')) ?></span></p>
                    </div>
                    <div class="col-12">
                        <label class="text-muted small">TxHash:</label>
                        <p class="font-monospace small">
                            <?= e($deposit->tx_hash ?? '—') ?>
                            <?php if ($explorerUrl && !empty($deposit->tx_hash)): ?>
                                <a href="<?= e($explorerUrl) ?>" target="_blank" class="btn btn-sm btn-outline-info ms-2">
                                    <i class="material-icons align-middle" style="font-size:14px">open_in_new</i> Explorer
                                </a>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small">وضعیت:</label>
                        <p>
                            <?php
                            $stMap=['pending'=>['در انتظار','warning'],'pending_review'=>['در بررسی','info'],'confirmed'=>['تأیید شده','success'],'rejected'=>['رد شده','danger'],'expired'=>['منقضی','secondary']];
                            $si=$stMap[$deposit->status??'']??[$deposit->status??'—','secondary'];
                            ?>
                            <span class="badge bg-<?= e($si[1]) ?>"><?= e($si[0]) ?></span>
                        </p>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small">تاریخ ثبت:</label>
                        <p><?= to_jalali($deposit->created_at) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <?php if (in_array($deposit->status ?? '', ['pending', 'pending_review'])): ?>
        <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">عملیات</h6></div>
            <div class="card-body">
                <div class="d-flex gap-2">
                    <button class="btn btn-success" onclick="doApprove()">
                        <i class="material-icons align-middle">check_circle</i> تأیید واریز
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
const base = '<?= url('/admin/crypto-deposits') ?>';

function doApprove() {
    if (!confirm('آیا از تأیید این واریز مطمئنید؟')) return;
    fetch(`${base}/${dId}/approve`, {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf}
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
