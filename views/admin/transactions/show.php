<?php
$pageTitle = $pageTitle ?? 'جزئیات تراکنش';
$transaction = $transaction ?? null;
$user = $user ?? null;
$metadata = $metadata ?? null;
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-transactions.css') ?>">


<div class="main-content">
    <!-- Header -->
    <div class="content-header">
        <h1>جزئیات تراکنش</h1>
        <a href="<?= url('/admin/transactions') ?>" class="btn btn-outline">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
    </div>

    <?php if ($transaction): ?>
    
    <!-- کارت اطلاعات تراکنش -->
    <div class="detail-card">
        <div class="detail-header">
            <h3>
                <i class="material-icons">receipt</i>
                اطلاعات تراکنش
            </h3>
            <div class="header-badges">
                <?php
                $typeLabels = [
                    'deposit' => 'واریز',
                    'withdraw' => 'برداشت',
                    'transfer' => 'انتقال',
                    'commission' => 'کمیسیون',
                    'task_reward' => 'پاداش تسک',
                    'penalty' => 'جریمه',
                ];
                $statusLabels = [
                    'pending' => 'در انتظار',
                    'processing' => 'در حال پردازش',
                    'completed' => 'تکمیل شده',
                    'failed' => 'ناموفق',
                    'cancelled' => 'لغو شده',
                ];
                ?>
                <span class="type-badge <?= e($transaction->type) ?>">
                    <?= e($typeLabels[$transaction->type] ?? $transaction->type) ?>
                </span>
                <span class="status-badge <?= e($transaction->status) ?>">
                    <?= e($statusLabels[$transaction->status] ?? $transaction->status) ?>
                </span>
            </div>
        </div>

        <div class="detail-grid">
            <div class="detail-row">
                <span class="label">شناسه تراکنش:</span>
                <code class="value"><?= e($transaction->transaction_id) ?></code>
            </div>

            <div class="detail-row">
                <span class="label">نوع تراکنش:</span>
                <span class="value"><?= e($typeLabels[$transaction->type] ?? $transaction->type) ?></span>
            </div>

            <div class="detail-row">
                <span class="label">مبلغ:</span>
                <span class="value amount-large <?= in_array($transaction->type, ['withdraw', 'penalty']) ? 'negative' : 'positive' ?>">
                    <?php if (in_array($transaction->type, ['withdraw', 'penalty'])): ?>-<?php else: ?>+<?php endif; ?>
                    <?= $transaction->currency === 'usdt' 
                        ? number_format($transaction->amount, 4) . ' USDT'
                        : number_format($transaction->amount) . ' تومان'
                    ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="label">موجودی قبل:</span>
                <span class="value">
                    <?= $transaction->currency === 'usdt' 
                        ? number_format($transaction->balance_before, 4) . ' USDT'
                        : number_format($transaction->balance_before) . ' تومان'
                    ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="label">موجودی بعد:</span>
                <span class="value">
                    <?= $transaction->currency === 'usdt' 
                        ? number_format($transaction->balance_after, 4) . ' USDT'
                        : number_format($transaction->balance_after) . ' تومان'
                    ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="label">وضعیت:</span>
                <span class="value"><?= e($statusLabels[$transaction->status] ?? $transaction->status) ?></span>
            </div>

            <?php if ($transaction->gateway): ?>
            <div class="detail-row">
                <span class="label">درگاه پرداخت:</span>
                <span class="value"><?= e($transaction->gateway) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($transaction->gateway_transaction_id): ?>
            <div class="detail-row">
                <span class="label">شناسه درگاه:</span>
                <code class="value"><?= e($transaction->gateway_transaction_id) ?></code>
            </div>
            <?php endif; ?>

            <?php if ($transaction->description): ?>
            <div class="detail-row full-width">
                <span class="label">توضیحات:</span>
                <span class="value"><?= e($transaction->description) ?></span>
            </div>
            <?php endif; ?>

            <div class="detail-row">
                <span class="label">تاریخ ایجاد:</span>
                <span class="value"><?= to_jalali($transaction->created_at) ?></span>
            </div>

            <?php if ($transaction->completed_at): ?>
            <div class="detail-row">
                <span class="label">تاریخ تکمیل:</span>
                <span class="value"><?= to_jalali($transaction->completed_at) ?></span>
            </div>
            <?php endif; ?>

            <div class="detail-row">
                <span class="label">آدرس IP:</span>
                <code class="value"><?= e($transaction->ip_address ?? '-') ?></code>
            </div>

            <?php if ($transaction->device_fingerprint): ?>
            <div class="detail-row full-width">
                <span class="label">اثر انگشت دستگاه:</span>
                <code class="value fingerprint"><?= e($transaction->device_fingerprint) ?></code>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- کارت اطلاعات کاربر -->
    <?php if ($user): ?>
    <div class="detail-card">
        <div class="detail-header">
            <h3>
                <i class="material-icons">person</i>
                اطلاعات کاربر
            </h3>
        </div>

        <div class="detail-grid">
            <div class="detail-row">
                <span class="label">نام کامل:</span>
                <span class="value"><?= e($user->full_name) ?></span>
            </div>

            <div class="detail-row">
                <span class="label">ایمیل:</span>
                <span class="value"><?= e($user->email) ?></span>
            </div>

            <div class="detail-row">
                <span class="label">شماره موبایل:</span>
                <span class="value" dir="ltr"><?= e($user->mobile ?? '-') ?></span>
            </div>

            <div class="detail-row">
                <span class="label">نقش:</span>
                <span class="value"><?= $user->role === 'admin' ? 'مدیر' : 'کاربر' ?></span>
            </div>

            <div class="detail-row">
                <span class="label">وضعیت حساب:</span>
                <span class="value">
                    <?php
                    $activeStatusLabels = [
                        'inactive' => 'غیرفعال',
                        'active' => 'فعال',
                        'suspended' => 'تعلیق',
                        'banned' => 'مسدود',
                    ];
                    ?>
                    <span class="status-badge <?= e($user->active_status) ?>">
                        <?= e($activeStatusLabels[$user->active_status] ?? $user->active_status) ?>
                    </span>
                </span>
            </div>

            <div class="detail-row">
                <span class="label">تاریخ عضویت:</span>
                <span class="value"><?= to_jalali($user->created_at) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- متادیتا -->
    <?php if ($metadata): ?>
    <div class="detail-card">
        <div class="detail-header">
            <h3>
                <i class="material-icons">data_object</i>
                اطلاعات تکمیلی (Metadata)
            </h3>
        </div>

        <div class="metadata-content">
            <pre><?= json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></pre>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="alert alert-danger">
        <i class="material-icons">error</i>
        تراکنش یافت نشد
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>