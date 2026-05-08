<?php view('layouts.header', ['title' => $title]) ?>

<div class="container py-4">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="m-0"><?= h($title) ?></h5>
                </div>
                <div class="card-body">
                    <?php if (isset($execution)): ?>
                        <div class="alert alert-info">
                            <strong>تسک:</strong> <?= h($execution->execution_title ?? '') ?><br>
                            <strong>کاربر:</strong> <?= h($target_user) ?><br>
                            <strong>نقش شما:</strong> <?= $role === 'executor' ? 'انجام‌دهنده' : 'تبلیغ‌دهنده' ?>
                        </div>

                        <form method="POST" action="<?= url('/social-tasks/' . $execution->id . '/rate') ?>">
                            <?= csrf_field() ?>

                            <!-- امتیاز ستاره‌ای -->
                            <div class="form-group mb-3">
                                <label for="stars" class="form-label"><strong>امتیاز:</strong></label>
                                <div class="star-rating" id="starRating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <input type="radio" id="star<?= $i ?>" name="stars" value="<?= $i ?>" required>
                                        <label for="star<?= $i ?>">★</label>
                                    <?php endfor; ?>
                                </div>
                            </div>

                            <!-- نظر -->
                            <div class="form-group mb-3">
                                <label for="comment" class="form-label">نظر (اختیاری)</label>
                                <textarea
                                    id="comment"
                                    name="comment"
                                    class="form-control"
                                    rows="4"
                                    placeholder="نظر خود را درباره این کاربر بنویسید..."
                                    maxlength="500"
                                ></textarea>
                                <small class="text-muted">حداکثر ۵۰۰ کاراکتر</small>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">ثبت امتیاز</button>
                                <a href="<?= url('/social-tasks/history') ?>" class="btn btn-outline-secondary">بازگشت</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">تسک یافت نشد</div>
                        <a href="<?= url('/social-tasks/history') ?>" class="btn btn-outline-secondary">بازگشت</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .star-rating {
        display: flex;
        flex-direction: row-reverse;
        justify-content: flex-end;
        gap: 0.5rem;
        font-size: 2rem;
        cursor: pointer;
    }

    .star-rating input {
        display: none;
    }

    .star-rating label {
        color: #ddd;
        cursor: pointer;
        transition: color 0.2s;
        margin: 0;
    }

    .star-rating input:checked ~ label,
    .star-rating label:hover,
    .star-rating label:hover ~ label {
        color: #ffc107;
    }
</style>

<?php view('layouts.footer') ?>
