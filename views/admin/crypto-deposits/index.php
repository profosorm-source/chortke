<?php
$pageTitle = $pageTitle ?? 'واریزهای کریپتو';
$deposits = $deposits ?? [];
$currentPage = $currentPage ?? 1;
$totalPages = $totalPages ?? 1;
$total = $total ?? 0;
$status = $status ?? '';
$network = $network ?? '';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-crypto-deposits.css') ?>">


<div class="main-content">
    <!-- Header -->
    <div class="content-header">
        <h1>مدیریت واریزهای USDT</h1>
        <div class="header-stats">
            <div class="stat-badge pending">
                <i class="material-icons">schedule</i>
                <span><?= to_jalali($total, '', true) ?> نیازمند بررسی</span>
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
                    <option value="manual_review" <?= $status === 'manual_review' ? 'selected' : '' ?>>بررسی دستی</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                    <option value="auto_verified" <?= $status === 'auto_verified' ? 'selected' : '' ?>>تأیید خودکار</option>
                    <option value="verified" <?= $status === 'verified' ? 'selected' : '' ?>>تأیید شده</option>
                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>رد شده</option>
                </select>
            </div>

            <div class="filter-group">
                <label>شبکه:</label>
                <select name="network" class="form-control">
                    <option value="">همه</option>
                    <option value="bnb20" <?= $network === 'bnb20' ? 'selected' : '' ?>>BNB20</option>
                    <option value="trc20" <?= $network === 'trc20' ? 'selected' : '' ?>>TRC20</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="material-icons">search</i>
                فیلتر
            </button>

            <?php if ($status || $network): ?>
            <a href="<?= url('/admin/crypto-deposits') ?>" class="btn btn-outline">
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
            <i class="material-icons">currency_bitcoin</i>
            <h3>واریزی یافت نشد</h3>
            <p>هیچ درخواست واریز USDT نیازمند بررسی وجود ندارد</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>کاربر</th>
                        <th>مبلغ</th>
                        <th>شبکه</th>
                        <th>هش تراکنش</th>
                        <th>تاریخ واریز</th>
                        <th>تلاش‌ها</th>
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
                            <span class="amount-badge crypto">
                                <?= number_format($deposit->amount, 4) ?> USDT
                            </span>
                        </td>
                        <td>
                            <span class="network-badge <?= e($deposit->network) ?>">
                                <?= e(strtoupper($deposit->network)) ?>
                            </span>
                        </td>
                        <td>
                            <div class="tx-hash-container">
                                <code class="tx-hash" title="<?= e($deposit->tx_hash) ?>">
                                    <?= substr($deposit->tx_hash, 0, 8) ?>...<?= substr($deposit->tx_hash, -6) ?>
                                </code>
                                <button class="copy-btn-small" onclick="copyToClipboard('<?= e($deposit->tx_hash) ?>')" title="کپی">
                                    <i class="material-icons">content_copy</i>
                                </button>
                                <?php
                                $explorerUrl = $deposit->network === 'trc20'
                                    ? "https://tronscan.org/#/transaction/{$deposit->tx_hash}"
                                    : "https://bscscan.com/tx/{$deposit->tx_hash}";
                                ?>
                                <a href="<?= e($explorerUrl) ?>" target="_blank" class="explorer-btn-small" title="مشاهده در Explorer">
                                    <i class="material-icons">open_in_new</i>
                                </a>
                            </div>
                        </td>
                        <td>
                            <span class="date-badge"><?= to_jalali($deposit->deposit_date) ?></span>
                            <small class="time-badge" dir="ltr"><?= e($deposit->deposit_time) ?></small>
                        </td>
                        <td>
                            <span class="attempts-badge">
                                <?= to_jalali($deposit->verification_attempts, '', true) ?> / 3
                            </span>
                        </td>
                        <td>
                            <?php
                            $statusLabels = [
                                'pending' => 'در انتظار',
                                'auto_verified' => 'تأیید خودکار',
                                'manual_review' => 'بررسی دستی',
                                'verified' => 'تأیید شده',
                                'rejected' => 'رد شده',
                            ];
                            ?>
                            <span class="status-badge <?= e($deposit->verification_status) ?>">
                                <?= e($statusLabels[$deposit->verification_status] ?? $deposit->verification_status) ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="<?= url('/admin/crypto-deposits/review?id=' . $deposit->id) ?>" 
                                   class="btn-icon" 
                                   title="بررسی جزئیات">
                                    <i class="material-icons">visibility</i>
                                </a>
                                <?php if ($deposit->verification_status === 'manual_review' || $deposit->verification_status === 'pending'): ?>
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
            <?php 
            $queryParams = [];
            if ($status) $queryParams[] = 'status=' . $status;
            if ($network) $queryParams[] = 'network=' . $network;
            $queryString = !empty($queryParams) ? '&' . implode('&', $queryParams) : '';
            ?>
            
            <?php if ($currentPage > 1): ?>
            <a href="?page=<?= $currentPage - 1 ?><?= e($queryString) ?>" class="page-link">
                <i class="material-icons">chevron_right</i>
            </a>
            <?php endif; ?>

            <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
            <a href="?page=<?= e($i) ?><?= e($queryString) ?>" 
               class="page-link <?= $i === $currentPage ? 'active' : '' ?>">
                <?= to_jalali($i, '', true) ?>
            </a>
            <?php endfor; ?>

            <?php if ($currentPage < $totalPages): ?>
            <a href="?page=<?= $currentPage + 1 ?><?= e($queryString) ?>" class="page-link">
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
            <h3>رد واریز USDT</h3>
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
                    <button type="button" class="reason-btn" onclick="setReason('هش تراکنش در Blockchain یافت نشد')">
                        هش نامعتبر
                    </button>
                    <button type="button" class="reason-btn" onclick="setReason('مبلغ تراکنش با مبلغ اعلام شده مطابقت ندارد')">
                        عدم تطابق مبلغ
                    </button>
                    <button type="button" class="reason-btn" onclick="setReason('آدرس کیف پول مقصد صحیح نیست')">
                        آدرس نادرست
                    </button>
                    <button type="button" class="reason-btn" onclick="setReason('شبکه انتقال با شبکه اعلام شده مطابقت ندارد')">
                        شبکه نادرست
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
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        notyf.success('کپی شد!');
    }).catch(() => {
        notyf.error('خطا در کپی کردن');
    });
}

function verifyDeposit(depositId) {
    Swal.fire({
        title: 'تأیید واریز USDT',
        html: `
            <p>آیا از تأیید این واریز اطمینان دارید؟</p>
            <div style="margin-top: 15px; padding: 15px; background: #fff3e0; border-radius: 8px; text-align: right;">
                <strong style="color: #f57c00;">توجه:</strong>
                <p style="margin: 5px 0 0 0; font-size: 13px; color: #666;">
                    لطفاً قبل از تأیید، تراکنش را در Blockchain بررسی کنید.
                    پس از تأیید، مبلغ به کیف پول کاربر افزوده می‌شود.
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

            fetch('<?= url('/admin/crypto-deposits/verify') ?>', {
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
    
    fetch('<?= url('/admin/crypto-deposits/reject') ?>', {
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