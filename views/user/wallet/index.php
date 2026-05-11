<?php
$title = $title ?? 'کیف پول';
$summary = $summary ?? null;
$siteCurrency = $siteCurrency ?? 'irt';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-wallet.css') ?>">


    <!-- Header -->
    <div class="content-header">
        <h1>کیف پول من</h1>
        <div class="header-actions">
            <a href="<?= url('/wallet/deposit') ?>" class="btn btn-primary">
                <i class="material-icons">add_circle</i>
                افزایش موجودی
            </a>
            <a href="<?= url('/wallet/withdraw') ?>" class="btn btn-success">
                <i class="material-icons">remove_circle</i>
                برداشت وجه
            </a>
        </div>
    </div>

    <?php if ($summary): ?>
    
    <!-- Wallet Cards -->
    <div class="wallet-cards">
        <!-- کارت تومانی -->
        <div class="wallet-card irt">
            <div class="card-header">
                <div class="card-icon">
                    <i class="material-icons">account_balance_wallet</i>
                </div>
                <h3>کیف پول تومانی (IRT)</h3>
            </div>
            <div class="card-body">
                <div class="balance-info">
                    <span class="label">موجودی آزاد:</span>
                    <span class="amount"><?= number_format($summary->balance_irt) ?> <small>تومان</small></span>
                </div>
                <?php if ($summary->locked_irt > 0): ?>
                <div class="balance-info locked">
                    <span class="label">موجودی قفل‌شده:</span>
                    <span class="amount"><?= number_format($summary->locked_irt) ?> <small>تومان</small></span>
                </div>
                <?php endif; ?>
                <div class="balance-info total">
                    <span class="label">مجموع:</span>
                    <span class="amount"><?= number_format($summary->total_irt) ?> <small>تومان</small></span>
                </div>
            </div>
            <div class="card-footer">
                <a href="<?= url('/wallet/history?currency=irt') ?>" class="card-link">
                    <i class="material-icons">history</i>
                    تاریخچه تراکنش‌ها
                </a>
            </div>
        </div>

        <!-- کارت تتری -->
        <div class="wallet-card usdt">
            <div class="card-header">
                <div class="card-icon">
                    <i class="material-icons">currency_bitcoin</i>
                </div>
                <h3>کیف پول تتری (USDT)</h3>
            </div>
            <div class="card-body">
                <div class="balance-info">
                    <span class="label">موجودی آزاد:</span>
                    <span class="amount"><?= number_format($summary->balance_usdt, 4) ?> <small>USDT</small></span>
                </div>
                <?php if ($summary->locked_usdt > 0): ?>
                <div class="balance-info locked">
                    <span class="label">موجودی قفل‌شده:</span>
                    <span class="amount"><?= number_format($summary->locked_usdt, 4) ?> <small>USDT</small></span>
                </div>
                <?php endif; ?>
                <div class="balance-info total">
                    <span class="label">مجموع:</span>
                    <span class="amount"><?= number_format($summary->total_usdt, 4) ?> <small>USDT</small></span>
                </div>
            </div>
            <div class="card-footer">
                <a href="<?= url('/wallet/history?currency=usdt') ?>" class="card-link">
                    <i class="material-icons">history</i>
                    تاریخچه تراکنش‌ها
                </a>
            </div>
        </div>
    </div>

    <!-- آمار تراکنش‌ها -->
    <div class="stats-section">
        <h2>آمار تراکنش‌ها</h2>
        <div class="stats-grid">
            <!-- آمار تومانی -->
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="material-icons">trending_up</i>
                </div>
                <div class="stat-info">
                    <h4>واریزهای تومانی</h4>
                    <p class="stat-value"><?= number_format($summary->stats->irt->total_deposits) ?> تومان</p>
                    <p class="stat-count"><?= to_jalali($summary->stats->irt->deposit_count, '', true) ?> تراکنش</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon red">
                    <i class="material-icons">trending_down</i>
                </div>
                <div class="stat-info">
                    <h4>برداشت‌های تومانی</h4>
                    <p class="stat-value"><?= number_format($summary->stats->irt->total_withdrawals) ?> تومان</p>
                    <p class="stat-count"><?= to_jalali($summary->stats->irt->withdrawal_count, '', true) ?> تراکنش</p>
                </div>
            </div>

            <!-- آمار تتری -->
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="material-icons">trending_up</i>
                </div>
                <div class="stat-info">
                    <h4>واریزهای تتری</h4>
                    <p class="stat-value"><?= number_format($summary->stats->usdt->total_deposits, 2) ?> USDT</p>
                    <p class="stat-count"><?= to_jalali($summary->stats->usdt->deposit_count, '', true) ?> تراکنش</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon red">
                    <i class="material-icons">trending_down</i>
                </div>
                <div class="stat-info">
                    <h4>برداشت‌های تتری</h4>
                    <p class="stat-value"><?= number_format($summary->stats->usdt->total_withdrawals, 2) ?> USDT</p>
                    <p class="stat-count"><?= to_jalali($summary->stats->usdt->withdrawal_count, '', true) ?> تراکنش</p>
                </div>
            </div>
        </div>
    </div>

    <!-- وضعیت برداشت -->
    <?php if (!$summary->can_withdraw_today): ?>
    <div class="alert alert-warning">
        <i class="material-icons">info</i>
        <div>
            <strong>توجه:</strong>
            شما امروز یکبار برداشت انجام داده‌اید. برداشت بعدی از فردا امکان‌پذیر است.
            <?php if ($summary->last_withdrawal_at): ?>
            <br>
            <small>آخرین برداشت: <?= to_jalali($summary->last_withdrawal_at) ?></small>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="alert alert-danger">
        <i class="material-icons">error</i>
        خطا در دریافت اطلاعات کیف پول
    </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>