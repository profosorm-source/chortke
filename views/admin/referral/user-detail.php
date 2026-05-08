<?php
$title = 'جزئیات معرفی: ' . e($user->full_name ?? '');
$layout = 'admin';
ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="page-title mb-1">
                <i class="material-icons text-primary">person</i>
                جزئیات معرفی: <?= e($user->full_name ?? '—') ?>
            </h4>
            <p class="text-muted mb-0" style="font-size:12px;">
                ایمیل: <code dir="ltr"><?= e($user->email ?? '') ?></code>
                | کد دعوت: <code dir="ltr"><?= e($user->referral_code ?? '—') ?></code>
            </p>
        </div>
        <a href="<?= url('/admin/referral') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i> بازگشت
        </a>
    </div>
</div>

<!-- آمار -->
<div class="row mt-3">
    <div class="col-md-3 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="text-primary" style="font-weight:bold;"><?= number_format($referredCount) ?></h5>
                <small class="text-muted">زیرمجموعه</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="text-success" style="font-weight:bold;"><?= number_format($stats->total_earned_irt ?? 0) ?></h5>
                <small class="text-muted">درآمد (تومان)</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="text-info" style="font-weight:bold;"><?= number_format($stats->total_earned_usdt ?? 0, 2) ?></h5>
                <small class="text-muted">درآمد (USDT)</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="text-warning" style="font-weight:bold;"><?= number_format($stats->pending_irt ?? 0) ?></h5>
                <small class="text-muted">در انتظار (تومان)</small>
            </div>
        </div>
    </div>
</div>

<!-- لیست زیرمجموعه‌ها -->
<div class="card mt-2">
    <div class="card-header">
        <h6 class="card-title mb-0">زیرمجموعه‌ها (<?= number_format($referredCount) ?> نفر)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:12px;">
                <thead>
                    <tr><th>#</th><th>نام</th><th>ایمیل</th><th>تاریخ عضویت</th><th>درآمد معرف (تومان)</th><th>درآمد معرف (USDT)</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($referredUsers as $idx => $ru): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= e($ru->full_name ?? '—') ?></td>
                        <td dir="ltr"><?= e($ru->email ?? '') ?></td>
                        <td><?= to_jalali($ru->joined_at ?? '') ?></td>
                        <td class="text-success"><?= number_format($ru->earned_irt ?? 0) ?></td>
                        <td class="text-info"><?= number_format($ru->earned_usdt ?? 0, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- لیست کمیسیون‌ها -->
<div class="card mt-3 mb-4">
    <div class="card-header">
        <h6 class="card-title mb-0">تاریخچه کمیسیون‌ها (<?= number_format($stats->total_count ?? 0) ?> رکورد)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:12px;">
                <thead>
                    <tr><th>#</th><th>زیرمجموعه</th><th>منبع</th><th>مبلغ اصلی</th><th>درصد</th><th>کمیسیون</th><th>ارز</th><th>وضعیت</th><th>تاریخ</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($commissions as $idx => $c): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= e($c->referred_name ?? '—') ?></td>
                        <td><span class="badge" style="background:#e3f2fd;color:#1976d2;font-size:9px;"><?= e(($c->source_label ?? $c->source_type)) ?></span></td>
                        <td><?= $c->currency==='usdt' ? number_format($c->source_amount,2) : number_format($c->source_amount) ?></td>
                        <td><?= e($c->commission_percent) ?>%</td>
                        <td><strong class="text-success"><?= $c->currency==='usdt' ? number_format($c->commission_amount,2) : number_format($c->commission_amount) ?></strong></td>
                        <td><?= $c->currency==='usdt' ? 'USDT' : 'تومان' ?></td>
                        <td>
                            <?php $sl=['pending'=>'در انتظار','paid'=>'پرداخت شده','cancelled'=>'لغو','failed'=>'ناموفق']; $sc=['pending'=>'badge-warning','paid'=>'badge-success','cancelled'=>'badge-danger','failed'=>'badge-danger']; ?>
                            <span class="badge <?= $sc[$c->status]??'' ?>"><?= $sl[$c->status]??$c->status ?></span>
                        </td>
                        <td style="font-size:10px;"><?= to_jalali($c->created_at ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>