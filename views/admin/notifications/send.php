<?php
$pageTitle = 'ارسال اعلان';
ob_start();
?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="mb-0"><i class="fas fa-bullhorn"></i> ارسال اعلان به کاربران</h5>
        <div class="d-flex gap-2">
            <a href="<?= url('/admin/notifications/stats') ?>" class="btn btn-sm btn-outline-info">
                <i class="fas fa-chart-bar"></i> آمار
            </a>
            <a href="<?= url('/admin/notifications/templates') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-file-alt"></i> قالب‌ها
            </a>
        </div>
    </div>

    <div class="card-body">
        <form method="POST" action="<?= url('/admin/notifications/send') ?>" id="sendForm">
            <?= csrf_field() ?>

            <div class="row g-3">

                <!-- ستون چپ — مخاطب -->
                <div class="col-lg-5">
                    <div class="card bg-light border-0">
                        <div class="card-body">
                            <h6 class="mb-3 text-muted">مخاطب</h6>

                            <!-- target selector -->
                            <div class="mb-3">
                                <label class="form-label">نوع ارسال</label>
                                <div class="d-flex gap-2 flex-wrap">
                                    <input type="radio" class="btn-check" name="target" id="target_all" value="all" checked>
                                    <label class="btn btn-outline-primary btn-sm" for="target_all">
                                        <i class="fas fa-users"></i> همه کاربران
                                    </label>

                                    <input type="radio" class="btn-check" name="target" id="target_segment" value="segment">
                                    <label class="btn btn-outline-warning btn-sm" for="target_segment">
                                        <i class="fas fa-filter"></i> گروه / Segment
                                    </label>

                                    <input type="radio" class="btn-check" name="target" id="target_user" value="user">
                                    <label class="btn btn-outline-secondary btn-sm" for="target_user">
                                        <i class="fas fa-user"></i> کاربر خاص
                                    </label>
                                </div>
                            </div>

                            <!-- Segment selector -->
                            <div id="segmentSection" class="mb-3" style="display:none">
                                <label class="form-label">انتخاب Segment</label>
                                <select name="segment" class="form-select">
                                    <?php foreach ($segments as $key => $label): ?>
                                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <!-- فیلترهای custom segment -->
                                <div id="customFilters" class="mt-3" style="display:none">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label small">وضعیت کاربر</label>
                                            <select name="filters[status]" class="form-select form-select-sm">
                                                <option value="">همه</option>
                                                <option value="active">فعال</option>
                                                <option value="inactive">غیرفعال</option>
                                                <option value="banned">مسدود</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small">وضعیت KYC</label>
                                            <select name="filters[kyc_status]" class="form-select form-select-sm">
                                                <option value="">همه</option>
                                                <option value="approved">تأیید‌شده</option>
                                                <option value="pending">در انتظار</option>
                                                <option value="rejected">رد‌شده</option>
                                                <option value="none">بدون KYC</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small">سطح کاربر</label>
                                            <select name="filters[level]" class="form-select form-select-sm">
                                                <option value="">همه</option>
                                                <option value="silver">نقره</option>
                                                <option value="gold">طلا</option>
                                                <option value="vip">VIP</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small">ثبت‌نام از تاریخ</label>
                                            <input type="date" name="filters[registered_after]" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- user ID -->
                            <div id="userSection" class="mb-3" style="display:none">
                                <label class="form-label">شناسه کاربر</label>
                                <input type="number" name="user_id" class="form-control" placeholder="مثلاً ۱۲۳۴">
                            </div>

                            <!-- زمان‌بندی -->
                            <hr>
                            <div class="mb-0">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" id="enableSchedule">
                                    <label class="form-check-label" for="enableSchedule">زمان‌بندی ارسال</label>
                                </div>
                                <div id="scheduleSection" style="display:none">
                                    <input type="datetime-local" name="scheduled_at" class="form-control form-control-sm"
                                           min="<?= date('Y-m-d\TH:i') ?>">
                                    <small class="text-muted">اگر خالی بماند، فوراً ارسال می‌شود.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ستون راست — محتوا -->
                <div class="col-lg-7">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">نوع اعلان</label>
                            <select name="type" class="form-select" required>
                                <?php foreach ($notification_types as $val => $label): ?>
                                    <option value="<?= e($val) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">اولویت</label>
                            <select name="priority" class="form-select">
                                <option value="normal">عادی</option>
                                <option value="high">مهم</option>
                                <option value="urgent">فوری</option>
                                <option value="low">کم‌اهمیت</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">عنوان <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" maxlength="255" required
                                   placeholder="عنوان نوتیفیکیشن">
                            <small class="text-muted char-counter" data-target="title">۰ / ۲۵۵</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label">متن پیام <span class="text-danger">*</span></label>
                            <textarea name="message" class="form-control" rows="4" required
                                      placeholder="متن نوتیفیکیشن..."></textarea>
                            <small class="text-muted char-counter" data-target="message">۰ / ۵۰۰</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">لینک دکمه (اختیاری)</label>
                            <input type="text" name="action_url" class="form-control"
                                   placeholder="/wallet یا https://...">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">متن دکمه (اختیاری)</label>
                            <input type="text" name="action_text" class="form-control"
                                   placeholder="مثلاً: مشاهده کیف پول">
                        </div>

                        <!-- پیش‌نمایش -->
                        <div class="col-12">
                            <div class="notif-preview p-3 rounded bg-light border" id="notifPreview" style="display:none">
                                <div class="d-flex gap-3">
                                    <div class="notif-preview-icon">
                                        <i class="fas fa-bell fa-lg text-primary"></i>
                                    </div>
                                    <div>
                                        <strong id="previewTitle">—</strong>
                                        <p class="mb-1 small" id="previewMessage">—</p>
                                        <a href="#" id="previewAction" class="small text-primary" style="display:none">پیش‌نمایش لینک</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- دکمه‌ها -->
                <div class="col-12 d-flex gap-2 pt-2">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> ارسال اعلان
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="previewBtn">
                        <i class="fas fa-eye"></i> پیش‌نمایش
                    </button>
                    <a href="<?= url('/admin/notifications') ?>" class="btn btn-outline-secondary ms-auto">
                        انصراف
                    </a>
                </div>

            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const notyf = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });

    // ── target toggle ─────────────────────────────────────────────────────────
    document.querySelectorAll('input[name="target"]').forEach(radio => {
        radio.addEventListener('change', function () {
            document.getElementById('segmentSection').style.display = this.value === 'segment' ? '' : 'none';
            document.getElementById('userSection').style.display    = this.value === 'user'    ? '' : 'none';
        });
    });

    // ── custom segment toggle ─────────────────────────────────────────────────
    document.querySelector('select[name="segment"]')?.addEventListener('change', function () {
        document.getElementById('customFilters').style.display = this.value === 'custom' ? '' : 'none';
    });

    // ── schedule toggle ───────────────────────────────────────────────────────
    document.getElementById('enableSchedule')?.addEventListener('change', function () {
        document.getElementById('scheduleSection').style.display = this.checked ? '' : 'none';
        if (!this.checked) {
            document.querySelector('input[name="scheduled_at"]').value = '';
        }
    });

    // ── char counter ──────────────────────────────────────────────────────────
    document.querySelectorAll('.char-counter').forEach(counter => {
        const targetName = counter.dataset.target;
        const field = document.querySelector(`[name="${targetName}"]`);
        const max   = parseInt(field?.getAttribute('maxlength') || '500');
        if (!field) return;
        field.addEventListener('input', () => {
            counter.textContent = `${field.value.length} / ${max}`;
        });
    });

    // ── پیش‌نمایش ─────────────────────────────────────────────────────────────
    document.getElementById('previewBtn')?.addEventListener('click', function () {
        const title   = document.querySelector('[name="title"]').value;
        const message = document.querySelector('[name="message"]').value;
        const action  = document.querySelector('[name="action_text"]').value;

        const preview = document.getElementById('notifPreview');
        document.getElementById('previewTitle').textContent   = title   || 'عنوان';
        document.getElementById('previewMessage').textContent = message || 'متن پیام';
        const actionEl = document.getElementById('previewAction');
        if (action) {
            actionEl.textContent    = action;
            actionEl.style.display  = '';
        } else {
            actionEl.style.display  = 'none';
        }
        preview.style.display = '';
    });

    // ── ارسال فرم ─────────────────────────────────────────────────────────────
    document.getElementById('sendForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const form = this;

        const scheduledAt = document.querySelector('[name="scheduled_at"]')?.value || '';
        const confirmText = scheduledAt
            ? `اعلان برای زمان ${scheduledAt} زمان‌بندی می‌شود. ادامه می‌دهید؟`
            : 'اعلان فوراً ارسال می‌شود. آیا مطمئن هستید؟';

        confirmAction({
            type:               'warning',
            title:              'تأیید ارسال',
            text:               confirmText,
            confirmButtonText:  'بله، ارسال شود',
            onConfirm: () => {
                form.submit();
            }
        });
    });
});
</script>
<?php
$content = ob_get_clean();
require VIEW_PATH . '/layouts/admin.php';
?>
