<?php
$layout    = 'user';
$execution = $execution ?? null;
$task      = $task      ?? null;
ob_start();
if (!$execution || !$task) { redirect(url('/social-tasks')); exit; }
$expectedTime = (int)($task->expected_time ?? 60);
$taskType     = $task->task_type ?? '';
$platform     = $task->platform  ?? '';
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-social-tasks.css') ?>">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0"><i class="material-icons align-middle me-1">task_alt</i> انجام تسک</h4>
        <p class="text-muted mb-0 small"><?= e($task->title ?? '') ?></p>
    </div>
    <a href="<?= url('/social-tasks') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons align-middle" style="font-size:16px;">arrow_back</i> بازگشت
    </a>
</div>

<div class="row justify-content-center">
<div class="col-md-7">
<div class="card mb-3">
<div class="card-body">

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <span class="badge bg-info text-dark me-1"><?= e($platform) ?></span>
            <span class="badge bg-secondary"><?= e($taskType) ?></span>
            <h5 class="mt-1 mb-0"><?= e($task->title ?? '') ?></h5>
        </div>
        <div class="text-end">
            <div class="fw-bold text-success fs-5"><?= number_format($task->reward ?? 0) ?> تومان</div>
            <small class="text-muted">پاداش</small>
        </div>
    </div>

    <?php if (!empty($task->description)): ?>
        <div class="alert alert-light border small mb-3" style="white-space:pre-wrap;"><?= nl2br(e($task->description)) ?></div>
    <?php endif; ?>

    <div class="text-center mb-3 p-3 bg-light rounded">
        <div class="execute-timer" id="timer">00:00</div>
        <div class="small text-muted mt-1">زمان انتظار: <?= $expectedTime ?> ثانیه</div>
        <div class="progress mt-2" style="height:6px;">
            <div class="progress-bar bg-success" id="progress-bar" style="width:0%"></div>
        </div>
    </div>

    <?php if (!empty($task->target_url)): ?>
        <div class="mb-3">
            <a href="<?= e($task->target_url) ?>" target="_blank" rel="noopener"
               id="btn-goto" class="btn btn-primary w-100">
                <i class="material-icons align-middle" style="font-size:16px;">open_in_new</i>
                رفتن به صفحه هدف
            </a>
            <small class="text-muted d-block mt-1 text-center">پس از انجام تسک، برگردید و مدرک ارسال کنید.</small>
        </div>
    <?php endif; ?>

    <div id="camera-verify-box" class="camera-verify-box mb-3" style="display:none;">
        <i class="material-icons camera-icon">camera_front</i>
        <h6 class="mt-2 fw-bold">تأیید با دوربین</h6>
        <p class="small text-muted mb-2">برای تأیید، با دوربین جلوی گوشی اسکرین‌شات بگیرید. <strong>عکس ذخیره نمی‌شود.</strong></p>
        <button id="btn-camera" class="btn btn-warning">
            <i class="material-icons align-middle" style="font-size:16px;">camera_front</i> شروع تأیید دوربین
        </button>
        <div id="camera-result" class="mt-2 small"></div>
    </div>

    <hr>
    <h6 class="fw-bold mb-3">ارسال مدرک</h6>

    <div id="submit-area" style="display:none;">
        <div class="mb-3">
            <label class="form-label fw-bold">اسکرین‌شات <span class="text-danger">*</span></label>
            <input type="file" id="proof-file" class="form-control" accept="image/*">
        </div>
        <?php if ($taskType === 'comment' && !empty($task->comment_templates)): ?>
            <?php $templates = json_decode($task->comment_templates ?? '[]', true) ?? []; ?>
            <?php if (!empty($templates)): ?>
                <div class="mb-3">
                    <label class="form-label fw-bold small">متن‌های پیشنهادی کامنت</label>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        <?php foreach ($templates as $tpl): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm btn-use-template"
                                    data-text="<?= e($tpl) ?>">
                                <?= e(mb_substr($tpl, 0, 25)) ?>...
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="mb-3">
            <label class="form-label small">توضیح تکمیلی</label>
            <textarea id="proof-text" class="form-control" rows="2" placeholder="اختیاری..."></textarea>
        </div>
        <button id="btn-submit" class="btn btn-success w-100">
            <i class="material-icons align-middle" style="font-size:16px;">send</i>
            ارسال مدرک و دریافت پاداش
        </button>
    </div>

    <div id="goto-hint" class="text-center text-muted small py-2">
        ابتدا روی دکمه رفتن به صفحه هدف کلیک کنید و تسک را انجام دهید.
    </div>

</div>
</div>
</div>
</div>

<script>
(function(){
    const executionId = <?= (int)($execution->id ?? 0) ?>;
    const expectedTime = <?= $expectedTime ?>;
    const csrf = '<?= csrf_token() ?>';

    let elapsed=0, activeTime=0, isActive=true, taskStarted=false;
    let actionTimes=[], lastAction=Date.now(), scrollSpeeds=[], lastScroll=Date.now(), blurStart=null;
    const sig = {
        tap_count:0, swipe_count:0, scroll_count:0, touch_pauses:0,
        scroll_pauses:0, hesitation_count:0, app_blur_count:0,
        natural_delay_count:0, touch_timing_variance:0,
        scroll_speed_variance:0, max_blur_duration:0
    };

    const timerEl = document.getElementById('timer');
    const progEl  = document.getElementById('progress-bar');

    const tick = setInterval(()=>{
        elapsed++; if(isActive) activeTime++;
        timerEl.textContent = `${String(Math.floor(elapsed/60)).padStart(2,'0')}:${String(elapsed%60).padStart(2,'0')}`;
        progEl.style.width = Math.min(100, Math.round(elapsed/expectedTime*100))+'%';
        if(elapsed >= expectedTime && taskStarted) showSubmit();
    },1000);

    function showSubmit(){
        document.getElementById('submit-area').style.display='block';
        document.getElementById('goto-hint').style.display='none';
    }

    function recordAction(){
        const now=Date.now(), delay=now-lastAction;
        actionTimes.push(delay); sig.tap_count++;
        if(delay>800) sig.hesitation_count++;
        if(delay>300) sig.natural_delay_count++;
        lastAction=now;
        if(actionTimes.length>2){
            const mean=actionTimes.reduce((a,b)=>a+b)/actionTimes.length;
            sig.touch_timing_variance=Math.round(Math.sqrt(actionTimes.reduce((s,v)=>s+Math.pow(v-mean,2),0)/actionTimes.length));
        }
    }
    document.addEventListener('click', recordAction);
    document.addEventListener('touchstart', recordAction);
    document.addEventListener('touchmove', ()=>sig.swipe_count++);
    document.addEventListener('scroll', ()=>{
        sig.scroll_count++;
        const now=Date.now(), speed=now-lastScroll; scrollSpeeds.push(speed);
        if(speed>500) sig.scroll_pauses++;
        if(scrollSpeeds.length>2){
            const mean=scrollSpeeds.reduce((a,b)=>a+b)/scrollSpeeds.length;
            sig.scroll_speed_variance=Math.round(Math.sqrt(scrollSpeeds.reduce((s,v)=>s+Math.pow(v-mean,2),0)/scrollSpeeds.length));
        }
        lastScroll=now;
    });
    document.addEventListener('visibilitychange',()=>{
        isActive=!document.hidden;
        if(document.hidden){sig.app_blur_count++; blurStart=Date.now();}
        else if(blurStart){const dur=Math.round((Date.now()-blurStart)/1000);if(dur>sig.max_blur_duration)sig.max_blur_duration=dur;blurStart=null;}
    });

    setInterval(()=>{
        if(!taskStarted) return;
        fetch('<?= url('/api/social-tasks/behavior') ?>',{
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
            body:JSON.stringify({execution_id:executionId,signals:{...sig,active_time:activeTime,session_duration:elapsed,expected_time:expectedTime}})
        }).catch(()=>{});
    },15000);

    document.getElementById('btn-goto')?.addEventListener('click',()=>{
        taskStarted=true;
        setTimeout(showSubmit, expectedTime*1000);
    });

    document.getElementById('btn-camera')?.addEventListener('click', async function(){
        this.disabled=true; this.textContent='در حال پردازش...';
        const res=await fetch('<?= url('/api/social-tasks/camera-verify') ?>',{
            method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
            body:JSON.stringify({execution_id:executionId,camera_score:75,verified_signals:['screen_visible']})
        });
        const d=await res.json();
        const el=document.getElementById('camera-result');
        if(d.success){el.className='mt-2 small text-success fw-bold';el.textContent='✓ تأیید دوربین انجام شد';showSubmit();}
        else{el.className='mt-2 small text-danger';el.textContent=d.message||'خطا';this.disabled=false;this.textContent='تلاش مجدد';}
    });

    document.querySelectorAll('.btn-use-template').forEach(b=>b.addEventListener('click',function(){
        document.getElementById('proof-text').value=this.dataset.text;
    }));

    document.getElementById('btn-submit')?.addEventListener('click', async function(){
        this.disabled=true; this.textContent='در حال ارسال...'; clearInterval(tick);
        const avg=actionTimes.length?Math.round(actionTimes.reduce((a,b)=>a+b)/actionTimes.length):0;
        const interactions=[sig.tap_count>0?'tap':null,sig.scroll_count>0?'scroll':null,sig.swipe_count>0?'swipe':null].filter(Boolean);
        const fd=new FormData();
        fd.append('_token',csrf); fd.append('active_time',activeTime);
        fd.append('interactions',JSON.stringify(interactions));
        fd.append('behavior_signals',JSON.stringify({...sig,active_time:activeTime,session_duration:elapsed,avg_action_delay_ms:avg,expected_time:expectedTime}));
        const fi=document.getElementById('proof-file');
        if(fi?.files[0]) fd.append('proof_file',fi.files[0]);
        const pt=document.getElementById('proof-text')?.value;
        if(pt) fd.append('proof_text',pt);
        try{
            const r=await fetch('<?= url('/social-tasks/') ?>'+executionId+'/submit',{method:'POST',headers:{'X-CSRF-Token':csrf},body:fd});
            const d=await r.json();
            if(d.success) window.location.href='<?= url('/social-tasks') ?>?result='+d.decision;
            else{alert(d.message||'خطا');this.disabled=false;this.textContent='ارسال مدرک و دریافت پاداش';}
        }catch(e){alert('خطای اتصال');this.disabled=false;this.textContent='ارسال مدرک و دریافت پاداش';}
    });

    if(new URLSearchParams(window.location.search).get('camera')==='1'){
        document.getElementById('camera-verify-box').style.display='block';
    }
})();
</script>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
