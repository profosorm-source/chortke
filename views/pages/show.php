<?php
$title = $page->title ?? 'صفحه';
ob_start();
?>

<div class="static-page-hero">
    <div class="container">
        <h1><?= e($page->title) ?></h1>
    </div>
</div>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-5 static-content">
                    <?= e($page->content) ?>
                    <hr class="my-4">
                    <div class="text-muted small">آخرین بروزرسانی: <?= to_jalali($page->updated_at) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include view_path('layouts.guest');
?>
