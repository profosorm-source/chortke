<?php
$title = 'افزودن کاربر جدید';
$layout = 'admin';
ob_start();
?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">افزودن کاربر جدید</h5>
    </div>
    <div class="card-body">
        <form id="createUserForm">
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">نام کامل <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" required>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">ایمیل <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" required>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">رمز عبور <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" required>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">نقش <span class="text-danger">*</span></label>
                    <select name="role" class="form-select" required>
                        <option value="user">کاربر</option>
                        <option value="support">پشتیبان</option>
                        <option value="admin">مدیر</option>
                    </select>
                    <div class="invalid-feedback"></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">وضعیت <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="active">فعال</option>
                        <option value="inactive">غیرفعال</option>
                        <option value="suspended">تعلیق</option>
                        <option value="banned">مسدود</option>
                    </select>
                    <div class="invalid-feedback"></div>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="material-icons" style="font-size: 18px; vertical-align: middle;">save</i>
                    ذخیره
                </button>
                <a href="<?= url('/admin/users') ?>" class="btn btn-secondary">انصراف</a>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('createUserForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    // پاک کردن خطاهای قبلی
    document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));

    const formData = new FormData(this);
    const data = Object.fromEntries(formData);

    try {
        const response = await fetch('<?= url('/admin/users/store') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': data._token
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            showToast(result.message, 'success');
            if (result.redirect) {
                setTimeout(() => window.location.href = result.redirect, 1000);
            }
        } else {
            if (result.errors) {
                Object.keys(result.errors).forEach(field => {
                    const input = document.querySelector(`[name="${field}"]`);
                    if (input) {
                        input.classList.add('is-invalid');
                        input.nextElementSibling.textContent = result.errors[field][0];
                    }
                });
            }
            showToast(result.message || 'خطا در ایجاد کاربر', 'error');
        }
    } catch (error) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>