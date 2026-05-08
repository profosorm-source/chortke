<?php
$title  = 'سرمایه‌گذاری';
$layout = 'user';
ob_start();

?>

<div class="inv-wrap">

    <!-- ── HEADER ──────────────────────────────────────── -->
    <div class="inv-hero">
        <div class="inv-hero__left">
            <div class="inv-hero__icon">
                <i class="material-icons">trending_up</i>
            </div>
            <div>
                <h1 class="inv-hero__title">سرمایه‌گذاری</h1>
                <p class="inv-hero__sub">مدیریت پرتفوی و پیگیری سود و زیان</p>
            </div>
        </div>
        <?php if (!$activeInvestment && !$isDepositLocked): ?>
        <a href="<?= url('/investment/create') ?>" class="inv-btn-new">
            <i class="material-icons">add</i>
            سرمایه‌گذاری جدید
        </a>
        <?php endif; ?>
    </div>

    <!-- ── RISK WARNING ─────────────────────────────────── -->
    <div class="inv-risk-banner">
        <i class="material-icons">warning_amber</i>
        <span><strong>هشدار ریسک:</strong> سرمایه‌گذاری در بازارهای مالی دارای ریسک بالاست. احتمال ضرر تا ۱۰۰٪ وجود دارد. فقط پولی سرمایه‌گذاری کنید که توان از دست دادنش را دارید.</span>
    </div>

    <?php if ($isDepositLocked): ?>
    <div class="inv-alert inv-alert--info">
        <i class="material-icons">lock_clock</i>
        <span>به دلیل برداشت اخیر، تا ۷ روز امکان سرمایه‌گذاری جدید ندارید.</span>
    </div>
    <?php endif; ?>

    <?php if ($activeInvestment): ?>
    <!-- ══════════════════════════════════════════════════
         ACTIVE INVESTMENT DASHBOARD
         ══════════════════════════════════════════════════ -->

    <?php
        $profit      = (float)$activeInvestment->current_balance - (float)$activeInvestment->amount;
        $profitPct   = $activeInvestment->amount > 0
            ? round(($profit / $activeInvestment->amount) * 100, 2)
            : 0;
        $isPositive  = $profit >= 0;
    ?>

    <!-- Stats Row -->
    <div class="inv-stats">
        <div class="inv-stat inv-stat--blue">
            <div class="inv-stat__icon"><i class="material-icons">account_balance</i></div>
            <div class="inv-stat__body">
                <span class="inv-stat__lbl">سرمایه اولیه</span>
                <span class="inv-stat__val" dir="ltr"><?= number_format((float)$activeInvestment->amount, 2) ?></span>
                <span class="inv-stat__unit">USDT</span>
            </div>
        </div>
        <div class="inv-stat <?= $isPositive ? 'inv-stat--green' : 'inv-stat--red' ?>">
            <div class="inv-stat__icon"><i class="material-icons">account_balance_wallet</i></div>
            <div class="inv-stat__body">
                <span class="inv-stat__lbl">موجودی فعلی</span>
                <span class="inv-stat__val" dir="ltr"><?= number_format((float)$activeInvestment->current_balance, 2) ?></span>
                <span class="inv-stat__unit">USDT</span>
            </div>
        </div>
        <div class="inv-stat <?= $isPositive ? 'inv-stat--green' : 'inv-stat--red' ?>">
            <div class="inv-stat__icon">
                <i class="material-icons"><?= $isPositive ? 'trending_up' : 'trending_down' ?></i>
            </div>
            <div class="inv-stat__body">
                <span class="inv-stat__lbl">سود / زیان</span>
                <span class="inv-stat__val" dir="ltr">
                    <?= $profit >= 0 ? '+' : '' ?><?= number_format($profit, 2) ?>
                </span>
                <span class="inv-stat__pct <?= $isPositive ? 'inv-stat__pct--up' : 'inv-stat__pct--down' ?>">
                    <?= $profitPct >= 0 ? '+' : '' ?><?= $profitPct ?>%
                </span>
            </div>
        </div>
        <div class="inv-stat inv-stat--gold">
            <div class="inv-stat__icon"><i class="material-icons">calendar_today</i></div>
            <div class="inv-stat__body">
                <span class="inv-stat__lbl">تاریخ شروع</span>
                <span class="inv-stat__val inv-stat__val--sm"><?= to_jalali($activeInvestment->start_date ?? $activeInvestment->created_at) ?></span>
                <?php if ($activeInvestment->last_profit_date): ?>
                <span class="inv-stat__unit">آخرین محاسبه: <?= to_jalali($activeInvestment->last_profit_date) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Active Plan Card -->
    <div class="inv-plan-card">
        <div class="inv-plan-card__header">
            <div class="inv-plan-card__title">
                <i class="material-icons">verified</i>
                پلن فعال
            </div>
            <span class="inv-badge inv-badge--active">
                <span class="inv-badge__dot"></span>
                فعال
            </span>
        </div>
        <div class="inv-plan-card__body">

            <!-- Progress bar -->
            <?php
                $balancePct = $activeInvestment->amount > 0
                    ? min(200, max(0, ((float)$activeInvestment->current_balance / (float)$activeInvestment->amount) * 100))
                    : 100;
            ?>
            <div class="inv-progress-wrap">
                <div class="inv-progress-label">
                    <span>پیشرفت موجودی</span>
                    <span dir="ltr"><?= round($balancePct, 1) ?>%</span>
                </div>
                <div class="inv-progress">
                    <div class="inv-progress__bar <?= $isPositive ? 'inv-progress__bar--up' : 'inv-progress__bar--down' ?>"
                         style="width: <?= min(100, $balancePct) ?>%"></div>
                </div>
            </div>

            <!-- Withdrawal Actions -->
            <div class="inv-actions">
                <?php if ($canWithdraw['allowed']): ?>
                    <?php if ($profit > 0): ?>
                    <button class="inv-action-btn inv-action-btn--profit"
                            onclick="requestWithdrawal('profit_only')">
                        <i class="material-icons">savings</i>
                        <div>
                            <strong>برداشت سود</strong>
                            <small dir="ltr"><?= number_format($profit, 2) ?> USDT</small>
                        </div>
                    </button>
                    <?php endif; ?>
                    <button class="inv-action-btn inv-action-btn--close"
                            onclick="requestWithdrawal('full_close')">
                        <i class="material-icons">exit_to_app</i>
                        <div>
                            <strong>بستن و برداشت کامل</strong>
                            <small dir="ltr"><?= number_format((float)$activeInvestment->current_balance, 2) ?> USDT</small>
                        </div>
                    </button>
                <?php else: ?>
                    <div class="inv-cooldown-notice">
                        <i class="material-icons">schedule</i>
                        <span><?= e($canWithdraw['reason'] ?? 'در حال بررسی') ?></span>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <?php else: ?>
    <!-- ══════════════════════════════════════════════════
         EMPTY STATE
         ══════════════════════════════════════════════════ -->
    <div class="inv-empty">
        <div class="inv-empty__icon">
            <i class="material-icons">trending_up</i>
        </div>
        <h3>هنوز سرمایه‌گذاری نکرده‌اید</h3>
        <p>با سرمایه‌گذاری در بازار طلا و فارکس، از سود هفتگی بهره‌مند شوید.</p>
        <?php if (!$isDepositLocked): ?>
        <a href="<?= url('/investment/create') ?>" class="inv-btn-new" style="margin-top:20px">
            <i class="material-icons">add</i>
            شروع سرمایه‌گذاری
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── WITHDRAWAL REQUESTS ──────────────────────────── -->
    <?php if (!empty($withdrawals)): ?>
    <div class="inv-section">
        <div class="inv-section__header">
            <i class="material-icons">receipt_long</i>
            <h2>درخواست‌های برداشت</h2>
        </div>
        <div class="inv-table-wrap">
            <table class="inv-table">
                <thead>
                    <tr>
                        <th>مبلغ</th>
                        <th>نوع</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $wStatusMap = [
                        'pending'   => ['در انتظار',  'inv-badge--pending'],
                        'approved'  => ['تأیید شده',  'inv-badge--info'],
                        'completed' => ['واریز شده',  'inv-badge--active'],
                        'rejected'  => ['رد شده',     'inv-badge--danger'],
                    ];
                    foreach ($withdrawals as $w):
                        [$wLabel, $wClass] = $wStatusMap[$w->status] ?? ['؟', 'inv-badge--muted'];
                    ?>
                    <tr>
                        <td dir="ltr"><strong><?= number_format((float)$w->amount, 2) ?></strong> <small>USDT</small></td>
                        <td><?= $w->withdrawal_type === 'profit_only' ? 'برداشت سود' : 'بستن کامل' ?></td>
                        <td><span class="inv-badge <?= $wClass ?>"><?= $wLabel ?></span></td>
                        <td><?= to_jalali($w->created_at) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── PROFIT HISTORY ───────────────────────────────── -->
    <?php if (!empty($profitHistory)): ?>
    <div class="inv-section">
        <div class="inv-section__header">
            <i class="material-icons">history</i>
            <h2>تاریخچه سود و زیان</h2>
            <a href="<?= url('/investment/profit-history') ?>" class="inv-section__more">
                مشاهده همه
                <i class="material-icons">chevron_left</i>
            </a>
        </div>
        <div class="inv-table-wrap">
            <table class="inv-table">
                <thead>
                    <tr>
                        <th>دوره</th>
                        <th>درصد</th>
                        <th>سود/زیان خالص</th>
                        <th>موجودی بعد</th>
                        <th>نوع</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profitHistory as $p): ?>
                    <tr>
                        <td><?= e($p->period) ?></td>
                        <td dir="ltr">
                            <span class="inv-pct inv-pct--<?= $p->profit_loss_percent >= 0 ? 'up' : 'down' ?>">
                                <?= $p->profit_loss_percent >= 0 ? '+' : '' ?><?= e($p->profit_loss_percent) ?>%
                            </span>
                        </td>
                        <td dir="ltr" class="<?= $p->net_amount >= 0 ? 'inv-text-up' : 'inv-text-down' ?>">
                            <?= $p->net_amount >= 0 ? '+' : '' ?><?= number_format((float)$p->net_amount, 2) ?> USDT
                        </td>
                        <td dir="ltr"><?= number_format((float)$p->balance_after, 2) ?></td>
                        <td>
                            <span class="inv-badge <?= $p->type === 'profit' ? 'inv-badge--active' : 'inv-badge--danger' ?>">
                                <?= $p->type === 'profit' ? 'سود' : 'زیان' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── RECENT TRADES ────────────────────────────────── -->
    <?php if (!empty($recentTrades)): ?>
    <div class="inv-section">
        <div class="inv-section__header">
            <i class="material-icons">candlestick_chart</i>
            <h2>آخرین معاملات بازار</h2>
        </div>
        <div class="inv-table-wrap">
            <table class="inv-table">
                <thead>
                    <tr>
                        <th>جفت ارز</th>
                        <th>جهت</th>
                        <th>قیمت باز</th>
                        <th>قیمت بسته</th>
                        <th>سود/زیان</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentTrades as $t): ?>
                    <tr>
                        <td><strong><?= e($t->pair) ?></strong></td>
                        <td>
                            <span class="inv-badge <?= $t->direction === 'buy' ? 'inv-badge--active' : 'inv-badge--danger' ?>">
                                <i class="material-icons" style="font-size:11px">
                                    <?= $t->direction === 'buy' ? 'arrow_upward' : 'arrow_downward' ?>
                                </i>
                                <?= $t->direction === 'buy' ? 'خرید' : 'فروش' ?>
                            </span>
                        </td>
                        <td dir="ltr"><?= number_format((float)$t->open_price, 2) ?></td>
                        <td dir="ltr"><?= number_format((float)$t->close_price, 2) ?></td>
                        <td dir="ltr">
                            <span class="inv-pct inv-pct--<?= $t->profit_loss_percent >= 0 ? 'up' : 'down' ?>">
                                <?= $t->profit_loss_percent >= 0 ? '+' : '' ?><?= e($t->profit_loss_percent) ?>%
                            </span>
                        </td>
                        <td><?= to_jalali($t->close_time ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
function requestWithdrawal(type) {
    const isClose = type === 'full_close';
    Swal.fire({
        title: isClose ? 'بستن و برداشت کامل' : 'برداشت سود',
        html: isClose
            ? '<p>با بستن کامل، سرمایه‌گذاری شما خاتمه می‌یابد و <strong>۷ روز</strong> امکان سرمایه‌گذاری مجدد ندارید.</p>'
            : '<p>پس از برداشت سود، <strong>۷ روز</strong> امکان واریز مجدد به سرمایه‌گذاری ندارید.</p>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'تأیید و ارسال درخواست',
        cancelButtonText: 'انصراف',
        confirmButtonColor: isClose ? '#ef4444' : '#0ecb81',
    }).then(result => {
        if (!result.isConfirmed) return;

        fetch('<?= url('/investment/withdraw') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '<?= csrf_token() ?>'
            },
            body: JSON.stringify({ withdrawal_type: type })
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                notyf.success(res.message);
                setTimeout(() => location.reload(), 1500);
            } else {
                notyf.error(res.message);
            }
        })
        .catch(() => notyf.error('خطا در ارتباط با سرور'));
    });
}
</script>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>