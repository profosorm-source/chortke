<?php
/**
 * Admin - لیست درآمدهای محتوا
 */
$title = 'درآمدهای محتوا';
$layout = 'admin';
ob_start();
?>

<div class="content-header">
    <h4>درآمدهای محتوا <span class="badge bg-secondary"><?= number_format($total ?? 0) ?></span></h4>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                <option value="">همه وضعیت‌ها</option>
                <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                <option value="approved" <?= ($filters['status'] ?? '') === 'approved' ? 'selected' : '' ?>>تأیید شده</option>
                <option value="paid" <?= ($filters['status'] ?? '') === 'paid' ? 'selected' : '' ?>>پرداخت شده</option>
            </select>
            <select name="period" class="form-control form-control-sm" onchange="this.form.submit()">
                <option value="">همه دوره‌ها</option>
                <?php
                $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 
                          'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
                foreach ($months as $m):
                ?>
                <option value="<?= $m ?>" <?= ($filters['period'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>محتوا</th>
                        <th>کاربر</th>
                        <th>دوره</th>
                        <th>درآمد کل</th>
                        <th>خالص کاربر</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revenues ?? [] as $r): ?>
                    <tr>
                        <td><?= $r->id ?></td>
                        <td>
                            <a href="<?= url('/admin/content/' . $r->submission_id) ?>">
                                <?= e(mb_substr($r->title ?? '', 0, 30)) ?>
                            </a>
                        </td>
                        <td><?= e($r->user_name ?? '') ?></td>
                        <td><?= e($r->period) ?></td>
                        <td><?= number_format($r->total_revenue) ?></td>
                        <td><strong><?= number_format($r->net_user_amount) ?></strong></td>
                        <td>
                            <?php
                            $badges = [
                                'pending' => 'warning',
                                'approved' => 'info',
                                'paid' => 'success'
                            ];
                            ?>
                            <span class="badge badge-<?= $badges[$r->status] ?? 'secondary' ?>">
                                <?= $r->status ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?= url('/admin/content/' . $r->submission_id) ?>" 
                               class="btn btn-sm btn-outline-primary">مشاهده</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php $content = ob_get_clean(); include view_path('layouts.' . $layout); ?>
