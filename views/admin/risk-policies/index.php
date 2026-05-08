<?php /** @var array $policies */ ?>
<!doctype html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <title>تنظیمات ریسک</title>
    <style>
        body { font-family: tahoma, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
        th { background: #f7f7f7; }
        .msg { margin: 8px 0; color: #333; }
        input, select { width: 100%; padding: 6px; }
        button { padding: 6px 12px; }
    </style>
</head>
<body>
    <h2>تنظیمات سیاست‌های ریسک</h2>

    <?php if ($success = flash('success')): ?>
        <div class="msg"><?php echo e($success); ?></div>
    <?php endif; ?>
    <?php if ($error = flash('error')): ?>
        <div class="msg"><?php echo e($error); ?></div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>دامنه</th>
                <th>کلید</th>
                <th>مقدار</th>
                <th>نوع</th>
                <th>توضیح</th>
                <th>ذخیره</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($policies as $p): ?>
            <tr>
                <form method="post" action="/admin/risk-policies/update">
                    <td>
                        <input type="text" name="domain" value="<?php echo e($p['domain']); ?>" readonly>
                    </td>
                    <td>
                        <input type="text" name="key_name" value="<?php echo e($p['key_name']); ?>" readonly>
                    </td>
                    <td>
                        <input type="text" name="value" value="<?php echo e((string)$p['value']); ?>">
                    </td>
                    <td>
                        <select name="value_type">
                            <?php foreach (['int','float','bool','string','json'] as $t): ?>
                                <option value="<?php echo e($t); ?>" <?php echo ($p['value_type'] === $t ? 'selected' : ''); ?>>
                                    <?php echo e($t); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="description" value="<?php echo e((string)$p['description']); ?>">
                    </td>
                    <td>
                        <button type="submit">ذخیره</button>
                    </td>
                </form>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>