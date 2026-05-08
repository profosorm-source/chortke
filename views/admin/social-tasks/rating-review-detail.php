<?php view('layouts.header', ['title' => $title]) ?>

<div class="container py-4">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <a href="<?= url('/admin/social-task-reviews') ?>" class="btn btn-outline-secondary mb-3">
                ← بازگشت
            </a>

            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="m-0"><?= h($title) ?></h5>
                </div>

                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <strong>امتیاز‌دهنده:</strong><br>
                                <?= h($review->rater_name) ?> (<?= $review->rater_type === 'executor' ? 'انجام‌دهنده' : 'تبلیغ‌دهنده' ?>)
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <strong>کاربر امتیاز‌شده:</strong><br>
                                <?= h($review->rated_name) ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <strong>تسک:</strong><br>
                        <?= h($review->execution_title) ?>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>امتیاز:</strong>
                            <div class="alert alert-warning">
                                <?php for ($i = 0; $i < $review->stars; $i++): ?>
                                    <span>★</span>
                                <?php endfor; ?>
                                <?php for ($i = $review->stars; $i < 5; $i++): ?>
                                    <span class="text-muted">★</span>
                                <?php endfor; ?>
                                (<?= $review->stars ?>/۵)
                            </div>
                        </div>
                        <div class="col-md-6">
                            <strong>تاریخ ارسال:</strong><br>
                            <?= date('Y-m-d H:i', strtotime($review->created_at)) ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <strong>نظر:</strong>
                        <div class="alert alert-light">
                            <?= nl2br(h($review->comment)) ?: '<em class="text-muted">هیچ نظری ثبت نشده</em>' ?>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <strong>وضعیت:</strong>
                            <div>
                                <span class="badge bg-warning"><?= $review->status ?></span>
                            </div>
                        </div>
                        <?php if (!empty($review->reviewed_at)): ?>
                            <div class="col-md-6">
                                <strong>بررسی‌شده توسط:</strong><br>
                                مدیر <?= date('Y-m-d H:i', strtotime($review->reviewed_at)) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($review->status === 'pending'): ?>
                        <div class="alert alert-info mb-4">
                            <strong>اقدام:</strong> این نظر برای تایید یا رد منتظر است
                        </div>

                        <form id="moderateForm" method="POST" action="<?= url('/admin/social-task-reviews/' . $review->id . '/moderate') ?>">
                            <?= csrf_field() ?>

                            <div class="d-flex gap-2">
                                <button
                                    type="submit"
                                    class="btn btn-success"
                                    onclick="document.getElementById('action').value='approve'"
                                    onclick="document.getElementById('action').value='approve'"
                                >
                                    ✓ تایید
                                </button>
                                <button
                                    type="submit"
                                    class="btn btn-danger"
                                    onclick="document.getElementById('action').value='reject'"
                                >
                                    ✕ رد
                                </button>
                            </div>

                            <input type="hidden" id="action" name="action" value="">
                        </form>
                    <?php else: ?>
                        <div class="alert alert-<?= $review->status === 'approved' ? 'success' : 'danger' ?>">
                            این نظر <?= $review->status === 'approved' ? 'تایید' : 'رد' ?> شده‌است.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php view('layouts.footer') ?>
