<?php $title = 'قرعه‌کشی'; $layout = 'user'; ob_start(); ?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-lottery.css') ?>">


<div class="content-header">
    <h4><i class="material-icons">casino</i> قرعه‌کشی روزانه</h4>
</div>

<?php if ($activeRound): ?>
<!-- دوره فعال -->
<div class="card lottery-hero">
    <div class="card-body text-center">
        <h3 style="color:#4fc3f7;"><?= e($activeRound->title) ?></h3>
        <p style="color:#999;">
            <?= e(to_jalali($activeRound->start_date ?? '')) ?> تا <?= e(to_jalali($activeRound->end_date ?? '')) ?>
        </p>
        <div class="prize-badge">
            <i class="material-icons">emoji_events</i>
            <span>جایزه: <strong><?= number_format($activeRound->prize_amount) ?></strong>
                <?= $activeRound->currency === 'usdt' ? 'تتر' : 'تومان' ?></span>
        </div>

        <?php if (!$participation): ?>
        <!-- دکمه شرکت -->
        <div class="mt-4">
            <?php if ($activeRound->entry_fee > 0): ?>
            <p style="color:#ff9800;">هزینه ورود: <?= number_format($activeRound->entry_fee) ?>
                <?= $activeRound->currency === 'usdt' ? 'تتر' : 'تومان' ?></p>
            <?php else: ?>
            <p style="color:#4caf50;">رایگان!</p>
            <?php endif; ?>
            <button class="btn btn-primary btn-lg" onclick="joinLottery(<?= e($activeRound->id) ?>)">
                <i class="material-icons">add_circle</i> شرکت در قرعه‌کشی
            </button>
        </div>
        <?php else: ?>
        <!-- اطلاعات شرکت‌کننده -->
        <div class="mt-4">
            <div class="code-display">
                <span class="code-label">کد اختصاصی شما:</span>
                <div class="code-digits">
                    <?php foreach (\str_split($participation->code) as $digit): ?>
                    <span class="digit"><?= e($digit) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($participation && $todayNumbers): ?>
<!-- رأی‌گیری امروز -->
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">how_to_vote</i> رأی‌گیری امروز</h5>
        <?php if ($todayNumbers->selected_number !== null): ?>
        <span class="badge badge-success">عدد منتخب: <?= e($todayNumbers->selected_number) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body text-center">
        <p>یکی از اعداد زیر را انتخاب کنید:</p>
        <div class="vote-numbers">
            <?php
            $nums = [$todayNumbers->number_1, $todayNumbers->number_2, $todayNumbers->number_3];
            foreach ($nums as $n):
                $isVoted = $userVote && (int)$userVote->voted_number === $n;
            ?>
            <button class="vote-btn <?= $isVoted ? 'voted' : '' ?>"
                    <?= $userVote ? 'disabled' : '' ?>
                    onclick="castVote(<?= e($activeRound->id) ?>, <?= e($n) ?>)">
                <?= e($n) ?>
                <?php if ($isVoted): ?><i class="material-icons">check</i><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php if ($userVote): ?>
        <p class="mt-3 text-success"><i class="material-icons">check_circle</i> رأی شما ثبت شد.</p>
        <?php else: ?>
        <p class="mt-3 text-muted">هر کاربر فقط یک رأی در روز دارد.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- آمار عمومی -->
<?php if ($distribution): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">bar_chart</i> وضعیت کلی شرکت‌کنندگان</h5>
    </div>
    <div class="card-body">
        <div class="stats-grid">
            <div class="stat-card stat-green">
                <div class="stat-info">
                    <span class="stat-label">شانس زیاد</span>
                    <span class="stat-value"><?= e($distribution['high']) ?> نفر</span>
                </div>
            </div>
            <div class="stat-card stat-orange">
                <div class="stat-info">
                    <span class="stat-label">شانس متوسط</span>
                    <span class="stat-value"><?= e($distribution['medium']) ?> نفر</span>
                </div>
            </div>
            <div class="stat-card stat-red">
                <div class="stat-info">
                    <span class="stat-label">شانس کم</span>
                    <span class="stat-value"><?= e($distribution['low']) ?> نفر</span>
                </div>
            </div>
            <div class="stat-card stat-blue">
                <div class="stat-info">
                    <span class="stat-label">کل شرکت‌کنندگان</span>
                    <span class="stat-value"><?= e($distribution['total']) ?> نفر</span>
                </div>
            </div>
        </div>
        <p class="text-muted mt-2" style="font-size:12px;">
            <i class="material-icons" style="font-size:14px;">info</i>
            رتبه فردی و مقدار شانس واقعی نمایش داده نمی‌شود.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- تاریخچه اعداد روزانه -->
<?php if (!empty($dailyHistory)): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">history</i> تاریخچه اعداد</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>تاریخ</th><th>اعداد روز</th><th>عدد منتخب</th><th>Seed Hash</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($dailyHistory as $d): ?>
                    <tr>
                        <td><?= e(to_jalali($d->date)) ?></td>
                        <td><?= e($d->number_1) ?> - <?= e($d->number_2) ?> - <?= e($d->number_3) ?></td>
                        <td>
                            <?php if ($d->selected_number !== null): ?>
                            <span class="badge badge-primary"><?= e($d->selected_number) ?></span>
                            <?php else: ?>
                            <span class="text-muted">منتظر...</span>
                            <?php endif; ?>
                        </td>
                        <td dir="ltr" style="font-size:10px; max-width:120px; overflow:hidden; text-overflow:ellipsis;">
                            <?= e($d->seed_hash ?? '-') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- بدون دوره فعال -->
<div class="card">
    <div class="card-body text-center" style="padding:50px;">
        <i class="material-icons" style="font-size:60px; color:#ccc;">casino</i>
        <h5 class="mt-3">در حال حاضر قرعه‌کشی فعالی وجود ندارد</h5>
        <p style="color:#999;">منتظر اعلام دوره جدید باشید!</p>
    </div>
</div>
<?php endif; ?>

<!-- تاریخچه برندگان -->
<?php if (!empty($completedRounds)): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">emoji_events</i> برندگان قبلی</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>عنوان</th><th>جایزه</th><th>برنده</th><th>تاریخ</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($completedRounds as $cr): ?>
                    <tr>
                        <td><?= e($cr->title) ?></td>
                        <td><?= number_format($cr->prize_amount) ?> <?= $cr->currency === 'usdt' ? 'تتر' : 'تومان' ?></td>
                        <td><?= e($cr->winner_name ?? 'نامشخص') ?></td>
                        <td><?= e(to_jalali($cr->end_date ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- شفافیت سیستم -->
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">verified_user</i> شفافیت سیستم قرعه‌کشی</h5>
    </div>
    <div class="card-body">
        <div style="background:#f8f9fa; padding:20px; border-radius:8px; font-size:13px; line-height:2.2; color:#555;">
            <?= \nl2br(e($transparencyText)) ?>
        </div>
    </div>
</div>

<script>
function joinLottery(roundId) {
    Swal.fire({
        title: 'شرکت در قرعه‌کشی',
        text: 'آیا مطمئن هستید؟',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'بله، شرکت می‌کنم',
        cancelButtonText: 'انصراف'
    }).then(result => {
        if (result.isConfirmed) {
            fetch('<?= url('/lottery/join') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
                body: JSON.stringify({ round_id: roundId })
            }).then(r => r.json()).then(res => {
                if (res.success) {
                    Swal.fire('ثبت شد!', res.message + (res.code ? '\nکد شما: ' + res.code : ''), 'success')
                        .then(() => location.reload());
                } else {
                    notyf.error(res.message);
                }
            });
        }
    });
}

function castVote(roundId, number) {
    fetch('<?= url('/lottery/vote') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
        body: JSON.stringify({ round_id: roundId, voted_number: number })
    }).then(r => r.json()).then(res => {
        if (res.success) {
            notyf.success(res.message);
            setTimeout(() => location.reload(), 1000);
        } else {
            notyf.error(res.message);
        }
    });
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>