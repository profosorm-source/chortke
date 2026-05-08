<?php
$title = 'مدیریت سیستم معرفی و کمیسیون';
$layout = 'admin';
ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="page-title mb-1">
                <i class="material-icons text-primary">share</i>
                مدیریت سیستم معرفی
            </h4>
            <p class="text-muted mb-0" style="font-size:12px;">مدیریت کمیسیون‌ها، تنظیمات و ضدتقلب</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('/admin/referral/settings') ?>" class="btn btn-outline-primary btn-sm">
                <i class="material-icons" style="font-size:16px;vertical-align:middle;">settings</i>
                تنظیمات
            </a>
            <button class="btn btn-success btn-sm" onclick="batchPay('irt')">
                <i class="material-icons" style="font-size:16px;vertical-align:middle;">payment</i>
                پرداخت دسته‌ای (تومان)
            </button>
        </div>
    </div>
</div>

<!-- آمار کلی -->
<div class="row mt-3">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card stat-card" style="border-top:3px solid #4caf50;">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted">کل پرداخت شده (تومان)</small>
                        <h5 class="mt-1 mb-0" style="font-weight:bold;"><?= number_format($stats->total_paid_irt ?? 0) ?></h5>
                    </div>
                    <div style="background:rgba(76,175,80,0.1);border-radius:10px;width:50px;height:50px;display:flex;align-items:center;justify-content:center;">
                        <i class="material-icons" style="color:#4caf50;">check_circle</i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card stat-card" style="border-top:3px solid #ff9800;">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted">در انتظار پرداخت (تومان)</small>
                        <h5 class="mt-1 mb-0" style="font-weight:bold;"><?= number_format($stats->total_pending_irt ?? 0) ?></h5>
                    </div>
                    <div style="background:rgba(255,152,0,0.1);border-radius:10px;width:50px;height:50px;display:flex;align-items:center;justify-content:center;">
                        <i class="material-icons" style="color:#ff9800;">schedule</i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card stat-card" style="border-top:3px solid #2196f3;">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted">معرف‌های فعال</small>
                        <h5 class="mt-1 mb-0" style="font-weight:bold;"><?= number_format($stats->active_referrers ?? 0) ?></h5>
                    </div>
                    <div style="background:rgba(33,150,243,0.1);border-radius:10px;width:50px;height:50px;display:flex;align-items:center;justify-content:center;">
                        <i class="material-icons" style="color:#2196f3;">people</i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card stat-card" style="border-top:3px solid #f44336;">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted">لغو شده</small>
                        <h5 class="mt-1 mb-0" style="font-weight:bold;"><?= number_format($stats->cancelled_count ?? 0) ?></h5>
                    </div>
                    <div style="background:rgba(244,67,54,0.1);border-radius:10px;width:50px;height:50px;display:flex;align-items:center;justify-content:center;">
                        <i class="material-icons" style="color:#f44336;">cancel</i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- برترین معرف‌ها -->
<?php if (!empty($topReferrers)): ?>
<div class="card mt-2">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="material-icons text-warning" style="font-size:18px;vertical-align:middle;">emoji_events</i>
            برترین معرف‌ها
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>رتبه</th>
                        <th>نام</th>
                        <th>ایمیل</th>
                        <th>زیرمجموعه</th>
                        <th>تعداد کمیسیون</th>
                        <th>مجموع درآمد</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topReferrers as $idx => $tr): ?>
                    <tr>
                        <td>
                            <?php if ($idx === 0): ?>
                                <span style="color:gold;font-size:18px;">🥇</span>
                            <?php elseif ($idx === 1): ?>
                                <span style="font-size:18px;">🥈</span>
                            <?php elseif ($idx === 2): ?>
                                <span style="font-size:18px;">🥉</span>
                            <?php else: ?>
                                <?= $idx + 1 ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= url('/admin/referral/user/' . $tr->referrer_id) ?>">
                                <?= e($tr->full_name ?? '—') ?>
                            </a>
                        </td>
                        <td style="font-size:12px;" dir="ltr"><?= e($tr->email ?? '') ?></td>
                        <td><?= number_format($tr->referred_count) ?> نفر</td>
                        <td><?= number_format($tr->commission_count) ?></td>
                        <td><strong class="text-success"><?= number_format($tr->total_earned) ?> تومان</strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- فیلتر -->
<div class="card mt-3">
    <div class="card-header">
        <h6 class="card-title mb-0">
            <i class="material-icons text-primary" style="font-size:18px;vertical-align:middle;">filter_list</i>
            فیلتر و جستجو
        </h6>
    </div>
    <div class="card-body">
        <form method="GET" action="<?= url('/admin/referral') ?>">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="جستجو (نام/ایمیل)" value="<?= e($filters['search'] ?? '') ?>">
                </div>
                <div class="col-md-2 mb-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                        <option value="paid" <?= ($filters['status'] ?? '') === 'paid' ? 'selected' : '' ?>>پرداخت شده</option>
                        <option value="cancelled" <?= ($filters['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>لغو شده</option>
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <select name="source_type" class="form-select form-select-sm">
                        <option value="">همه منابع</option>
                        <?php foreach ($sourceTypes as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= ($filters['source_type'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <select name="currency" class="form-select form-select-sm">
                        <option value="">همه ارزها</option>
                        <option value="irt" <?= ($filters['currency'] ?? '') === 'irt' ? 'selected' : '' ?>>تومان</option>
                        <option value="usdt" <?= ($filters['currency'] ?? '') === 'usdt' ? 'selected' : '' ?>>USDT</option>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="material-icons" style="font-size:14px;vertical-align:middle;">search</i> فیلتر
                    </button>
                    <a href="<?= url('/admin/referral') ?>" class="btn btn-outline-secondary btn-sm">پاکسازی</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- جدول کمیسیون‌ها -->
<div class="card mt-3 mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">
            <i class="material-icons text-primary" style="font-size:18px;vertical-align:middle;">receipt_long</i>
            لیست کمیسیون‌ها
        </h6>
        <span class="badge bg-info"><?= number_format($total) ?> رکورد</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:12px;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>معرف</th>
                        <th>زیرمجموعه</th>
                        <th>منبع</th>
                        <th>مبلغ اصلی</th>
                        <th>درصد</th>
                        <th>کمیسیون</th>
                        <th>ارز</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($commissions)): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">
                            <i class="material-icons" style="font-size:40px;opacity:0.3;">inbox</i>
                            <p class="mt-2">رکوردی یافت نشد.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($commissions as $idx => $c): ?>
                    <tr id="comm-row-<?= e($c->id) ?>">
                        <td class="text-muted"><?= (($page - 1) * 30) + $idx + 1 ?></td>
                        <td>
                            <a href="<?= url('/admin/referral/user/' . $c->referrer_id) ?>" style="font-size:12px;">
                                <?= e($c->referrer_name ?? '—') ?>
                            </a>
                        </td>
                        <td style="font-size:11px;"><?= e($c->referred_name ?? '—') ?></td>
                        <td>
                            <span class="badge" style="background:#e3f2fd;color:#1976d2;font-size:9px;">
                                <?= e(($c->source_label ?? $c->source_type)) ?>
                            </span>
                        </td>
                        <td><?= $c->currency === 'usdt' ? number_format($c->source_amount, 2) : number_format($c->source_amount) ?></td>
                        <td><?= e($c->commission_percent) ?>%</td>
                        <td><strong class="text-success"><?= $c->currency === 'usdt' ? number_format($c->commission_amount, 2) : number_format($c->commission_amount) ?></strong></td>
                        <td><?= $c->currency === 'usdt' ? 'USDT' : 'تومان' ?></td>
                        <td>
                            <?php
                            $sLabel = ['pending'=>'در انتظار','paid'=>'پرداخت شده','cancelled'=>'لغو','failed'=>'ناموفق'];
                            $sClass = ['pending'=>'badge-warning','paid'=>'badge-success','cancelled'=>'badge-danger','failed'=>'badge-danger'];
                            ?>
                            <span class="badge <?= $sClass[$c->status] ?? '' ?>"><?= $sLabel[$c->status] ?? $c->status ?></span>
                        </td>
                        <td style="font-size:10px;"><?= to_jalali($c->created_at ?? '') ?></td>
                        <td>
                            <?php if ($c->status === 'pending'): ?>
                            <button class="btn btn-sm btn-outline-danger" onclick="cancelCommission(<?= e($c->id) ?>)" title="لغو">
                                <i class="material-icons" style="font-size:14px;">close</i>
                            </button>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- صفحه‌بندی -->
    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-center">
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= url('/admin/referral?page=' . $i . '&status=' . e($filters['status'] ?? '') . '&source_type=' . e($filters['source_type'] ?? '') . '&currency=' . e($filters['currency'] ?? '') . '&search=' . e($filters['search'] ?? '')) ?>">
                        <?= e($i) ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<script>
function cancelCommission(id) {
    Swal.fire({
        title: 'لغو کمیسیون',
        input: 'text',
        inputLabel: 'دلیل لغو:',
        inputPlaceholder: 'مثال: تقلب زیرمجموعه',
        showCancelButton: true,
        confirmButtonColor: '#f44336',
        confirmButtonText: 'لغو کمیسیون',
        cancelButtonText: 'انصراف',
        inputValidator: function(value) {
            if (!value) return 'لطفاً دلیل لغو را وارد کنید';
        }
    }).then(function(result) {
        if (result.isConfirmed) {
            fetch('<?= url('/admin/referral/') ?>' + id + '/cancel', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify({csrf_token: '<?= csrf_token() ?>', reason: result.value})
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var notyf = new Notyf({duration: 3000, position: {x:'left',y:'top'}});
                if (data.success) {
                    notyf.success(data.message);
                    location.reload();
                } else {
                    notyf.error(data.message);
                }
            });
        }
    });
}

function batchPay(currency) {
    Swal.fire({
        title: 'پرداخت دسته‌ای',
        text: 'تمام کمیسیون‌های در انتظار پرداخت خواهند شد. آیا مطمئن هستید؟',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#4caf50',
        confirmButtonText: 'بله، پرداخت کن',
        cancelButtonText: 'انصراف'
    }).then(function(result) {
        if (result.isConfirmed) {
            fetch('<?= url('/admin/referral/batch-pay') ?>', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify({csrf_token: '<?= csrf_token() ?>', currency: currency})
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var notyf = new Notyf({duration: 5000, position: {x:'left',y:'top'}});
                if (data.success) {
                    notyf.success(data.message);
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    notyf.error(data.message);
                }
            });
        }
    });
}
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>