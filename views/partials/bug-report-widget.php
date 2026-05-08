<?php if (auth()): ?>
<!-- دکمه شناور گزارش باگ -->
<div id="bug-report-fab" title="گزارش مشکل" style="
    position: fixed; bottom: 25px; left: 25px; z-index: 9999;
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, #ffa726, #ff9800);
    color: #fff; display: flex; align-items: center; justify-content: center;
    cursor: pointer; box-shadow: 0 4px 15px rgba(255,152,0,0.4);
    transition: all 0.3s ease;
">
    <span class="material-icons" style="font-size:24px;">bug_report</span>
</div>

<!-- مودال گزارش باگ -->
<div class="modal fade" id="bugReportModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#ff9800,#ffa726);border:none;">
                <h6 class="modal-title text-white mb-0">
                    <span class="material-icons me-1" style="vertical-align:middle;font-size:20px;">bug_report</span>
                    گزارش مشکل
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="bug-report-success" style="display:none;" class="text-center py-4">
                    <span class="material-icons text-success" style="font-size:60px;">check_circle</span>
                    <h5 class="mt-3 text-success">گزارش ثبت شد!</h5>
                    <p class="text-muted">از همکاری شما متشکریم. تیم فنی بررسی خواهد کرد.</p>
                </div>

                <form id="bugReportForm" style="display:block;">
                    <input type="hidden" id="bug_page_url" value="">
                    <input type="hidden" id="bug_page_title" value="">
                    <input type="hidden" id="bug_screen_resolution" value="">
                    <input type="hidden" id="bug_device_fingerprint" value="">

                    <div class="mb-3">
                        <label class="form-label fw-bold" style="font-size:13px;">دسته‌بندی مشکل</label>
                        <select id="bug_category" class="form-select form-select-sm">
                            <option value="other">سایر</option>
                            <option value="ui_issue">مشکل ظاهری</option>
                            <option value="functional">مشکل عملکردی</option>
                            <option value="payment">مشکل پرداخت</option>
                            <option value="security">مشکل امنیتی</option>
                            <option value="performance">مشکل سرعت</option>
                            <option value="content">محتوای اشتباه</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold" style="font-size:13px;">توضیحات مشکل <span class="text-danger">*</span></label>
                        <textarea id="bug_description" class="form-control" rows="4" placeholder="لطفاً مشکل را با جزئیات توضیح دهید..." maxlength="2000" required></textarea>
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted">حداقل ۱۰ کاراکتر</small>
                            <small class="text-muted"><span id="bug_char_count">0</span>/2000</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold" style="font-size:13px;">اسکرین‌شات (اختیاری)</label>
                        <input type="file" id="bug_screenshot" class="form-control form-control-sm" accept="image/jpeg,image/png,image/gif,image/webp">
                        <small class="text-muted">حداکثر ۳ مگابایت - فقط تصویر</small>
                    </div>

                    <div class="alert alert-info py-2 mb-3" style="font-size:12px;">
                        <span class="material-icons me-1" style="font-size:14px;vertical-align:middle;">info</span>
                        گزارش‌های شما به تیم فنی ارسال می‌شود. از ارسال گزارش‌های بی‌مورد خودداری کنید.
                        <br>محدودیت: حداکثر ۲ گزارش در روز.
                    </div>

                    <div id="bug_error" class="alert alert-danger py-2 mb-3" style="display:none;font-size:12px;"></div>
                </form>
            </div>
            <div class="modal-footer" id="bug-report-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-sm btn-warning" id="submitBugReport" style="min-width:120px;">
                    <span class="material-icons me-1" style="font-size:16px;vertical-align:middle;">send</span>
                    ارسال گزارش
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    // تنظیم مقادیر مخفی
    document.getElementById('bug_page_url').value = window.location.href;
    document.getElementById('bug_page_title').value = document.title;
    document.getElementById('bug_screen_resolution').value = screen.width + 'x' + screen.height;

    // fingerprint اگر موجود بود
    if (typeof window.deviceFingerprint !== 'undefined') {
        document.getElementById('bug_device_fingerprint').value = window.deviceFingerprint;
    }

    // شمارنده کاراکتر
    var descEl = document.getElementById('bug_description');
    var countEl = document.getElementById('bug_char_count');
    descEl.addEventListener('input', function() {
        countEl.textContent = this.value.length;
    });

    // باز کردن مودال
    document.getElementById('bug-report-fab').addEventListener('click', function() {
        var modal = new bootstrap.Modal(document.getElementById('bugReportModal'));
        // ریست فرم
        document.getElementById('bugReportForm').style.display = 'block';
        document.getElementById('bug-report-success').style.display = 'none';
        document.getElementById('bug-report-footer').style.display = 'flex';
        document.getElementById('bug_error').style.display = 'none';
        document.getElementById('bug_description').value = '';
        document.getElementById('bug_screenshot').value = '';
        countEl.textContent = '0';
        modal.show();
    });

    // hover FAB
    var fab = document.getElementById('bug-report-fab');
    fab.addEventListener('mouseenter', function() {
        this.style.transform = 'scale(1.1)';
        this.style.boxShadow = '0 6px 20px rgba(255,152,0,0.5)';
    });
    fab.addEventListener('mouseleave', function() {
        this.style.transform = 'scale(1)';
        this.style.boxShadow = '0 4px 15px rgba(255,152,0,0.4)';
    });

    // ارسال گزارش
    document.getElementById('submitBugReport').addEventListener('click', function() {
        var btn = this;
        var errorEl = document.getElementById('bug_error');
        errorEl.style.display = 'none';

        var desc = descEl.value.trim();
        if (desc.length < 10) {
            errorEl.textContent = 'توضیحات باید حداقل ۱۰ کاراکتر باشد';
            errorEl.style.display = 'block';
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> در حال ارسال...';

        var formData = new FormData();
        formData.append('page_url', document.getElementById('bug_page_url').value);
        formData.append('page_title', document.getElementById('bug_page_title').value);
        formData.append('category', document.getElementById('bug_category').value);
        formData.append('description', desc);
        formData.append('screen_resolution', document.getElementById('bug_screen_resolution').value);
        formData.append('device_fingerprint', document.getElementById('bug_device_fingerprint').value);
        formData.append('_token', '<?= csrf_token() ?>');

        var screenshotFile = document.getElementById('bug_screenshot').files[0];
        if (screenshotFile) {
            formData.append('screenshot', screenshotFile);
        }

        fetch('<?= url('/bug-reports/store') ?>', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<span class="material-icons me-1" style="font-size:16px;vertical-align:middle;">send</span> ارسال گزارش';

            if (data.success) {
                document.getElementById('bugReportForm').style.display = 'none';
                document.getElementById('bug-report-success').style.display = 'block';
                document.getElementById('bug-report-footer').style.display = 'none';

                if (typeof notyf !== 'undefined') {
                    notyf.success(data.message || 'گزارش ثبت شد');
                }

                setTimeout(function() {
                    bootstrap.Modal.getInstance(document.getElementById('bugReportModal')).hide();
                }, 3000);
            } else {
                var errMsg = data.message || '';
                if (data.errors) {
                    var errArr = Object.values(data.errors);
                    errMsg = errArr.join(' | ');
                }
                errorEl.textContent = errMsg || 'خطایی رخ داد';
                errorEl.style.display = 'block';
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<span class="material-icons me-1" style="font-size:16px;vertical-align:middle;">send</span> ارسال گزارش';
            errorEl.textContent = 'خطا در ارتباط با سرور';
            errorEl.style.display = 'block';
        });
    });
})();
</script>
<?php endif; ?>