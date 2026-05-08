<?php
$pageTitle = $pageTitle ?? 'کارت‌های بانکی من';
$layout = 'user';
$cards = $cards ?? [];
$cardCount = $cardCount ?? 0;
$maxCards = $maxCards ?? 4;
ob_start();
?>

<style>
.bc-wrap {
    max-width: 960px;
    margin: 0 auto;
    padding: 8px 0 32px;
}

/* ── Header ── */
.bc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
}
.bc-header h1 {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.bc-header h1 i { color: var(--gold); font-size: 1.5rem; }

/* ── Alert ── */
.bc-alert {
    background: var(--info-bg);
    border-right: 4px solid var(--info);
    border-radius: var(--r-sm);
    padding: 12px 16px;
    font-size: .85rem;
    color: var(--text);
    margin-bottom: 24px;
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.bc-alert i { color: var(--info); margin-top: 2px; flex-shrink: 0; }

/* ── Grid ── */
.bc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 24px;
}

/* ── Empty ── */
.bc-empty {
    grid-column: 1/-1;
    text-align: center;
    padding: 64px 24px;
    background: var(--surface);
    border-radius: var(--r);
    border: 2px dashed var(--border);
    color: var(--muted);
}
.bc-empty i { font-size: 3rem; margin-bottom: 12px; display: block; }
.bc-empty h3 { margin: 0 0 6px; color: var(--sub); font-size: 1.05rem; }
.bc-empty p  { margin: 0 0 20px; font-size: .85rem; }

/* ── Card ── */
.bc-card {
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,.12);
    position: relative;
    transition: transform .25s, box-shadow .25s;
}
.bc-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 40px rgba(0,0,0,.16);
}

/* رنگ پس‌زمینه بر اساس وضعیت */
.bc-card.verified  { background: linear-gradient(135deg, #1A1A2E 0%, #16213E 50%, #0F3460 100%); }
.bc-card.pending   { background: linear-gradient(135deg, #2D2D44 0%, #3D3D5C 50%, #4A4A6A 100%); }
.bc-card.rejected  { background: linear-gradient(135deg, #3D1515 0%, #5C1E1E 50%, #6B2020 100%); }

/* نقش‌برجسته پشت کارت */
.bc-card::before {
    content: '';
    position: absolute;
    width: 250px;
    height: 250px;
    border-radius: 50%;
    opacity: .06;
    top: -80px;
    left: -60px;
    background: #fff;
}
.bc-card::after {
    content: '';
    position: absolute;
    width: 180px;
    height: 180px;
    border-radius: 50%;
    opacity: .05;
    bottom: -50px;
    right: -30px;
    background: #fff;
}

/* نوار بالای کارت */
.bc-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 20px 22px 0;
    position: relative;
    z-index: 1;
}

.bc-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: .72rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 20px;
    letter-spacing: .03em;
}
.bc-card.verified  .bc-status-badge { background: rgba(24,185,90,.2);  color: #4ade80; }
.bc-card.pending   .bc-status-badge { background: rgba(249,115,22,.2); color: #fdba74; }
.bc-card.rejected  .bc-status-badge { background: rgba(229,62,62,.25); color: #fca5a5; }
.bc-status-badge i { font-size: .85rem; }

.bc-default-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: .7rem;
    font-weight: 700;
    color: var(--gold);
    background: rgba(245,197,24,.15);
    border: 1px solid rgba(245,197,24,.3);
    padding: 3px 8px;
    border-radius: 20px;
}
.bc-default-badge i { font-size: .8rem; }

/* لوگو بانک */
.bc-bank-logo {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(255,255,255,.15);
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255,255,255,.9);
    font-size: 1.3rem;
    font-weight: 700;
    backdrop-filter: blur(4px);
    flex-shrink: 0;
}

/* چیپ */
.bc-chip {
    width: 38px;
    height: 28px;
    border-radius: 6px;
    background: linear-gradient(135deg, #d4a800 0%, #f5c518 50%, #d4a800 100%);
    margin: 18px 22px 12px;
    position: relative;
    z-index: 1;
    box-shadow: 0 2px 6px rgba(0,0,0,.3);
}
.bc-chip::before {
    content: '';
    position: absolute;
    top: 6px; left: 6px; right: 6px; bottom: 6px;
    border: 1px solid rgba(0,0,0,.2);
    border-radius: 3px;
}
.bc-chip::after {
    content: '';
    position: absolute;
    top: 50%; left: 0; right: 0;
    height: 1px;
    background: rgba(0,0,0,.2);
    transform: translateY(-50%);
}

/* شماره کارت */
.bc-number {
    font-size: 1.25rem;
    font-weight: 600;
    letter-spacing: .18em;
    color: rgba(255,255,255,.95);
    padding: 0 22px 16px;
    position: relative;
    z-index: 1;
    direction: ltr;
    text-align: left;
    font-family: 'Courier New', monospace;
}

/* پایین کارت */
.bc-card-bottom {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    padding: 0 22px 18px;
    position: relative;
    z-index: 1;
}
.bc-holder {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.bc-holder-label {
    font-size: .62rem;
    color: rgba(255,255,255,.45);
    text-transform: uppercase;
    letter-spacing: .08em;
}
.bc-holder-name {
    font-size: .88rem;
    font-weight: 600;
    color: rgba(255,255,255,.9);
}
.bc-bank-name {
    font-size: .78rem;
    color: rgba(255,255,255,.55);
    text-align: left;
}

/* رد شده — علت */
.bc-rejection {
    margin: 0 16px 16px;
    background: rgba(229,62,62,.2);
    border: 1px solid rgba(229,62,62,.35);
    border-radius: 10px;
    padding: 10px 12px;
    display: flex;
    gap: 8px;
    align-items: flex-start;
    position: relative;
    z-index: 1;
}
.bc-rejection i { color: #fca5a5; font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
.bc-rejection p { margin: 0; font-size: .78rem; color: rgba(255,255,255,.8); line-height: 1.5; }
.bc-rejection strong { color: #fca5a5; display: block; margin-bottom: 2px; }

/* دکمه‌ها */
.bc-actions {
    display: flex;
    gap: 8px;
    padding: 12px 16px;
    background: rgba(0,0,0,.2);
    position: relative;
    z-index: 1;
    border-top: 1px solid rgba(255,255,255,.06);
}
.bc-btn {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    font-size: .78rem;
    font-weight: 600;
    padding: 8px 12px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    transition: all .2s;
    font-family: inherit;
}
.bc-btn i { font-size: .9rem; }
.bc-btn-star {
    background: rgba(245,197,24,.15);
    color: var(--gold);
    border: 1px solid rgba(245,197,24,.3);
}
.bc-btn-star:hover { background: rgba(245,197,24,.25); }
.bc-btn-del {
    background: rgba(229,62,62,.15);
    color: #fca5a5;
    border: 1px solid rgba(229,62,62,.3);
}
.bc-btn-del:hover { background: rgba(229,62,62,.25); }

/* تاریخ ثبت */
.bc-date {
    font-size: .7rem;
    color: rgba(255,255,255,.35);
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0 22px 14px;
    position: relative;
    z-index: 1;
}
.bc-date i { font-size: .8rem; }

/* Add card */
.bc-add-card {
    border-radius: 20px;
    border: 2px dashed var(--border);
    background: var(--surface);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 220px;
    color: var(--muted);
    text-decoration: none;
    transition: all .2s;
    cursor: pointer;
}
.bc-add-card:hover {
    border-color: var(--gold);
    color: var(--gold);
    background: var(--gold-pale);
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(245,197,24,.1);
}
.bc-add-card i { font-size: 2.2rem; }
.bc-add-card span { font-size: .88rem; font-weight: 600; }
</style>

<div class="bc-wrap">

    <!-- Header -->
    <div class="bc-header">
        <h1>
            <i class="material-icons">credit_card</i>
            کارت‌های بانکی من
        </h1>
        <small style="color:var(--muted);font-size:.8rem"><?= $cardCount ?> از <?= $maxCards ?> کارت</small>
    </div>

    <!-- Alert -->
    <div class="bc-alert">
        <i class="material-icons">info</i>
        <div>
            کارت‌ها باید به نام خودتان باشند. پس از تأیید مدیریت می‌توانید برای برداشت و واریز استفاده کنید.
        </div>
    </div>

    <!-- Grid -->
    <div class="bc-grid">

        <?php if (empty($cards)): ?>
        <div class="bc-empty">
            <i class="material-icons">credit_card_off</i>
            <h3>کارتی ثبت نشده</h3>
            <p>اولین کارت بانکی خود را اضافه کنید</p>
            <a href="<?= url('/bank-cards/create') ?>" class="btn btn-primary">
                <i class="material-icons">add</i> افزودن کارت
            </a>
        </div>

        <?php else: ?>
        <?php foreach ($cards as $card): ?>

        <?php
        $statusMap = [
            'verified' => ['label' => 'تأیید شده',       'icon' => 'check_circle'],
            'pending'  => ['label' => 'در انتظار تأیید',  'icon' => 'schedule'],
            'rejected' => ['label' => 'رد شده',           'icon' => 'cancel'],
        ];
        $st = $statusMap[$card->status] ?? $statusMap['pending'];
        $num = preg_replace('/\D/', '', $card->card_number);
        $formatted = implode('-', str_split($num, 4));
        $masked = substr($formatted,0,4) . '-****-****-' . substr($num,-4);
        ?>

        <div class="bc-card <?= e($card->status) ?>">

            <!-- بالای کارت: وضعیت + بانک -->
            <div class="bc-card-top">
                <div class="bc-status-badge">
                    <i class="material-icons"><?= $st['icon'] ?></i>
                    <?= $st['label'] ?>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">
                    <?php if ($card->is_default): ?>
                    <div class="bc-default-badge">
                        <i class="material-icons">star</i> پیش‌فرض
                    </div>
                    <?php endif; ?>
                    <div class="bc-bank-logo">
                        <?= mb_substr($card->bank_name, 0, 1) ?>
                    </div>
                </div>
            </div>

            <!-- چیپ -->
            <div class="bc-chip"></div>

            <!-- شماره کارت -->
            <div class="bc-number"><?= $masked ?></div>

            <!-- نام و بانک -->
            <div class="bc-card-bottom">
                <div class="bc-holder">
                    <span class="bc-holder-label">Card Holder</span>
                    <span class="bc-holder-name"><?= e($card->cardholder_name) ?></span>
                </div>
                <div class="bc-bank-name"><?= e($card->bank_name) ?></div>
            </div>

            <!-- تاریخ ثبت -->
            <div class="bc-date">
                <i class="material-icons">access_time</i>
                ثبت: <?= to_jalali($card->created_at, 'Y/m/d') ?>
            </div>

            <!-- دلیل رد -->
            <?php if ($card->status === 'rejected' && !empty($card->rejection_reason)): ?>
            <div class="bc-rejection">
                <i class="material-icons">error_outline</i>
                <p><strong>دلیل رد:</strong><?= e($card->rejection_reason) ?></p>
            </div>
            <?php endif; ?>

            <!-- اکشن‌ها -->
            <?php if (($card->status === 'verified' && !$card->is_default) || $card->status !== 'verified'): ?>
            <div class="bc-actions">
                <?php if ($card->status === 'verified' && !$card->is_default): ?>
                <button class="bc-btn bc-btn-star" onclick="setDefaultCard(<?= (int)$card->id ?>)">
                    <i class="material-icons">star</i> پیش‌فرض
                </button>
                <?php endif; ?>
                <?php if ($card->status !== 'verified'): ?>
                <button class="bc-btn bc-btn-del" onclick="confirmDeleteCard(<?= (int)$card->id ?>)">
                    <i class="material-icons">delete</i> حذف
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- افزودن کارت جدید -->
        <?php if ($cardCount < $maxCards): ?>
        <a href="<?= url('/bank-cards/create') ?>" class="bc-add-card">
            <i class="material-icons">add_card</i>
            <span>افزودن کارت جدید</span>
        </a>
        <?php endif; ?>

    </div>
</div>

<script>
function setDefaultCard(cardId) {
    if (!confirm('آیا می‌خواهید این کارت را به عنوان پیش‌فرض تنظیم کنید؟')) return;
    const fd = new FormData();
    fd.append('card_id', cardId);
    fd.append('<?= csrf_token() ?>', '<?= csrf_token() ?>');
    fetch('<?= url('/bank-cards/set-default') ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { d.success ? notyf.success(d.message) : notyf.error(d.message); if(d.success) setTimeout(()=>location.reload(),1000); })
        .catch(() => notyf.error('خطا در ارتباط با سرور'));
}

function confirmDeleteCard(cardId) {
    Swal.fire({
        title: 'حذف کارت بانکی',
        text: 'آیا از حذف این کارت اطمینان دارید؟',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#E53E3E',
        cancelButtonColor: '#999',
        confirmButtonText: 'بله، حذف شود',
        cancelButtonText: 'انصراف'
    }).then(r => { if (r.isConfirmed) deleteCard(cardId); });
}

function deleteCard(cardId) {
    const fd = new FormData();
    fd.append('card_id', cardId);
    fd.append('<?= csrf_token() ?>', '<?= csrf_token() ?>');
    fetch('<?= url('/bank-cards/delete') ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { d.success ? notyf.success(d.message) : notyf.error(d.message); if(d.success) setTimeout(()=>location.reload(),1000); })
        .catch(() => notyf.error('خطا در ارتباط با سرور'));
}
</script>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>