<?php
$pageTitle = $pageTitle ?? 'درخواست‌های برداشت';
$withdrawals = $withdrawals ?? [];
?>

<div class="main-content">
    <div class="content-header">
        <h1>درخواست‌های برداشت</h1>
        <a href="<?= url('/withdrawal/create') ?>" class="btn btn-primary">
            <i class="material-icons">add_circle</i>
            درخواست جدید
        </a>
    </div>

    <?php if (empty($withdrawals)): ?>
    <div class="empty-state-card">
        <i class="material-icons">payments</i>
        <h3>هنوز درخواست برداشتی ندارید</h3>
        <p>برای برداشت وجه از کیف پول خود درخواست دهید.</p>
        <a href="<?= url('/withdrawal/create') ?>" class="btn btn-primary">
            <i class="material-icons">add_circle</i>
            درخواست برداشت
        </a>
    </div>
    <?php else: ?>
    <div class="table-card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>مبلغ</th>
                        <th>ارز</th>
                        <th>مقصد</th>
                        <th>تاریخ</th>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($withdrawals as $w): ?>
                    <tr>
                        <td><?= (int)$w->id ?></td>
                        <td><strong><?= number_format((float)$w->amount) ?></strong></td>
                        <td><?= strtoupper(e($w->currency ?? 'IRT')) ?></td>
                        <td class="ltr-text">
                            <?php if (!empty($w->card_number)): ?>
                                <i class="material-icons" style="font-size:14px;vertical-align:middle">credit_card</i>
                                <?= e($w->card_number) ?>
                                <?php if (!empty($w->bank_name)): ?>
                                    <small class="text-muted">(<?= e($w->bank_name) ?>)</small>
                                <?php endif; ?>
                            <?php elseif (!empty($w->wallet_address)): ?>
                                <i class="material-icons" style="font-size:14px;vertical-align:middle">currency_bitcoin</i>
                                <?= e(substr($w->wallet_address, 0, 12)) ?>...
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= to_jalali($w->created_at) ?></td>
                        <td>
                            <?php
                            $statusMap = [
                                'pending'    => ['label' => 'در انتظار',     'class' => 'badge-warning'],
                                'processing' => ['label' => 'در حال پردازش', 'class' => 'badge-info'],
                                'completed'  => ['label' => 'تکمیل شده',    'class' => 'badge-success'],
                                'rejected'   => ['label' => 'رد شده',        'class' => 'badge-danger'],
                            ];
                            $st = $statusMap[$w->status ?? ''] ?? ['label' => e($w->status ?? '—'), 'class' => 'badge-secondary'];
                            ?>
                            <span class="badge <?= e($st['class']) ?>"><?= e($st['label']) ?></span>
                            <?php if (!empty($w->reject_reason) && $w->status === 'rejected'): ?>
                                <small class="d-block text-danger mt-1"><?= e($w->reject_reason) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.empty-state-card {
    background: white;
    border-radius: 12px;
    padding: 60px 30px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.empty-state-card .material-icons { font-size: 64px; color: #ccc; }
.empty-state-card h3 { margin: 15px 0 8px; color: #555; }
.empty-state-card p { color: #999; margin-bottom: 25px; }
.ltr-text { direction: ltr; text-align: right; font-family: monospace; font-size: 13px; }
</style>
