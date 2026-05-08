<?php view('layouts.header', ['title' => $title]) ?>

<div class="container py-4">
    <h2 class="mb-4"><?= h($title) ?></h2>

    <!-- Tabs for received/given -->
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="received-tab" data-bs-toggle="tab" href="#received" role="tab">
                امتیازات دریافتی (<?= count($received) ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="given-tab" data-bs-toggle="tab" href="#given" role="tab">
                امتیازات داده‌شده (<?= count($given) ?>)
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Received Ratings -->
        <div class="tab-pane fade show active" id="received" role="tabpanel">
            <?php if (!empty($received)): ?>
                <div class="row">
                    <?php foreach ($received as $rating): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="card-title">
                                                <strong><?= h($rating->rater_name) ?></strong>
                                                <small class="text-muted">(<?= h($rating->rater_type === 'executor' ? 'انجام‌دهنده' : 'تبلیغ‌دهنده') ?>)</small>
                                            </h6>
                                            <div class="stars mb-2">
                                                <?php for ($i = 0; $i < $rating->stars; $i++): ?>
                                                    <span class="text-warning">★</span>
                                                <?php endfor; ?>
                                                <?php for ($i = $rating->stars; $i < 5; $i++): ?>
                                                    <span class="text-muted">★</span>
                                                <?php endfor; ?>
                                            </div>
                                            <?php if (!empty($rating->comment)): ?>
                                                <p class="card-text small"><?= h($rating->comment) ?></p>
                                            <?php endif; ?>
                                            <small class="text-muted">
                                                <?= h($rating->execution_title ?? '') ?> • 
                                                <?= date('jS F Y', strtotime($rating->created_at)) ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info">هنوز امتیاز دریافتی ندارید</div>
            <?php endif; ?>
        </div>

        <!-- Given Ratings -->
        <div class="tab-pane fade" id="given" role="tabpanel">
            <?php if (!empty($given)): ?>
                <div class="row">
                    <?php foreach ($given as $rating): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="card-title">
                                                <strong><?= h($rating->rated_name) ?></strong>
                                                <small class="text-muted">(<?= h($rating->rater_type === 'executor' ? 'توسط شما به عنوان انجام‌دهنده' : 'توسط شما به عنوان تبلیغ‌دهنده') ?>)</small>
                                            </h6>
                                            <div class="stars mb-2">
                                                <?php for ($i = 0; $i < $rating->stars; $i++): ?>
                                                    <span class="text-warning">★</span>
                                                <?php endfor; ?>
                                                <?php for ($i = $rating->stars; $i < 5; $i++): ?>
                                                    <span class="text-muted">★</span>
                                                <?php endfor; ?>
                                            </div>
                                            <?php if (!empty($rating->comment)): ?>
                                                <p class="card-text small"><?= h($rating->comment) ?></p>
                                            <?php endif; ?>
                                            <small class="text-muted">
                                                <?= h($rating->execution_title ?? '') ?> • 
                                                <?= date('jS F Y', strtotime($rating->created_at)) ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info">هنوز امتیازی به دیگران نداده‌اید</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pagination -->
    <nav aria-label="Page navigation" class="mt-4">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="<?= url('/social-ratings/history?page=' . ($page - 1)) ?>">
                        قبلی
                    </a>
                </li>
            <?php endif; ?>

            <li class="page-item active">
                <span class="page-link"><?= $page ?></span>
            </li>

            <?php if (count($received) > 19 || count($given) > 19): ?>
                <li class="page-item">
                    <a class="page-link" href="<?= url('/social-ratings/history?page=' . ($page + 1)) ?>">
                        بعدی
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
</div>

<?php view('layouts.footer') ?>
