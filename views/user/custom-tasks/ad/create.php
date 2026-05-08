<?php
$title = 'ایجاد تسک ';
$layout = 'user';
ob_start();
?>
<h1>ایجاد تسک جدید</h1>

<form method="post" action="/custom-tasks/ad">
    <div>
        <label>عنوان</label>
        <input type="text" name="title" required>
    </div>

    <div>
        <label>توضیحات</label>
        <textarea name="description" required></textarea>
    </div>

    <div>
        <label>دسته</label>
        <select name="category">
            <option value="signup">ثبت نام</option>
            <option value="install">نصب اپ</option>
            <option value="survey">نظرسنجی</option>
            <option value="other">سایر</option>
        </select>
    </div>

    <div>
        <label>پاداش هر انجام</label>
        <input type="number" step="0.01" name="reward_amount" required>
    </div>

    <div>
        <label>تعداد انجام</label>
        <input type="number" name="worker_limit" required>
    </div>

    <div>
        <label>تاریخ پایان</label>
        <input type="datetime-local" name="expires_at" required>
    </div>

    <div>
        <label>قوانین مدرک</label>
        <textarea name="proof_rules" required></textarea>
    </div>

    <button type="submit">ثبت تسک</button>
</form>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>