<?php
$title = $title ?? 'توکن‌های API';
 $layout='user';
 ob_start();
?>

<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">🔑 توکن‌های API</h4>
            <p class="text-muted mb-0">با توکن API می‌توانید به داده‌های حسابتان از طریق برنامه‌های خارجی دسترسی داشته باشید.</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTokenModal">
            <span class="material-icons align-middle">add</span>
            توکن جدید
        </button>
    </div>

    <!-- نمایش توکن جدید (یک بار) -->
    <?php if (!empty($newToken)): ?>
    <div class="alert alert-warning border-warning mb-4">
        <div class="d-flex align-items-start gap-3">
            <span class="material-icons text-warning fs-4">warning</span>
            <div class="flex-grow-1">
                <h6 class="alert-heading">توکن شما ساخته شد - یک بار نمایش داده می‌شود!</h6>
                <p class="mb-2 small">این توکن را در جای امنی ذخیره کنید. بعد از بستن این صفحه دیگر قابل مشاهده نیست.</p>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" id="newTokenInput"
                           value="<?= e($newToken) ?>" readonly>
                    <button class="btn btn-outline-secondary" type="button" onclick="copyToken()">
                        <span class="material-icons align-middle" style="font-size:18px">content_copy</span>
                        کپی
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- راهنمای استفاده -->
    <div class="card border-0 bg-light mb-4">
        <div class="card-body">
            <h6 class="mb-3">📖 نحوه استفاده</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <p class="small mb-1 text-muted">ارسال در Header:</p>
                    <code class="d-block bg-dark text-light p-2 rounded small">
                        Authorization: Bearer &lt;token&gt;
                    </code>
                </div>
                <div class="col-md-6">
                    <p class="small mb-1 text-muted">نمونه endpoint:</p>
                    <code class="d-block bg-dark text-light p-2 rounded small">
                        GET <?= url('/api/v1/wallet') ?>
                    </code>
                </div>
            </div>
            <p class="small text-muted mt-2 mb-0">
                مستندات کامل API:
                <a href="<?= url('/api/v1/docs') ?>" target="_blank">مشاهده مستندات</a>
            </p>
        </div>
    </div>

    <!-- لیست توکن‌ها -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom">
            <h6 class="mb-0">توکن‌های فعال</h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($tokens)): ?>
                <div class="text-center py-5 text-muted">
                    <span class="material-icons fs-1">vpn_key_off</span>
                    <p class="mt-2">هنوز توکنی نساخته‌اید.</p>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createTokenModal">
                        اولین توکن را بسازید
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>نام</th>
                                <th>دسترسی‌ها</th>
                                <th>آخرین استفاده</th>
                                <th>تعداد استفاده</th>
                                <th>انقضا</th>
                                <th>ساخته شده</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tokens as $token): ?>
                            <?php $token = (object)$token; ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold"><?= e($token->name) ?></span>
                                </td>
                                <td>
                                    <?php foreach (explode(',', $token->scopes ?? 'read') as $scope): ?>
                                        <span class="badge bg-secondary me-1"><?= e(trim($scope)) ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-muted small">
                                    <?= $token->last_used_at ? to_jalali($token->last_used_at) : 'هرگز' ?>
                                </td>
                                <td class="text-muted small">
                                    <?= number_format((int)($token->use_count ?? 0)) ?> بار
                                </td>
                                <td class="small">
                                    <?php if ($token->expires_at): ?>
                                        <?php $expired = strtotime($token->expires_at) < time(); ?>
                                        <span class="<?= $expired ? 'text-danger' : 'text-success' ?>">
                                            <?= to_jalali($token->expires_at) ?>
                                            <?= $expired ? '(منقضی)' : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">بدون انقضا</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small">
                                    <?= to_jalali($token->created_at) ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="revokeToken(<?= (int)$token->id ?>, '<?= e($token->name) ?>')">
                                        <span class="material-icons" style="font-size:16px">delete</span>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<!-- Modal: ساخت توکن جدید -->
<div class="modal fade" id="createTokenModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('/api-tokens/create') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title">ساخت توکن جدید</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">نام توکن <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control"
                               placeholder="مثلاً: اپلیکیشن موبایل" required maxlength="100">
                        <div class="form-text">نامی توصیفی که بتوانید این توکن را از سایرین تشخیص دهید.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">انقضا</label>
                        <select name="expires_in" class="form-select">
                            <option value="30">۳۰ روز</option>
                            <option value="90">۹۰ روز</option>
                            <option value="365">یک سال</option>
                            <option value="0">بدون انقضا</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                    <button type="submit" class="btn btn-primary">ساخت توکن</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function copyToken() {
    const input = document.getElementById('newTokenInput');
    input.select();
    navigator.clipboard.writeText(input.value).then(() => {
        notyf.success('توکن کپی شد');
    });
}

function revokeToken(id, name) {
    if (!confirm(`آیا مطمئنید که توکن "${name}" را باطل کنید؟\nبعد از این عمل، اتصالاتی که از این توکن استفاده می‌کنند قطع خواهند شد.`)) return;

    fetch(`<?= url('/api-tokens') ?>/${id}/revoke`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '<?= csrf_token() ?>'
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            notyf.success('توکن باطل شد');
            setTimeout(() => location.reload(), 1000);
        } else {
            notyf.error(data.message || 'خطا');
        }
    });
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
