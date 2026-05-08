<h1>تسک های من</h1>

<p>
    <a href="/custom-tasks/ad/create">ایجاد تسک جدید</a>
</p>

<?php if (empty($tasks)): ?>
    <p>هنوز تسکی ثبت نشده است.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>عنوان</th>
                <th>وضعیت</th>
                <th>پاداش</th>
                <th>ظرفیت</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tasks as $task): ?>
            <tr>
                <td><?= e($task->title) ?></td>
                <td><?= e($task->status) ?></td>
                <td><?= e($task->reward_amount) ?></td>
                <td><?= e($task->worker_limit) ?></td>
                <td><a href="/custom-tasks/ad/<?= (int) $task->id ?>">مدیریت</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>