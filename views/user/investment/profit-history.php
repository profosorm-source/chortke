<?php
$pageTitle = $pageTitle ?? 'تاریخچه سود و زیان';
$profits   = $profits   ?? [];
$total     = $total     ?? 0;
$totalPages  = $totalPages  ?? 1;
$currentPage = $currentPage ?? 1;

$totalProfit = 0; $totalLoss = 0; $profitCount = 0; $lossCount = 0;
foreach ($profits as $p) {
    $amt = (float)($p->amount ?? 0);
    if ($amt >= 0) { $totalProfit += $amt; $profitCount++; }
    else           { $totalLoss  += $amt; $lossCount++;  }
}
$netResult = $totalProfit + $totalLoss;
$layout = 'user'; 
ob_start();
?>

<style>
/* ── هدر ───────────────────────────────────────────────────────── */
.ph-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.ph-header-title{display:flex;align-items:center;gap:12px}
.ph-header-icon{width:46px;height:46px;background:linear-gradient(135deg,#F5C518,#D4A800);border-radius:12px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(245,197,24,.30);flex-shrink:0}
.ph-header-icon .material-icons{font-size:22px;color:#1A1A2E}
.ph-header-title h1{font-size:18px;font-weight:800;color:#1A1A2E;margin:0}
.ph-header-title small{font-size:12px;color:#9A9AB0;display:block;margin-top:2px}

/* ── کارت‌های آماری ─────────────────────────────────────────────── */
.ph-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:14px;margin-bottom:24px}
.ph-stat{background:#fff;border-radius:14px;padding:18px 20px;box-shadow:0 2px 10px rgba(0,0,0,.06);display:flex;align-items:center;gap:14px;transition:transform .2s,box-shadow .2s;position:relative;overflow:hidden}
.ph-stat::before{content:'';position:absolute;top:0;right:0;width:4px;height:100%;border-radius:0 14px 14px 0}
.ph-stat:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.10)}
.ph-stat.gold::before{background:#F5C518} .ph-stat.green::before{background:#18B95A} .ph-stat.red::before{background:#E53E3E} .ph-stat.blue::before{background:#2563EB}
.ph-stat-ico{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ph-stat.gold  .ph-stat-ico{background:#FFF9DB;color:#D4A800}
.ph-stat.green .ph-stat-ico{background:#E6FAF0;color:#18B95A}
.ph-stat.red   .ph-stat-ico{background:#FEE2E2;color:#E53E3E}
.ph-stat.blue  .ph-stat-ico{background:#EEF3FF;color:#2563EB}
.ph-stat-ico .material-icons{font-size:20px}
.ph-stat-lbl{font-size:11px;color:#9A9AB0;font-weight:500;display:block;margin-bottom:4px}
.ph-stat-val{font-size:16px;font-weight:800;color:#1A1A2E;direction:ltr;display:block}

/* ── کارت جدول ──────────────────────────────────────────────────── */
.ph-card{background:#fff;border-radius:16px;box-shadow:0 2px 10px rgba(0,0,0,.07);overflow:hidden}
.ph-toolbar{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #EBEBEB;background:#FAFAF8;flex-wrap:wrap;gap:8px}
.ph-toolbar-info{font-size:13px;color:#5A5A7A;display:flex;align-items:center;gap:6px}
.ph-toolbar-info strong{color:#1A1A2E;font-weight:700}
.ph-toolbar-info .material-icons{font-size:15px;color:#9A9AB0}

/* ── جدول ───────────────────────────────────────────────────────── */
.ph-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
.ph-table{width:100%;border-collapse:collapse;font-size:13px}
.ph-table thead{background:linear-gradient(135deg,#1A1A2E,#16213E)}
.ph-table thead th{padding:13px 16px;text-align:right;font-size:11px;font-weight:700;color:rgba(255,255,255,.65);white-space:nowrap;letter-spacing:.03em}
.ph-table thead th .material-icons{font-size:13px;vertical-align:middle;margin-left:4px;color:#F5C518}
.ph-table tbody td{padding:13px 16px;border-bottom:1px solid #EBEBEB;vertical-align:middle}
.ph-table tbody tr:last-child td{border-bottom:none}
.ph-table tbody tr{transition:background .15s}
.ph-table tbody tr:hover td{background:#FAFAF8}

/* ── المان‌های سلول ──────────────────────────────────────────────── */
.ph-date{font-weight:600;color:#1A1A2E;font-size:13px;direction:rtl}
.ph-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700}
.ph-badge .material-icons{font-size:12px}
.ph-badge.profit{background:#E6FAF0;color:#18B95A}
.ph-badge.loss{background:#FEE2E2;color:#E53E3E}
.ph-amount{font-family:'Courier New',monospace;font-size:14px;font-weight:700;direction:ltr;display:inline-block}
.ph-amount.profit{color:#18B95A} .ph-amount.loss{color:#E53E3E}
.ph-pct{display:inline-flex;align-items:center;gap:2px;font-size:12px;font-weight:700;direction:ltr;padding:3px 8px;border-radius:8px}
.ph-pct .material-icons{font-size:13px}
.ph-pct.profit{background:#E6FAF0;color:#18B95A} .ph-pct.loss{background:#FEE2E2;color:#E53E3E}
.ph-bal{font-family:'Courier New',monospace;font-size:13px;color:#5A5A7A;direction:ltr;display:block}
.ph-bal-lbl{font-size:10px;color:#9A9AB0;display:block}
.ph-note{color:#5A5A7A;font-size:12px;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
.ph-dash{color:#9A9AB0}

/* ── حالت خالی ───────────────────────────────────────────────────── */
.ph-empty{padding:70px 30px;text-align:center}
.ph-empty-ico{width:80px;height:80px;background:linear-gradient(135deg,#FFF9DB,#fff);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;border:2px dashed #F5C518}
.ph-empty-ico .material-icons{font-size:36px;color:#D4A800}
.ph-empty h3{font-size:16px;font-weight:700;color:#1A1A2E;margin-bottom:8px}
.ph-empty p{font-size:13px;color:#9A9AB0;max-width:300px;margin:0 auto 22px;line-height:1.9}

/* ── پاجینیشن ────────────────────────────────────────────────────── */
.ph-pages{display:flex;align-items:center;justify-content:center;gap:6px;padding:16px 20px;border-top:1px solid #EBEBEB;background:#FAFAF8;flex-wrap:wrap}
.ph-page{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 10px;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none;color:#5A5A7A;background:#fff;border:1.5px solid #EBEBEB;transition:all .2s}
.ph-page:hover{background:#FFF9DB;border-color:#F5C518;color:#1A1A2E}
.ph-page.active{background:linear-gradient(135deg,#F5C518,#D4A800);border-color:#F5C518;color:#1A1A2E;box-shadow:0 3px 10px rgba(245,197,24,.35)}
.ph-page .material-icons{font-size:16px}
.ph-dots{color:#9A9AB0;padding:0 4px}

/* ── ریسپانسیو ───────────────────────────────────────────────────── */
@media(max-width:768px){
  .ph-stats{grid-template-columns:repeat(2,1fr)}
  .ph-table thead th,.ph-table tbody td{padding:10px 10px}
  .ph-table{font-size:12px}
  .ph-note{max-width:90px}
}
</style>

<!-- هدر -->
<div class="ph-header">
    <div class="ph-header-title">
        <div class="ph-header-icon"><i class="material-icons">show_chart</i></div>
        <div>
            <h1>تاریخچه سود و زیان</h1>
            <small>گزارش کامل عملکرد سرمایه‌گذاری شما</small>
        </div>
    </div>
    <a href="<?= url('/investment') ?>" class="btn btn-outline btn-sm">
        <i class="material-icons">arrow_forward</i> بازگشت
    </a>
</div>

<!-- کارت‌های آماری -->
<div class="ph-stats">
    <div class="ph-stat gold">
        <div class="ph-stat-ico"><i class="material-icons">receipt_long</i></div>
        <div><span class="ph-stat-lbl">کل رکوردها</span><span class="ph-stat-val"><?= number_format($total) ?></span></div>
    </div>
    <div class="ph-stat green">
        <div class="ph-stat-ico"><i class="material-icons">trending_up</i></div>
        <div><span class="ph-stat-lbl">مجموع سود (USDT)</span><span class="ph-stat-val">+<?= number_format($totalProfit, 4) ?></span></div>
    </div>
    <div class="ph-stat red">
        <div class="ph-stat-ico"><i class="material-icons">trending_down</i></div>
        <div><span class="ph-stat-lbl">مجموع ضرر (USDT)</span><span class="ph-stat-val"><?= number_format($totalLoss, 4) ?></span></div>
    </div>
    <div class="ph-stat <?= $netResult >= 0 ? 'green' : 'red' ?>">
        <div class="ph-stat-ico"><i class="material-icons"><?= $netResult >= 0 ? 'account_balance_wallet' : 'money_off' ?></i></div>
        <div><span class="ph-stat-lbl">خالص نتیجه (USDT)</span><span class="ph-stat-val"><?= ($netResult >= 0 ? '+' : '') . number_format($netResult, 4) ?></span></div>
    </div>
</div>

<!-- جدول اصلی -->
<div class="ph-card">
    <div class="ph-toolbar">
        <div class="ph-toolbar-info">
            <i class="material-icons">table_rows</i>
            نمایش <strong><?= count($profits) ?></strong> از <strong><?= number_format($total) ?></strong> رکورد
        </div>
        <div style="font-size:13px;display:flex;gap:12px">
            <span style="color:#18B95A;font-weight:700">↑ سود: <?= $profitCount ?></span>
            <span style="color:#E53E3E;font-weight:700">↓ ضرر: <?= $lossCount ?></span>
        </div>
    </div>

    <?php if (empty($profits)): ?>
    <div class="ph-empty">
        <div class="ph-empty-ico"><i class="material-icons">show_chart</i></div>
        <h3>تاریخچه‌ای وجود ندارد</h3>
        <p>پس از فعال‌سازی سرمایه‌گذاری، سود و زیان روزانه شما اینجا نمایش داده می‌شود.</p>
        <a href="<?= url('/investment') ?>" class="btn btn-primary btn-sm">
            <i class="material-icons">add</i> شروع سرمایه‌گذاری
        </a>
    </div>

    <?php else: ?>
    <div class="ph-responsive">
        <table class="ph-table">
            <thead>
                <tr>
                    <th><i class="material-icons">calendar_today</i>تاریخ</th>
                    <th><i class="material-icons">label</i>نوع</th>
                    <th><i class="material-icons">paid</i>مقدار (USDT)</th>
                    <th><i class="material-icons">percent</i>درصد</th>
                    <th><i class="material-icons">account_balance</i>موجودی بعد</th>
                    <th><i class="material-icons">notes</i>توضیح</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($profits as $p): ?>
            <?php $isProfit = (float)($p->amount ?? 0) >= 0; ?>
            <tr>
                <td><span class="ph-date"><?= to_jalali($p->profit_date ?? $p->created_at) ?></span></td>
                <td>
                    <?php if ($isProfit): ?>
                        <span class="ph-badge profit"><i class="material-icons">arrow_upward</i>سود</span>
                    <?php else: ?>
                        <span class="ph-badge loss"><i class="material-icons">arrow_downward</i>ضرر</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="ph-amount <?= $isProfit ? 'profit' : 'loss' ?>">
                        <?= $isProfit ? '+' : '' ?><?= number_format((float)($p->amount ?? 0), 4) ?>
                    </span>
                </td>
                <td>
                    <?php if (!empty($p->percent)): ?>
                        <span class="ph-pct <?= $isProfit ? 'profit' : 'loss' ?>">
                            <i class="material-icons"><?= $isProfit ? 'arrow_drop_up' : 'arrow_drop_down' ?></i>
                            <?= $isProfit ? '+' : '' ?><?= number_format((float)$p->percent, 2) ?>%
                        </span>
                    <?php else: ?><span class="ph-dash">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($p->balance_after)): ?>
                        <span class="ph-bal"><?= number_format((float)$p->balance_after, 4) ?></span>
                        <span class="ph-bal-lbl">USDT</span>
                    <?php else: ?><span class="ph-dash">—</span><?php endif; ?>
                </td>
                <td>
                    <?php $note = $p->note ?? $p->description ?? ''; ?>
                    <?php if ($note): ?>
                        <span class="ph-note" title="<?= e($note) ?>"><?= e($note) ?></span>
                    <?php else: ?><span class="ph-dash">—</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="ph-pages">
        <?php if ($currentPage > 1): ?>
            <a href="?page=<?= $currentPage - 1 ?>" class="ph-page"><i class="material-icons">chevron_right</i></a>
        <?php endif; ?>
        <?php
        $start = max(1, $currentPage - 2);
        $end   = min($totalPages, $currentPage + 2);
        if ($start > 1): echo '<a href="?page=1" class="ph-page">1</a>';
            if ($start > 2): echo '<span class="ph-dots">…</span>'; endif;
        endif;
        for ($i = $start; $i <= $end; $i++):
            echo '<a href="?page=' . e($i) . '" class="ph-page ' . ($i === $currentPage ? 'active' : '') . '">' . e($i) . '</a>';
        endfor;
        if ($end < $totalPages):
            if ($end < $totalPages - 1): echo '<span class="ph-dots">…</span>'; endif;
            echo '<a href="?page=' . $totalPages . '" class="ph-page">' . $totalPages . '</a>';
        endif;
        ?>
        <?php if ($currentPage < $totalPages): ?>
            <a href="?page=<?= $currentPage + 1 ?>" class="ph-page"><i class="material-icons">chevron_left</i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>