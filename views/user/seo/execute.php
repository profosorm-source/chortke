<?php
$title = 'اجرای تسک';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-seo.css') ?>">

<div class="execution-container">
    <div class="execution-header">
        <div class="task-info">
            <h5><?= e($ad->title) ?></h5>
            <p><?= e($ad->keyword) ?></p>
        </div>
        <div class="task-timer">
            <i class="material-icons">timer</i>
            <span id="timerDisplay">00:00</span>
        </div>
    </div>

    <div class="execution-stats">
        <div class="stat-item">
            <span>زمان: <strong id="durationText">0s</strong></span>
        </div>
        <div class="stat-item">
            <span>اسکرول: <strong id="scrollText">0%</strong></span>
        </div>
        <div class="stat-item">
            <span>تعامل: <strong id="interactionText">0</strong></span>
        </div>
    </div>

    <div class="webview-container">
        <iframe id="taskFrame" src="<?= e($ad->site_url) ?>"></iframe>
    </div>

    <div class="execution-actions">
        <button id="btnComplete" class="btn btn-success btn-lg" disabled>تکمیل</button>
        <button id="btnCancel" class="btn btn-danger">لغو</button>
    </div>
</div>

<script src="<?= asset('assets/js/seo-tracker.js') ?>"></script>
<script>
const executionId = <?= $execution->id ?>;
const minDuration = <?= $ad->target_duration ?>;
let tracker;

document.addEventListener('DOMContentLoaded', function() {
    tracker = new SeoTracker({frameId: 'taskFrame', minDuration: minDuration, onUpdate: updateUI, onReady: () => document.getElementById('btnComplete').disabled = false});
    tracker.start();
});

function updateUI(data) {
    document.getElementById('durationText').textContent = data.duration + 's';
    document.getElementById('scrollText').textContent = Math.round(data.scrollDepth) + '%';
    document.getElementById('interactionText').textContent = data.interactions;
    const mins = Math.floor(data.duration / 60), secs = data.duration % 60;
    document.getElementById('timerDisplay').textContent = `${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
}

document.getElementById('btnComplete').addEventListener('click', function() {
    const data = tracker.getData();
    if (data.duration < minDuration) {notyf.error(`حداقل ${minDuration} ثانیه نیاز است`); return;}
    const btn = this;
    btn.disabled = true;
    fetch('<?= url('/seo') ?>/' + executionId + '/complete', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>'},
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            Swal.fire({icon: 'success', title: 'تکمیل شد!', html: `امتیاز: ${Math.round(d.score)}<br>پاداش: ${d.payout.toLocaleString()} تومان`}).then(() => window.location.href = '<?= url('/seo') ?>');
        } else {
            Swal.fire({icon: 'error', text: d.message});
            btn.disabled = false;
        }
    });
});

document.getElementById('btnCancel').addEventListener('click', () => window.location.href = '<?= url('/seo') ?>');
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>
