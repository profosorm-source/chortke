<?php
$pageTitle = $pageTitle ?? 'واریزهای دستی';
$deposits = $deposits ?? [];
$currentPage = $currentPage ?? 1;
$totalPages = $totalPages ?? 1;
$total = $total ?? 0;
$status = $status ?? '';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-manual-deposits.css') ?>">


<div class="main-content">
    <!-- Header -->
    <div class="content-header">
        <h1>مدیریت واریزهای دستی</h1>
        <div class="header-stats">
            <div class="stat-badge pending">
                <i class="material-icons">schedule</i>
                <span><?= to_jalali($total, '', true) ?> در انتظار</span>
            </div>
        </div>
    </div>

    <!-- فیلترها -->
    <div class="filters-card">
        <form method="GET" class="filters-form">
            <div class="filter-group">
                <label>وضعیت:</label>
                <select name="status" class="form-control">
                    <option value="">همه</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                    <option value="under_review" <?= $status === 'under_review' ? 'selected' : '' ?>>در حال بررسی</option>
                    <option value="verified" <?= $status === 'verified' ? 'selected' : '' ?>>تأیید شده</option>
                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>رد شده</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="material-icons">search</i>
                فیلتر
            </button>

            <?php if ($status): ?>
            <a href="<?= url('/admin/manual-deposits') ?>" class="btn btn-outline">
                <i class="material-icons">clear</i>
                حذف فیلتر
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- جدول -->
    <div class="table-card">
        <?php if (empty($deposits)): ?>
        <div class="empty-state">
            <i class="material-icons">account_balance</i>
            <h3>واریزی یافت نشد</h3>
            <p>هیچ درخواست واریز دستی وجود ندارد</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>کاربر</th>
                        <th>مبلغ</th>
                        <th>کارت مبدا</th>
                        <th>شماره پیگیری</th>
                        <th>تاریخ واریز</th>
                        <th>ساعت</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deposits as $deposit): ?>
                    <tr>
                        <td>
                            <div class="user-info">
                                <strong><?= e($deposit->full_name ?? 'نامشخص') ?></strong>
                                <small><?= e($deposit->email ?? '') ?></small>
                            </div>
                        </td>
                        <td>
                            <span class="amount-badge">
                                <?= number_format($deposit->amount) ?> تومان
                            </span>
                        </td>
                        <td>
                            <code class="card-number">
                                <?= substr($deposit->card_number, 0, 4) ?>-****-****-<?= substr($deposit->card_number, -4) ?>
                            </code>
                            <small class="bank-name"><?= e($deposit->bank_name) ?></small>
                        </td>
                        <td>
                            <code class="tracking-code"><?= e($deposit->tracking_code) ?></code>
                        </td>
                        <td>
                            <span class="date-badge"><?= to_jalali($deposit->deposit_date) ?></span>
                        </td>
                        <td>
                            <span class="time-badge" dir="ltr"><?= e($deposit->deposit_time) ?></span>
                        </td>
                        <td>
                            <?php
                            $statusLabels = [
                                'pending' => 'در انتظار',
                                'under_review' => 'در حال بررسی',
                                'verified' => 'تأیید شده',
                                'rejected' => 'رد شده',
                            ];
                            ?>
                            <span class="status-badge <?= e($deposit->status) ?>">
                                <?= e($statusLabels[$deposit->status] ?? $deposit->status) ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="<?= url('/admin/manual-deposits/review?id=' . $deposit->id) ?>" 
                                   class="btn-icon" 
                                   title="بررسی جزئیات">
                                    <i class="material-icons">visibility</i>
                                </a>
                                <?php if ($deposit->status === 'pending' || $deposit->status === 'under_review'): ?>
                                <button class="btn-icon success" 
                                        onclick="verifyDeposit(<?= e($deposit->id) ?>)" 
                                        title="تأیید">
                                    <i class="material-icons">check_circle</i>
                                </button>
                                <button class="btn-icon danger" 
                                        onclick="showRejectModal(<?= e($deposit->id) ?>)" 
                                        title="رد">
                                    <i class="material-icons">cancel</i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($currentPage > 1): ?>
            <a href="?page=<?= $currentPage - 1 ?><?= $status ? '&status=' . $status : '' ?>" class="page-link">
                <i class="material-icons">chevron_right</i>
            </a>
            <?php endif; ?>

            <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
            <a href="?page=<?= e($i) ?><?= $status ? '&status=' . $status : '' ?>" 
               class="page-link <?= $i === $currentPage ? 'active' : '' ?>">
                <?= to_jalali($i, '', true) ?>
            </a>
            <?php endfor; ?>

            <?php if ($currentPage < $totalPages): ?>
            <a href="?page=<?= $currentPage + 1 ?><?= $status ? '&status=' . $status : '' ?>" class="page-link">
                <i class="material-icons">chevron_left</i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- مودال رد واریز -->
<div class="modal" id="rejectModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>رد واریز دستی</h3>
            <button class="modal-close" onclick="closeRejectModal()">
                <i class="material-icons">close</i>
            </button>
        </div>
        <form id="rejectForm">
            <?= csrf_field() ?>
            <input type="hidden" id="reject_deposit_id" name="deposit_id">
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="rejection_reason">دلیل رد: <span class="required">*</span></label>
                    <textarea id="rejection_reason" 
                              name="rejection_reason" 
                              class="form-control" 
                              rows="4"
                              placeholder="لطفاً دلیل رد واریز را به صورت واضح توضیح دهید..."
                              required></textarea>
                    <small class="form-text">این پیام به کاربر نمایش داده می‌شود</small>
                </div>

                <div class="common-reasons">
                    <strong>دلایل متداول:</strong>
                    <button type="button" class="reason-btn" onclick="setReason('شماره پیگیری نامعتبر است')">
                        شماره پیگیری نادرست
                    </button>
                    <button type="button" class="reason-btn" onclick="setReason('مبلغ واریزی با مبلغ ثبت شده مطابقت ندارد')">
                        عدم تطابق مبلغ
                    </button>
                    <button type="button" class="reason-btn" onclick="setReason('واریز از کارت دیگری انجام شده است')">
                        کارت نامتعلق
                    </button>
                    <button type="button" class="reason-btn" onclick="setReason('اطلاعات ثبت شده صحیح نیست')">
                        اطلاعات نادرست
                    </button>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeRejectModal()">انصراف</button>
                <button type="submit" class="btn btn-danger">
                    <i class="material-icons">cancel</i>
                    رد واریز
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function verifyDeposit(depositId) {
    Swal.fire({
        title: 'تأیید واریز',
        html: `
            <p>آیا از تأیید این واریز اطمینان دارید؟</p>
            <div style="margin-top: 15px; padding: 15px; background: #fff3e0; border-radius: 8px; text-align: right;">
                <strong style="color: #f57c00;">توجه:</strong>
                <p style="margin: 5px 0 0 0; font-size: 13px; color: #666;">
                    پس از تأیید، مبلغ به کیف پول کاربر افزوده می‌شود و این عملیات غیرقابل بازگشت است.
                </p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#4caf50',
        cancelButtonColor: '#999',
        confirmButtonText: 'بله، تأیید شود',
        cancelButtonText: 'انصراف'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('deposit_id', depositId);
            formData.append('<?= csrf_token() ?>', '<?= csrf_token() ?>');

            fetch('<?= url('/admin/manual-deposits/verify') ?>', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    notyf.success(data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    notyf.error(data.message);
                }
            })
            .catch(err => {
                notyf.error('خطا در ارتباط با سرور');
            });
        }
    });
}

function showRejectModal(depositId) {
    document.getElementById('reject_deposit_id').value = depositId;
    document.getElementById('rejection_reason').value = '';
    document.getElementById('rejectModal').classList.add('show');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('show');
}

function setReason(reason) {
    document.getElementById('rejection_reason').value = reason;
}

document.getElementById('rejectForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('<?= url('/admin/manual-deposits/reject') ?>', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            closeRejectModal();
            notyf.success(data.message);
            setTimeout(() => location.reload(), 1000);
        } else {
            notyf.error(data.message);
        }
    })
    .catch(err => {
        notyf.error('خطا در ارتباط با سرور');
    });
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>