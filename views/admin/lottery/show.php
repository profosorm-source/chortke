<?php $title = 'جزئیات قرعه‌کشی #' . $round->id; $layout = 'admin'; ob_start(); ?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-lottery.css') ?>">


<div class="content-header">
    <h4><i class="material-icons">casino</i> <?= e($round->title) ?></h4>
    <a href="<?= url('/admin/lottery') ?>" class="btn btn-outline-secondary btn-sm"><i class="material-icons">arrow_back</i> بازگشت</a>
</div>

<?php
$sl = [
    'active' => ['فعال', 'badge-success'],
    'voting' => ['رأی‌گیری', 'badge-info'],
    'completed' => ['تکمیل شده', 'badge-primary'],
    'cancelled' => ['لغو شده', 'badge-danger'],
][$round->status] ?? ['؟', 'badge-secondary'];
?>

<div class="card">
    <div class="card-header">
        <h5>اطلاعات دوره</h5>
        <span class="badge <?= e($sl[1]) ?>" style="font-size:13px;"><?= e($sl[0]) ?></span>
    </div>
    <div class="card-body">
        <div class="detail-grid">
            <div class="detail-item"><span class="detail-label">جایزه</span><span class="detail-value"><?= number_format($round->prize_amount) ?> <?= $round->currency === 'usdt' ? 'تتر' : 'تومان' ?></span></div>
            <div class="detail-item"><span class="detail-label">هزینه ورود</span><span class="detail-value"><?= number_format($round->entry_fee) ?></span></div>
            <div class="detail-item"><span class="detail-label">شرکت‌کنندگان</span><span class="detail-value"><?= e($participantCount) ?></span></div>
            <div class="detail-item"><span class="detail-label">شروع</span><span class="detail-value"><?= e(to_jalali($round->start_date ?? '')) ?></span></div>
            <div class="detail-item"><span class="detail-label">پایان</span><span class="detail-value"><?= e(to_jalali($round->end_date ?? '')) ?></span></div>
            <?php if ($round->winner_name): ?>
            <div class="detail-item" style="background:#e8f5e9;"><span class="detail-label">🏆 برنده</span><span class="detail-value"><?= e($round->winner_name) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-footer" style="display:flex; gap:8px; flex-wrap:wrap;">
        <?php if (\in_array($round->status, ['active', 'voting'])): ?>
        <button class="btn btn-info btn-sm" onclick="generateNumbers(<?= e($round->id) ?>)">
            <i class="material-icons">auto_awesome</i> تولید اعداد امروز
        </button>
        <button class="btn btn-success btn-sm" onclick="selectWinner(<?= e($round->id) ?>)">
            <i class="material-icons">emoji_events</i> انتخاب برنده
        </button>
        <button class="btn btn-danger btn-sm" onclick="cancelRound(<?= e($round->id) ?>)">
            <i class="material-icons">cancel</i> لغو دوره
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- توزیع شانس -->
<div class="card mt-4">
    <div class="card-header"><h5>توزیع شانس</h5></div>
    <div class="card-body">
        <div class="stats-grid">
            <div class="stat-card stat-green"><div class="stat-info"><span class="stat-label">شانس زیاد (≥80)</span><span class="stat-value"><?= e($distribution['high']) ?></span></div></div>
            <div class="stat-card stat-orange"><div class="stat-info"><span class="stat-label">شانس متوسط (40-79)</span><span class="stat-value"><?= e($distribution['medium']) ?></span></div></div>
            <div class="stat-card stat-red"><div class="stat-info"><span class="stat-label">شانس کم (<40)</span><span class="stat-value"><?= e($distribution['low']) ?></span></div></div>
        </div>
    </div>
</div>

<!-- اعداد روزانه -->
<div class="card mt-4">
    <div class="card-header"><h5>اعداد روزانه</h5></div>
    <div class="card-body">
        <?php if (empty($dailyNumbers)): ?>
        <p class="text-muted text-center">هنوز اعدادی تولید نشده.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>تاریخ</th><th>اعداد</th><th>منتخب</th><th>نوع بررسی</th><th>نهایی</th><th>عملیات</th></tr></thead>
                <tbody>
                    <?php foreach ($dailyNumbers as $d): ?>
                    <tr>
                        <td><?= e(to_jalali($d->date)) ?></td>
                        <td><?= e($d->number_1) ?> - <?= e($d->number_2) ?> - <?= e($d->number_3) ?></td>
                        <td><?= $d->selected_number !== null ? "<span class='badge badge-primary'>{$d->selected_number}</span>" : '-' ?></td>
                        <td><span class="badge badge-secondary"><?= e($d->match_type ?? '-') ?></span></td>
                        <td><?= $d->is_finalized ? '✅' : '⏳' ?></td>
                        <td>
                            <?php if (!$d->is_finalized): ?>
                            <button class="btn btn-xs btn-warning" onclick="finalizeDaily(<?= e($d->id) ?>)">نهایی‌سازی</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- شرکت‌کنندگان -->
<div class="card mt-4">
    <div class="card-header"><h5>شرکت‌کنندگان (<?= e($participantCount) ?>)</h5></div>
    <div class="card-body">
        <?php if (empty($participants)): ?>
        <p class="text-muted text-center">شرکت‌کننده‌ای نیست.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>کاربر</th><th>کد</th><th>امتیاز شانس</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
                <tbody>
                    <?php foreach ($participants as $p): ?>
                    <tr class="<?= $p->status === 'winner' ? 'table-success' : '' ?>">
                        <td><?= e($p->user_name ?? '') ?></td>
                        <td dir="ltr" style="font-family:monospace; letter-spacing:2px;"><?= e($p->code) ?></td>
                        <td><strong><?= number_format($p->chance_score, 2) ?></strong></td>
                        <td>
                            <?php if ($p->status === 'winner'): ?>
                            <span class="badge badge-success">🏆 برنده</span>
                            <?php else: ?>
                            <span class="badge badge-secondary"><?= e($p->status) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(to_jalali($p->created_at ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function generateNumbers(id) {
    confirmAction('تولید اعداد امروز', 'آیا مطمئنید؟', () => {
        ajaxPost(`<?= url('/admin/lottery/') ?>${id}/generate-numbers`);
    });
}
function finalizeDaily(dailyId) {
    confirmAction('نهایی‌سازی', 'عدد منتخب انتخاب و وزن‌ها اعمال می‌شود.', () => {
        ajaxPost(`<?= url('/admin/lottery/daily/') ?>${dailyId}/finalize`);
    });
}
function selectWinner(id) {
    confirmAction('انتخاب برنده', '⚠️ این عملیات برگشت‌ناپذیر است!', () => {
        ajaxPost(`<?= url('/admin/lottery/') ?>${id}/select-winner`);
    });
}
function cancelRound(id) {
    confirmAction('لغو دوره', 'آیا مطمئنید؟', () => {
        ajaxPost(`<?= url('/admin/lottery/') ?>${id}/cancel`);
    });
}
function confirmAction(title, text, cb) {
    Swal.fire({ title, text, icon: 'question', showCancelButton: true, confirmButtonText: 'بله', cancelButtonText: 'انصراف' })
        .then(r => { if (r.isConfirmed) cb(); });
}
function ajaxPost(url) {
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' }
    }).then(r => r.json()).then(res => {
        res.success ? notyf.success(res.message) : notyf.error(res.message);
        if (res.success) setTimeout(() => location.reload(), 1200);
    });
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>