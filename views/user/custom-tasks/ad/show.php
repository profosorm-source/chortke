<h1><?= e($task->title) ?></h1>
<p><?= nl2br(e($task->description)) ?></p>

<p>وضعیت: <?= e($task->status) ?></p>
<p>پاداش: <?= e($task->reward_amount) ?></p>
<p>ظرفیت: <?= e($task->worker_limit) ?></p>

<form method="post" action="/custom-tasks/ad/<?= (int) $task->id ?>/publish" style="display:inline-block;">
    <button type="submit">انتشار</button>
</form>

<form method="post" action="/custom-tasks/ad/<?= (int) $task->id ?>/pause" style="display:inline-block;">
    <button type="submit">توقف</button>
</form>

<form method="post" action="/custom-tasks/ad/<?= (int) $task->id ?>/cancel" style="display:inline-block;">
    <button type="submit">لغو</button>
</form>

<hr>

<h2>درخواست های انجام</h2>

<?php if (empty($submissions)): ?>
    <p>هنوز درخواستی ثبت نشده است.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>انجام دهنده</th>
                <th>وضعیت</th>
                <th>مدرک</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($submissions as $item): ?>
            <tr>
                <td><?= e($item->executor_name ?? ('#' . $item->user_id)) ?></td>
                <td><?= e($item->status) ?></td>
                <td><?= e($item->proof_text ?? '-') ?></td>
                <td>
                    <form method="post" action="/custom-tasks/ad/submissions/<?= (int) $item->id ?>/approve" style="display:inline-block;">
                        <input type="text" name="note" placeholder="یادداشت">
                        <button type="submit">تایید</button>
                    </form>

                    <form method="post" action="/custom-tasks/ad/submissions/<?= (int) $item->id ?>/reject" style="display:inline-block;">
                        <input type="text" name="reason" placeholder="دلیل رد" required>
                        <button type="submit">رد</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>