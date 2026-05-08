<?php
$title  = 'آپلود مدارک احراز هویت';
$layout = 'user';
ob_start();

$errors = $errors ?? [];
?>

<div class="kyc-wrap">

    <!-- HEADER -->
    <div class="kyc-hero">
        <div class="kyc-hero__left">
            <div class="kyc-hero__icon">
                <i class="material-icons">upload_file</i>
            </div>
            <div>
                <h1 class="kyc-hero__title">آپلود مدارک</h1>
                <p class="kyc-hero__sub">ارسال مدارک برای تأیید هویت</p>
            </div>
        </div>
        <a href="<?= url('/kyc') ?>" class="kyc-back-btn">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
    </div>

    <?php if (!($canSubmit ?? true)): ?>

    <!-- ── BLOCKED ─────────────────────────────────────── -->
    <div class="kyc-blocked">
        <div class="kyc-blocked__icon"><i class="material-icons">lock</i></div>
        <h3>امکان ثبت درخواست نیست</h3>
        <p><?= e($error ?? 'شما در حال حاضر مجاز به ثبت درخواست احراز هویت نیستید.') ?></p>
        <a href="<?= url('/kyc') ?>" class="kyc-back-btn" style="margin-top:16px">
            <i class="material-icons">arrow_forward</i>
            بازگشت به صفحه اصلی
        </a>
    </div>

    <?php else: ?>

    <div class="kyc-upload-layout">

        <!-- FORM -->
        <div class="kyc-form-card">
            <div class="kyc-form-card__header">
                <i class="material-icons">assignment_ind</i>
                اطلاعات هویتی
            </div>

            <!-- Warning -->
            <div class="kyc-warn-banner">
                <i class="material-icons">warning_amber</i>
                <span>
                    <strong>توجه:</strong> اطلاعات باید دقیقاً مطابق مدارک هویتی شما باشد.
                    ارسال مدارک جعلی منجر به <strong>مسدود شدن دائمی</strong> حساب و پیگرد قانونی می‌شود.
                </span>
            </div>

            <form method="POST" action="<?= url('/kyc/submit') ?>"
                  enctype="multipart/form-data" id="kycForm" class="kyc-form">
                <?= csrf_field() ?>

                <!-- کد ملی -->
                <div class="kyc-field <?= !empty($errors['national_code']) ? 'kyc-field--error' : '' ?>">
                    <label class="kyc-label" for="national_code">
                        کد ملی <span class="kyc-req">*</span>
                    </label>
                    <input type="text" name="national_code" id="national_code"
                           class="kyc-input" dir="ltr" inputmode="numeric"
                           maxlength="10" pattern="[0-9]{10}"
                           placeholder="۱۰ رقم کد ملی" required>
                    <?php if (!empty($errors['national_code'])): ?>
                    <span class="kyc-field-err"><?= e($errors['national_code'][0]) ?></span>
                    <?php endif; ?>
                    <div class="kyc-input-progress" id="ncProgress"></div>
                </div>

                <!-- تاریخ تولد -->
                <div class="kyc-field <?= !empty($errors['birth_date']) ? 'kyc-field--error' : '' ?>">
                    <label class="kyc-label" for="birth_date">
                        تاریخ تولد <span class="kyc-req">*</span>
                    </label>
                    <input type="date" name="birth_date" id="birth_date"
                           class="kyc-input"
                           max="<?= date('Y-m-d', strtotime('-18 years')) ?>"
                           min="<?= date('Y-m-d', strtotime('-100 years')) ?>"
                           required>
                    <?php if (!empty($errors['birth_date'])): ?>
                    <span class="kyc-field-err"><?= e($errors['birth_date'][0]) ?></span>
                    <?php endif; ?>
                    <small class="kyc-hint">باید حداقل ۱۸ سال داشته باشید</small>
                </div>

                <!-- آپلود تصویر -->
                <div class="kyc-field <?= !empty($errors['verification_image']) ? 'kyc-field--error' : '' ?>">
                    <label class="kyc-label">
                        تصویر احراز هویت <span class="kyc-req">*</span>
                    </label>

                    <!-- Drop Zone -->
                    <label class="kyc-drop-zone" id="dropZone" for="verificationImage">
                        <div class="kyc-drop-zone__content" id="dropContent">
                            <div class="kyc-drop-zone__icon">
                                <i class="material-icons">cloud_upload</i>
                            </div>
                            <div class="kyc-drop-zone__text">
                                <strong>کلیک کنید یا فایل را اینجا بکشید</strong>
                                <small>JPG یا PNG — حداکثر ۵ مگابایت</small>
                            </div>
                        </div>
                        <!-- Preview placeholder -->
                        <div class="kyc-drop-zone__preview" id="imgPreview" style="display:none">
                            <img id="previewImg" src="" alt="پیش‌نمایش">
                            <div class="kyc-drop-zone__overlay">
                                <i class="material-icons">edit</i>
                                تغییر تصویر
                            </div>
                        </div>
                        <input type="file" id="verificationImage" name="verification_image"
                               accept="image/jpeg,image/jpg,image/png"
                               class="kyc-file-input" required>
                    </label>

                    <?php if (!empty($errors['verification_image'])): ?>
                    <span class="kyc-field-err"><?= e($errors['verification_image'][0]) ?></span>
                    <?php endif; ?>

                    <!-- Checklist after upload -->
                    <div class="kyc-checklist" id="checklist" style="display:none">
                        <div class="kyc-checklist__title">
                            <i class="material-icons">checklist</i>
                            قبل از ارسال بررسی کنید:
                        </div>
                        <label class="kyc-check-item">
                            <input type="checkbox" class="kyc-check-input" id="chk1">
                            <span>صورت شما واضح و بدون سایه است</span>
                        </label>
                        <label class="kyc-check-item">
                            <input type="checkbox" class="kyc-check-input" id="chk2">
                            <span>متن کارت ملی کاملاً خوانا است</span>
                        </label>
                        <label class="kyc-check-item">
                            <input type="checkbox" class="kyc-check-input" id="chk3">
                            <span>نوشته برگه دست‌نوشته خوانا است</span>
                        </label>
                        <label class="kyc-check-item">
                            <input type="checkbox" class="kyc-check-input" id="chk4">
                            <span>تصویر بدون فیلتر یا ویرایش است</span>
                        </label>
                    </div>
                </div>

                <!-- تأییدیه -->
                <div class="kyc-field">
                    <label class="kyc-confirm-box" for="confirmCheck">
                        <input type="checkbox" id="confirmCheck" required class="kyc-check-input">
                        <span>
                            تأیید می‌کنم که تمام اطلاعات وارد‌شده <strong>صحیح و مطابق مدارک هویتی</strong> من است
                            و تصویر ارسالی توسط خودم و در <strong>زمان حال</strong> گرفته شده است.
                        </span>
                    </label>
                </div>

                <!-- Submit -->
                <div class="kyc-form-actions">
                    <button type="submit" id="submitBtn" class="kyc-submit-btn" disabled>
                        <i class="material-icons">send</i>
                        ارسال درخواست احراز هویت
                    </button>
                </div>

            </form>
        </div>

        <!-- GUIDE SIDEBAR -->
        <aside class="kyc-guide-side">
            <div class="kyc-guide-side__header">
                <i class="material-icons">info_outline</i>
                چه تصویری ارسال کنم؟
            </div>
            <div class="kyc-sample-card kyc-sample-card--ok">
                <div class="kyc-sample-card__label kyc-sample-card__label--ok">
                    <i class="material-icons">check_circle</i>
                    نمونه صحیح
                </div>
                <div class="kyc-sample-illustration">
                    <div class="kyc-sample-face">
                        <i class="material-icons">face</i>
                    </div>
                    <div class="kyc-sample-items">
                        <div class="kyc-sample-card-box">
                            <i class="material-icons">credit_card</i>
                            <span>کارت ملی</span>
                        </div>
                        <div class="kyc-sample-note">
                            <i class="material-icons">description</i>
                            <span><?= e($appName) ?> - <?= e($todayJalali) ?></span>
                        </div>
                    </div>
                </div>
                <ul class="kyc-guide-list">
                    <li><i class="material-icons">check</i><span>صورت واضح</span></li>
                    <li><i class="material-icons">check</i><span>کارت ملی خوانا</span></li>
                    <li><i class="material-icons">check</i><span>برگه با متن صحیح</span></li>
                    <li><i class="material-icons">check</i><span>نور مناسب</span></li>
                </ul>
            </div>
            <div class="kyc-sample-card kyc-sample-card--no">
                <div class="kyc-sample-card__label kyc-sample-card__label--no">
                    <i class="material-icons">cancel</i>
                    موارد غیرمجاز
                </div>
                <ul class="kyc-guide-list kyc-guide-list--no">
                    <li><i class="material-icons">close</i><span>تصویر تار یا تاریک</span></li>
                    <li><i class="material-icons">close</i><span>فیلتر یا ویرایش‌شده</span></li>
                    <li><i class="material-icons">close</i><span>بدون کارت ملی</span></li>
                    <li><i class="material-icons">close</i><span>مدارک متعلق به دیگری</span></li>
                </ul>
            </div>
        </aside>

    </div>

    <?php endif; ?>
</div>

<script>
(function () {
    const fileInput   = document.getElementById('verificationImage');
    const dropZone    = document.getElementById('dropZone');
    const dropContent = document.getElementById('dropContent');
    const imgPreview  = document.getElementById('imgPreview');
    const previewImg  = document.getElementById('previewImg');
    const checklist   = document.getElementById('checklist');
    const submitBtn   = document.getElementById('submitBtn');
    const confirmCheck= document.getElementById('confirmCheck');
    const ncInput     = document.getElementById('national_code');
    const ncProgress  = document.getElementById('ncProgress');

    // ── فقط اعداد در کد ملی ──────────────────────────────
    ncInput?.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
        const len = this.value.length;
        if (ncProgress) {
            ncProgress.style.width = (len / 10 * 100) + '%';
            ncProgress.style.background = len === 10 ? 'var(--kyc-green)' : 'var(--kyc-gold)';
        }
        updateSubmitBtn();
    });

    // ── پیش‌نمایش تصویر ────────────────────────────────
    function handleFile(file) {
        if (!file) return;

        if (file.size > 5 * 1024 * 1024) {
            notyf.error('حجم فایل نباید بیشتر از ۵ مگابایت باشد');
            fileInput.value = '';
            return;
        }

        if (!['image/jpeg', 'image/jpg', 'image/png'].includes(file.type)) {
            notyf.error('فقط فرمت JPG و PNG مجاز است');
            fileInput.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            previewImg.src = e.target.result;
            dropContent.style.display = 'none';
            imgPreview.style.display  = 'flex';
            checklist.style.display   = 'block';
            updateSubmitBtn();
        };
        reader.readAsDataURL(file);
    }

    fileInput?.addEventListener('change', e => handleFile(e.target.files[0]));

    // Drag & Drop
    dropZone?.addEventListener('dragover', e => {
        e.preventDefault();
        dropZone.classList.add('kyc-drop-zone--dragover');
    });
    dropZone?.addEventListener('dragleave', () => dropZone.classList.remove('kyc-drop-zone--dragover'));
    dropZone?.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('kyc-drop-zone--dragover');
        const file = e.dataTransfer.files[0];
        if (file) {
            // انتقال فایل به input
            const dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
            handleFile(file);
        }
    });

    // ── فعال/غیرفعال دکمه ──────────────────────────────
    function updateSubmitBtn() {
        const hasFile    = fileInput?.files?.length > 0;
        const hasConfirm = confirmCheck?.checked;
        const hasNc      = ncInput?.value?.length === 10;
        const allChecks  = checklist?.style.display !== 'none'
            ? ['chk1','chk2','chk3','chk4'].every(id => document.getElementById(id)?.checked)
            : true;

        if (submitBtn) {
            submitBtn.disabled = !(hasFile && hasConfirm && hasNc && allChecks);
        }
    }

    confirmCheck?.addEventListener('change', updateSubmitBtn);
    document.querySelectorAll('.kyc-check-input').forEach(el => el.addEventListener('change', updateSubmitBtn));

    // ── جلوگیری از ارسال دوباره ────────────────────────
    document.getElementById('kycForm')?.addEventListener('submit', function () {
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="material-icons" style="animation:spin .8s linear infinite">refresh</i> در حال ارسال...';
        }
    });

    const style = document.createElement('style');
    style.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
    document.head.appendChild(style);
})();
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>
