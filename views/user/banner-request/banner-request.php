<?php
$pageTitle = 'درخواست تبلیغ';
$layout = 'user';
ob_start();
?>

<div class="user-content">
    <div class="page-header">
        <h1>📢 درخواست‌های تبلیغ من</h1>
        <a href="/banner-request/create" class="btn btn-primary">درخواست جدید</a>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th width="100">تصویر</th>
                <th>عنوان</th>
                <th>جایگاه</th>
                <th>نوع</th>
                <th>قیمت</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($banners)): ?>
                <tr><td colspan="7" class="text-center">درخواستی ثبت نشده</td></tr>
            <?php else: ?>
                <?php foreach ($banners as $b): ?>
                    <tr>
                        <td>
                            <?php if ($b->image_path): ?>
                                <img src="<?= e($b->image_path) ?>" alt="" style="max-width:80px;max-height:50px;object-fit:cover;">
                            <?php endif; ?>
                        </td>
                        <td><?= e($b->title) ?></td>
                        <td><code><?= e($b->placement) ?></code></td>
                        <td><?= banner_type_label($b->banner_type) ?></td>
                        <td><?= $b->price > 0 ? number_format($b->price) . ' تومان' : 'رایگان' ?></td>
                        <td><?= banner_status_badge($b) ?></td>
                        <td><?= date('Y/m/d', strtotime($b->created_at)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
.user-content{max-width:1200px;margin:0 auto;padding:30px}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px}
.data-table{width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1)}
.data-table thead{background:#f8f9fa}
.data-table th,.data-table td{padding:15px;text-align:right}
.data-table tbody tr{border-bottom:1px solid #eee}
.text-center{text-align:center}
</style>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
