<?php
$title  = 'تاریخچه تریدها';
$layout = 'admin';
ob_start();
$trades      = $trades      ?? [];
$stats       = $stats       ?? null;
$total       = $total       ?? 0;
$totalPages  = $totalPages  ?? 1;
$currentPage = $currentPage ?? 1;
$filters     = $filters     ?? [];

$statusQ    = $filters['status']    ?? '';
$directionQ = $filters['direction'] ?? '';
?>
<style>
/* ══════════════════════════════════════════
   TRADING DASHBOARD — صرافی حرفه‌ای
   تم: طلایی | مشکی | سفید
   ══════════════════════════════════════════ */
:root {
    --g:       #F5C518;
    --g-d:     #D4A800;
    --g-glow:  rgba(245,197,24,.20);
    --g-dim:   rgba(245,197,24,.09);
    --g-brd:   rgba(245,197,24,.25);
    --up:      #00C896;
    --up-dim:  rgba(0,200,150,.10);
    --up-brd:  rgba(0,200,150,.25);
    --dn:      #FF4D6A;
    --dn-dim:  rgba(255,77,106,.10);
    --dn-brd:  rgba(255,77,106,.25);
    --open:    #3D9BF5;
    --open-dim:rgba(61,155,245,.10);
}

/* ── ticker bar ── */
.tr-ticker {
    overflow:hidden; height:36px;
    background:var(--bg-card2); border:1px solid var(--border);
    border-radius:10px; margin-bottom:22px;
    display:flex; align-items:center;
    position:relative;
}
.tr-ticker::before,.tr-ticker::after {
    content:''; position:absolute; top:0; width:60px; height:100%; z-index:2; pointer-events:none;
}
.tr-ticker::before { right:0; background:linear-gradient(to left, var(--bg-card2), transparent); }
.tr-ticker::after  { left:0;  background:linear-gradient(to right,var(--bg-card2), transparent); }
.tr-ticker-track {
    display:flex; gap:40px; white-space:nowrap;
    animation:tickerSlide 28s linear infinite;
    padding:0 30px;
}
@keyframes tickerSlide { from{transform:translateX(0)} to{transform:translateX(-50%)} }
.tr-ticker-item {
    display:inline-flex; align-items:center; gap:8px;
    font-size:12px; font-weight:600; color:var(--text-secondary);
    letter-spacing:.3px;
}
.tr-ticker-item .sym  { color:var(--g); font-weight:800; font-size:11px; }
.tr-ticker-item .prc  { color:var(--text-primary); font-family:'Courier New',monospace; }
.tr-ticker-item .chg.up  { color:var(--up); }
.tr-ticker-item .chg.dn  { color:var(--dn); }
.tr-ticker-sep { color:rgba(255,255,255,.12); }

/* ── hero header ── */
.tr-hero {
    position:relative; overflow:hidden;
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:16px; padding:26px 28px; margin-bottom:22px;
    display:flex; align-items:center; justify-content:space-between; gap:20px;
}
.tr-hero::before {
    content:''; position:absolute; top:-80px; right:-80px;
    width:260px; height:260px;
    background:radial-gradient(circle, var(--g-glow) 0%, transparent 65%);
    pointer-events:none;
}
.tr-hero::after {
    content:''; position:absolute; bottom:-1px; left:0; right:0; height:2px;
    background:linear-gradient(90deg,transparent 0%,var(--g) 30%,var(--g-d) 70%,transparent 100%);
    opacity:.4;
}
.tr-hero-left { display:flex; align-items:center; gap:16px; position:relative; z-index:1; }
.tr-hero-icon {
    width:52px; height:52px; border-radius:14px; flex-shrink:0;
    background:linear-gradient(135deg,var(--g) 0%,var(--g-d) 100%);
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 0 0 1px var(--g-brd), 0 6px 24px var(--g-glow);
}
.tr-hero-icon .material-icons { font-size:26px!important; color:#1a1200; }
.tr-hero-title { font-size:20px; font-weight:800; color:var(--text-primary); margin:0 0 3px; letter-spacing:-.3px; }
.tr-hero-sub   { font-size:12px; color:var(--text-muted); margin:0; }
.tr-hero-right { display:flex; gap:10px; align-items:center; flex-shrink:0; }

/* ── stat cards ── */
.tr-stats { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:22px; }
@media(max-width:900px){ .tr-stats{ grid-template-columns:repeat(3,1fr); } }
@media(max-width:600px){ .tr-stats{ grid-template-columns:repeat(2,1fr); } }

.tr-stat {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:14px; padding:16px 18px; position:relative; overflow:hidden;
    transition:border-color .2s, transform .2s;
}
.tr-stat:hover { border-color:var(--border-bright); transform:translateY(-2px); }
.tr-stat-glow {
    position:absolute; top:-20px; right:-20px; width:80px; height:80px;
    border-radius:50%; opacity:.18; filter:blur(16px); pointer-events:none;
}
.tr-stat-icon {
    width:36px; height:36px; border-radius:10px; margin-bottom:12px;
    display:flex; align-items:center; justify-content:center;
}
.tr-stat-icon .material-icons { font-size:18px!important; }
.tr-stat-label { font-size:11px; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }
.tr-stat-val   { font-size:24px; font-weight:800; color:var(--text-primary); line-height:1; margin-bottom:4px; }
.tr-stat-val.up  { color:var(--up); }
.tr-stat-val.dn  { color:var(--dn); }
.tr-stat-val.gold{ color:var(--g); }

/* ── filter bar ── */
.tr-filter {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:12px; padding:14px 18px; margin-bottom:18px;
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
}
.tr-filter-label { font-size:12px; color:var(--text-muted); font-weight:600; white-space:nowrap; }
.tr-seg { display:flex; gap:4px; }
.tr-seg-btn {
    height:32px; padding:0 14px; border-radius:8px; border:1px solid var(--border);
    font-size:12px; font-weight:600; cursor:pointer; transition:all .15s;
    background:rgba(255,255,255,.04); color:var(--text-secondary); font-family:inherit;
    text-decoration:none; display:inline-flex; align-items:center; gap:5px;
}
.tr-seg-btn .material-icons { font-size:13px!important; }
.tr-seg-btn:hover   { border-color:var(--g-brd); color:var(--g); background:var(--g-dim); }
.tr-seg-btn.active  { background:var(--g); border-color:var(--g); color:#1a1200; font-weight:800; }
.tr-seg-btn.active-up  { background:var(--up-dim); border-color:var(--up-brd); color:var(--up); }
.tr-seg-btn.active-dn  { background:var(--dn-dim); border-color:var(--dn-brd); color:var(--dn); }
.tr-seg-btn.active-buy { background:var(--up-dim); border-color:var(--up-brd); color:var(--up); }
.tr-seg-btn.active-sell{ background:var(--dn-dim); border-color:var(--dn-brd); color:var(--dn); }

/* ── table ── */
.tr-table-wrap {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:14px; overflow:hidden;
}
.tr-table-head {
    padding:14px 20px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    background:rgba(255,255,255,.015);
}
.tr-table-head h3 { font-size:14px; font-weight:700; color:var(--text-primary); margin:0; display:flex; align-items:center; gap:8px; }
.tr-table-head h3 .material-icons { font-size:17px!important; color:var(--g); }
.tr-count-badge {
    font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px;
    background:var(--g-dim); color:var(--g); border:1px solid var(--g-brd);
}

.tr-table { width:100%; border-collapse:collapse; }
.tr-table thead th {
    padding:9px 16px; text-align:right;
    font-size:10px; font-weight:700; color:var(--text-muted);
    text-transform:uppercase; letter-spacing:.6px;
    background:rgba(255,255,255,.02); border-bottom:1px solid var(--border);
    white-space:nowrap;
}
.tr-table tbody td {
    padding:13px 16px; border-bottom:1px solid var(--border);
    font-size:13px; color:var(--text-secondary); vertical-align:middle;
}
.tr-table tbody tr:last-child td { border-bottom:none; }
.tr-table tbody tr { transition:background .12s; }
.tr-table tbody tr:hover { background:rgba(245,197,24,.025); }

/* ── row cells ── */
.tr-id { font-size:11px; color:var(--text-muted); font-family:'Courier New',monospace; }

.tr-pair {
    display:inline-flex; align-items:center; gap:6px;
    font-size:13px; font-weight:700; color:var(--text-primary);
}
.tr-pair-icon {
    width:26px; height:26px; border-radius:50%;
    background:linear-gradient(135deg,var(--g-dim),rgba(245,197,24,.2));
    border:1px solid var(--g-brd);
    display:flex; align-items:center; justify-content:center;
    font-size:9px; font-weight:800; color:var(--g);
}

.tr-dir {
    display:inline-flex; align-items:center; gap:4px;
    height:24px; padding:0 10px; border-radius:20px; border:1px solid;
    font-size:11px; font-weight:700; letter-spacing:.3px;
}
.tr-dir .material-icons { font-size:12px!important; }
.tr-dir.buy  { background:var(--up-dim); border-color:var(--up-brd); color:var(--up); }
.tr-dir.sell { background:var(--dn-dim); border-color:var(--dn-brd); color:var(--dn); }

.tr-status {
    display:inline-flex; align-items:center; gap:4px;
    height:24px; padding:0 10px; border-radius:20px; border:1px solid;
    font-size:11px; font-weight:600;
}
.tr-status .material-icons { font-size:12px!important; }
.tr-status.open    { background:var(--open-dim); border-color:rgba(61,155,245,.25); color:var(--open); }
.tr-status.closed  { background:rgba(255,255,255,.05); border-color:var(--border); color:var(--text-muted); }
.tr-status.stopped { background:var(--dn-dim); border-color:var(--dn-brd); color:var(--dn); }

.tr-pnl {
    font-family:'Courier New',monospace; font-size:13px; font-weight:700;
    display:inline-flex; align-items:center; gap:4px;
}
.tr-pnl .material-icons { font-size:14px!important; }
.tr-pnl.up { color:var(--up); }
.tr-pnl.dn { color:var(--dn); }
.tr-pnl.zero { color:var(--text-muted); }

.tr-price {
    font-family:'Courier New',monospace; font-size:12px;
    color:var(--text-secondary);
}

.tr-date { font-size:11px; color:var(--text-muted); white-space:nowrap; }
.tr-note { font-size:11px; color:var(--text-muted); max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* ── close btn ── */
.tr-close-btn {
    height:28px; padding:0 10px; border-radius:7px;
    background:var(--dn-dim); border:1px solid var(--dn-brd); color:var(--dn);
    font-size:11px; font-weight:600; cursor:pointer; font-family:inherit;
    display:inline-flex; align-items:center; gap:4px; transition:all .15s;
}
.tr-close-btn .material-icons { font-size:13px!important; }
.tr-close-btn:hover { background:var(--dn); color:#fff; transform:scale(1.04); }

/* ── empty ── */
.tr-empty { padding:60px 20px; text-align:center; }
.tr-empty-icon {
    width:64px; height:64px; border-radius:50%;
    background:var(--g-dim); border:1px solid var(--g-brd);
    display:flex; align-items:center; justify-content:center; margin:0 auto 18px;
}
.tr-empty-icon .material-icons { font-size:30px!important; color:var(--g); }
.tr-empty h3 { font-size:15px; font-weight:700; color:var(--text-primary); margin:0 0 6px; }
.tr-empty p  { font-size:12px; color:var(--text-muted); margin:0; }

/* ── pagination ── */
.tr-pagination { display:flex; align-items:center; justify-content:center; gap:5px; padding:18px; border-top:1px solid var(--border); }
.tr-page-btn {
    width:34px; height:34px; border-radius:8px;
    background:rgba(255,255,255,.04); border:1px solid var(--border);
    display:flex; align-items:center; justify-content:center;
    font-size:12px; font-weight:700; color:var(--text-secondary);
    cursor:pointer; transition:all .14s; text-decoration:none;
}
.tr-page-btn:hover  { border-color:var(--g-brd); color:var(--g); background:var(--g-dim); }
.tr-page-btn.active { background:var(--g); border-color:var(--g); color:#1a1200; }
.tr-page-btn .material-icons { font-size:15px!important; }

/* ── action btns ── */
.tr-btn {
    height:34px; padding:0 14px; border-radius:9px; border:1px solid;
    font-size:12px; font-weight:700; cursor:pointer; font-family:inherit;
    display:inline-flex; align-items:center; gap:5px; transition:all .16s;
    text-decoration:none; white-space:nowrap;
}
.tr-btn .material-icons { font-size:15px!important; }
.tr-btn-primary { background:var(--g); border-color:var(--g); color:#1a1200; }
.tr-btn-primary:hover { background:var(--g-d); box-shadow:0 4px 16px var(--g-glow); transform:translateY(-1px); }
.tr-btn-secondary { background:rgba(255,255,255,.05); border-color:var(--border); color:var(--text-secondary); }
.tr-btn-secondary:hover { background:rgba(255,255,255,.1); color:var(--text-primary); }

/* ── modal ── */
.tr-modal-overlay {
    position:fixed; inset:0; z-index:1060;
    background:rgba(0,0,0,.8); backdrop-filter:blur(5px);
    display:none; align-items:center; justify-content:center; padding:20px;
}
.tr-modal-overlay.show { display:flex; animation:trFadeIn .18s ease; }
@keyframes trFadeIn { from{opacity:0}to{opacity:1} }
.tr-modal {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:16px; width:100%; max-width:420px;
    box-shadow:0 24px 64px rgba(0,0,0,.6);
    animation:trSlideUp .22s cubic-bezier(.34,1.56,.64,1);
    overflow:hidden;
}
@keyframes trSlideUp { from{transform:translateY(28px);opacity:0}to{transform:translateY(0);opacity:1} }
.tr-modal-head {
    padding:18px 20px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
    background:linear-gradient(90deg,var(--dn-dim) 0%,transparent 100%);
}
.tr-modal-title { display:flex; align-items:center; gap:8px; font-size:15px; font-weight:700; color:var(--text-primary); }
.tr-modal-title .material-icons { font-size:18px!important; color:var(--dn); }
.tr-modal-x {
    width:28px; height:28px; border-radius:7px; background:rgba(255,255,255,.05);
    border:1px solid var(--border); display:flex; align-items:center; justify-content:center;
    cursor:pointer; color:var(--text-muted); transition:all .14s;
}
.tr-modal-x:hover { background:var(--dn-dim); color:var(--dn); border-color:var(--dn-brd); }
.tr-modal-x .material-icons { font-size:15px!important; }
.tr-modal-body { padding:22px; }
.tr-modal-body p { font-size:13px; color:var(--text-secondary); margin:0 0 16px; line-height:1.7; }
.tr-input-wrap { margin-bottom:14px; }
.tr-input-wrap label { display:block; font-size:12px; font-weight:600; color:var(--text-secondary); margin-bottom:7px; }
.tr-input {
    width:100%; background:rgba(255,255,255,.05); border:1px solid var(--border);
    border-radius:9px; color:var(--text-primary); font-family:inherit; font-size:13px;
    padding:10px 13px; outline:none; transition:border-color .18s;
}
.tr-input:focus { border-color:var(--g); box-shadow:0 0 0 3px var(--g-dim); }
.tr-modal-foot { padding:14px 20px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:8px; }
.tr-btn-cancel-sm { background:rgba(255,255,255,.05); border-color:var(--border); color:var(--text-secondary); }
.tr-btn-cancel-sm:hover { background:rgba(255,255,255,.1); color:var(--text-primary); }
.tr-btn-danger { background:var(--dn-dim); border-color:var(--dn-brd); color:var(--dn); }
.tr-btn-danger:hover { background:var(--dn); color:#fff; box-shadow:0 4px 14px rgba(255,77,106,.3); }

/* ── toast ── */
.tr-toasts { position:fixed; bottom:22px; left:22px; z-index:9999; display:flex; flex-direction:column; gap:8px; pointer-events:none; }
.tr-toast {
    min-width:240px; background:var(--bg-card); border:1px solid var(--border);
    border-radius:11px; padding:12px 14px; display:flex; align-items:center; gap:10px;
    box-shadow:0 8px 28px rgba(0,0,0,.45); pointer-events:all;
    animation:trToastIn .28s cubic-bezier(.34,1.56,.64,1);
}
.tr-toast.hide { animation:trToastOut .22s ease forwards; }
@keyframes trToastIn  { from{transform:translateY(18px);opacity:0}to{transform:translateY(0);opacity:1} }
@keyframes trToastOut { from{opacity:1}to{opacity:0;transform:translateX(-16px)} }
.tr-toast-ico { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.tr-toast-ico .material-icons { font-size:15px!important; }
.tr-toast.ok  .tr-toast-ico { background:var(--up-dim); color:var(--up); }
.tr-toast.err .tr-toast-ico { background:var(--dn-dim); color:var(--dn); }
.tr-toast-msg { font-size:13px; font-weight:500; color:var(--text-primary); }

html.light .tr-hero       { background:#fff; }
html.light .tr-stat       { background:#fff; }
html.light .tr-table-wrap { background:#fff; }
html.light .tr-filter     { background:#fff; }
html.light .tr-modal      { background:#fff; }
html.light .tr-toast      { background:#fff; }
html.light .tr-ticker     { background:#f8f9fa; }
html.light .tr-input      { background:rgba(0,0,0,.04); color:#1a1a2e; }
</style>

<?php
// محاسبه نسبت‌ها برای ticker
$winRate = ($stats && ($stats->total ?? 0) > 0)
    ? round(($stats->profit_count / $stats->total) * 100, 1)
    : 0;
$lossRate = 100 - $winRate;
?>

<!-- ══ TICKER ══ -->
<div class="tr-ticker">
    <div class="tr-ticker-track" id="tickerTrack">
        <?php
        $tickerItems = [
            ['BTC/USDT','43,250.00','+2.4','up'],
            ['ETH/USDT','2,280.50','+1.8','up'],
            ['BNB/USDT','312.40','-0.6','dn'],
            ['SOL/USDT','98.75','+4.2','up'],
            ['XRP/USDT','0.5820','-1.1','dn'],
            ['ADA/USDT','0.4420','+0.9','up'],
            ['DOGE/USDT','0.0820','+3.1','up'],
            ['TRX/USDT','0.1050','-0.3','dn'],
        ];
         $tickerAll = array_merge($tickerItems, $tickerItems); foreach($tickerAll as $ti): ?>
        <span class="tr-ticker-item">
            <span class="sym"><?= $ti[0] ?></span>
            <span class="prc"><?= $ti[1] ?></span>
            <span class="chg <?= $ti[3] ?>"><?= $ti[3]==='up' ? '▲' : '▼' ?> <?= $ti[2] ?>%</span>
        </span>
        <span class="tr-ticker-sep">|</span>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══ HERO ══ -->
<div class="tr-hero">
    <div class="tr-hero-left">
        <div class="tr-hero-icon">
            <span class="material-icons">candlestick_chart</span>
        </div>
        <div>
            <h1 class="tr-hero-title">تاریخچه تریدها</h1>
            <p class="tr-hero-sub">مدیریت و نظارت بر معاملات — <?= number_format($total) ?> ترید ثبت‌شده</p>
        </div>
    </div>
    <div class="tr-hero-right">
        <a href="<?= url('/admin/investment/trades/create') ?>" class="tr-btn tr-btn-primary">
            <span class="material-icons">add_chart</span>
            ثبت ترید جدید
        </a>
        <a href="<?= url('/admin/investment') ?>" class="tr-btn tr-btn-secondary">
            <span class="material-icons">arrow_forward</span>
            بازگشت
        </a>
    </div>
</div>

<!-- ══ STATS ══ -->
<?php if ($stats): ?>
<div class="tr-stats">
    <!-- مجموع -->
    <div class="tr-stat">
        <div class="tr-stat-glow" style="background:var(--g);"></div>
        <div class="tr-stat-icon" style="background:var(--g-dim);"><span class="material-icons" style="color:var(--g);font-size:18px!important;">bar_chart</span></div>
        <div class="tr-stat-label">مجموع تریدها</div>
        <div class="tr-stat-val gold"><?= number_format((int)($stats->total ?? 0)) ?></div>
    </div>
    <!-- باز -->
    <div class="tr-stat">
        <div class="tr-stat-glow" style="background:var(--open);"></div>
        <div class="tr-stat-icon" style="background:var(--open-dim);"><span class="material-icons" style="color:var(--open);font-size:18px!important;">pending</span></div>
        <div class="tr-stat-label">باز</div>
        <div class="tr-stat-val" style="color:var(--open);"><?= number_format((int)($stats->open_count ?? 0)) ?></div>
    </div>
    <!-- سودده -->
    <div class="tr-stat">
        <div class="tr-stat-glow" style="background:var(--up);"></div>
        <div class="tr-stat-icon" style="background:var(--up-dim);"><span class="material-icons" style="color:var(--up);font-size:18px!important;">trending_up</span></div>
        <div class="tr-stat-label">سودده</div>
        <div class="tr-stat-val up"><?= number_format((int)($stats->profit_count ?? 0)) ?></div>
    </div>
    <!-- ضررده -->
    <div class="tr-stat">
        <div class="tr-stat-glow" style="background:var(--dn);"></div>
        <div class="tr-stat-icon" style="background:var(--dn-dim);"><span class="material-icons" style="color:var(--dn);font-size:18px!important;">trending_down</span></div>
        <div class="tr-stat-label">ضررده</div>
        <div class="tr-stat-val dn"><?= number_format((int)($stats->loss_count ?? 0)) ?></div>
    </div>
    <!-- win rate -->
    <div class="tr-stat">
        <div class="tr-stat-glow" style="background:var(--g);"></div>
        <div class="tr-stat-icon" style="background:var(--g-dim);"><span class="material-icons" style="color:var(--g);font-size:18px!important;">percent</span></div>
        <div class="tr-stat-label">Win Rate</div>
        <div class="tr-stat-val gold"><?= $winRate ?>%</div>
    </div>
</div>
<?php endif; ?>

<!-- ══ FILTER ══ -->
<div class="tr-filter">
    <span class="tr-filter-label">فیلتر:</span>

    <!-- وضعیت -->
    <div class="tr-seg">
        <?php
        $sBase = url('/admin/investment/trades') . ($directionQ ? '?direction='.$directionQ : '');
        ?>
        <a href="<?= url('/admin/investment/trades') ?><?= $directionQ ? '?direction='.$directionQ : '' ?>"
           class="tr-seg-btn <?= !$statusQ ? 'active' : '' ?>">همه</a>
        <a href="<?= $sBase . ($directionQ ? '&' : '?') ?>status=open"
           class="tr-seg-btn <?= $statusQ==='open' ? 'active' : '' ?>">
            <span class="material-icons">pending</span>باز
        </a>
        <a href="<?= $sBase . ($directionQ ? '&' : '?') ?>status=closed"
           class="tr-seg-btn <?= $statusQ==='closed' ? 'active' : '' ?>">
            <span class="material-icons">check_circle</span>بسته
        </a>
    </div>

    <span style="color:var(--border);font-size:18px;">|</span>

    <!-- جهت -->
    <div class="tr-seg">
        <?php
        $dBase = url('/admin/investment/trades') . ($statusQ ? '?status='.$statusQ : '');
        ?>
        <a href="<?= url('/admin/investment/trades') ?><?= $statusQ ? '?status='.$statusQ : '' ?>"
           class="tr-seg-btn <?= !$directionQ ? 'active' : '' ?>">همه جهت‌ها</a>
        <a href="<?= $dBase . ($statusQ ? '&' : '?') ?>direction=buy"
           class="tr-seg-btn <?= $directionQ==='buy' ? 'active-buy' : '' ?>">
            <span class="material-icons">arrow_upward</span>خرید
        </a>
        <a href="<?= $dBase . ($statusQ ? '&' : '?') ?>direction=sell"
           class="tr-seg-btn <?= $directionQ==='sell' ? 'active-sell' : '' ?>">
            <span class="material-icons">arrow_downward</span>فروش
        </a>
    </div>
</div>

<!-- ══ TABLE ══ -->
<div class="tr-table-wrap">
    <div class="tr-table-head">
        <h3>
            <span class="material-icons">format_list_bulleted</span>
            لیست تریدها
        </h3>
        <span class="tr-count-badge"><?= number_format($total) ?> ترید</span>
    </div>

    <?php if (empty($trades)): ?>
    <div class="tr-empty">
        <div class="tr-empty-icon"><span class="material-icons">candlestick_chart</span></div>
        <h3>هیچ ترید‌ای یافت نشد</h3>
        <p>با فیلتر انتخابی نتیجه‌ای وجود ندارد</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="tr-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>جفت ارز</th>
                    <th>جهت</th>
                    <th>وضعیت</th>
                    <th>قیمت باز</th>
                    <th>قیمت بسته</th>
                    <th>سود/ضرر (USDT)</th>
                    <th>تاریخ باز</th>
                    <th>توضیح</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trades as $t):
                    $pnl    = (float)($t->profit_loss_amount ?? 0);
                    $isUp   = $pnl > 0;
                    $isZero = $pnl == 0;
                    $dir    = strtolower($t->direction ?? 'buy');
                    $status = strtolower($t->status ?? 'open');
                    $pair   = $t->trading_pair ?? ($t->symbol ?? 'BTC/USDT');
                    $sym    = explode('/', $pair)[0] ?? 'BTC';
                ?>
                <tr>
                    <td><span class="tr-id">#<?= (int)$t->id ?></span></td>
                    <td>
                        <div class="tr-pair">
                            <div class="tr-pair-icon"><?= mb_substr($sym,0,1) ?></div>
                            <?= e($pair) ?>
                        </div>
                    </td>
                    <td>
                        <span class="tr-dir <?= $dir ?>">
                            <span class="material-icons"><?= $dir==='buy' ? 'arrow_upward' : 'arrow_downward' ?></span>
                            <?= $dir==='buy' ? 'خرید' : 'فروش' ?>
                        </span>
                    </td>
                    <td>
                        <?php
                        $stIco = ['open'=>'pending','closed'=>'check_circle','stopped'=>'cancel'];
                        $stLbl = ['open'=>'باز','closed'=>'بسته','stopped'=>'متوقف'];
                        ?>
                        <span class="tr-status <?= $status ?>">
                            <span class="material-icons"><?= $stIco[$status] ?? 'help' ?></span>
                            <?= $stLbl[$status] ?? $status ?>
                        </span>
                    </td>
                    <td><span class="tr-price"><?= number_format((float)($t->open_price ?? 0), 4) ?></span></td>
                    <td><span class="tr-price"><?= $t->close_price ? number_format((float)$t->close_price, 4) : '—' ?></span></td>
                    <td>
                        <span class="tr-pnl <?= $isZero ? 'zero' : ($isUp ? 'up' : 'dn') ?>">
                            <span class="material-icons"><?= $isZero ? 'remove' : ($isUp ? 'arrow_drop_up' : 'arrow_drop_down') ?></span>
                            <?= $isUp ? '+' : '' ?><?= number_format($pnl, 4) ?>
                        </span>
                    </td>
                    <td><span class="tr-date"><?= to_jalali($t->open_time ?? $t->created_at) ?></span></td>
                    <td><span class="tr-note" title="<?= e($t->note ?? $t->description ?? '') ?>"><?= e($t->note ?? $t->description ?? '—') ?></span></td>
                    <td>
                        <?php if ($status === 'open'): ?>
                        <button class="tr-close-btn" onclick="openCloseModal(<?= (int)$t->id ?>, '<?= e(addslashes($pair)) ?>')">
                            <span class="material-icons">close</span>
                            بستن
                        </button>
                        <?php else: ?>
                        <span style="font-size:11px;color:var(--text-muted);">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="tr-pagination">
        <?php if ($currentPage > 1): ?>
        <a href="?page=<?= $currentPage-1 ?><?= $statusQ ? '&status='.$statusQ : '' ?><?= $directionQ ? '&direction='.$directionQ : '' ?>" class="tr-page-btn">
            <span class="material-icons">chevron_right</span>
        </a>
        <?php endif; ?>
        <?php for ($i = max(1,$currentPage-2); $i <= min($totalPages,$currentPage+2); $i++): ?>
        <a href="?page=<?= $i ?><?= $statusQ ? '&status='.$statusQ : '' ?><?= $directionQ ? '&direction='.$directionQ : '' ?>"
           class="tr-page-btn <?= $i===$currentPage ? 'active' : '' ?>">
            <?= fa_number($i) ?>
        </a>
        <?php endfor; ?>
        <?php if ($currentPage < $totalPages): ?>
        <a href="?page=<?= $currentPage+1 ?><?= $statusQ ? '&status='.$statusQ : '' ?><?= $directionQ ? '&direction='.$directionQ : '' ?>" class="tr-page-btn">
            <span class="material-icons">chevron_left</span>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ══ MODAL بستن ترید ══ -->
<div class="tr-modal-overlay" id="closeOverlay">
    <div class="tr-modal">
        <div class="tr-modal-head">
            <div class="tr-modal-title">
                <span class="material-icons">close</span>
                بستن ترید
            </div>
            <button class="tr-modal-x" onclick="closeModal()">
                <span class="material-icons">close</span>
            </button>
        </div>
        <div class="tr-modal-body">
            <p id="closeModalDesc">در حال بستن ترید...</p>
            <div class="tr-input-wrap">
                <label>قیمت بستن (اختیاری)</label>
                <input type="number" id="closePrice" class="tr-input" step="0.0001" placeholder="مثال: 43250.0000">
            </div>
            <div class="tr-input-wrap">
                <label>مقدار سود/ضرر (USDT)</label>
                <input type="number" id="closePnl" class="tr-input" step="0.0001" placeholder="مثبت=سود، منفی=ضرر">
            </div>
            <div class="tr-input-wrap">
                <label>توضیح</label>
                <input type="text" id="closeNote" class="tr-input" placeholder="دلیل بستن...">
            </div>
        </div>
        <div class="tr-modal-foot">
            <button class="tr-btn tr-btn-cancel-sm" onclick="closeModal()">
                <span class="material-icons">close</span> انصراف
            </button>
            <button class="tr-btn tr-btn-danger" id="closeConfirmBtn" onclick="doClose()">
                <span class="material-icons">check</span> بستن ترید
            </button>
        </div>
    </div>
</div>

<!-- Toasts -->
<div class="tr-toasts" id="trToasts"></div>

<script>
const CSRF = '<?= csrf_token() ?>';
let _closeId = null;

function toast(msg, type='ok') {
    const w = document.getElementById('trToasts');
    const t = document.createElement('div');
    t.className = `tr-toast ${type}`;
    t.innerHTML = `<div class="tr-toast-ico"><span class="material-icons">${type==='ok'?'check_circle':'error'}</span></div><div class="tr-toast-msg">${msg}</div>`;
    w.appendChild(t);
    setTimeout(() => { t.classList.add('hide'); setTimeout(() => t.remove(), 280); }, 3200);
}

function openCloseModal(id, pair) {
    _closeId = id;
    document.getElementById('closeModalDesc').textContent = `بستن ترید #${id} — ${pair}`;
    document.getElementById('closePrice').value = '';
    document.getElementById('closePnl').value   = '';
    document.getElementById('closeNote').value  = '';
    document.getElementById('closeOverlay').classList.add('show');
}

function closeModal() {
    document.getElementById('closeOverlay').classList.remove('show');
    _closeId = null;
}

document.getElementById('closeOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function doClose() {
    if (!_closeId) return;
    const btn = document.getElementById('closeConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="material-icons" style="animation:spin 1s linear infinite">refresh</span> در حال بستن...';

    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('close_price',      document.getElementById('closePrice').value);
    fd.append('profit_loss_amount',document.getElementById('closePnl').value);
    fd.append('note',             document.getElementById('closeNote').value);

    fetch(`<?= url('/admin/investment/trades/') ?>${_closeId}/close`, { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeModal();
                toast(data.message || 'ترید با موفقیت بسته شد');
                setTimeout(() => location.reload(), 1200);
            } else {
                toast(data.message || 'خطا در بستن ترید', 'err');
                btn.disabled = false;
                btn.innerHTML = '<span class="material-icons">check</span> بستن ترید';
            }
        })
        .catch(() => { toast('خطا در ارتباط با سرور','err'); btn.disabled=false; });
}
</script>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>

<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>