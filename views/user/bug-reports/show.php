<?php
$title = 'جزئیات گزارش #' . $report->id; $layout = 'user'; ob_start();
$statusLabels = [
    'open' => ['باز', 'bg-primary'], 'in_progress' => ['در حال بررسی', 'bg-info'],
    'resolved' => ['حل شده', 'bg-success'], 'closed' => ['بسته شده', 'bg-secondary'],
    'duplicate' => ['تکراری', 'bg-warning'], 'wont_fix' => ['رد شده', 'bg-danger'],
];
$priorityLabels = [
    'low' => ['کم', 'bg-secondary'], 'normal' => ['متوسط', 'bg-info'],
    'high' => ['بالا', 'bg-warning'], 'critical' => ['بحرانی', 'bg-danger'],
];
$categoryLabels = [
    'ui_issue' => 'ظاهری', 'functional' => 'عملکردی', 'payment' => 'پرداخت',
    'security' => 'امنیتی', 'performance' => 'سرعت', 'content' => 'محتوا', 'other' => 'سایر',
];
$st = $statusLabels[$report->status] ?? ['?', 'bg-secondary'];
$pri = $priorityLabels[$report->priority] ?? ['?', 'bg-secondary'];
?>

<div class="container-fluid">
    <a href="<?= url('/bug-reports') ?>" class="btn btn-sm btn-outline-secondary mb-3">
        <span class="material-icons" style="font-size:14px;vertical-align:middle;">arrow_forward</span> بازگشت
    </a>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">گزارش #<?= e($report->id) ?></h6>
            <div>
                <span class="badge <?= e($st[1]) ?>"><?= e($st[0]) ?></span>
                <span class="badge <?= e($pri[1]) ?> ms-1"><?= e($pri[0]) ?></span>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-4"><strong>دسته‌بندی:</strong> <?= e($categoryLabels[$report->category] ?? $report->category) ?></div>
                <div class="col-md-4"><strong>تاریخ:</strong> <?= e(to_jalali($report->created_at ?? '')) ?></div>
                <div class="col-md-4"><strong>صفحه:</strong> <small dir="ltr"><?= e(\mb_strimwidth($report->page_url ?? '', 0, 50, '...')) ?></small></div>
            </div>

            <div class="mb-3">
                <strong>توضیحات:</strong>
                <div class="p-3 bg-light rounded mt-1" style="white-space:pre-wrap;"><?= e($report->description) ?></div>
            </div>

            <?php if ($report->screenshot_path): ?>
                <div class="mb-3">
                    <strong>اسکرین‌شات:</strong><br>
                    <img src="<?= asset($report->screenshot_path) ?>" alt="screenshot" class="mt-1" style="max-width:400px;border-radius:8px;border:1px solid #eee;">
                </div>
            <?php endif; ?>

            <?php if ($report->admin_note): ?>
                <div class="alert alert-info mt-3">
                    <strong>یادداشت مدیریت:</strong><br>
                    <?= e($report->admin_note) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- پیام‌ها -->
    <div class="card">
        <div class="card-header"><h6 class="mb-0">پیام‌ها</h6></div>
        <div class="card-body">
            <?php if (empty($comments)): ?>
                <p class="text-muted text-center">هنوز پیامی ثبت نشده</p>
            <?php else: ?>
                <?php foreach ($comments as $c): ?>
                    <div class="d-flex mb-3 <?= $c->user_type === 'admin' ? 'flex-row-reverse' : '' ?>">
                        <div class="p-3 rounded" style="max-width:80%;background:<?= $c->user_type === 'admin' ? '#e3f2fd' : '#f5f5f5' ?>;">
                            <div class="d-flex justify-content-between mb-1">
                                <strong style="font-size:12px;"><?= e($c->user_full_name ?? 'کاربر') ?></strong>
                                <small class="text-muted" style="font-size:11px;"><?= e(to_jalali($c->created_at ?? '')) ?></small>
                            </div>
                            <div style="font-size:13px;white-space:pre-wrap;"><?= e($c->comment) ?></div>
                            <?php if ($c->attachment_path): ?>
                                <img src="<?= asset($c->attachment_path) ?>" alt="پیوست" class="mt-2" style="max-width:200px;border-radius:6px;">
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (\in_array($report->status, ['open', 'in_progress'])): ?>
                <hr>
                <div class="d-flex gap-2">
                    <input type="text" id="userComment" class="form-control form-control-sm" placeholder="پیام خود را بنویسید...">
                    <button class="btn btn-sm btn-primary" id="sendComment" style="min-width:100px;">ارسال</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var sendBtn = document.getElementById('sendComment');
    if (!sendBtn) return;

    sendBtn.addEventListener('click', function() {
        var input = document.getElementById('userComment');
        var comment = input.value.trim();
        if (!comment) return;

        sendBtn.disabled = true;

        fetch('<?= url("/bug-reports/{$report->id}/comment") ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
            body: JSON.stringify({ comment: comment })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            sendBtn.disabled = false;
            if (data.success) {
                if (typeof notyf !== 'undefined') notyf.success('پیام ارسال شد');
                location.reload();
            } else {
                if (typeof notyf !== 'undefined') notyf.error(data.message || 'خطا');
            }
        })
        .catch(function() { sendBtn.disabled = false; });
    });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>