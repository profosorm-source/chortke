<?php
$title = 'جزئیات برداشت';
$layout = 'admin';
ob_start();
?>

<div class="row">
    <!-- اطلاعات کاربر -->
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">اطلاعات کاربر</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="text-muted small">نام کامل:</label>
                    <p class="mb-0"><?= e($user->full_name) ?></p>
                </div>
                <div class="mb-3">
                    <label class="text-muted small">ایمیل:</label>
                    <p class="mb-0"><?= e($user->email) ?></p>
                </div>
                <div class="mb-3">
                    <label class="text-muted small">وضعیت احراز هویت:</label>
                    <p class="mb-0">
                        <?php
                        $kycLabels = [
                            'none' => '<span class="badge bg-secondary">ندارد</span>',
                            'pending' => '<span class="badge bg-warning">در انتظار</span>',
                            'verified' => '<span class="badge bg-success">تأیید شده</span>',
                            'rejected' => '<span class="badge bg-danger">رد شده</span>'
                        ];
                        echo $kycLabels[$user->kyc_status ?? 'none'];
                        ?>
                    </p>
                </div>
                <div class="mb-3">
                    <label class="text-muted small">موجودی فعلی:</label>
                    <?php
                    $wallet = db()->query("SELECT * FROM wallets WHERE user_id = ?", [$user->id])->fetch();
                    ?>
                    <p class="mb-0">
                        <?= e(to_jalali(\number_format($wallet->balance_irr ?? 0, 0), '', true)) ?> تومان
                    </p>
                </div>
            </div>
        </div>

        <!-- تاریخچه برداشت‌های کاربر -->
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">تاریخچه برداشت‌ها</h6>
            </div>
            <div class="card-body">
                <?php if (empty($userWithdrawals)): ?>
                    <p class="text-muted small">برداشت قبلی ندارد</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($userWithdrawals as $uw): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex justify-content-between">
                                    <span class="small"><?= to_jalali(\number_format($uw->amount, 0), '', true) ?> تومان</span>
                                    <?php
                                    $statusColors = [
                                        'completed' => 'success',
                                        'rejected' => 'danger',
                                        'pending' => 'warning'
                                    ];
                                    $color = $statusColors[$uw->status] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= e($color) ?>"><?= e($uw->status) ?></span>
                                </div>
                                <small class="text-muted"><?= e(to_jalali(\date('Y/m/d', \strtotime($uw->created_at)))) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- جزئیات برداشت -->
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">جزئیات درخواست برداشت</h5>
            </div>
            <div class="card-body">
                <!-- وضعیت -->
                <div class="alert alert-<?= e(
                    $withdrawal->status === 'completed' ? 'success' : 
                    ($withdrawal->status === 'rejected' ? 'danger' : 
                    ($withdrawal->status === 'processing' ? 'info' : 'warning')) 
                ) ?>">
                    <strong>وضعیت:</strong>
                    <?php
                    $statusLabels = [
                        'pending' => 'در انتظار بررسی',
                        'processing' => 'در حال پردازش',
                        'completed' => 'تکمیل شده',
                        'rejected' => 'رد شده',
                        'cancelled' => 'لغو شده'
                    ];
                    echo e($statusLabels[$withdrawal->status] ?? $withdrawal->status);
                    ?>
                </div>

                <!-- اطلاعات -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="text-muted small">شناسه برداشت:</label>
                        <p class="mb-0"><code><?= e($withdrawal->withdrawal_id) ?></code></p>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">مبلغ:</label>
                        <h5 class="mb-0 text-danger">
                            <?= e(to_jalali(\number_format($withdrawal->amount, $withdrawal->currency === 'USDT' ? 2 : 0), '', true)) ?>
                            <?= e($withdrawal->currency === 'USDT' ? 'USDT' : 'تومان') ?>
                        </h5>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">تاریخ ثبت:</label>
                        <p class="mb-0"><?= e(to_jalali(\date('Y/m/d H:i:s', \strtotime($withdrawal->created_at)))) ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="text-muted small">IP کاربر:</label>
                        <p class="mb-0"><code><?= e($withdrawal->ip_address) ?></code></p>
                    </div>
                </div>

                <!-- اطلاعات کارت (تومان) -->
<?php if ($withdrawal->currency === 'IRR' && $card): ?>
    <div class="alert alert-info">
        <h6 class="mb-2">اطلاعات کارت مقصد:</h6>
        <p class="mb-1">
            <strong>شماره کارت:</strong>
            <code><?= e($card->card_number ?? '-') ?></code>
        </p>
        <p class="mb-0">
            <strong>نام صاحب کارت:</strong>
            <?= e($card->account_holder_name ?? '-') ?>
        </p>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require view_path('layouts.admin');
?>