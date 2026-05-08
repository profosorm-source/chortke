<?php
$pageTitle = 'قالب‌های نوتیفیکیشن';
ob_start();
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h5 class="mb-0"><i class="fas fa-file-alt"></i> قالب‌های نوتیفیکیشن</h5>
    <a href="<?= url('/admin/notifications/send') ?>" class="btn btn-sm btn-primary">
        <i class="fas fa-paper-plane"></i> ارسال اعلان
    </a>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    قالب‌های پیش‌فرض در کد تعریف شده‌اند. می‌توانید آن‌ها را از اینجا override کنید.
    متغیرهای هر قالب مشخص‌اند — تنها همان متغیرها قابل استفاده‌اند.
</div>

<div class="row g-4">
<?php foreach ($templates as $key => $tpl): ?>
<div class="col-md-6">
    <div class="card h-100 <?= $tpl['has_override'] ? 'border-warning' : '' ?>">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div>
                <code class="text-primary"><?= e($key) ?></code>
                <?php if ($tpl['has_override']): ?>
                    <span class="badge bg-warning text-dark ms-2">Override فعال</span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-1">
                <button class="btn btn-xs btn-outline-primary edit-btn"
                        data-key="<?= e($key) ?>"
                        data-title="<?= e(e($tpl['override_title'] ?? $tpl['default_title'])) ?>"
                        data-message="<?= e(e($tpl['override_message'] ?? $tpl['default_message'])) ?>"
                        data-vars='<?= json_encode($tpl['variables'], JSON_UNESCAPED_UNICODE) ?>'>
                    <i class="fas fa-edit"></i> ویرایش
                </button>
                <?php if ($tpl['has_override']): ?>
                <button class="btn btn-xs btn-outline-danger reset-btn"
                        data-key="<?= e($key) ?>">
                    <i class="fas fa-undo"></i> بازگشت
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <!-- پیش‌فرض -->
            <div class="mb-2">
                <div class="small text-muted mb-1">عنوان <?= $tpl['has_override'] ? '(پیش‌فرض)' : '' ?>:</div>
                <div class="fw-semibold small"><?= e($tpl['default_title']) ?></div>
            </div>
            <div class="mb-3">
                <div class="small text-muted mb-1">متن <?= $tpl['has_override'] ? '(پیش‌فرض)' : '' ?>:</div>
                <div class="text-muted small"><?= e($tpl['default_message']) ?></div>
            </div>

            <!-- override فعال -->
            <?php if ($tpl['has_override']): ?>
            <hr>
            <div class="mb-2">
                <div class="small text-warning mb-1">عنوان (override):</div>
                <div class="fw-semibold small text-warning"><?= e($tpl['override_title']) ?></div>
            </div>
            <div class="mb-2">
                <div class="small text-warning mb-1">متن (override):</div>
                <div class="text-warning small"><?= e($tpl['override_message']) ?></div>
            </div>
            <?php endif; ?>

            <!-- متغیرها -->
            <?php if (!empty($tpl['variables'])): ?>
            <div class="mt-2">
                <div class="small text-muted mb-1">متغیرهای قابل استفاده:</div>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($tpl['variables'] as $var => $desc): ?>
                    <span class="badge bg-light text-dark border" title="<?= e($desc) ?>">
                        <code>{{<?= e($var) ?>}}</code>
                        <span class="text-muted ms-1"><?= e($desc) ?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
                <div class="small text-muted">این قالب متغیر dynamic ندارد.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Modal ویرایش -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ویرایش قالب: <code id="modalTemplateKey">—</code></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editKey">

                <div class="mb-3">
                    <label class="form-label">متغیرهای مجاز این قالب:</label>
                    <div id="modalVarBadges" class="d-flex flex-wrap gap-1 mb-2"></div>
                    <small class="text-muted">روی هر متغیر کلیک کنید تا در فیلد فعال درج شود.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">عنوان</label>
                    <input type="text" class="form-control" id="editTitle" maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="form-label">متن پیام</label>
                    <textarea class="form-control" id="editMessage" rows="4"></textarea>
                </div>

                <div id="editError" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-primary" id="saveTemplateBtn">
                    <i class="fas fa-save"></i> ذخیره Override
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const notyf   = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });
    const modal   = new bootstrap.Modal(document.getElementById('editModal'));
    const CSRF    = '<?= csrf_token() ?>';

    let activeVars = {};
    let lastFocused = null;

    // ── باز کردن modal ویرایش ─────────────────────────────────────────────────
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const key     = this.dataset.key;
            const title   = this.dataset.title;
            const message = this.dataset.message;
            activeVars    = JSON.parse(this.dataset.vars || '{}');

            document.getElementById('editKey').value      = key;
            document.getElementById('modalTemplateKey').textContent = key;
            document.getElementById('editTitle').value    = title;
            document.getElementById('editMessage').value  = message;
            document.getElementById('editError').classList.add('d-none');

            // badge متغیرها
            const badgeContainer = document.getElementById('modalVarBadges');
            badgeContainer.innerHTML = '';
            Object.entries(activeVars).forEach(([v, desc]) => {
                const span = document.createElement('span');
                span.className    = 'badge bg-primary cursor-pointer var-badge';
                span.style.cursor = 'pointer';
                span.innerHTML    = `{{${v}}} <small class="opacity-75">${desc}</small>`;
                span.dataset.var  = `{{${v}}}`;
                badgeContainer.appendChild(span);
            });

            modal.show();
        });
    });

    // ── درج متغیر با کلیک ──────────────────────────────────────────────────
    document.getElementById('modalVarBadges').addEventListener('click', function (e) {
        const badge = e.target.closest('.var-badge');
        if (!badge) return;
        const varText = badge.dataset.var;

        const target = lastFocused || document.getElementById('editMessage');
        const start  = target.selectionStart ?? target.value.length;
        const end    = target.selectionEnd   ?? target.value.length;
        target.value = target.value.slice(0, start) + varText + target.value.slice(end);
        target.focus();
        target.setSelectionRange(start + varText.length, start + varText.length);
    });

    ['editTitle', 'editMessage'].forEach(id => {
        document.getElementById(id)?.addEventListener('focus', function () {
            lastFocused = this;
        });
    });

    // ── ذخیره override ──────────────────────────────────────────────────────
    document.getElementById('saveTemplateBtn').addEventListener('click', async function () {
        const key     = document.getElementById('editKey').value;
        const title   = document.getElementById('editTitle').value.trim();
        const message = document.getElementById('editMessage').value.trim();
        const errEl   = document.getElementById('editError');

        if (!title || !message) {
            errEl.textContent = 'عنوان و متن الزامی هستند.';
            errEl.classList.remove('d-none');
            return;
        }

        try {
            const res  = await fetch('<?= url('/admin/notifications/templates/save') ?>', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body:    JSON.stringify({ template_key: key, title, message }),
            });
            const data = await res.json();

            if (data.success) {
                modal.hide();
                notyf.success('قالب ذخیره شد');
                setTimeout(() => location.reload(), 800);
            } else {
                errEl.textContent = data.error || 'خطا در ذخیره‌سازی';
                errEl.classList.remove('d-none');
            }
        } catch {
            errEl.textContent = 'خطا در ارتباط با سرور';
            errEl.classList.remove('d-none');
        }
    });

    // ── بازگشت به پیش‌فرض ────────────────────────────────────────────────────
    document.querySelectorAll('.reset-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const key = this.dataset.key;
            confirmAction({
                type: 'warning',
                title: 'بازگشت به قالب پیش‌فرض',
                text:  `Override قالب «${key}» حذف می‌شود. آیا مطمئن هستید؟`,
                confirmButtonText: 'بله، بازگردان',
                onConfirm: async () => {
                    const res  = await fetch('<?= url('/admin/notifications/templates/delete') ?>', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                        body:    JSON.stringify({ template_key: key }),
                    });
                    const data = await res.json();
                    if (data.success) {
                        notyf.success(data.message);
                        setTimeout(() => location.reload(), 800);
                    }
                }
            });
        });
    });
});
</script>
<?php
$content = ob_get_clean();
require VIEW_PATH . '/layouts/admin.php';
?>
