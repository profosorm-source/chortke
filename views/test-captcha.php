<?php
$siteKey = trim((string)config('captcha.recaptcha_site_key', ''));
if ($siteKey === '') {
    try {
        $container = \Core\Container::getInstance();
        $settingModel = $container->make(\App\Models\SystemSetting::class);
        $siteKey = trim((string)$settingModel->get('recaptcha_site_key', ''));
    } catch (\Throwable $e) {
        $siteKey = '';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تست CAPTCHA</title>
    <link href="<?= asset('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
    <style>
        :root { --primary:#667eea; --success:#18B95A; --danger:#E53E3E; }
        body { font-family:'Vazirmatn',sans-serif; background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); min-height:100vh; padding:40px 0 80px; }
        .test-container { max-width:680px; margin:0 auto; }
        .page-title { color:white; font-size:22px; font-weight:700; text-align:center; margin-bottom:28px; }
        .captcha-card { background:white; padding:28px 30px; border-radius:14px; margin-bottom:20px; box-shadow:0 4px 24px rgba(102,126,234,.10); }
        .captcha-card h4 { font-size:16px; font-weight:700; color:var(--primary); margin-bottom:18px; display:flex; align-items:center; gap:8px; }
        .badge-type { font-size:11px; font-weight:600; background:#ede9fe; color:var(--primary); padding:2px 10px; border-radius:20px; margin-right:auto; }
        .flash-msg { padding:14px 18px; border-radius:10px; margin-bottom:22px; font-size:15px; font-weight:600; display:flex; align-items:center; gap:10px; animation:slideIn .3s ease; }
        @keyframes slideIn { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
        .flash-success { background:#e6faf0; color:var(--success); border:1.5px solid var(--success); }
        .flash-error   { background:#fee2e2; color:var(--danger);  border:1.5px solid var(--danger); }
        .btn-verify { background:var(--primary); color:white; border:none; border-radius:8px; padding:9px 26px; font-family:'Vazirmatn',sans-serif; font-size:14px; font-weight:600; cursor:pointer; transition:opacity .15s; }
        .btn-verify:hover { opacity:.88; }
        .btn-verify:disabled { opacity:.45; cursor:not-allowed; }

        /* behavioral */
        .behavioral-panel { background:#f8f7ff; border:1.5px solid #e0d9ff; border-radius:10px; padding:16px 18px; margin-bottom:16px; }
        .behavioral-panel .hint { font-size:13px; color:#6b7280; margin-bottom:12px; line-height:1.7; }
        .metrics-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px; }
        .metric-box { background:white; border:1px solid #e5e7eb; border-radius:8px; padding:10px 14px; text-align:center; }
        .metric-box .val { font-size:22px; font-weight:700; color:var(--primary); line-height:1; }
        .metric-box .lbl { font-size:11px; color:#9ca3af; margin-top:3px; }
        .bc-progress { height:8px; background:#e5e7eb; border-radius:99px; overflow:hidden; margin-bottom:8px; }
        .bc-bar { height:100%; border-radius:99px; background:linear-gradient(90deg,#667eea,#764ba2); width:0%; transition:width .4s ease; }
        .status-badge { display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; padding:4px 12px; border-radius:20px; }
        .s-wait  { background:#fef9c3; color:#92400e; }
        .s-check { background:#dbeafe; color:#1e40af; }
        .s-ready { background:#dcfce7; color:#15803d; }
        .status-dot { width:7px; height:7px; border-radius:50%; background:currentColor; animation:pulse 1.4s infinite; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
        .log-box { background:#1e1e2e; color:#a6e3a1; font-family:'Courier New',monospace; font-size:12px; border-radius:8px; padding:12px 14px; max-height:130px; overflow-y:auto; margin-top:10px; direction:ltr; }
        .log-box .ll { line-height:1.65; }
        .ll.ok   { color:#a6e3a1; }
        .ll.warn { color:#f9e2af; }
        .ll.err  { color:#f38ba8; }
        .badge-na { display:inline-block; background:#fef3c7; color:#b45309; font-size:12px; font-weight:600; padding:4px 12px; border-radius:20px; border:1px solid #fde68a; }
    </style>
</head>
<body>
<div class="test-container">

    <div class="page-title">
        <i class="material-icons" style="vertical-align:middle;font-size:26px;">shield</i>
        تست سیستم CAPTCHA
    </div>

    <?php if (!empty($flashSuccess)): ?>
    <div class="flash-msg flash-success">
        <i class="material-icons">check_circle</i>
        <?= e($flashSuccess) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($flashError)): ?>
    <div class="flash-msg flash-error">
        <i class="material-icons">cancel</i>
        <?= e($flashError) ?>
    </div>
    <?php endif; ?>

    <!-- 1. Math -->
    <div class="captcha-card">
        <h4>
            <i class="material-icons" style="font-size:20px;">calculate</i>
            Math CAPTCHA
            <span class="badge-type">ریاضی</span>
        </h4>
        <form method="POST" action="<?= url('/test-captcha/verify') ?>">
            <?= csrf_field() ?>
            <?= captcha_field('math') ?>
            <button type="submit" class="btn-verify mt-2">ارسال و تأیید</button>
        </form>
    </div>

    <!-- 2. Image -->
    <div class="captcha-card">
        <h4>
            <i class="material-icons" style="font-size:20px;">image</i>
            Image CAPTCHA
            <span class="badge-type">تصویری</span>
        </h4>
        <form method="POST" action="<?= url('/test-captcha/verify') ?>">
            <?= csrf_field() ?>
            <?= captcha_field('image') ?>
            <button type="submit" class="btn-verify mt-2">ارسال و تأیید</button>
        </form>
    </div>

    <!-- 3. Behavioral -->
    <div class="captcha-card">
        <h4>
            <i class="material-icons" style="font-size:20px;">touch_app</i>
            Behavioral CAPTCHA
            <span class="badge-type">رفتاری</span>
        </h4>

        <form method="POST" action="<?= url('/test-captcha/verify') ?>" id="bc-form">
            <?= csrf_field() ?>
            <?= captcha_field('behavioral') ?>
            <!-- DEBUG sid=<?= session_id() ?> -->

            <div class="behavioral-panel">
                <p class="hint">
                    <i class="material-icons" style="font-size:15px;vertical-align:middle;">info</i>
                    این کپچا رفتار شما را آنالیز می‌کند. کمی موس حرکت دهید، اسکرول کنید یا تایپ کنید تا امتیاز کافی برسد.
                </p>

                <div class="metrics-grid">
                    <div class="metric-box"><div class="val" id="bc-events">0</div><div class="lbl">رویداد ثبت‌شده</div></div>
                    <div class="metric-box"><div class="val" id="bc-score">0</div><div class="lbl">امتیاز انسانی</div></div>
                    <div class="metric-box"><div class="val" id="bc-elapsed">0s</div><div class="lbl">زمان سپری‌شده</div></div>
                    <div class="metric-box"><div class="val" id="bc-iact">0</div><div class="lbl">تعامل (کلیک+تایپ)</div></div>
                </div>

                <div class="bc-progress"><div class="bc-bar" id="bc-bar"></div></div>

                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span class="status-badge s-wait" id="bc-status">
                        <span class="status-dot"></span>
                        در انتظار تعامل
                    </span>
                    <span style="font-size:12px;color:#9ca3af;">حداقل امتیاز: 60</span>
                </div>

                <div class="log-box" id="bc-log"></div>
            </div>

            <button type="submit" class="btn-verify" id="bc-submit" disabled>
                <i class="material-icons" style="font-size:16px;vertical-align:middle;">send</i>
                ارسال و تأیید
            </button>
        </form>
    </div>

    <!-- 4. reCAPTCHA v2 -->
    <div class="captcha-card">
        <h4>
            <i class="material-icons" style="font-size:20px;">verified_user</i>
            reCAPTCHA v2
            <span class="badge-type">Google</span>
        </h4>
        <?php if ($siteKey !== ''): ?>
        <form method="POST" action="<?= url('/test-captcha/verify') ?>">
            <?= csrf_field() ?>
            <div style="margin:10px 0 16px;">
                <div class="g-recaptcha" data-sitekey="<?= e($siteKey) ?>"></div>
            </div>
            <button type="submit" class="btn-verify">ارسال و تأیید</button>
        </form>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
        <?php else: ?>
        <span class="badge-na">
            <i class="material-icons" style="font-size:14px;vertical-align:middle;">warning</i>
            Site Key در تنظیمات سیستم وارد نشده
        </span>
        <?php endif; ?>
    </div>

</div><!-- /test-container -->

<?= captcha_refresh_script() ?>

<script>
(function(){
    'use strict';

    var form = document.getElementById('bc-form');
    if (!form) return;
    var tokenInput = form.querySelector('input[name="captcha_token"]');
    if (!tokenInput || !tokenInput.value) return;

    var elEvents  = document.getElementById('bc-events');
    var elScore   = document.getElementById('bc-score');
    var elElapsed = document.getElementById('bc-elapsed');
    var elIact    = document.getElementById('bc-iact');
    var elBar     = document.getElementById('bc-bar');
    var elStatus  = document.getElementById('bc-status');
    var elLog     = document.getElementById('bc-log');
    var elSubmit  = document.getElementById('bc-submit');

    // ── ثابت‌ها — باید با سرور هماهنگ باشند
    var MIN_INTERACTIONS = 5;   // حداقل تعامل واقعی (کلیک یا تایپ)
    var MIN_SECONDS      = 4;   // حداقل زمان حضور
    var PING_URL         = '<?= url('/captcha/behavioral/ping') ?>';

    var startTime         = Date.now();
    var eventsBuf         = [];
    var interactions      = 0;      // تعداد تأیید‌شده از سرور
    var localIact         = 0;      // تعداد محلی
    var isReady           = false;
    var pingInFlight      = false;
    var lastPingTime      = 0;
    var behavioralState   = '';     // signed state از سرور

    // ── لاگ
    function addLog(msg, type){
        type = type || 'ok';
        var ts = new Date().toTimeString().slice(0,8);
        var d  = document.createElement('div');
        d.className = 'll ' + type;
        d.textContent = '[' + ts + '] ' + msg;
        elLog.appendChild(d);
        elLog.scrollTop = elLog.scrollHeight;
    }

    // ── بروزرسانی UI
    function refreshUI(){
        var elapsed = Math.floor((Date.now() - startTime) / 1000);
        elEvents.textContent  = eventsBuf.length;
        // امتیاز: ترکیب تعامل سرور + زمان
        var iactScore    = Math.min((interactions / MIN_INTERACTIONS) * 60, 60);
        var timeScore    = Math.min((elapsed / MIN_SECONDS) * 40, 40);
        var displayScore = Math.round(iactScore + timeScore);

        elScore.textContent   = displayScore;
        elElapsed.textContent = elapsed + 's';
        elIact.textContent    = interactions + ' / ' + MIN_INTERACTIONS;

        var pct = Math.min(displayScore, 100);
        elBar.style.width = pct + '%';
        elBar.style.background = pct >= 100
            ? 'linear-gradient(90deg,#18B95A,#38d67b)'
            : 'linear-gradient(90deg,#667eea,#764ba2)';

        // آماده = سرور حداقل MIN_INTERACTIONS تعامل تأیید کرده + زمان کافی
        var ready = (interactions >= MIN_INTERACTIONS) && (elapsed >= MIN_SECONDS);

        if (ready && !isReady) {
            isReady = true;
            elSubmit.disabled = false;
            elStatus.className = 'status-badge s-ready';
            elStatus.innerHTML = '<span class="status-dot"></span>تأیید شد — آماده ارسال ✓';
            addLog('شرایط تأیید شد (تعامل: ' + interactions + ', زمان: ' + elapsed + 's)', 'ok');
        } else if (!isReady && (localIact > 0 || elapsed > 1)) {
            elStatus.className = 'status-badge s-check';
            elStatus.innerHTML = '<span class="status-dot"></span>در حال آنالیز…';
        }
    }

    // ── ping به سرور — interactions رو در session ثبت می‌کنه
    function sendPing(){
        var now = Date.now();
        // throttle: هر 800ms حداکثر یک ping
        if (pingInFlight || (now - lastPingTime) < 800) return;

        pingInFlight = true;
        lastPingTime = now;

        var token = tokenInput.value;
        var body  = 'captcha_token=' + encodeURIComponent(token)
                    + '&behavioral_state=' + encodeURIComponent(behavioralState);

        fetch(PING_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body
        })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data && data.success && data.data) {
                var iact = parseInt(data.data.interactions, 10) || 0;
                if (iact > interactions) {
                    interactions = iact;
                    addLog('سرور تأیید کرد — تعامل: ' + interactions, 'ok');
                }
                if (data.data.behavioral_state) {
                    behavioralState = data.data.behavioral_state;
                }
                refreshUI();
            }
        })
        .catch(function(err){
            addLog('خطا در ارتباط با سرور: ' + err, 'err');
        })
        .finally(function(){
            pingInFlight = false;
        });
    }

    // ── رویدادها
    document.addEventListener('mousemove', function(e){
        eventsBuf.push({t:'mm', x:e.clientX, y:e.clientY});
        if (eventsBuf.length > 80) eventsBuf.shift();
        refreshUI();
    });

    document.addEventListener('click', function(e){
        if (e.target.closest('[data-captcha-refresh]')) return;
        // کلیک روی دکمه submit هم نباید تکراری ping بزنه
        eventsBuf.push({t:'cl'});
        localIact++;
        addLog('کلیک — ارسال ping به سرور…', 'warn');
        sendPing();
        refreshUI();
    });

    document.addEventListener('keypress', function(){
        eventsBuf.push({t:'kp'});
        localIact++;
        addLog('کیبورد — ارسال ping به سرور…', 'warn');
        sendPing();
        refreshUI();
    });

    window.addEventListener('scroll', function(){
        eventsBuf.push({t:'sc', y:window.scrollY});
        refreshUI();
    });

    // تایمر زمان‌سنج
    setInterval(refreshUI, 500);

    // ── ارسال فرم
    form.addEventListener('submit', function(e){
        if (!isReady) {
            e.preventDefault();
            var elapsed = Math.floor((Date.now() - startTime) / 1000);
            if (interactions < MIN_INTERACTIONS) {
                addLog('کم‌تر از ' + MIN_INTERACTIONS + ' تعامل — لطفاً بیشتر کلیک یا تایپ کنید.', 'err');
            } else if (elapsed < MIN_SECONDS) {
                addLog('زمان کافی نیست — ' + (MIN_SECONDS - elapsed) + ' ثانیه دیگر صبر کنید.', 'err');
            } else {
                addLog('هنوز آماده نشده — کمی صبر کنید.', 'err');
            }
            return;
        }
        // اضافه کردن behavioral_state به فرم
        var bsInp = document.createElement('input');
        bsInp.type = 'hidden';
        bsInp.name = 'behavioral_state';
        bsInp.value = behavioralState;
        form.appendChild(bsInp);
        addLog('ارسال فرم — تعامل سرور: ' + interactions, 'ok');
    });

    addLog('سیستم behavioral شروع به رصد کرد. حداقل ' + MIN_INTERACTIONS + ' تعامل لازم است.', 'warn');
})();
</script>
</body>
</html>