<?php
$title = 'اختلاف ها';
$layout = 'user';
ob_start();
?>

<h1>اختلاف های من</h1>

<?php if (empty($items)): ?>
    <p>اختلاف بازی ندارید.</p>
<?php else: ?>
<table>
    <thead>
    <tr>
        <th>#</th>
        <th>تسک</th>
        <th>وضعیت</th>
        <th>دلیل</th>
        <th>تاریخ</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td><?= (int)$item->id ?></td>
            <td><?= e($item->task_title ?? '-') ?></td>
            <td><?= e($item->status) ?></td>
            <td><?= e($item->reason) ?></td>
            <td><?= e($item->created_at) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>