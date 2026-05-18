<?php
$pageTitle = $pageTitle ?? 'بررسی پرداخت‌های آنلاین معلق';
$payments = $payments ?? [];
$layout = 'admin';
ob_start();
?>

<div class="main-content">
    <!-- Header -->
    <div class="content-header">
        <h1>مدیریت پرداخت‌های آنلاین معلق (Pending Verification)</h1>
        <div class="header-stats">
            <div class="stat-badge pending" style="background: #fff3e0; color: #e65100;">
                <i class="material-icons">schedule</i>
                <span><?= count($payments) ?> تراکنش در انتظار بررسی</span>
            </div>
        </div>
    </div>

    <!-- جدول -->
    <div class="table-card">
        <?php if (empty($payments)): ?>
        <div class="empty-state" style="padding: 40px; text-align: center;">
            <i class="material-icons" style="font-size: 48px; color: #ccc;">credit_card</i>
            <h3>پرداخت معلقی یافت نشد</h3>
            <p>همه تراکنش‌های آنلاین با موفقیت تایید شده‌اند و هیچ موردی در صف بررسی دستی نیست.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>شناسه پرداخت</th>
                        <th>کاربر</th>
                        <th>درگاه</th>
                        <th>کارت بانکی</th>
                        <th>مبلغ تراکنش</th>
                        <th>شناسه مرجع (Authority)</th>
                        <th>تاریخ ایجاد</th>
                        <th>خطای درگاه (آخرین وضعیت)</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $pay): ?>
                    <?php 
                        $resData = @json_decode($pay->response_data ?? '', true) ?: [];
                        $attempts = $resData['verification_attempts'] ?? 0;
                        $lastError = $resData['verification_error'] ?? 'خطای ناشناخته در اتصال درگاه';
                    ?>
                    <tr>
                        <td>
                            <code>#<?= e($pay->id) ?></code>
                        </td>
                        <td>
                            <div class="user-info">
                                <strong>شناسه کاربر: <?= e($pay->user_id) ?></strong>
                                <small style="display: block; color: #666;"><?= e($pay->email ?? $pay->mobile ?? '') ?></small>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge" style="background: #e3f2fd; color: #0d47a1; font-weight: bold; padding: 4px 8px; border-radius: 4px;">
                                <?= strtoupper(e($pay->gateway)) ?>
                            </span>
                        </td>
                        <td>
                            <code class="card-number">
                                ****-****-****-<?= e($pay->card_last4 ?? '****') ?>
                            </code>
                        </td>
                        <td>
                            <span class="amount-badge" style="font-weight: bold; color: #2e7d32;">
                                <?= number_format((float)$pay->amount) ?> تومان
                            </span>
                        </td>
                        <td>
                            <code class="tracking-code" style="background: #f5f5f5; padding: 2px 6px; border-radius: 4px; font-size: 0.9em;"><?= e($pay->authority) ?></code>
                        </td>
                        <td>
                            <span class="date-badge" dir="ltr"><?= e($pay->created_at) ?></span>
                        </td>
                        <td>
                            <div style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e($lastError) ?>">
                                <span class="text-danger" style="font-size: 0.85em;">
                                    <i class="material-icons" style="font-size: 14px; vertical-align: middle;">error_outline</i>
                                    <?= e($lastError) ?>
                                </span>
                                <?php if ($attempts > 0): ?>
                                <small class="text-muted" style="display:block;">(تعداد تلاش: <?= $attempts ?>)</small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn btn-sm btn-success" 
                                        style="display: inline-flex; align-items: center; gap: 4px;"
                                        onclick="verifyPayment(<?= e($pay->id) ?>, '<?= e($pay->authority) ?>')">
                                    <i class="material-icons" style="font-size: 16px;">check_circle</i>
                                    استعلام و تأیید
                                </button>
                            </div>
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
function verifyPayment(paymentId, authority) {
    Swal.fire({
        title: 'استعلام مجدد و تایید دستی پرداخت',
        html: `
            <p>سیستم به صورت مستقیم با درگاه بانکی ارتباط برقرار کرده و صحت تراکنش <strong>#${paymentId}</strong> را بررسی می‌کند.</p>
            <p style="font-size: 13px; color: #666;">کد مرجع درگاه: <code>${authority}</code></p>
            <div style="margin-top: 15px; padding: 15px; background: #e8f5e9; border-radius: 8px; text-align: right;">
                <strong style="color: #2e7d32;">راهنما:</strong>
                <p style="margin: 5px 0 0 0; font-size: 12px; color: #555;">
                    اگر تراکنش در درگاه موفقیت‌آمیز بوده باشد، کیف پول کاربر شارژ شده و وضعیت تراکنش به <strong>تکمیل شده (Completed)</strong> تغییر خواهد یافت.
                </p>
            </div>
        `,
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#2e7d32',
        cancelButtonColor: '#757575',
        confirmButtonText: 'بله، استعلام و تأیید شود',
        cancelButtonText: 'انصراف',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            const formData = new FormData();
            formData.append('payment_id', paymentId);
            formData.append('<?= csrf_token() ?>', '<?= csrf_token() ?>');

            return fetch('<?= url('/admin/gateway-payments/verify') ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => { throw new Error(err.message || 'خطا در انجام عملیات') });
                }
                return response.json();
            })
            .catch(error => {
                Swal.showValidationMessage(`خطا: ${error.message}`);
            });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            if (result.value.success) {
                Swal.fire({
                    title: 'تأیید موفقیت‌آمیز',
                    text: result.value.message || 'تراکنش با موفقیت تأیید و کیف پول کاربر شارژ شد.',
                    icon: 'success',
                    confirmButtonText: 'باشه'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    title: 'خطا در تایید',
                    text: result.value.message || 'تایید تراکنش ناموفق بود.',
                    icon: 'error',
                    confirmButtonText: 'باشه'
                });
            }
        }
    });
}
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>
