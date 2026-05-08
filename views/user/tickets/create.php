<?php
$pageTitle = 'ایجاد تیکت جدید';
ob_start();

use App\Enums\TicketPriority;
?>

<div class="tkt-wrap">

    <!-- BACK + HEADER -->
    <div class="tkt-show-header">
        <a href="<?= url('/tickets') ?>" class="tkt-back-btn">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
        <div class="tkt-show-title">
            <h1>ایجاد تیکت جدید</h1>
        </div>
    </div>

    <div class="tkt-create-layout">

        <!-- GUIDE CARD -->
        <aside class="tkt-guide-card">
            <div class="tkt-guide-card__header">
                <i class="material-icons">lightbulb_outline</i>
                راهنما
            </div>
            <ul class="tkt-guide-list">
                <li>
                    <i class="material-icons">payments</i>
                    <span>برای مشکلات <strong>مالی و پرداخت</strong> حتماً تیکت ثبت کنید.</span>
                </li>
                <li>
                    <i class="material-icons">chat</i>
                    <span>برای سوالات ساده از <strong>چت آنلاین</strong> استفاده کنید.</span>
                </li>
                <li>
                    <i class="material-icons">edit_note</i>
                    <span>موضوع را <strong>واضح و کامل</strong> بنویسید.</span>
                </li>
                <li>
                    <i class="material-icons">attach_file</i>
                    <span>در صورت نیاز فایل پیوست ارسال کنید.</span>
                </li>
                <li>
                    <i class="material-icons">schedule</i>
                    <span>زمان پاسخ معمولاً <strong>کمتر از ۲۴ ساعت</strong> است.</span>
                </li>
            </ul>
        </aside>

        <!-- FORM CARD -->
        <div class="tkt-form-card">
            <div class="tkt-form-card__header">
                <i class="material-icons">add_circle_outline</i>
                اطلاعات تیکت
            </div>
            <form method="POST" action="<?= url('/tickets/store') ?>" enctype="multipart/form-data" class="tkt-form">
                <?= csrf_field() ?>

                <div class="tkt-field">
                    <label class="tkt-label">دسته‌بندی <span class="tkt-req">*</span></label>
                    <select name="category_id" class="tkt-select" required>
                        <option value="">انتخاب کنید...</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e($category->id) ?>"
                                <?= old('category_id') == $category->id ? 'selected' : '' ?>>
                                <?= e($category->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tkt-field">
                    <label class="tkt-label">موضوع <span class="tkt-req">*</span></label>
                    <input type="text" name="subject" class="tkt-input"
                           value="<?= old('subject') ?>"
                           placeholder="مشکل یا سوال خود را به‌طور خلاصه بنویسید"
                           required>
                </div>

                <div class="tkt-field">
                    <label class="tkt-label">اولویت <span class="tkt-req">*</span></label>
                    <div class="tkt-priority-grid">
                        <?php
                        $prLabels = ['low' => 'کم', 'normal' => 'معمولی', 'high' => 'زیاد', 'urgent' => 'فوری'];
                        $prIcons  = ['low' => 'arrow_downward', 'normal' => 'remove', 'high' => 'arrow_upward', 'urgent' => 'priority_high'];
                        foreach (TicketPriority::all() as $p):
                            $selected = old('priority', 'normal') === $p;
                        ?>
                        <label class="tkt-priority-opt tkt-priority-opt--<?= $p ?> <?= $selected ? 'active' : '' ?>">
                            <input type="radio" name="priority" value="<?= e($p) ?>"
                                   <?= $selected ? 'checked' : '' ?> required>
                            <i class="material-icons"><?= $prIcons[$p] ?? 'remove' ?></i>
                            <?= $prLabels[$p] ?? $p ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="tkt-field">
                    <label class="tkt-label">شرح مشکل <span class="tkt-req">*</span></label>
                    <textarea name="message" class="tkt-textarea tkt-textarea--lg" rows="7"
                              placeholder="جزئیات مشکل، مراحل بروز خطا، و هر اطلاعات مفید دیگری را بنویسید..." required><?= old('message') ?></textarea>
                </div>

                <div class="tkt-field">
                    <label class="tkt-label">فایل پیوست <span class="tkt-optional">(اختیاری)</span></label>
                    <label class="tkt-drop-zone" id="dropZone" for="attachFiles">
                        <i class="material-icons">cloud_upload</i>
                        <span>فایل را اینجا بکشید یا کلیک کنید</span>
                        <small>JPG، PNG، PDF — حداکثر ۵ مگابایت</small>
                        <input type="file" id="attachFiles" name="attachments[]"
                               multiple accept="image/*,.pdf" class="tkt-file-input">
                    </label>
                    <div class="tkt-file-preview" id="filePreview"></div>
                </div>

                <div class="tkt-form-actions">
                    <button type="submit" class="tkt-submit-btn">
                        <i class="material-icons">send</i>
                        ارسال تیکت
                    </button>
                    <a href="<?= url('/tickets') ?>" class="tkt-cancel-btn">
                        <i class="material-icons">close</i>
                        انصراف
                    </a>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
// اینتراکتیو بودن انتخاب اولویت
document.querySelectorAll('.tkt-priority-opt input').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.tkt-priority-opt').forEach(el => el.classList.remove('active'));
        radio.closest('.tkt-priority-opt').classList.add('active');
    });
});

// نمایش پیش‌نمایش فایل
document.getElementById('attachFiles')?.addEventListener('change', function() {
    const preview = document.getElementById('filePreview');
    preview.innerHTML = '';
    Array.from(this.files).forEach(file => {
        const chip = document.createElement('span');
        chip.className = 'tkt-file-chip';
        chip.innerHTML = `<i class="material-icons">insert_drive_file</i>${file.name}`;
        preview.appendChild(chip);
    });
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
