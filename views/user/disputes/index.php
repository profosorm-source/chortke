<?php $title = '⚖️ مرکز حل اختلافات'; ?>
<?php include_once dirname(__DIR__, 2) . '/partials/user/header.php'; ?>

<style>
    .dispute-card {
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 1.25rem;
        transition: all 0.3s ease;
        margin-bottom: 1rem;
    }
    [data-theme="dark"] .dispute-card {
        background: rgba(30, 41, 59, 0.7);
        border-color: rgba(255, 255, 255, 0.1);
    }
    .dispute-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.05);
    }
    .status-indicator {
        width: 12px; height: 12px; border-radius: 50%; display: inline-block;
    }
    .bg-open { background-color: #3b82f6; box-shadow: 0 0 10px rgba(59, 130, 246, 0.5); }
    .bg-review { background-color: #f59e0b; box-shadow: 0 0 10px rgba(245, 158, 11, 0.5); }
    .bg-closed { background-color: #6b7280; }
</style>

<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1">⚖️ مرکز حل اختلافات و داوری</h2>
            <p class="text-muted small mb-0">پیگیری چالش‌ها، رد شدن تسک‌ها و اعتراضات مالی شما در یک محیط امن</p>
        </div>
    </div>

    <?php if (empty($disputes)): ?>
        <div class="text-center py-5 bg-light rounded-4 border border-dashed">
            <span class="material-icons text-muted mb-3" style="font-size: 60px;">verified_user</span>
            <h4 class="fw-bold">هیچ اختلافی یافت نشد!</h4>
            <p class="text-muted">شما پرونده فعال یا بسته‌شده‌ای در این بخش ندارید. بسیار عالی است!</p>
        </div>
    <?php else: ?>
        <div class="row">
            <div class="col-12">
                <?php foreach ($disputes as $d): 
                    $isClosed = in_array($d->status, $model::CLOSED_STATUSES);
                    $indicatorClass = $isClosed ? 'bg-closed' : ($d->status === 'under_review' ? 'bg-review' : 'bg-open');
                ?>
                    <div class="dispute-card p-3 p-md-4">
                        <div class="row align-items-center g-3">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="flex-shrink-0 text-center rounded-3 p-2 bg-light" style="min-width:60px;">
                                        <span class="small text-muted d-block">کد پرونده</span>
                                        <span class="fw-bold">#<?= $d->id ?></span>
                                    </div>
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="status-indicator <?= $indicatorClass ?>"></span>
                                            <span class="badge bg-light text-dark border"><?= e($model->statusLabel($d->status)) ?></span>
                                            <span class="small text-muted"><?= e($d->ref_type === 'task' ? 'مربوط به تسک' : ($d->ref_type === 'order' ? 'مربوط به سفارش' : 'عمومی')) ?></span>
                                        </div>
                                        <h6 class="fw-bold mb-0 line-clamp-1"><?= e($d->reason) ?></h6>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4 border-end-md text-md-end">
                                <div class="small text-muted">آخرین بروزرسانی:</div>
                                <div class="fw-semibold"><?= \Core\Helpers\PersianDate::toPersian($d->updated_at) ?></div>
                            </div>

                            <div class="col-md-2 text-end">
                                <a href="<?= url("/disputes/{$d->id}") ?>" class="btn btn-primary rounded-pill btn-sm px-4 py-2 w-100 shadow-sm">
                                    مشاهده پرونده <i class="material-icons small align-middle ms-1">visibility</i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include_once dirname(__DIR__, 2) . '/partials/user/footer.php'; ?>
