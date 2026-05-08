<?php
$pageTitle = $pageTitle ?? 'درخواست‌های واریز دستی';
$deposits = $deposits ?? [];
?>

<div class="main-content">
    <div class="content-header">
        <h1>درخواست‌های واریز دستی</h1>
        <a href="<?= url('/manual-deposit/create') ?>" class="btn btn-primary">
            <i class="material-icons">add_circle</i>
            واریز جدید
        </a>
    </div>

    <?php if (empty($deposits)): ?>
    <div class="empty-state-card">
        <i class="material-icons">account_balance</i>
        <h3>درخواست واریزی ندارید</h3>
        <p>برای افزایش موجودی از طریق کارت بانکی اقدام کنید.</p>
        <a href="<?= url('/manual-deposit/create') ?>" class="btn btn-primary">واریز دستی</a>
    </div>
    <?php else: ?>
    <div class="table-card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>مبلغ (تومان)</th>
                        <th>شماره کارت مبدأ</th>
                        <th>تاریخ واریز</th>
                        <th>شماره پیگیری</th>
                        <th>تاریخ ثبت</th>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deposits as $d): ?>
                    <tr>
                        <td><?= (int)$d->id ?></td>
                        <td><strong><?= number_format((float)$d->amount) ?></strong></td>
                        <td class="ltr-text"><?= e($d->card_number ?? '—') ?></td>
                        <td><?= $d->deposit_date ? to_jalali($d->deposit_date) : '—' ?></td>
                        <td class="ltr-text"><?= e($d->reference_number ?? '—') ?></td>
                        <td><?= to_jalali($d->created_at) ?></td>
                        <td>
                            <?php
                            $statusMap = [
                                'pending'  => ['label' => 'در انتظار بررسی', 'class' => 'badge-warning'],
                                'approved' => ['label' => 'تأیید شده',       'class' => 'badge-success'],
                                'rejected' => ['label' => 'رد شده',          'class' => 'badge-danger'],
                            ];
                            $st = $statusMap[$d->status ?? ''] ?? ['label' => e($d->status ?? '—'), 'class' => 'badge-secondary'];
                            ?>
                            <span class="badge <?= e($st['class']) ?>"><?= e($st['label']) ?></span>
                            <?php if (!empty($d->rejection_reason) && $d->status === 'rejected'): ?>
                                <small class="d-block text-danger mt-1"><?= e($d->rejection_reason) ?></small>
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
.empty-state-card { background:white; border-radius:12px; padding:60px 30px; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
.empty-state-card .material-icons { font-size:64px; color:#ccc; }
.empty-state-card h3 { margin:15px 0 8px; color:#555; }
.empty-state-card p { color:#999; margin-bottom:25px; }
.ltr-text { direction:ltr; text-align:right; font-family:monospace; font-size:13px; }
</style>
