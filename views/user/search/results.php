<?php
// views/user/search/results.php
$title = 'نتایج جستجو';
?>
<?php
$title = $title ?? 'نتایج جستجو';
ob_start();
?>

<div class="container-fluid py-4">

    <div class="mb-4">
        <h4 class="mb-1">🔍 نتایج جستجو</h4>
        <?php if (!empty($query)): ?>
        <p class="text-muted mb-3">نتایج برای: <strong><?= e($query) ?></strong></p>
        <?php endif; ?>

        <!-- سرچ باکس -->
        <form method="GET" action="<?= url('/search') ?>">
            <div class="input-group" style="max-width:500px">
                <input type="text" name="q" class="form-control" value="<?= e($query) ?>"
                       placeholder="جستجو در تراکنش‌ها، تیکت‌ها، کمپین‌ها..." autofocus>
                <button class="btn btn-primary">جستجو</button>
            </div>
        </form>
    </div>

    <?php if (empty($query)): ?>
    <div class="card border-0 bg-light">
        <div class="card-body text-center py-5">
            <span class="material-icons fs-1 text-muted">search</span>
            <p class="text-muted mt-2">عبارت جستجو را وارد کنید</p>
        </div>
    </div>

    <?php elseif (empty($results) || array_sum(array_map(fn($v) => is_array($v) ? count($v) : 0, $results)) === 0): ?>
    <div class="card border-0 bg-light">
        <div class="card-body text-center py-5">
            <span class="material-icons fs-1 text-muted">search_off</span>
            <h5 class="mt-2 text-muted">نتیجه‌ای یافت نشد</h5>
            <p class="text-muted">برای «<?= e($query) ?>» چیزی پیدا نکردیم</p>
        </div>
    </div>

    <?php else: ?>

    <div class="row g-3">

        <!-- تراکنش‌ها -->
        <?php if (!empty($results['transactions'])): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0">💳 تراکنش‌ها
                        <span class="badge bg-secondary ms-1"><?= count($results['transactions']) ?></span>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm small mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th><th>نوع</th><th>مبلغ</th><th>ارز</th><th>وضعیت</th><th>تاریخ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results['transactions'] as $t): $t = (object)$t; ?>
                                <tr>
                                    <td class="text-muted"><?= (int)$t->id ?></td>
                                    <td><?= e($t->type) ?></td>
                                    <td class="fw-semibold"><?= number_format((float)$t->amount) ?></td>
                                    <td><?= e($t->currency) ?></td>
                                    <td><span class="badge bg-secondary"><?= e($t->status) ?></span></td>
                                    <td class="text-muted"><?= to_jalali($t->created_at) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- تیکت‌ها -->
        <?php if (!empty($results['tickets'])): ?>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0">🎫 تیکت‌ها
                        <span class="badge bg-secondary ms-1"><?= count($results['tickets']) ?></span>
                    </h6>
                </div>
                <div class="list-group list-group-flush small">
                    <?php foreach ($results['tickets'] as $t): $t = (object)$t; ?>
                    <a href="<?= url('/tickets/' . (int)$t->id) ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between">
                            <span><?= e(mb_substr($t->subject, 0, 40)) ?></span>
                            <span class="badge bg-<?= $t->status==='open'?'success':'secondary' ?>">
                                <?= $t->status==='open'?'باز':'بسته' ?>
                            </span>
                        </div>
                        <small class="text-muted"><?= to_jalali($t->created_at) ?></small>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- کمپین‌ها -->
        <?php if (!empty($results['ads'])): ?>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0">📢 کمپین‌های تبلیغاتی
                        <span class="badge bg-secondary ms-1"><?= count($results['ads']) ?></span>
                    </h6>
                </div>
                <div class="list-group list-group-flush small">
                    <?php foreach ($results['ads'] as $a): $a = (object)$a; ?>
                    <a href="<?= url('/ad-tasks/' . (int)$a->id) ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between">
                            <span><?= e(mb_substr($a->title, 0, 40)) ?></span>
                            <span class="badge bg-primary"><?= e($a->platform) ?></span>
                        </div>
                        <small class="text-muted"><?= e($a->task_type) ?> · <?= to_jalali($a->created_at) ?></small>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- تسک‌ها -->
        <?php if (!empty($results['tasks'])): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0">✅ تسک‌های انجام‌شده
                        <span class="badge bg-secondary ms-1"><?= count($results['tasks']) ?></span>
                    </h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm small mb-0">
                        <thead class="table-light">
                            <tr><th>آگهی</th><th>پاداش</th><th>وضعیت</th><th>تاریخ</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results['tasks'] as $t): $t = (object)$t; ?>
                            <tr>
                                <td><?= e(mb_substr($t->ad_title ?? '', 0, 40)) ?></td>
                                <td><?= number_format((float)$t->reward_amount) ?></td>
                                <td>
                                    <?php
                                    $sc = ['approved'=>'success','rejected'=>'danger','submitted'=>'warning'];
                                    $sl = ['approved'=>'تأیید','rejected'=>'رد','submitted'=>'در انتظار'];
                                    ?>
                                    <span class="badge bg-<?= $sc[$t->status]??'secondary' ?>">
                                        <?= $sl[$t->status]??$t->status ?>
                                    </span>
                                </td>
                                <td class="text-muted"><?= to_jalali($t->created_at) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

</div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>