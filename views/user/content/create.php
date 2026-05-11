<?php

$title = 'ارسال محتوای جدید';
$layout = 'user';
ob_start();

// Helper functions
function safe_escape($value) {
    return e((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<link rel="stylesheet" href="<?= asset('assets/css/views/user-content.css') ?>">

<div class="content-header">
    <h4>
        <i class="material-icons">cloud_upload</i> 
        ارسال محتوای جدید
    </h4>
    <a href="<?= url('/content') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons">arrow_back</i> 
        بازگشت
    </a>
</div>

<!-- راهنما -->
<div class="alert alert-info" role="alert">
    <i class="material-icons" aria-hidden="true">info</i>
    <div>
        <strong>نحوه کار:</strong>
        <ol style="margin: 10px 0 0; padding-right: 20px;">
            <li>ویدیوی خود را در آپارات، یوتیوب یا یکی از <strong>آپلود سنترها</strong> (مثل مدیافایر، پیکوفایل و ...) آپلود کنید</li>
            <li>لینک ویدیو را در فرم زیر وارد کنید</li>
            <li>پس از تأیید مدیریت، ویدیو در کانال‌های مجموعه منتشر خواهد شد</li>
            <li>درآمد شما از <strong>ماه سوم</strong> به بعد محاسبه و پرداخت می‌شود</li>
            <li>هرچه فعال‌تر باشید، سهم درآمد شما بیشتر خواهد بود</li>
        </ol>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5>فرم ارسال محتوا</h5>
    </div>
    <div class="card-body">
        <form id="contentForm" method="POST" novalidate>
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" id="csrf_token" value="<?= csrf_token() ?>">
            
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="platform">
                        پلتفرم 
                        <span class="text-danger" aria-label="الزامی">*</span>
                    </label>
                    <select name="platform" 
                            id="platform" 
                            class="form-control" 
                            required 
                            aria-required="true"
                            aria-describedby="platform-help">
                        <option value="">انتخاب کنید...</option>
                        <option value="aparat">آپارات (ویدیوی منتشر شده)</option>
                        <option value="youtube">یوتیوب (ویدیوی منتشر شده)</option>
                        <option value="upload_center">آپلود سنتر (لینک دانلود فایل خام جهت آپلود ما)</option>
                    </select>
                    <small id="platform-help" class="form-text text-muted">
                        ویدیو باید قبلاً در پلتفرم مورد نظر آپلود شده باشد.
                    </small>
                    <div class="invalid-feedback" id="platform-error"></div>
                </div>
                
                <div class="form-group col-md-6">
                    <label for="category">دسته‌بندی</label>
                    <select name="category" 
                            id="category" 
                            class="form-control"
                            aria-describedby="category-help">
                        <option value="">انتخاب کنید...</option>
                        <option value="comedy">طنز و کمدی</option>
                        <option value="education">آموزشی</option>
                        <option value="tech">تکنولوژی</option>
                        <option value="cooking">آشپزی</option>
                        <option value="music">موسیقی</option>
                        <option value="vlog">ولاگ</option>
                        <option value="gaming">بازی</option>
                        <option value="art">هنر و خلاقیت</option>
                        <option value="sport">ورزشی</option>
                        <option value="other">سایر</option>
                    </select>
                    <small id="category-help" class="form-text text-muted">
                        دسته‌بندی به بهبود نمایش محتوا کمک می‌کند.
                    </small>
                </div>
            </div>

            <div class="form-group">
                <label for="video_url">
                    لینک ویدیو 
                    <span class="text-danger" aria-label="الزامی">*</span>
                </label>
                <input type="url" 
                       name="video_url" 
                       id="video_url" 
                       class="form-control" 
                       dir="ltr"
                       placeholder="https://www.aparat.com/v/..." 
                       required
                       aria-required="true"
                       aria-describedby="url-hint"
                       maxlength="500"
                       pattern="https?://.+">
                <small class="form-text text-muted" id="url-hint">
                    لینک کامل ویدیو را از مرورگر کپی کنید.
                </small>
                <div class="invalid-feedback" id="video_url-error"></div>
            </div>

            <div class="form-group">
                <label for="title">
                    عنوان ویدیو 
                    <span class="text-danger" aria-label="الزامی">*</span>
                </label>
                <input type="text" 
                       name="title" 
                       id="title" 
                       class="form-control" 
                       maxlength="255"
                       minlength="5"
                       placeholder="عنوان ویدیوی خود را وارد کنید" 
                       required
                       aria-required="true"
                       aria-describedby="title-help">
                <small id="title-help" class="form-text text-muted">
                    <span id="title-count">0</span>/255 کاراکتر
                </small>
                <div class="invalid-feedback" id="title-error"></div>
            </div>

            <div class="form-group">
                <label for="description">توضیحات</label>
                <textarea name="description" 
                          id="description" 
                          class="form-control" 
                          rows="4" 
                          maxlength="2000"
                          placeholder="توضیح مختصری درباره ویدیو بنویسید..."
                          aria-describedby="desc-help"></textarea>
                <small id="desc-help" class="form-text text-muted">
                    <span id="descCount">0</span>/2000 کاراکتر
                </small>
            </div>

            <!-- تعهدنامه -->
            <div class="agreement-box">
                <h6>
                    <i class="material-icons" aria-hidden="true">gavel</i> 
                    تعهدنامه همکاری محتوایی
                </h6>
                <div class="agreement-text" 
                     tabindex="0" 
                     role="region" 
                     aria-label="متن تعهدنامه">
                    <?= nl2br(safe_escape($agreementText ?? '')) ?>
                </div>
                <div class="form-check mt-3">
                    <input type="checkbox" 
                           name="agreement_accepted" 
                           id="agreement_accepted" 
                           value="1" 
                           class="form-check-input" 
                           required
                           aria-required="true"
                           aria-describedby="agreement-help">
                    <label for="agreement_accepted" class="form-check-label">
                        <strong>تمامی شرایط فوق را مطالعه کردم و می‌پذیرم.</strong>
                    </label>
                    <div class="invalid-feedback" id="agreement-error">
                        پذیرش تعهدنامه برای ارسال محتوا الزامی است.
                    </div>
                </div>
            </div>

            <div class="form-actions mt-4">
                <button type="submit" 
                        id="submitBtn" 
                        class="btn btn-primary" 
                        disabled
                        aria-busy="false">
                    <i class="material-icons" aria-hidden="true">send</i> 
                    ارسال محتوا
                </button>
                <button type="reset" 
                        class="btn btn-outline-secondary"
                        id="resetBtn">
                    <i class="material-icons" aria-hidden="true">refresh</i>
                    پاک کردن فرم
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    'use strict';
    
    // DOM Elements
    const form = document.getElementById('contentForm');
    const submitBtn = document.getElementById('submitBtn');
    const agreementCheck = document.getElementById('agreement_accepted');
    const descField = document.getElementById('description');
    const titleField = document.getElementById('title');
    const descCount = document.getElementById('descCount');
    const titleCount = document.getElementById('title-count');
    const platformSelect = document.getElementById('platform');
    const videoUrlField = document.getElementById('video_url');
    const urlHint = document.getElementById('url-hint');
    
    // URL Patterns
    const urlPatterns = {
        'aparat': {
            pattern: /^https?:\/\/(www\.)?aparat\.com\/v\//i,
            hint: 'مثال: https://www.aparat.com/v/abcdef',
            placeholder: 'https://www.aparat.com/v/...'
        },
        'youtube': {
            pattern: /^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)/i,
            hint: 'مثال: https://www.youtube.com/watch?v=abcdef یا https://youtu.be/abcdef',
            placeholder: 'https://www.youtube.com/watch?v=...'
        },
        'upload_center': {
            pattern: /^https?:\/\/.+/i,
            hint: 'لینک دانلود مستقیم فایل ویدیو (از مدیافایر، پیکوفایل، گوگل درایو و غیره)',
            placeholder: 'https://...'
        }
    };
    
    // Enable submit button when agreement is checked
    agreementCheck.addEventListener('change', function() {
        submitBtn.disabled = !this.checked;
        validateField(agreementCheck);
    });
    
    // Character counter for description
    descField.addEventListener('input', function() {
        const length = this.value.length;
        descCount.textContent = length;
        
        if (length > 2000) {
            descCount.classList.add('text-danger');
        } else {
            descCount.classList.remove('text-danger');
        }
    });
    
    // Character counter for title
    titleField.addEventListener('input', function() {
        const length = this.value.length;
        titleCount.textContent = length;
        
        if (length < 5 || length > 255) {
            titleCount.classList.add('text-danger');
        } else {
            titleCount.classList.remove('text-danger');
        }
        
        validateField(titleField);
    });
    
    // Platform change handler
    platformSelect.addEventListener('change', function() {
        const platform = this.value;
        
        if (platform && urlPatterns[platform]) {
            urlHint.textContent = urlPatterns[platform].hint;
            videoUrlField.placeholder = urlPatterns[platform].placeholder;
        } else {
            urlHint.textContent = 'لینک کامل ویدیو را وارد کنید.';
            videoUrlField.placeholder = 'https://...';
        }
        
        validateField(platformSelect);
        
        // Re-validate URL if it has value
        if (videoUrlField.value) {
            validateField(videoUrlField);
        }
    });
    
    // URL validation on blur
    videoUrlField.addEventListener('blur', function() {
        validateField(videoUrlField);
    });
    
    // Real-time validation
    const fields = [platformSelect, videoUrlField, titleField, agreementCheck];
    fields.forEach(field => {
        field.addEventListener('blur', () => validateField(field));
    });
    
    // Field validation
    function validateField(field) {
        const value = field.value.trim();
        let isValid = true;
        let errorMessage = '';
        
        if (field === platformSelect) {
            if (!value) {
                isValid = false;
                errorMessage = 'انتخاب پلتفرم الزامی است.';
            }
        } else if (field === videoUrlField) {
            if (!value) {
                isValid = false;
                errorMessage = 'لینک ویدیو الزامی است.';
            } else {
                const platform = platformSelect.value;
                
                // Basic URL validation
                try {
                    new URL(value);
                } catch {
                    isValid = false;
                    errorMessage = 'فرمت لینک نامعتبر است.';
                }
                
                // Platform-specific validation
                if (isValid && platform && urlPatterns[platform]) {
                    if (!urlPatterns[platform].pattern.test(value)) {
                        isValid = false;
                        errorMessage = `لینک وارد شده مربوط به ${platform === 'aparat' ? 'آپارات' : 'یوتیوب'} نیست.`;
                    }
                }
            }
        } else if (field === titleField) {
            if (!value) {
                isValid = false;
                errorMessage = 'عنوان ویدیو الزامی است.';
            } else if (value.length < 5) {
                isValid = false;
                errorMessage = 'عنوان باید حداقل 5 کاراکتر باشد.';
            } else if (value.length > 255) {
                isValid = false;
                errorMessage = 'عنوان نباید بیشتر از 255 کاراکتر باشد.';
            }
        } else if (field === agreementCheck) {
            if (!field.checked) {
                isValid = false;
                errorMessage = 'پذیرش تعهدنامه الزامی است.';
            }
        }
        
        // Update UI
        const errorDiv = document.getElementById(field.id + '-error');
        
        if (!isValid) {
            field.classList.add('is-invalid');
            field.classList.remove('is-valid');
            if (errorDiv) {
                errorDiv.textContent = errorMessage;
            }
        } else {
            field.classList.remove('is-invalid');
            field.classList.add('is-valid');
            if (errorDiv) {
                errorDiv.textContent = '';
            }
        }
        
        return isValid;
    }
    
    // Form validation
    function validateForm() {
        let isValid = true;
        
        fields.forEach(field => {
            if (!validateField(field)) {
                isValid = false;
            }
        });
        
        return isValid;
    }
    
    // Form submission
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Validate form
        if (!validateForm()) {
            if (window.notyf) {
                notyf.error('لطفاً تمام فیلدهای الزامی را به درستی پر کنید.');
            }
            return;
        }
        
        // Disable button and show loading
        submitBtn.disabled = true;
        submitBtn.setAttribute('aria-busy', 'true');
        submitBtn.innerHTML = '<i class="material-icons spin">refresh</i> در حال ارسال...';
        
        // Prepare data
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            data[key] = value;
        });
        
        try {
            const response = await fetch('<?= url('/content/store') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (result.success) {
                if (window.notyf) {
                    notyf.success(result.message || 'محتوا با موفقیت ثبت شد.');
                }
                
                // Redirect after delay
                setTimeout(() => {
                    window.location.href = '<?= url('/content') ?>';
                }, 1500);
            } else {
                if (window.notyf) {
                    notyf.error(result.message || 'خطایی رخ داد.');
                }
                
                // Show field errors if any
                if (result.errors) {
                    Object.keys(result.errors).forEach(fieldName => {
                        const field = document.getElementById(fieldName);
                        const errorDiv = document.getElementById(fieldName + '-error');
                        
                        if (field && errorDiv) {
                            field.classList.add('is-invalid');
                            errorDiv.textContent = result.errors[fieldName][0] || result.errors[fieldName];
                        }
                    });
                }
                
                // Re-enable button
                submitBtn.disabled = !agreementCheck.checked;
                submitBtn.setAttribute('aria-busy', 'false');
                submitBtn.innerHTML = '<i class="material-icons">send</i> ارسال محتوا';
            }
        } catch (error) {
            console.error('Submit error:', error);
            
            if (window.notyf) {
                notyf.error('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
            }
            
            // Re-enable button
            submitBtn.disabled = !agreementCheck.checked;
            submitBtn.setAttribute('aria-busy', 'false');
            submitBtn.innerHTML = '<i class="material-icons">send</i> ارسال محتوا';
        }
    });
    
    // Reset form
    document.getElementById('resetBtn').addEventListener('click', function() {
        // Clear validation classes
        fields.forEach(field => {
            field.classList.remove('is-valid', 'is-invalid');
        });
        
        // Reset counters
        descCount.textContent = '0';
        titleCount.textContent = '0';
        
        // Disable submit button
        submitBtn.disabled = true;
    });
    
    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        // Focus first field
        platformSelect.focus();
    });
})();
</script>

<style>
.spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.is-invalid {
    border-color: #dc3545 !important;
}

.is-valid {
    border-color: #28a745 !important;
}

.invalid-feedback {
    display: none;
    color: #dc3545;
    font-size: 0.875rem;
    margin-top: 0.25rem;
}

.is-invalid ~ .invalid-feedback {
    display: block;
}

.text-danger {
    color: #dc3545 !important;
}

.agreement-box {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
}

.agreement-text {
    max-height: 300px;
    overflow-y: auto;
    background: white;
    padding: 15px;
    border-radius: 4px;
    margin: 15px 0;
    line-height: 1.8;
    font-size: 14px;
}

.agreement-text:focus {
    outline: 2px solid #4fc3f7;
    outline-offset: 2px;
}
</style>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>