<?php
/** @var array $user */
/** @var float $fraud_raw */
/** @var float $fraud_effective */
/** @var float $task_raw */
/** @var float $task_effective */
/** @var array $fraud_adjustments */
/** @var array $task_adjustments */
/** @var array $events */
?>
<!doctype html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <title>مدیریت امتیاز کاربر</title>
    <style>
        body { font-family: tahoma, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: right; vertical-align: top; }
        th { background: #f7f7f7; }
        .box { margin: 12px 0; padding: 10px; border: 1px solid #ddd; }
        input, select, textarea { width: 100%; padding: 6px; }
        button { padding: 6px 12px; }
    </style>
</head>
<body>
    <h2>مدیریت امتیاز کاربر: <?php echo e($user['username'] ?? ('#'.$user['id'])); ?></h2>

    <div class="box">
        <strong>Fraud Raw:</strong> <?php echo (float)$fraud_raw; ?> |
        <strong>Fraud Effective:</strong> <?php echo (float)$fraud_effective; ?> |
        <strong>Task Raw:</strong> <?php echo (float)$task_raw; ?> |
        <strong>Task Effective:</strong> <?php echo (float)$task_effective; ?>
    </div>

    <div class="box">
        <h3>ثبت اصلاح امتیاز</h3>
        <form method="post" action="/admin/users/<?php echo (int)$user['id']; ?>/scores/adjust">
            <label>دامنه</label>
            <select name="domain">
                <option value="fraud">fraud</option>
                <option value="task">task</option>
            </select>

            <label>عملیات</label>
            <select name="operation">
                <option value="add">add</option>
                <option value="subtract">subtract</option>
                <option value="set">set</option>
            </select>

            <label>مقدار</label>
            <input type="number" step="0.01" name="value" required>

            <label>انقضا (اختیاری - YYYY-MM-DD HH:MM:SS)</label>
            <input type="text" name="expires_at">

            <label>دلیل (الزامی)</label>
            <textarea name="reason" required></textarea>

            <button type="submit">ثبت اصلاح</button>
        </form>
    </div>

    <h3>Adjustments فعال - Fraud</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>عملیات</th><th>مقدار</th><th>دلیل</th><th>انقضا</th><th>اقدام</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($fraud_adjustments as $a): ?>
            <tr>
                <td><?php echo (int)$a['id']; ?></td>
                <td><?php echo e($a['operation']); ?></td>
                <td><?php echo e((string)$a['value']); ?></td>
                <td><?php echo e((string)$a['reason']); ?></td>
                <td><?php echo e((string)$a['expires_at']); ?></td>
                <td>
                    <form method="post" action="/admin/scores/adjustments/<?php echo (int)$a['id']; ?>/revoke">
                        <input type="hidden" name="reason" value="manual_revoke">
                        <button type="submit">غیرفعال‌سازی</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Adjustments فعال - Task</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>عملیات</th><th>مقدار</th><th>دلیل</th><th>انقضا</th><th>اقدام</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($task_adjustments as $a): ?>
            <tr>
                <td><?php echo (int)$a['id']; ?></td>
                <td><?php echo e($a['operation']); ?></td>
                <td><?php echo e((string)$a['value']); ?></td>
                <td><?php echo e((string)$a['reason']); ?></td>
                <td><?php echo e((string)$a['expires_at']); ?></td>
                <td>
                    <form method="post" action="/admin/scores/adjustments/<?php echo (int)$a['id']; ?>/revoke">
                        <input type="hidden" name="reason" value="manual_revoke">
                        <button type="submit">غیرفعال‌سازی</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3>رویدادهای اخیر امتیاز</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>Domain</th><th>Source</th><th>Delta</th><th>Meta</th><th>زمان</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($events as $e): ?>
            <tr>
                <td><?php echo (int)$e['id']; ?></td>
                <td><?php echo e($e['domain']); ?></td>
                <td><?php echo e($e['source']); ?></td>
                <td><?php echo e((string)$e['delta']); ?></td>
                <td><pre style="white-space: pre-wrap;"><?php echo e((string)$e['meta_json']); ?></pre></td>
                <td><?php echo e((string)$e['created_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>