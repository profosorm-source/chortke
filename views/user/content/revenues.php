<?php

$pageTitle = $pageTitle ?? 'درآمدهای محتوا';
$revenues = $revenues ?? [];
$total = $total ?? 0;
$totalPages = $totalPages ?? 1;
$currentPage = $currentPage ?? 1;
$totalPaid = $totalPaid ?? 0;
$totalPending = $totalPending ?? 0;
$layout = 'user';
ob_start();
?>

<div class="main-content">
    <div class="content-header">
        <h1>درآمدهای محتوا</h1>
        <a href="<?= url('/content') ?>" class="btn btn-outline">
            <i class="material-icons">arrow_forward</i>
            بازگشت به محتوا
        </a>
    </div>

    <!-- آمار -->
    <div class="stats-row">
        <div class="stat-card stat-green">
            <div class="stat-icon"><i class="material-icons">payments</i></div>
            <div class="stat-info">
                <span class="stat-label">پرداخت‌شده</span>
                <span class="stat-value"><?= number_format((float)$totalPaid, 0) ?> <small>تومان</small></span>
            </div>
        </div>
        <div class="stat-card stat-orange">
            <div class="stat-icon"><i class="material-icons">schedule</i></div>
            <div class="stat-info">
                <span class="stat-label">در انتظار پرداخت</span>
                <span class="stat-value"><?= number_format((float)$totalPending, 0) ?> <small>تومان</small></span>
            </div>
        </div>
    </div>

    <?php if (empty($revenues)): ?>
    <div class="empty-state-card">
        <i class="material-icons">videocam_off</i>
        <h3>درآمدی ثبت نشده</h3>
        <p>پس از تأیید محتوا و کسب بازدید، درآمد شما اینجا نمایش داده می‌شود.</p>
    </div>
    <?php else: ?>
    <div class="table-card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>محتوا</th>
                        <th>مقدار</th>
                        <th>نوع</th>
                        <th>تاریخ</th>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revenues as $r): ?>
                    <tr>
                        <td><?= (int)$r->id ?></td>
                        <td>
                            <?php if (!empty($r->content_id)): ?>
                                <a href="<?= url('/content/' . (int)$r->content_id) ?>">
                                    محتوا #<?= (int)$r->content_id ?>
                                </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><strong><?= number_format((float)$r->amount, 0) ?></strong> تومان</td>
                        <td><?= e($r->type ?? '—') ?></td>
                        <td><?= to_jalali($r->created_at) ?></td>
                        <td>
                            <?php
                            $stMap = [
                                'pending' => ['label' => 'در انتظار', 'class' => 'badge-warning'],
                                'paid'    => ['label' => 'پرداخت شده','class' => 'badge-success'],
                                'failed'  => ['label' => 'ناموفق',    'class' => 'badge-danger'],
                            ];
                            $st = $stMap[$r->status ?? ''] ?? ['label' => e($r->status ?? '—'), 'class' => 'badge-secondary'];
                            ?>
                            <span class="badge <?= e($st['class']) ?>"><?= e($st['label']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= e($i) ?>" class="pagination-btn <?= $i === $currentPage ? 'active' : '' ?>"><?= e($i) ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.stats-row { display:flex; gap:20px; margin-bottom:25px; flex-wrap:wrap; }
.stat-card { display:flex; align-items:center; gap:15px; background:white; border-radius:12px; padding:20px 25px; box-shadow:0 2px 8px rgba(0,0,0,0.06); flex:1; min-width:220px; }
.stat-card.stat-green { border-right:4px solid #22c55e; }
.stat-card.stat-orange { border-right:4px solid #f97316; }
.stat-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; background:#f5f5f5; }
.stat-label { display:block; font-size:12px; color:#999; margin-bottom:4px; }
.stat-value { font-size:20px; font-weight:700; color:#333; }
.stat-value small { font-size:12px; font-weight:400; color:#999; }
.empty-state-card { background:white; border-radius:12px; padding:60px 30px; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
.empty-state-card .material-icons { font-size:64px; color:#ccc; }
.empty-state-card h3 { margin:15px 0 8px; color:#555; }
.empty-state-card p { color:#999; }
.pagination-wrapper { display:flex; gap:6px; padding:16px 20px; justify-content:center; }
.pagination-btn { padding:6px 12px; border-radius:6px; background:#f5f5f5; color:#333; text-decoration:none; font-size:13px; }
.pagination-btn.active { background:#4fc3f7; color:white; }
</style>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>