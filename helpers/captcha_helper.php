<?php

if (!function_exists('captcha')) {
    /**
     * تولید CAPTCHA
     */
    function captcha(string $type = null): array
    {
        $service = app(\App\Services\CaptchaService::class);
        return $service->generate($type);
    }
}

if (!function_exists('captcha_field')) {
    /**
     * فیلد CAPTCHA برای فرم
     */
    function captcha_field(string $type = null): string
    {
        $service = app(\App\Services\CaptchaService::class);
        
        if (!$service->isEnabled()) {
            return '';
        }
        
        $captcha = $service->generate($type);
        
        ob_start();
        
        // ID یکتا برای هر فیلد کپچا در صفحه
        $captchaId = 'captcha_' . bin2hex(random_bytes(4));

        switch ($captcha['type']) {
            case 'math':
                $refreshUrl = url('captcha/refresh?type=math');
                ?>
                <div class="form-group captcha-container" id="<?= $captchaId ?>_wrap"
                     data-refresh-url="<?= e($refreshUrl) ?>"
                     data-type="math">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <label style="margin:0;">حل کنید:</label>
                        <span id="<?= e($captchaId) ?>_question" style="font-weight:700;font-size:16px;direction:ltr;">
                            <?= e($captcha['question']) ?>
                        </span>
                        <button type="button"
                                data-captcha-refresh="<?= e($captchaId) ?>"
                                style="background:none;border:none;cursor:pointer;color:#0066cc;padding:2px 6px;font-size:20px;line-height:1;"
                                title="تعویض کپچا">&#x21BB;</button>
                    </div>
                    <input type="hidden" name="captcha_token" id="<?= e($captchaId )?>_token" value="<?= e($captcha['token']) ?>">
                    <input type="text" name="captcha_response" class="form-control" required autocomplete="off" placeholder="جواب را وارد کنید">
                </div>
                <?php
                break;

            case 'image':
                $image    = (string)($captcha['image'] ?? '');
                $filename = basename(ltrim($image, '/'));
                $imgUrl   = url('file/view/captcha/' . $filename) . '?t=' . time();
                $refreshUrl = url('captcha/refresh?type=image');
                ?>
                <div class="form-group captcha-container" id="<?= e($captchaId) ?>_wrap"
                     data-refresh-url="<?= e($refreshUrl) ?>"
                     data-type="image">
                    <label>کد تصویر را وارد کنید:</label>
                    <div style="display:flex;align-items:center;gap:10px;margin:6px 0;">
                        <img id="<?= e($captchaId) ?>_img"
                             src="<?= e($imgUrl) ?>"
                             alt="CAPTCHA"
                             style="border:1px solid #ddd;border-radius:4px;height:50px;">
                        <button type="button"
                                data-captcha-refresh="<?= e($captchaId) ?>"
                                style="background:none;border:none;cursor:pointer;color:#0066cc;padding:2px 6px;font-size:20px;line-height:1;"
                                title="تعویض کپچا">&#x21BB;</button>
                    </div>
                    <input type="hidden" name="captcha_token" id="<?= e($captchaId )?>_token" value="<?= e($captcha['token']) ?>">
                    <input type="text" name="captcha_response" class="form-control" required autocomplete="off" placeholder="کد تصویر">
                </div>
                <?php
                break;
            
            case 'recaptcha_v2':
                ?>
                <div class="form-group captcha-container">
                    <div class="g-recaptcha" data-sitekey="<?= e($captcha['site_key']) ?>"></div>
                </div>
                <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                <?php
                break;
            
            case 'recaptcha_v3':
                ?>
                <input type="hidden" id="recaptcha_response" name="recaptcha_response">
                <script src="https://www.google.com/recaptcha/api.js?render=<?= e($captcha['site_key']) ?>"></script>
                <script>
                grecaptcha.ready(function() {
                    grecaptcha.execute('<?= e($captcha['site_key']) ?>', {action: 'submit'}).then(function(token) {
                        document.getElementById('recaptcha_response').value = token;
                    });
                });
                </script>
                <?php
                break;
            
            case 'behavioral':
                ?>
                <input type="hidden" name="captcha_token" value="<?= e($captcha['token']) ?>">
                <div class="alert alert-info" style="font-size: 13px;">
                    <i class="material-icons" style="vertical-align: middle;">touch_app</i>
                    <?= e($captcha['instruction']) ?>
                </div>
                <?php
                break;
        }
        
        return ob_get_clean();
    }
}

if (!function_exists('verify_captcha')) {
    /**
     * بررسی CAPTCHA
     */
    function verify_captcha(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        $service = app(\App\Services\CaptchaService::class);

        if (!$service->isEnabled()) {
            return true;
        }

        $token = $_POST['captcha_token'] ?? '';
        $response = $_POST['captcha_response'] ?? '';
        $recaptchaResponse = $_POST['g-recaptcha-response'] ?? $_POST['recaptcha_response'] ?? null;
        $behavioralState = $_POST['behavioral_state'] ?? '';

        $token = is_string($token) ? trim($token) : '';
        $response = is_string($response) ? trim($response) : '';
        $recaptchaResponse = is_string($recaptchaResponse) ? trim($recaptchaResponse) : null;
        $behavioralState = is_string($behavioralState) ? trim($behavioralState) : '';

        return $service->verify($token, $response, $recaptchaResponse, $behavioralState);
    }
}


if (!function_exists('captcha_refresh_script')) {
    function captcha_refresh_script(): string
    {
        return <<<'JSCRIPT'
<script>
(function() {
    if (window._captchaRefreshInit) return;
    window._captchaRefreshInit = true;

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-captcha-refresh]');
        if (!btn) return;
        e.preventDefault();

        var id  = btn.getAttribute('data-captcha-refresh');
        var wrap = document.getElementById(id + '_wrap');
        if (!wrap) return;

        var url  = wrap.getAttribute('data-refresh-url');
        var type = wrap.getAttribute('data-type');
        if (!url) return;

        btn.style.opacity = '0.4';
        btn.disabled = true;

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (type === 'math') {
                var q = document.getElementById(id + '_question');
                if (q && data.question) q.textContent = data.question;
                var t = document.getElementById(id + '_token');
                if (t && data.token) t.value = data.token;
            } else {
                var img = document.getElementById(id + '_img');
                if (img && data.image_url) img.src = data.image_url + '?t=' + Date.now();
                var t = document.getElementById(id + '_token');
                if (t && data.token) t.value = data.token;
            }
            var inp = wrap.querySelector('input[name="captcha_response"]');
            if (inp) inp.value = '';
        })
        .catch(function() {})
        .finally(function() {
            btn.style.opacity = '1';
            btn.disabled = false;
        });
    });
})();
</script>
JSCRIPT;
    }
}