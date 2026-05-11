<?php
$title = 'ثبت تبلیغ جدید';
$layout = 'user';
ob_start();
?>

<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
        --glass-bg: rgba(255, 255, 255, 0.95);
    }

    /* Step indicator wrapper */
    .wizard-steps {
        display: flex;
        justify-content: space-between;
        margin-bottom: 40px;
        position: relative;
    }
    .wizard-steps::before {
        content: '';
        position: absolute;
        top: 20px;
        left: 10%;
        right: 10%;
        height: 2px;
        background: #e5e7eb;
        z-index: 0;
    }
    .step-node {
        position: relative;
        z-index: 1;
        background: white;
        border: 2px solid #e5e7eb;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        color: #6b7280;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .step-node.active {
        border-color: #6366f1;
        background: var(--primary-gradient);
        color: white;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        transform: scale(1.1);
    }
    .step-node.completed {
        background: #10b981;
        border-color: #10b981;
        color: white;
    }

    /* Main Type Selector Grid */
    .type-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    .type-card {
        background: white;
        border: 2px solid #f3f4f6;
        border-radius: 16px;
        padding: 25px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .type-card:hover {
        transform: translateY(-5px);
        border-color: #6366f1;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
    }
    .type-card.selected {
        border-color: #6366f1;
        background: #f5f3ff;
    }
    .type-card i {
        font-size: 42px;
        background: var(--primary-gradient);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin-bottom: 15px;
    }

    /* Smooth Dynamic Section Transition */
    .wizard-panel {
        display: none;
        opacity: 0;
        transform: translateY(20px);
        transition: all 0.5s ease;
    }
    .wizard-panel.active {
        display: block;
        opacity: 1;
        transform: translateY(0);
    }

    /* Live Preview Sidebar Bubble */
    .preview-pane {
        background: #111827;
        color: white;
        border-radius: 16px;
        padding: 20px;
        height: 100%;
        position: sticky;
        top: 20px;
    }
    .phone-skeleton {
        background: #1f2937;
        border-radius: 24px;
        padding: 15px;
        min-height: 300px;
        border: 4px solid #374151;
        position: relative;
    }
</style>

<div class="container py-4">
    
    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="material-icons align-middle me-1 text-primary">magic</i> ثبت تبلیغ هوشمند</h3>
            <p class="text-muted small">در چند گام کوتاه و پرسرعت، کمپین تبلیغاتی خود را راه‌اندازی کنید.</p>
        </div>
        <a href="<?= url('/ads') ?>" class="btn btn-light rounded-pill shadow-sm">
            <i class="material-icons align-middle small">list</i> بازگشت به آگهی‌ها
        </a>
    </div>

    <!-- PROGRESS BAR -->
    <div class="wizard-steps">
        <div class="step-node active" id="node-1">۱</div>
        <div class="step-node" id="node-2">۲</div>
        <div class="step-node" id="node-3">۳</div>
        <div class="step-node" id="node-4">۴</div>
    </div>

    <form id="mainWizardForm" class="row">
        <!-- FORM SIDE (Left) -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-lg p-4 rounded-4">
                
                <!-- STEP 1: CHOOSE AD TYPE -->
                <div class="wizard-panel active" id="panel-1">
                    <h4 class="mb-4 fw-bold">گام ۱: انتخاب بستر تبلیغاتی</h4>
                    <div class="type-grid">
                        <div class="type-card" onclick="selectAdType('banner')" data-desc="افزایش فوق‌العاده برندینگ از طریق نمایش گرافیکی و متحرک بنر شما در هدر، سایدبار و محتوای اصلی سایت با کلیک تضمینی.">
                            <i class="material-icons">space_dashboard</i>
                            <h6 class="fw-bold">تبلیغات بنری</h6>
                            <small class="text-muted d-block">نمایش در جایگاه‌های سایت</small>
                        </div>
                        <div class="type-card" onclick="selectAdType('notification')" data-desc="ارسال مستقیم اطلاعیه به نوار نوتیفیکیشن مرورگر یا گوشی کاربران در کسری از ثانیه، ایده‌آل برای فروش‌های فوری و آنی.">
                            <i class="material-icons">notifications_active</i>
                            <h6 class="fw-bold">پوش نوتیفیکیشن</h6>
                            <small class="text-muted d-block">شلیک پیام به گوشی کاربران</small>
                        </div>
                        <div class="type-card" onclick="selectAdType('seo')" data-desc="بهبود تضمینی رتبه در نتایج گوگل با استفاده از ترافیک ارگانیک کاربران واقعی که کلمه کلیدی شما را جستجو و روی لینک کلیک می‌کنند.">
                            <i class="material-icons">language</i>
                            <h6 class="fw-bold">بهبود سئو و بازدید</h6>
                            <small class="text-muted d-block">ورودی مستقیم از گوگل</small>
                        </div>
                        <div class="type-card" onclick="selectAdType('social_task')" data-desc="رشد ارگانیک و طبیعی شبکه‌های اجتماعی با دریافت فالوور واقعی، لایک و کامنت‌های هدفمند جهت تسخیر الگوریتم و اکسپلور.">
                            <i class="material-icons">group_add</i>
                            <h6 class="fw-bold">رشد شبکه‌های اجتماعی</h6>
                            <small class="text-muted d-block">لایک، کامنت، سابسکرایب</small>
                        </div>
                        <div class="type-card" onclick="selectAdType('adtube')" data-desc="افزایش واقعی و سریع زمان تماشا (Watch Time) و بازدید ویدیوهای یوتیوب و آپارات توسط کاربران حقیقی جهت فعال‌سازی مانیتایز.">
                            <i class="material-icons">play_circle</i>
                            <h6 class="fw-bold">تبلیغات ویدیویی</h6>
                            <small class="text-muted d-block">رشد ویو در آپارات و یوتیوب</small>
                        </div>
                        <div class="type-card" style="border-style: dashed;" onclick="window.location.href='<?= url('/influencer/ads') ?>'" data-desc="ارتباط بدون واسطه با اینفلوئنسرهای معتبر و رزرو آنی استوری یا پست اختصاصی با نظارت و سیستم آزادسازی وجه ایمن.">
                            <i class="material-icons" style="background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); -webkit-background-clip: text;">stars</i>
                            <h6 class="fw-bold">اینفلوئنسر مارکتینگ</h6>
                            <small class="text-muted d-block">خرید مستقیم استوری و پست</small>
                        </div>
                    </div>
                    <!-- ELEGANT INFO BOX -->
                    <div id="type-desc-box" class="mt-4 p-3 rounded-3 text-center border-0 shadow-sm" style="background: rgba(99, 102, 241, 0.05); color: #4f46e5; display:none; transition: all 0.3s ease; border-right: 4px solid #6366f1 !important;">
                        <i class="material-icons align-middle me-1 small">info</i>
                        <span class="desc-text small fw-medium"></span>
                    </div>
                    <input type="hidden" name="ad_type" id="selected_type_input">
                </div>

                <!-- STEP 2: DYNAMIC CONFIGURATION FIELDS -->
                <div class="wizard-panel" id="panel-2">
                    <h4 class="mb-4 fw-bold">گام ۲: پیکربندی اطلاعات تبلیغ</h4>
                    
                    <!-- Dynamic Subforms CONTAINER -->
                    <div id="dynamic-fields-container">
                        <!-- JS WILL INJECT SUBFORM HERE -->
                    </div>

                    <div class="d-flex justify-content-between mt-5">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" onclick="prevStep()">مرحله قبل</button>
                        <button type="button" class="btn btn-primary px-4 rounded-pill" onclick="nextStep()">تایید و ادامه</button>
                    </div>
                </div>

                <!-- STEP 3: BUDGET & PRICING -->
                <div class="wizard-panel" id="panel-3">
                    <h4 class="mb-4 fw-bold">گام ۳: مدیریت بودجه و پرداخت</h4>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">بودجه تبلیغ (تومان)</label>
                            <div class="input-group input-group-lg">
                                <input type="number" name="budget" id="budgetInput" class="form-control text-center border-2" placeholder="100,000" required>
                            </div>
                            <small class="text-muted">هر چقدر بودجه بالاتر باشد، گستره نمایش تبلیغ شما وسیع‌تر خواهد شد.</small>
                        </div>
                        <div class="col-md-6">
                            <div class="bg-light p-3 rounded-3 border border-dashed text-center">
                                <p class="mb-1">جمع کل با کارمزد سایت:</p>
                                <h3 class="fw-bold text-indigo" id="finalTotal">۰ تومان</h3>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mt-5">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" onclick="prevStep()">مرحله قبل</button>
                        <button type="button" class="btn btn-primary px-4 rounded-pill" onclick="nextStep()">بررسی نهایی</button>
                    </div>
                </div>

                <!-- STEP 4: REVIEW & EXECUTE -->
                <div class="wizard-panel" id="panel-4">
                    <div class="text-center mb-4">
                        <i class="material-icons text-success" style="font-size: 64px">verified</i>
                        <h4 class="fw-bold mt-2">آماده پرتاب!</h4>
                        <p class="text-muted">خلاصه اطلاعات را در پنل کنار بررسی کرده و دکمه پرداخت نهایی را فشار دهید.</p>
                    </div>

                    <div class="alert alert-warning small border-0">
                        <i class="material-icons align-middle">info</i>
                        مبلغ نهایی مستقیماً از کیف پول جاری شما کسر خواهد شد.
                    </div>

                    <div class="d-flex justify-content-between mt-5">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" onclick="prevStep()">اصلاح اطلاعات</button>
                        <button type="submit" id="finalSubmitBtn" class="btn btn-success px-5 btn-lg rounded-pill shadow">
                             پرداخت و ثبت نهایی
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- PREVIEW SIDE (Right) -->
        <div class="col-lg-4 mt-4 mt-lg-0">
            <div class="preview-pane shadow-lg">
                <h6 class="text-muted mb-3"><i class="material-icons align-middle me-1 small">visibility</i> پیش‌نمایش زنده</h6>
                
                <div class="phone-skeleton">
                    <!-- Dynamic Context Preview Logic inside skeleton -->
                    <div id="previewContent" class="d-flex flex-column justify-content-center align-items-center text-center h-100 text-white-50">
                        <i class="material-icons display-4 mb-2">touch_app</i>
                        <p>انتخاب کنید تا شبیه‌سازی شروع شود...</p>
                    </div>
                </div>

                <div class="mt-4 bg-dark bg-opacity-50 p-3 rounded-3 small border border-secondary border-opacity-25">
                    <h6 class="fw-bold mb-2 text-warning">اطلاعات فاکتور آنی:</h6>
                    <div class="d-flex justify-content-between mb-1"><span>نوع آگهی:</span> <span id="lblType" class="fw-bold text-white">-</span></div>
                    <div class="d-flex justify-content-between mb-1"><span>بودجه پایه:</span> <span id="lblBudget" class="text-white">۰ تومان</span></div>
                </div>
            </div>
        </div>

    </form>
</div>

<!-- TEMPLATE INJECTOR HELPERS (Hidden structures used by JS to render custom fields instantly) -->
<template id="tpl-banner">
    <div class="mb-3">
        <label class="form-label fw-bold">عنوان آگهی</label>
        <input type="text" name="title" class="form-control" oninput="updatePreview()" placeholder="مثال: فروشگاه لباس" required>
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">انتخاب جایگاه</label>
        <select name="placement" class="form-select" required>
            <?php foreach($placements as $p): ?>
                <option value="<?= $p['slug'] ?>"><?= e($p['title']) ?> (<?= $p['max_width'] ?>x<?= $p['max_height'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">آپلود فایل بنر</label>
        <input type="file" name="image" class="form-control">
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">لینک مقصد</label>
        <input type="url" name="target_link" class="form-control" dir="ltr" placeholder="https://mysite.com">
    </div>
</template>

<template id="tpl-notification">
    <div class="mb-3">
        <label class="form-label fw-bold">تیتر نوتیفیکیشن (کوتاه و جذاب)</label>
        <input type="text" name="title" class="form-control" oninput="updatePushPreview()" placeholder="مثال: ۵۰٪ تخفیف داغ تابستانه!" required>
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">متن پیام اصلی</label>
        <textarea name="body" class="form-control" rows="3" oninput="updatePushPreview()" placeholder="جمله دعوت به اقدام بنویسید..." required></textarea>
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">لینک هدف (هنگام کلیک روی نوتیف)</label>
        <input type="url" name="target_link" class="form-control" dir="ltr" placeholder="https://site.com/landing">
    </div>
</template>

<template id="tpl-seo">
    <div class="mb-3">
        <label class="form-label fw-bold">عنوان کمپین</label>
        <input type="text" name="title" class="form-control" required placeholder="بازدید وبلاگ">
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">URL صفحه هدف</label>
        <input type="url" name="target_link" class="form-control" dir="ltr" required placeholder="https://mysite.com/page1">
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">کلمه کلیدی سرچ (در صورت جستجوی گوگل)</label>
        <input type="text" name="keyword" class="form-control" placeholder="خرید گوشی سامسونگ">
    </div>
</template>

<template id="tpl-adtube">
    <div class="mb-3">
        <label class="form-label fw-bold">عنوان ویدیو</label>
        <input type="text" name="title" class="form-control" required placeholder="تست و بررسی گوشی X">
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">لینک ویدیو (یوتیوب یا آپارات)</label>
        <input type="url" name="target_link" class="form-control" dir="ltr" required placeholder="https://aparat.com/v/...">
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">توضیحات تکمیلی</label>
        <textarea name="description" class="form-control" rows="2" placeholder="پیامی برای بازدیدکنندگان بنویسید..."></textarea>
    </div>
</template>

<template id="tpl-social_task">
    <div class="mb-3">
        <label class="form-label fw-bold">موضوع فعالیت</label>
        <input type="text" name="title" class="form-control" required placeholder="فالوی پیج اینستاگرام">
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">نوع پلتفرم</label>
        <select name="platform" class="form-select">
            <option value="instagram">اینستاگرام</option>
            <option value="telegram">تلگرام</option>
            <option value="twitter">توییتر (X)</option>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">آیدی یا لینک صفحه</label>
        <input type="text" name="target_link" class="form-control" dir="ltr" required placeholder="@username or link">
    </div>
</template>

<script>
    let currentStep = 1;
    let selectedType = null;

    function selectAdType(type) {
        selectedType = type;
        document.getElementById('selected_type_input').value = type;
        
        // Visual highlighting
        document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
        event.currentTarget.classList.add('selected');
        
        // Set Facture Label
        document.getElementById('lblType').innerText = getPersianLabel(type);

        // Dynamic Template Injection
        const container = document.getElementById('dynamic-fields-container');
        const template = document.getElementById('tpl-' + type);
        if (template) {
            container.innerHTML = template.innerHTML;
        } else {
            // Fallback for generic social tasks
            container.innerHTML = '<div class="alert alert-secondary">فرم جزئیات ویژه این بخش در حال توسعه است.</div>';
        }

        // Auto Proceed to next step for ultimate speed vibe!
        setTimeout(() => nextStep(), 300);
    }

    function nextStep() {
        if (currentStep === 1 && !selectedType) {
            alert('لطفاً ابتدا یک نوع تبلیغ انتخاب کنید.');
            return;
        }

        document.getElementById('panel-' + currentStep).classList.remove('active');
        document.getElementById('node-' + currentStep).classList.add('completed');
        
        currentStep++;
        
        
        document.getElementById('panel-' + currentStep).classList.add('active');
        document.getElementById('node-' + currentStep).classList.add('active');
    }

    function prevStep() {
        document.getElementById('panel-' + currentStep).classList.remove('active');
        document.getElementById('node-' + currentStep).classList.remove('active');
        
        currentStep--;
        
        document.getElementById('panel-' + currentStep).classList.add('active');
        document.getElementById('node-' + (currentStep+1)).classList.remove('completed');
    }

    function updatePushPreview() {
        const phone = document.getElementById('previewContent');
        const title = document.querySelector('input[name="title"]')?.value || 'تیتر پیام شما';
        const body = document.querySelector('textarea[name="body"]')?.value || 'اینجا متن پیام نوتیفیکیشن روی گوشی دیده می‌شود.';
        
        phone.innerHTML = `
            <div class="w-100 text-start p-2" style="position:absolute; top: 20px;">
                <div style="background: rgba(255,255,255,0.9); border-radius: 12px; color: #333; padding: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.5);">
                    <div class="d-flex align-items-center mb-1">
                        <img src="/assets/img/favicon.png" width="16" class="me-2 rounded">
                        <small class="text-muted fw-bold" style="font-size: 10px;">اکنون • چرتکه</small>
                    </div>
                    <strong style="font-size: 14px;">${title}</strong>
                    <p class="mb-0 text-secondary" style="font-size: 12px; line-height: 1.4;">${body}</p>
                </div>
            </div>
        `;
    }

    document.getElementById('budgetInput')?.addEventListener('input', function(e) {
        const val = parseFloat(e.target.value) || 0;
        document.getElementById('lblBudget').innerText = val.toLocaleString() + ' تومان';
        
        // Simulate dynamic calculation
        const final = val + (val * 0.15); // Assuming 15% fee
        document.getElementById('finalTotal').innerText = final.toLocaleString() + ' تومان';
    });

    function getPersianLabel(type) {
        const map = {
            'banner': 'تبلیغات بنری',
            'notification': 'پوش نوتیفیکیشن',
            'seo': 'بهبود سئو',
            'social_task': 'سوشیال مدیا',
            'adtube': 'تبلیغات ویدیویی'
        };
        return map[type] || 'ناشناس';
    }

    // FINAL AJAX SUBMISSION
    document.getElementById('mainWizardForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('finalSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال پردازش...';

        const form = e.target;
        const formData = new FormData(form);
        
        // Convert FormData into flat Object suitable for our centralized AdSystemManager->create()
        const payload = {};
        formData.forEach((value, key) => {
            payload[key] = value;
        });

        try {
            const res = await fetch('<?= url("/ads/store") ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify(payload)
            });

            const result = await res.json();
            if (result.success) {
                alert(result.message || 'ثبت با موفقیت انجام شد.');
                window.location.href = '<?= url("/ads") ?>';
            } else {
                alert('خطا: ' + result.message);
                btn.disabled = false;
                btn.innerText = 'پرداخت و ثبت نهایی';
            }
        } catch (err) {
            alert('خطای سروری پیش آمد.');
            btn.disabled = false;
            btn.innerText = 'پرداخت و ثبت نهایی';
        }
    });

    // --- Dynamic Type Description Reveal ---
    const descBox = document.getElementById('type-desc-box');
    const descText = descBox.querySelector('.desc-text');
    document.querySelectorAll('.type-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            const desc = this.getAttribute('data-desc');
            if (desc) {
                descText.textContent = desc;
                descBox.style.display = 'block';
            }
        });
    });

</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
