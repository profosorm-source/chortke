<?php
$title = 'نشست‌های فعال';
$layout = 'user';
ob_start();
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">نشست‌های فعال</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-4">
                    <i class="material-icons" style="font-size: 18px; vertical-align: middle;">info</i>
                    در این صفحه می‌توانید تمام دستگاه‌هایی که با حساب کاربری شما وارد شده‌اند را مشاهده و مدیریت کنید.
                </div>

                <?php if (empty($sessions)): ?>
                    <div class="text-center py-5">
                        <i class="material-icons text-muted" style="font-size: 60px;">devices</i>
                        <p class="text-muted mt-3">هیچ نشست فعالی یافت نشد</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($sessions as $session): ?>
                            <div class="col-md-6">
                                <div class="card border <?= $session->session_id === $currentSessionId ? 'border-primary' : '' ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="material-icons text-primary me-2" style="font-size: 40px;">
                                                    <?php
                                                    echo match($session->device_type) {
                                                        'mobile' => 'smartphone',
                                                        'tablet' => 'tablet',
                                                        default => 'computer'
                                                    };
                                                    ?>
                                                </i>
                                                <div>
                                                    <h6 class="mb-0">
                                                        <?= e($session->browser) ?> - <?= e($session->os) ?>
                                                    </h6>
                                                    <small class="text-muted"><?= e($session->device_type) ?></small>
                                                </div>
                                            </div>

                                            <?php if ($session->session_id === $currentSessionId): ?>
                                                <span class="badge bg-success">نشست فعلی</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="mb-2">
                                            <small class="text-muted">
                                                <i class="material-icons" style="font-size: 14px; vertical-align: middle;">place</i>
                                                آدرس IP: <code><?= e($session->ip_address) ?></code>
                                            </small>
                                        </div>

                                        <div class="mb-3">
                                            <small class="text-muted">
                                                <i class="material-icons" style="font-size: 14px; vertical-align: middle;">schedule</i>
                                                آخرین فعالیت: 
                                                <?= to_jalali(\date('Y/m/d H:i', \strtotime($session->last_activity))) ?>
                                            </small>
                                        </div>

                                        <?php if ($session->session_id !== $currentSessionId): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-terminate" 
                                                    data-id="<?= e($session->id) ?>">
                                                <i class="material-icons" style="font-size: 16px;">logout</i>
                                                خروج از این دستگاه
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="alert alert-warning mt-4 mb-0">
                    <h6 class="mb-2">نکات امنیتی:</h6>
                    <ul class="mb-0 small">
                        <li>اگر نشستی را که متعلق به شما نیست مشاهده کردید، فوراً آن را خارج کنید</li>
                        <li>رمز عبور خود را تغییر دهید</li>
                        <li>همیشه از مرورگرها و دستگاه‌های امن استفاده کنید</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// حذف نشست (SweetAlert)
document.querySelectorAll('.btn-terminate').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id = this.dataset.id;

        const result = await Swal.fire({
            title: 'خروج از دستگاه',
            text: 'آیا مطمئنید که می‌خواهید از این دستگاه خارج شوید؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'بله، خارج شود',
            cancelButtonText: 'انصراف'
        });

        if (!result.isConfirmed) return;

        try {
            const response = await fetch(`<?= url('/sessions/terminate/') ?>${id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                }
            });

            const data = await response.json();

            if (data.success) {
                notyf.success(data.message);
                setTimeout(() => location.reload(), 1000);
            } else {
                notyf.error(data.message);
            }
        } catch (error) {
            notyf.error('خطا در ارتباط با سرور');
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>