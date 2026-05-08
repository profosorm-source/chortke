<?php
// views/admin/email-queue/index.php
/** @var array $emails لیست ایمیل‌ها */
/** @var array $stats آمار */
/** @var int $total */
/** @var int $page */
/** @var int $totalPages */
$title = 'صف ایمیل';
// مقادیر filter — از Controller inject می‌شوند؛ fallback امن از superglobal
$filterStatus = $filterStatus ?? e($_GET['status'] ?? '', ENT_QUOTES, 'UTF-8');
$filterSearch = $filterSearch ?? e($_GET['search'] ?? '', ENT_QUOTES, 'UTF-8');
include BASE_PATH . '/views/layouts/admin.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">📧 مدیریت صف ایمیل</h4>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" onclick="processQueue()">
                <span class="material-icons align-middle" style="font-size:18px">send</span>
                پردازش صف
            </button>
            <button class="btn btn-outline-danger btn-sm" onclick="retryFailed()">
                <span class="material-icons align-middle" style="font-size:18px">replay</span>
                تلاش مجدد ناموفق‌ها
            </button>
        </div>
    </div>

    <!-- آمار -->
    <div class="row g-3 mb-4">
        <?php
        $statCards = [
            ['pending', 'در انتظار',    'hourglass_empty', 'warning'],
            ['sending', 'در حال ارسال', 'send',             'info'],
            ['sent',    'ارسال شده',    'check_circle',     'success'],
            ['failed',  'ناموفق',       'error',            'danger'],
        ];
        foreach ($statCards as [$key, $label, $icon, $color]):
            $val = $stats[$key] ?? 0;
        ?>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-3">
                    <span class="material-icons text-<?= e($color) ?> fs-3"><?= e($icon) ?></span>
                    <h4 class="mb-0 mt-1"><?= number_format($val) ?></h4>
                    <small class="text-muted"><?= e($label) ?></small>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- فیلتر -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
                <select name="status" class="form-select form-select-sm" style="width:auto">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="pending"  <?= $filterStatus==='pending'  ? 'selected':'' ?>>در انتظار</option>
                    <option value="sending"  <?= $filterStatus==='sending'  ? 'selected':'' ?>>در حال ارسال</option>
                    <option value="sent"     <?= $filterStatus==='sent'     ? 'selected':'' ?>>ارسال شده</option>
                    <option value="failed"   <?= $filterStatus==='failed'   ? 'selected':'' ?>>ناموفق</option>
                </select>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="جستجو ایمیل یا موضوع..." value="<?= e($filterSearch) ?>"
                       style="width:220px">
                <button class="btn btn-secondary btn-sm" type="submit">فیلتر</button>
                <a href="<?= url('/admin/email-queue') ?>" class="btn btn-outline-secondary btn-sm">پاک‌سازی</a>
            </form>
        </div>
    </div>

    <!-- جدول -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>گیرنده</th>
                            <th>موضوع</th>
                            <th>اولویت</th>
                            <th>تلاش</th>
                            <th>وضعیت</th>
                            <th>ارسال</th>
                            <th>خطا</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($emails)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">موردی یافت نشد</td></tr>
                        <?php else: ?>
                        <?php foreach ($emails as $email): $email = (object)$email; ?>
                        <tr>
                            <td class="text-muted"><?= (int)$email->id ?></td>
                            <td>
                                <div><?= e($email->to_name ?? '') ?></div>
                                <div class="text-muted"><?= e($email->to_email) ?></div>
                            </td>
                            <td class="text-truncate" style="max-width:200px"><?= e($email->subject) ?></td>
                            <td>
                                <?php $pc = ['urgent'=>'danger','high'=>'warning','normal'=>'secondary','low'=>'light'];
                                      $pl = ['urgent'=>'فوری','high'=>'بالا','normal'=>'معمولی','low'=>'پایین']; ?>
                                <span class="badge bg-<?= $pc[$email->priority??'normal'] ?>">
                                    <?= $pl[$email->priority??'normal'] ?>
                                </span>
                            </td>
                            <td><?= (int)$email->attempts ?>/3</td>
                            <td>
                                <?php $sc = ['pending'=>'warning','sending'=>'info','sent'=>'success','failed'=>'danger'];
                                      $sl = ['pending'=>'در انتظار','sending'=>'ارسال','sent'=>'ارسال شد','failed'=>'ناموفق']; ?>
                                <span class="badge bg-<?= $sc[$email->status] ?? 'secondary' ?>">
                                    <?= $sl[$email->status] ?? $email->status ?>
                                </span>
                            </td>
                            <td class="text-muted"><?= $email->sent_at ? to_jalali($email->sent_at) : '-' ?></td>
                            <td class="text-danger small text-truncate" style="max-width:150px"
                                title="<?= e($email->error_message ?? '') ?>">
                                <?= e(mb_substr($email->error_message ?? '', 0, 40)) ?>
                            </td>
                            <td>
                                <?php if (in_array($email->status, ['pending','failed'])): ?>
                                <button class="btn btn-xs btn-outline-primary"
                                        onclick="retrySingle(<?= (int)$email->id ?>)"
                                        title="تلاش مجدد">
                                    <span class="material-icons" style="font-size:14px">replay</span>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if (($totalPages ?? 1) > 1): ?>
        <div class="card-footer bg-white">
            <?php include BASE_PATH . '/views/partials/pagination.php'; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<script>
const csrfToken = '<?= csrf_token() ?>';

function processQueue() {
    fetch('<?= url('/admin/email-queue/process') ?>', {
        method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken}
    }).then(r => r.json()).then(d => {
        notyf.success(`ارسال شد: ${d.sent ?? 0} | ناموفق: ${d.failed ?? 0}`);
        setTimeout(() => location.reload(), 1500);
    });
}

function retryFailed() {
    fetch('<?= url('/admin/email-queue/retry-failed') ?>', {
        method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken}
    }).then(r => r.json()).then(d => {
        notyf.success(`${d.count ?? 0} ایمیل برای تلاش مجدد آماده شد`);
        setTimeout(() => location.reload(), 1500);
    });
}

function retrySingle(id) {
    fetch(`<?= url('/admin/email-queue') ?>/${id}/retry`, {
        method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken}
    }).then(r => r.json()).then(d => {
        if (d.success) notyf.success('آماده تلاش مجدد');
        else notyf.error(d.message);
        setTimeout(() => location.reload(), 1000);
    });
}
</script>
