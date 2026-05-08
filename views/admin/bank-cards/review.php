<?php
$title  = 'بررسی کارت بانکی';
$layout = 'admin';
ob_start();
$card = $card ?? null;
$user = $user ?? null;
$cardId = (int)($card->id ?? 0);

$statusMap = [
    'pending'  => ['label' => 'در انتظار',   'icon' => 'schedule',      'cls' => 'pending'],
    'verified' => ['label' => 'تأیید شده',   'icon' => 'check_circle',  'cls' => 'verified'],
    'rejected' => ['label' => 'رد شده',      'icon' => 'cancel',        'cls' => 'rejected'],
];
$st = $statusMap[$card->status ?? ''] ?? ['label' => e($card->status ?? '—'), 'icon' => 'help', 'cls' => 'pending'];

// فرمت شماره کارت نمایشی
$rawNum  = $card->card_number ?? '0000000000000000';
$dispNum = strlen($rawNum) >= 16
    ? substr($rawNum,0,4).'  ****  ****  '.substr($rawNum,-4)
    : '****  ****  ****  ****';
?>
<style>
/* ══════════════════════════════════════════════════
   admin-bank-cards.css — کارت‌های بانکی
   تم: طلایی + مشکی حرفه‌ای — هماهنگ با admin.css
   ══════════════════════════════════════════════════ */

:root {
    --gold:        #F5C518;
    --gold-d:      #D4A800;
    --gold-glow:   rgba(245,197,24,0.18);
    --gold-dim:    rgba(245,197,24,0.10);
    --gold-border: rgba(245,197,24,0.28);
}

/* ══ HERO HEADER ══ */
.bc-hero {
    position:relative; background:var(--bg-card);
    border:1px solid var(--border); border-radius:var(--radius);
    padding:28px 28px 24px; margin-bottom:24px; overflow:hidden;
    display:flex; align-items:center; justify-content:space-between; gap:20px;
}
.bc-hero::before {
    content:''; position:absolute; top:-60px; right:-60px;
    width:200px; height:200px;
    background:radial-gradient(circle,var(--gold-glow) 0%,transparent 70%);
    pointer-events:none;
}
.bc-hero-left { display:flex; align-items:center; gap:18px; position:relative; z-index:1; }
.bc-hero-icon {
    width:56px; height:56px; border-radius:14px;
    background:linear-gradient(135deg,var(--gold) 0%,var(--gold-d) 100%);
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 4px 20px var(--gold-glow), 0 0 0 1px var(--gold-border);
    flex-shrink:0;
}
.bc-hero-icon .material-icons { font-size:28px !important; color:#1a1200; }
.bc-hero-text h1 { font-size:20px; font-weight:800; color:var(--text-primary); margin:0 0 4px; }
.bc-hero-text p  { font-size:13px; color:var(--text-muted); margin:0; }
.bc-hero-right { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }

.bc-stat-pill {
    display:flex; align-items:center; gap:8px; padding:10px 18px;
    border-radius:40px; border:1px solid; font-size:13px; font-weight:600; white-space:nowrap;
}
.bc-stat-pill .material-icons { font-size:16px !important; }
.bc-stat-pill.gold  { background:var(--gold-dim);  color:var(--gold);  border-color:var(--gold-border); }
.bc-stat-pill.green { background:var(--green-dim); color:var(--green); border-color:rgba(16,185,129,.25); }
.bc-stat-pill.red   { background:var(--red-dim);   color:var(--red);   border-color:rgba(239,68,68,.25); }

/* ══ BANK CARD VISUAL ══ */
.bank-card-visual {
    position:relative; width:200px; min-width:200px; height:118px;
    border-radius:12px;
    background:linear-gradient(135deg,#1a1200 0%,#2d2000 50%,#1a1200 100%);
    border:1px solid var(--gold-border);
    box-shadow:0 6px 24px rgba(0,0,0,.5),inset 0 1px 0 rgba(245,197,24,.15);
    padding:14px 16px; display:flex; flex-direction:column; justify-content:space-between;
    overflow:hidden; flex-shrink:0;
}
.bank-card-visual::before { content:''; position:absolute; top:-20px; left:-20px; width:100px; height:100px; background:radial-gradient(circle,rgba(245,197,24,.12) 0%,transparent 70%); }
.bank-card-visual::after  { content:''; position:absolute; bottom:-30px; right:-10px; width:120px; height:120px; border:1px solid rgba(245,197,24,.08); border-radius:50%; }
.bcv-chip { width:28px; height:20px; background:linear-gradient(135deg,var(--gold) 0%,var(--gold-d) 100%); border-radius:4px; opacity:.85; }
.bcv-number { font-family:'Courier New',monospace; font-size:11px; letter-spacing:2px; color:rgba(245,197,24,.8); direction:ltr; }
.bcv-bottom { display:flex; justify-content:space-between; align-items:flex-end; }
.bcv-name { font-size:9px; color:rgba(255,255,255,.55); text-transform:uppercase; letter-spacing:1px; }
.bcv-bank { font-size:9px; color:var(--gold); font-weight:700; letter-spacing:.5px; }

/* ══ TABLE ══ */
.bc-table-wrap { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.bc-table-header {
    padding:16px 20px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
}
.bc-table-header h3 { font-size:15px; font-weight:700; color:var(--text-primary); margin:0; display:flex; align-items:center; gap:8px; }
.bc-table-header h3 .material-icons { font-size:18px !important; color:var(--gold); }

.bc-table { width:100%; border-collapse:collapse; }
.bc-table thead th {
    padding:10px 16px; text-align:right;
    font-size:11px; font-weight:600; color:var(--text-muted);
    text-transform:uppercase; letter-spacing:.5px;
    background:rgba(255,255,255,.02); border-bottom:1px solid var(--border); white-space:nowrap;
}
.bc-table tbody td { padding:14px 16px; border-bottom:1px solid var(--border); font-size:13px; color:var(--text-secondary); vertical-align:middle; }
.bc-table tbody tr:last-child td { border-bottom:none; }
.bc-table tbody tr { transition:background .15s; }
.bc-table tbody tr:hover { background:rgba(245,197,24,.03); }

.bc-user-cell { display:flex; align-items:center; gap:10px; }
.bc-user-avatar {
    width:36px; height:36px; border-radius:50%;
    background:linear-gradient(135deg,var(--gold-dim),rgba(245,197,24,.2));
    border:1px solid var(--gold-border);
    display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:700; color:var(--gold); flex-shrink:0;
}
.bc-user-name  { font-size:13px; font-weight:600; color:var(--text-primary); margin:0 0 2px; }
.bc-user-email { font-size:11px; color:var(--text-muted); margin:0; }

.bc-card-num {
    font-family:'Courier New',monospace; font-size:12px; letter-spacing:1.5px;
    color:var(--text-primary); background:rgba(245,197,24,.06);
    border:1px solid var(--gold-border); padding:4px 10px; border-radius:6px;
    direction:ltr; display:inline-block;
}
.bc-sheba {
    font-family:'Courier New',monospace; font-size:11px; letter-spacing:1px;
    color:var(--text-secondary); background:rgba(255,255,255,.04);
    border:1px solid var(--border); padding:3px 8px; border-radius:5px;
    direction:ltr; display:inline-block;
}
.bc-bank-badge {
    display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:20px;
    background:rgba(255,255,255,.04); border:1px solid var(--border);
    font-size:12px; font-weight:500; color:var(--text-secondary);
}
.bc-bank-badge .material-icons { font-size:13px !important; color:var(--gold); }

.bc-actions { display:flex; gap:6px; align-items:center; }
.bc-btn {
    display:inline-flex; align-items:center; justify-content:center; gap:5px;
    height:34px; padding:0 12px; border-radius:8px; border:1px solid;
    font-size:12px; font-weight:600; cursor:pointer; transition:all .18s ease;
    white-space:nowrap; font-family:inherit;
}
.bc-btn .material-icons { font-size:15px !important; }
.bc-btn-view    { background:rgba(91,138,245,.08); border-color:rgba(91,138,245,.2); color:var(--accent); }
.bc-btn-view:hover    { background:rgba(91,138,245,.18); border-color:rgba(91,138,245,.4); transform:translateY(-1px); }
.bc-btn-approve { background:var(--green-dim); border-color:rgba(16,185,129,.25); color:var(--green); }
.bc-btn-approve:hover { background:rgba(16,185,129,.25); transform:translateY(-1px); box-shadow:0 4px 12px rgba(16,185,129,.2); }
.bc-btn-reject  { background:var(--red-dim); border-color:rgba(239,68,68,.25); color:var(--red); }
.bc-btn-reject:hover  { background:rgba(239,68,68,.22); transform:translateY(-1px); box-shadow:0 4px 12px rgba(239,68,68,.2); }

/* ══ EMPTY STATE ══ */
.bc-empty { padding:64px 20px; text-align:center; }
.bc-empty-icon {
    width:72px; height:72px; border-radius:50%;
    background:var(--gold-dim); border:1px solid var(--gold-border);
    display:flex; align-items:center; justify-content:center; margin:0 auto 20px;
}
.bc-empty-icon .material-icons { font-size:34px !important; color:var(--gold); }
.bc-empty h3 { font-size:16px; font-weight:700; color:var(--text-primary); margin:0 0 8px; }
.bc-empty p  { font-size:13px; color:var(--text-muted); margin:0; }

/* ══ MODAL ══ */
.bc-modal-overlay {
    position:fixed; inset:0; z-index:1050;
    background:rgba(0,0,0,.75); backdrop-filter:blur(4px);
    display:none; align-items:center; justify-content:center; padding:20px;
}
.bc-modal-overlay.show { display:flex; animation:bcFadeIn .2s ease; }
@keyframes bcFadeIn { from{opacity:0} to{opacity:1} }

.bc-modal {
    background:var(--bg-card); border:1px solid var(--border); border-radius:16px;
    width:100%; max-width:480px;
    box-shadow:0 24px 64px rgba(0,0,0,.5); overflow:hidden;
    animation:bcSlideUp .25s cubic-bezier(.34,1.56,.64,1);
}
@keyframes bcSlideUp { from{transform:translateY(30px);opacity:0} to{transform:translateY(0);opacity:1} }

.bc-modal-head {
    padding:20px 22px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
    background:linear-gradient(90deg,rgba(239,68,68,.08) 0%,transparent 100%);
}
.bc-modal-head-title { display:flex; align-items:center; gap:10px; font-size:16px; font-weight:700; color:var(--text-primary); }
.bc-modal-head-title .material-icons { font-size:20px !important; color:var(--red); }
.bc-modal-close {
    width:30px; height:30px; border-radius:8px; background:rgba(255,255,255,.05);
    border:1px solid var(--border); display:flex; align-items:center; justify-content:center;
    cursor:pointer; color:var(--text-muted); transition:all .15s;
}
.bc-modal-close:hover { background:var(--red-dim); color:var(--red); border-color:rgba(239,68,68,.3); }
.bc-modal-close .material-icons { font-size:16px !important; }
.bc-modal-body { padding:22px; }

.bc-form-group { margin-bottom:16px; }
.bc-form-group label { display:block; font-size:13px; font-weight:600; color:var(--text-secondary); margin-bottom:8px; }
.bc-form-group label .req { color:var(--red); margin-right:2px; }
.bc-textarea {
    width:100%; background:rgba(255,255,255,.04); border:1px solid var(--border);
    border-radius:10px; color:var(--text-primary); font-family:inherit; font-size:13px;
    padding:12px 14px; resize:vertical; min-height:100px;
    outline:none; transition:border-color .2s; line-height:1.7;
}
.bc-textarea:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(239,68,68,.08); }

.bc-quick-reasons { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }
.bc-reason-chip {
    padding:5px 10px; border-radius:20px; background:rgba(255,255,255,.05);
    border:1px solid var(--border); font-size:11px; color:var(--text-secondary);
    cursor:pointer; transition:all .15s; font-family:inherit;
}
.bc-reason-chip:hover { border-color:var(--red); color:var(--red); background:var(--red-dim); }

.bc-modal-foot {
    padding:16px 22px; border-top:1px solid var(--border);
    display:flex; justify-content:flex-end; gap:10px; background:rgba(255,255,255,.01);
}
.bc-btn-lg {
    height:40px; padding:0 20px; border-radius:10px; border:1px solid;
    font-size:13px; font-weight:600; cursor:pointer; transition:all .18s;
    display:inline-flex; align-items:center; gap:6px; font-family:inherit;
}
.bc-btn-lg .material-icons { font-size:16px !important; }
.bc-btn-cancel { background:rgba(255,255,255,.05); border-color:var(--border); color:var(--text-secondary); }
.bc-btn-cancel:hover { background:rgba(255,255,255,.1); color:var(--text-primary); }
.bc-btn-submit-reject { background:var(--red-dim); border-color:rgba(239,68,68,.3); color:var(--red); }
.bc-btn-submit-reject:hover { background:var(--red); color:#fff; box-shadow:0 4px 16px rgba(239,68,68,.3); }

/* ══ REVIEW PAGE ══ */
.bc-review-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
@media(max-width:768px) { .bc-review-grid { grid-template-columns:1fr; } }

.bc-info-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.bc-info-card-head {
    padding:14px 18px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; gap:8px; background:rgba(255,255,255,.01);
}
.bc-info-card-head .material-icons { font-size:18px !important; color:var(--gold); }
.bc-info-card-head h4 { font-size:14px; font-weight:700; color:var(--text-primary); margin:0; }
.bc-info-card-body { padding:18px; }

.bc-info-row { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:10px 0; border-bottom:1px solid var(--border); }
.bc-info-row:last-child { border-bottom:none; padding-bottom:0; }
.bc-info-label { font-size:12px; color:var(--text-muted); flex-shrink:0; font-weight:500; }
.bc-info-value { font-size:13px; color:var(--text-primary); font-weight:500; text-align:left; }

.bc-card-preview-wrap { display:flex; justify-content:center; padding:20px 0 10px; }
.bank-card-visual-lg {
    position:relative; width:280px; height:165px; border-radius:16px;
    background:linear-gradient(135deg,#1a1200 0%,#302000 40%,#1a1200 100%);
    border:1px solid var(--gold-border);
    box-shadow:0 12px 40px rgba(0,0,0,.6),0 0 0 1px rgba(245,197,24,.08),inset 0 1px 0 rgba(245,197,24,.12);
    padding:20px 22px; display:flex; flex-direction:column; justify-content:space-between; overflow:hidden;
}
.bank-card-visual-lg::before { content:''; position:absolute; top:-30px; left:-30px; width:140px; height:140px; background:radial-gradient(circle,rgba(245,197,24,.1) 0%,transparent 70%); }
.bank-card-visual-lg::after  { content:''; position:absolute; bottom:-40px; right:-15px; width:160px; height:160px; border:1.5px solid rgba(245,197,24,.07); border-radius:50%; }
.bcvl-top   { display:flex; justify-content:space-between; align-items:flex-start; }
.bcvl-chip  { width:36px; height:26px; background:linear-gradient(135deg,var(--gold) 0%,var(--gold-d) 100%); border-radius:5px; opacity:.9; }
.bcvl-bank  { font-size:12px; color:var(--gold); font-weight:800; letter-spacing:1px; text-align:left; }
.bcvl-number{ font-family:'Courier New',monospace; font-size:16px; letter-spacing:3px; color:rgba(245,197,24,.85); direction:ltr; text-align:center; }
.bcvl-bottom{ display:flex; justify-content:space-between; align-items:flex-end; }
.bcvl-holder{ font-size:10px; color:rgba(255,255,255,.55); text-transform:uppercase; letter-spacing:1.5px; }
.bcvl-sheba { font-size:9px; color:rgba(245,197,24,.5); direction:ltr; letter-spacing:.5px; }

.bc-action-panel { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; margin-bottom:20px; }
.bc-action-panel-head {
    padding:14px 18px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; gap:8px;
    background:linear-gradient(90deg,rgba(245,197,24,.05) 0%,transparent 100%);
}
.bc-action-panel-head .material-icons { font-size:18px !important; color:var(--gold); }
.bc-action-panel-head h4 { font-size:14px; font-weight:700; color:var(--text-primary); margin:0; }
.bc-action-panel-body { padding:20px; display:flex; gap:12px; flex-wrap:wrap; }

.bc-btn-action {
    height:44px; padding:0 22px; border-radius:10px; border:1px solid;
    font-size:14px; font-weight:700; cursor:pointer; transition:all .2s;
    display:inline-flex; align-items:center; gap:8px; font-family:inherit;
}
.bc-btn-action .material-icons { font-size:18px !important; }
.bc-btn-action.approve { background:var(--green-dim); border-color:rgba(16,185,129,.3); color:var(--green); }
.bc-btn-action.approve:hover { background:var(--green); color:#fff; border-color:var(--green); box-shadow:0 6px 20px rgba(16,185,129,.3); transform:translateY(-2px); }
.bc-btn-action.reject-action { background:var(--red-dim); border-color:rgba(239,68,68,.3); color:var(--red); }
.bc-btn-action.reject-action:hover { background:var(--red); color:#fff; border-color:var(--red); box-shadow:0 6px 20px rgba(239,68,68,.3); transform:translateY(-2px); }

.bc-status-display { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:20px; font-size:13px; font-weight:600; border:1px solid; }
.bc-status-display .material-icons { font-size:14px !important; }
.bc-status-display.pending  { background:var(--orange-dim); color:var(--orange); border-color:rgba(245,158,11,.3); }
.bc-status-display.verified { background:var(--green-dim);  color:var(--green);  border-color:rgba(16,185,129,.3); }
.bc-status-display.rejected { background:var(--red-dim);    color:var(--red);    border-color:rgba(239,68,68,.3); }

/* ══ PAGINATION ══ */
.bc-pagination { display:flex; align-items:center; justify-content:center; gap:6px; padding:20px; border-top:1px solid var(--border); }
.bc-page-btn {
    width:36px; height:36px; border-radius:8px;
    background:rgba(255,255,255,.04); border:1px solid var(--border);
    display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:600; color:var(--text-secondary);
    cursor:pointer; transition:all .15s; text-decoration:none;
}
.bc-page-btn:hover { border-color:var(--gold-border); color:var(--gold); background:var(--gold-dim); }
.bc-page-btn.active { background:var(--gold); border-color:var(--gold); color:#1a1200; font-weight:800; }
.bc-page-btn .material-icons { font-size:16px !important; }

/* ══ TOAST ══ */
.bc-toast-wrap { position:fixed; bottom:24px; left:24px; z-index:9999; display:flex; flex-direction:column; gap:10px; pointer-events:none; }
.bc-toast {
    min-width:260px; max-width:340px; background:var(--bg-card); border:1px solid var(--border);
    border-radius:12px; padding:14px 16px; display:flex; align-items:center; gap:12px;
    box-shadow:0 8px 32px rgba(0,0,0,.4);
    animation:bcToastIn .3s cubic-bezier(.34,1.56,.64,1); pointer-events:all;
}
.bc-toast.hide { animation:bcToastOut .25s ease forwards; }
@keyframes bcToastIn  { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }
@keyframes bcToastOut { from{opacity:1} to{opacity:0;transform:translateX(-20px)} }
.bc-toast-icon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.bc-toast-icon .material-icons { font-size:16px !important; }
.bc-toast.success .bc-toast-icon { background:var(--green-dim); color:var(--green); }
.bc-toast.error   .bc-toast-icon { background:var(--red-dim);   color:var(--red); }
.bc-toast-msg { font-size:13px; font-weight:500; color:var(--text-primary); }

/* ══ LIGHT MODE ══ */
html.light .bc-hero         { background:#fff; }
html.light .bc-table-wrap   { background:#fff; }
html.light .bc-info-card    { background:#fff; }
html.light .bc-action-panel { background:#fff; }
html.light .bc-toast        { background:#fff; }
html.light .bc-modal        { background:#fff; }
html.light .bc-card-num     { background:rgba(245,197,24,.08); }
html.light .bc-textarea     { background:rgba(0,0,0,.03); color:#1a1a2e; }
html.light .bc-page-btn     { background:#f5f5f5; }

</style>
">

<!-- ══ PAGE HEADER ══ -->
<div class="page-header-section" style="margin-bottom:24px;">
    <div class="bc-hero-left" style="gap:14px;">
        <div class="bc-hero-icon" style="width:46px;height:46px;border-radius:12px;">
            <span class="material-icons" style="font-size:22px!important;">credit_card</span>
        </div>
        <div>
            <h1 style="font-size:18px;font-weight:800;margin:0 0 3px;">بررسی کارت بانکی <span style="color:var(--text-muted);font-weight:400;">#<?= $cardId ?></span></h1>
            <p style="font-size:12px;color:var(--text-muted);margin:0;">
                ثبت شده توسط <?= e($user->full_name ?? 'نامشخص') ?> — <?= to_jalali($card->created_at ?? '') ?>
            </p>
        </div>
    </div>
    <div class="page-header-right">
        <div class="bc-status-display <?= $st['cls'] ?>">
            <span class="material-icons"><?= $st['icon'] ?></span>
            <?= $st['label'] ?>
        </div>
        <a href="<?= url('/admin/bank-cards') ?>" class="btn btn-secondary btn-sm">
            <span class="material-icons">arrow_forward</span> بازگشت
        </a>
    </div>
</div>

<!-- ══ CARD PREVIEW ══ -->
<div class="bc-card-preview-wrap" style="margin-bottom:24px;">
    <div class="bank-card-visual-lg">
        <div class="bcvl-top">
            <div class="bcvl-chip"></div>
            <div class="bcvl-bank"><?= e(strtoupper($card->bank_name ?? 'BANK')) ?></div>
        </div>
        <div class="bcvl-number"><?= $dispNum ?></div>
        <div class="bcvl-bottom">
            <div class="bcvl-holder"><?= e($card->cardholder_name ?? 'CARD HOLDER') ?></div>
            <?php if (!empty($card->sheba)): ?>
            <div class="bcvl-sheba">IR<?= e($card->sheba) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══ INFO GRID ══ -->
<div class="bc-review-grid">

    <!-- اطلاعات کاربر -->
    <div class="bc-info-card">
        <div class="bc-info-card-head">
            <span class="material-icons">person</span>
            <h4>اطلاعات صاحب کارت</h4>
        </div>
        <div class="bc-info-card-body">
            <div class="bc-info-row">
                <span class="bc-info-label">نام کامل</span>
                <span class="bc-info-value" style="font-weight:700;"><?= e($user->full_name ?? '—') ?></span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">ایمیل</span>
                <span class="bc-info-value" dir="ltr"><?= e($user->email ?? '—') ?></span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">موبایل</span>
                <span class="bc-info-value" dir="ltr"><?= e($user->mobile ?? '—') ?></span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">وضعیت KYC</span>
                <span class="bc-info-value">
                    <?php
                    $kyc = $user->kyc_status ?? 'none';
                    $kycColor = $kyc === 'verified' ? 'var(--green)' : 'var(--orange)';
                    $kycIcon  = $kyc === 'verified' ? 'verified_user' : 'pending';
                    $kycLabel = $kyc === 'verified' ? 'تأیید شده' : 'در انتظار';
                    ?>
                    <span style="display:inline-flex;align-items:center;gap:5px;color:<?= $kycColor ?>;">
                        <span class="material-icons" style="font-size:14px!important;"><?= $kycIcon ?></span>
                        <?= $kycLabel ?>
                    </span>
                </span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">عضویت از</span>
                <span class="bc-info-value"><?= to_jalali($user->created_at ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- اطلاعات کارت -->
    <div class="bc-info-card">
        <div class="bc-info-card-head">
            <span class="material-icons">credit_card</span>
            <h4>اطلاعات کارت بانکی</h4>
        </div>
        <div class="bc-info-card-body">
            <div class="bc-info-row">
                <span class="bc-info-label">شماره کارت</span>
                <span class="bc-info-value">
                    <span class="bc-card-num"><?= e($card->card_number ?? '—') ?></span>
                </span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">نام بانک</span>
                <span class="bc-info-value">
                    <span class="bc-bank-badge">
                        <span class="material-icons">account_balance</span>
                        <?= e($card->bank_name ?? '—') ?>
                    </span>
                </span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">نام صاحب حساب</span>
                <span class="bc-info-value" style="font-weight:600;"><?= e($card->cardholder_name ?? '—') ?></span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">شماره شبا</span>
                <span class="bc-info-value">
                    <?php if (!empty($card->sheba)): ?>
                    <span class="bc-sheba">IR<?= e($card->sheba) ?></span>
                    <?php else: ?>
                    <span style="color:var(--text-muted);">—</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="bc-info-row">
                <span class="bc-info-label">تاریخ ثبت</span>
                <span class="bc-info-value"><?= to_jalali($card->created_at ?? '') ?></span>
            </div>
        </div>
    </div>
</div>

<!-- ══ ACTION PANEL (فقط در وضعیت pending) ══ -->
<?php if (($card->status ?? '') === 'pending'): ?>
<div class="bc-action-panel">
    <div class="bc-action-panel-head">
        <span class="material-icons">admin_panel_settings</span>
        <h4>تصمیم‌گیری</h4>
    </div>
    <div class="bc-action-panel-body">
        <button class="bc-btn-action approve" onclick="doVerify()">
            <span class="material-icons">check_circle</span>
            تأیید کارت
        </button>
        <button class="bc-btn-action reject-action" onclick="document.getElementById('rejectOverlay').classList.add('show')">
            <span class="material-icons">cancel</span>
            رد کارت
        </button>
    </div>
</div>
<?php endif; ?>

<!-- ══ مودال رد ══ -->
<div class="bc-modal-overlay" id="rejectOverlay">
    <div class="bc-modal">
        <div class="bc-modal-head">
            <div class="bc-modal-head-title">
                <span class="material-icons">cancel</span>
                رد کارت بانکی
            </div>
            <button class="bc-modal-close" onclick="document.getElementById('rejectOverlay').classList.remove('show')">
                <span class="material-icons">close</span>
            </button>
        </div>
        <form id="rejectForm">
            <?= csrf_field() ?>
            <input type="hidden" name="card_id" value="<?= $cardId ?>">
            <div class="bc-modal-body">
                <div class="bc-form-group">
                    <label>دلیل رد <span class="req">*</span></label>
                    <textarea id="rejectReason" name="rejection_reason" class="bc-textarea"
                              placeholder="دلیل رد را به صورت واضح برای کاربر توضیح دهید..." required></textarea>
                </div>
                <div class="bc-quick-reasons">
                    <button type="button" class="bc-reason-chip" onclick="setR('نام صاحب کارت با نام کاربر مطابقت ندارد')">عدم تطابق نام</button>
                    <button type="button" class="bc-reason-chip" onclick="setR('تصویر کارت واضح نیست')">تصویر نامناسب</button>
                    <button type="button" class="bc-reason-chip" onclick="setR('اطلاعات وارد شده نادرست است')">اطلاعات نادرست</button>
                    <button type="button" class="bc-reason-chip" onclick="setR('شماره کارت با شبا مطابقت ندارد')">عدم تطابق شبا</button>
                </div>
            </div>
            <div class="bc-modal-foot">
                <button type="button" class="bc-btn-lg bc-btn-cancel"
                        onclick="document.getElementById('rejectOverlay').classList.remove('show')">
                    <span class="material-icons">arrow_back</span> انصراف
                </button>
                <button type="submit" class="bc-btn-lg bc-btn-submit-reject">
                    <span class="material-icons">cancel</span> ثبت رد
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Toast -->
<!-- ══ مودال تأیید ══ -->
<div class="bc-modal-overlay" id="confirmOverlay">
    <div class="bc-modal" style="max-width:400px;">
        <div class="bc-modal-head" style="background:linear-gradient(90deg,rgba(16,185,129,.08) 0%,transparent 100%);">
            <div class="bc-modal-head-title">
                <span class="material-icons" style="color:var(--green);">check_circle</span>
                تأیید کارت بانکی
            </div>
            <button class="bc-modal-close" onclick="document.getElementById('confirmOverlay').classList.remove('show')">
                <span class="material-icons">close</span>
            </button>
        </div>
        <div class="bc-modal-body" style="text-align:center;padding:28px 22px;">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--green-dim);border:1px solid rgba(16,185,129,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <span class="material-icons" style="font-size:28px!important;color:var(--green);">credit_card</span>
            </div>
            <p style="font-size:14px;color:var(--text-primary);font-weight:600;margin:0 0 8px;">آیا از تأیید این کارت مطمئنید؟</p>
            <p style="font-size:12px;color:var(--text-muted);margin:0;">پس از تأیید، کاربر می‌تواند از این کارت استفاده کند.</p>
        </div>
        <div class="bc-modal-foot" style="justify-content:center;gap:12px;">
            <button type="button" class="bc-btn-lg bc-btn-cancel"
                    onclick="document.getElementById('confirmOverlay').classList.remove('show')">
                <span class="material-icons">close</span> انصراف
            </button>
            <button type="button" class="bc-btn-lg" id="confirmYesBtn"
                    style="background:var(--green-dim);border-color:rgba(16,185,129,.3);color:var(--green);"
                    onmouseover="this.style.background='var(--green)';this.style.color='#fff';"
                    onmouseout="this.style.background='var(--green-dim)';this.style.color='var(--green)';">
                <span class="material-icons">check_circle</span> بله، تأیید شود
            </button>
        </div>
    </div>
</div>

<div class="bc-toast-wrap" id="bcToastWrap"></div>

<script>
const CSRF    = '<?= csrf_token() ?>';
const BASE    = '<?= url('/admin/bank-cards') ?>';
const CARD_ID = <?= $cardId ?>;

function bcToast(msg, type = 'success') {
    const wrap = document.getElementById('bcToastWrap');
    const t = document.createElement('div');
    t.className = `bc-toast ${type}`;
    t.innerHTML = `
        <div class="bc-toast-icon"><span class="material-icons">${type === 'success' ? 'check_circle' : 'error'}</span></div>
        <div class="bc-toast-msg">${msg}</div>`;
    wrap.appendChild(t);
    setTimeout(() => { t.classList.add('hide'); setTimeout(() => t.remove(), 300); }, 3500);
}

function doVerify() {
    document.getElementById('confirmOverlay').classList.add('show');
}

function doVerifyConfirmed() {
    document.getElementById('confirmOverlay').classList.remove('show');
    const fd = new FormData();
    fd.append('card_id', CARD_ID);
    fd.append('csrf_token', CSRF);
    fetch(`${BASE}/verify`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bcToast(data.message || 'کارت تأیید شد');
                setTimeout(() => location.href = BASE, 1400);
            } else {
                bcToast(data.message || 'خطا در تأیید', 'error');
            }
        })
        .catch(() => bcToast('خطا در ارتباط با سرور', 'error'));
}

function setR(txt) {
    document.getElementById('rejectReason').value = txt;
}

document.getElementById('confirmYesBtn').addEventListener('click', doVerifyConfirmed);
document.getElementById('confirmOverlay').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('show');
});

document.getElementById('rejectOverlay').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('show');
});

document.getElementById('rejectForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = this.querySelector('[type=submit]');
    btn.disabled = true;
    fetch(`${BASE}/reject`, { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('rejectOverlay').classList.remove('show');
                bcToast(data.message || 'کارت رد شد');
                setTimeout(() => location.href = BASE, 1400);
            } else {
                bcToast(data.message || 'خطا', 'error');
            }
        })
        .catch(() => bcToast('خطا در ارتباط با سرور', 'error'))
        .finally(() => btn.disabled = false);
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>