<?php
/**
 * Admin - نمایش جزئیات محتوا
 * 
 * @var object $submission
 * @var array $revenues
 * @var object|null $agreement
 */

$title = 'جزئیات محتوا #' . $submission->id;
$layout = 'admin';
ob_start();

// Helper function
function safe_escape($value) {
    return e((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<div class="content-header">
    <h4><i class="material-icons">movie</i> <?= safe_escape($submission->title) ?></h4>
    <div>
        <a href="<?= url('/admin/content') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons">arrow_back</i> بازگشت
        </a>
        <?php if ($submission->status === 'published'): ?>
        <a href="<?= url('/admin/content/' . $submission->id . '/revenue/create') ?>" class="btn btn-primary btn-sm">
            <i class="material-icons">add</i> ثبت درآمد
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
$statusLabels = [
    'pending' => ['در انتظار بررسی', 'badge-warning', 'hourglass_empty'],
    'under_review' => ['در حال بررسی', 'badge-info', 'rate_review'],
    'approved' => ['تأیید شده', 'badge-success', 'check_circle'],
    'published' => ['منتشر شده', 'badge-primary', 'public'],
    'rejected' => ['رد شده', 'badge-danger', 'cancel'],
    'suspended' => ['تعلیق شده', 'badge-dark', 'block'],
];
$sl = $statusLabels[$submission->status] ?? ['نامشخص', 'badge-secondary', 'help'];
?>

<!-- اطلاعات محتوا -->
<div class="card">
    <div class="card-header">
        <h5>اطلاعات محتوا</h5>
        <span class="badge <?= safe_escape($sl[1]) ?>" style="font-size: 13px;">
            <i class="material-icons" style="font-size:14px; vertical-align:middle;"><?= safe_escape($sl[2]) ?></i>
            <?= safe_escape($sl[0]) ?>
        </span>
    </div>
    <div class="card-body">
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">شناسه</span>
                <span class="detail-value">#<?= safe_escape($submission->id) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">کاربر</span>
                <span class="detail-value">
                    <a href="<?= url('/admin/users/' . ($submission->user_id ?? 0) . '/edit') ?>">
                        <?= safe_escape($submission->user_name ?? 'نامشخص') ?>
                    </a>
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">پلتفرم</span>
                <span class="detail-value"><?= $submission->platform === 'aparat' ? 'آپارات' : 'یوتیوب' ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">لینک ویدیو</span>
                <span class="detail-value">
                    <a href="<?= safe_escape($submission->video_url) ?>" target="_blank" 
                       rel="noopener noreferrer" dir="ltr" style="word-break:break-all;">
                        <?= safe_escape($submission->video_url) ?>
                    </a>
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">دسته‌بندی</span>
                <span class="detail-value"><?= safe_escape($submission->category ?? '-') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">تاریخ ارسال</span>
                <span class="detail-value"><?= safe_escape(to_jalali($submission->created_at ?? '')) ?></span>
            </div>
            <?php if ($submission->approved_at): ?>
            <div class="detail-item">
                <span class="detail-label">تاریخ تأیید</span>
                <span class="detail-value"><?= safe_escape(to_jalali($submission->approved_at)) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($submission->published_at ?? false): ?>
            <div class="detail-item">
                <span class="detail-label">تاریخ انتشار</span>
                <span class="detail-value"><?= safe_escape(to_jalali($submission->published_at)) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">کانال</span>
                <span class="detail-value"><?= safe_escape($submission->channel_name ?? '-') ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($submission->description)): ?>
        <div class="mt-3">
            <strong>توضیحات:</strong>
            <p style="color:#666; margin-top:5px;"><?= nl2br(safe_escape($submission->description)) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($submission->rejection_reason)): ?>
        <div class="alert alert-danger mt-3">
            <strong>دلیل رد/تعلیق:</strong>
            <p style="margin:5px 0 0;"><?= nl2br(safe_escape($submission->rejection_reason)) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- دکمه‌های عملیات -->
    <div class="card-footer" style="display:flex; gap:8px; flex-wrap:wrap;">
        <?php if (in_array($submission->status, ['pending', 'under_review'], true)): ?>
            <button class="btn btn-success btn-sm" onclick="approveContent(<?= safe_escape($submission->id) ?>)">
                <i class="material-icons">check</i> تأیید
            </button>
            <button class="btn btn-danger btn-sm" onclick="rejectContent(<?= safe_escape($submission->id) ?>)">
                <i class="material-icons">close</i> رد
            </button>
        <?php endif; ?>
        <?php if ($submission->status === 'approved'): ?>
            <button class="btn btn-info btn-sm" onclick="publishContent(<?= safe_escape($submission->id) ?>)">
                <i class="material-icons">public</i> ثبت انتشار
            </button>
        <?php endif; ?>
        <?php if (in_array($submission->status, ['approved', 'published'], true)): ?>
            <button class="btn btn-dark btn-sm" onclick="suspendContent(<?= safe_escape($submission->id) ?>)">
                <i class="material-icons">block</i> تعلیق
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- تعهدنامه -->
<?php if ($agreement): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">gavel</i> تعهدنامه</h5>
    </div>
    <div class="card-body">
        <div style="background:#f8f9fa; padding:15px; border-radius:6px; font-size:13px; line-height:2;">
            <?= nl2br(safe_escape($agreement->agreement_text)) ?>
        </div>
        <div class="mt-2" style="font-size:12px; color:#999;">
            <span>IP: <?= safe_escape($agreement->ip_address ?? '-') ?></span> |
            <span>تاریخ: <?= safe_escape(to_jalali($agreement->accepted_at ?? '')) ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- درآمدها -->
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">monetization_on</i> تاریخچه درآمد</h5>
        <?php if ($submission->status === 'published'): ?>
        <a href="<?= url('/admin/content/' . $submission->id . '/revenue/create') ?>" class="btn btn-primary btn-sm">
            <i class="material-icons">add</i> ثبت درآمد جدید
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($revenues)): ?>
            <p class="text-muted text-center">هنوز درآمدی ثبت نشده.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>دوره</th>
                            <th>بازدید</th>
                            <th>درآمد کل</th>
                            <th>سهم سایت</th>
                            <th>سهم کاربر</th>
                            <th>مالیات</th>
                            <th>خالص</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revenues as $rev): ?>
                        <tr>
                            <td><?= safe_escape($rev->period) ?></td>
                            <td><?= number_format($rev->views ?? 0) ?></td>
                            <td><?= number_format($rev->total_revenue ?? 0) ?></td>
                            <td><?= number_format($rev->site_share_amount ?? 0) ?> (<?= safe_escape($rev->site_share_percent ?? 0) ?>%)</td>
                            <td><?= number_format($rev->user_share_amount ?? 0) ?> (<?= safe_escape($rev->user_share_percent ?? 0) ?>%)</td>
                            <td><?= number_format($rev->tax_amount ?? 0) ?></td>
                            <td><strong><?= number_format($rev->net_user_amount ?? 0) ?></strong></td>
                            <td>
                                <?php
                                $rsl = [
                                    'pending' => ['در انتظار', 'badge-warning'],
                                    'approved' => ['تأیید', 'badge-info'],
                                    'paid' => ['پرداخت شده', 'badge-success'],
                                    'cancelled' => ['لغو', 'badge-danger'],
                                ][$rev->status] ?? ['؟', 'badge-secondary'];
                                ?>
                                <span class="badge <?= safe_escape($rsl[1]) ?>"><?= safe_escape($rsl[0]) ?></span>
                            </td>
                            <td>
                                <?php if ($rev->status === 'pending'): ?>
                                    <button class="btn btn-xs btn-success" onclick="approveRevenue(<?= safe_escape($rev->id) ?>)">تأیید</button>
                                <?php elseif ($rev->status === 'approved'): ?>
                                    <button class="btn btn-xs btn-primary" onclick="payRevenue(<?= safe_escape($rev->id) ?>)">پرداخت</button>
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

<script>
const csrfToken = '<?= csrf_token() ?>';

function approveContent(id) {
    Swal.fire({
        title: 'تأیید محتوا',
        text: 'آیا از تأیید این محتوا مطمئن هستید؟',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'بله، تأیید کن',
        cancelButtonText: 'انصراف'
    }).then(result => {
        if (result.isConfirmed) {
            fetch(`<?= url('/admin/content/') ?>${id}/approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    notyf.success(res.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(res.message);
                }
            })
            .catch(() => notyf.error('خطا در ارتباط با سرور'));
        }
    });
}

function rejectContent(id) {
    Swal.fire({
        title: 'رد محتوا',
        input: 'textarea',
        inputLabel: 'دلیل رد',
        inputPlaceholder: 'دلیل رد محتوا را وارد کنید (حداقل ۱۰ کاراکتر)...',
        inputAttributes: { minlength: 10, required: true },
        showCancelButton: true,
        confirmButtonText: 'رد کن',
        cancelButtonText: 'انصراف',
        confirmButtonColor: '#f44336',
        inputValidator: (value) => {
            if (!value || value.length < 10) return 'حداقل ۱۰ کاراکتر وارد کنید.';
        }
    }).then(result => {
        if (result.isConfirmed) {
            fetch(`<?= url('/admin/content/') ?>${id}/reject`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ reason: result.value })
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    notyf.success(res.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(res.message);
                }
            })
            .catch(() => notyf.error('خطا در ارتباط با سرور'));
        }
    });
}

function publishContent(id) {
    Swal.fire({
        title: 'ثبت انتشار',
        html: `
            <div style="text-align:right; direction:rtl;">
                <div class="form-group" style="margin-bottom:10px;">
                    <label>لینک ویدیو منتشرشده</label>
                    <input id="swal-url" class="swal2-input" dir="ltr" placeholder="https://...">
                </div>
                <div class="form-group">
                    <label>نام کانال</label>
                    <input id="swal-channel" class="swal2-input" placeholder="نام کانال مجموعه">
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'ثبت انتشار',
        cancelButtonText: 'انصراف',
        preConfirm: () => ({
            published_url: document.getElementById('swal-url').value,
            channel_name: document.getElementById('swal-channel').value
        })
    }).then(result => {
        if (result.isConfirmed) {
            fetch(`<?= url('/admin/content/') ?>${id}/publish`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify(result.value)
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    notyf.success(res.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(res.message);
                }
            })
            .catch(() => notyf.error('خطا در ارتباط با سرور'));
        }
    });
}

function suspendContent(id) {
    Swal.fire({
        title: 'تعلیق محتوا',
        input: 'textarea',
        inputLabel: 'دلیل تعلیق',
        inputPlaceholder: 'حداقل ۱۰ کاراکتر...',
        showCancelButton: true,
        confirmButtonText: 'تعلیق',
        cancelButtonText: 'انصراف',
        confirmButtonColor: '#333',
        inputValidator: v => (!v || v.length < 10) ? 'حداقل ۱۰ کاراکتر' : null
    }).then(result => {
        if (result.isConfirmed) {
            fetch(`<?= url('/admin/content/') ?>${id}/suspend`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ reason: result.value })
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    notyf.success(res.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(res.message);
                }
            })
            .catch(() => notyf.error('خطا در ارتباط با سرور'));
        }
    });
}

function approveRevenue(id) {
    Swal.fire({
        title: 'تأیید درآمد',
        text: 'آیا از تأیید این درآمد مطمئنید؟',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'بله',
        cancelButtonText: 'انصراف'
    }).then(result => {
        if (result.isConfirmed) {
            fetch(`<?= url('/admin/content/revenue/') ?>${id}/approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    notyf.success(res.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(res.message);
                }
            })
            .catch(() => notyf.error('خطا در ارتباط با سرور'));
        }
    });
}

function payRevenue(id) {
    Swal.fire({
        title: 'پرداخت درآمد',
        text: 'آیا مطمئنید؟ مبلغ به کیف پول کاربر واریز خواهد شد.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'بله، واریز شود',
        cancelButtonText: 'انصراف'
    }).then(result => {
        if (result.isConfirmed) {
            fetch(`<?= url('/admin/content/revenue/') ?>${id}/pay`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    notyf.success(res.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(res.message);
                }
            })
            .catch(() => notyf.error('خطا در ارتباط با سرور'));
        }
    });
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>
