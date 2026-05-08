<?php
$title  = 'سرمایه‌گذاری جدید';
$layout = 'user';
ob_start();
?>

<div class="inv-wrap">

    <!-- HEADER -->
    <div class="inv-hero">
        <div class="inv-hero__left">
            <div class="inv-hero__icon">
                <i class="material-icons">add_chart</i>
            </div>
            <div>
                <h1 class="inv-hero__title">سرمایه‌گذاری جدید</h1>
                <p class="inv-hero__sub">ثبت سرمایه‌گذاری در بازار طلا و فارکس</p>
            </div>
        </div>
        <a href="<?= url('/investment') ?>" class="inv-btn-new" style="background:var(--inv-card2);color:var(--inv-fg2) !important;border:1px solid var(--inv-border);">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
    </div>

    <?php if ($isDepositLocked): ?>
    <div class="inv-alert inv-alert--info">
        <i class="material-icons">lock_clock</i>
        <span>به دلیل برداشت اخیر، فعلاً امکان سرمایه‌گذاری جدید ندارید.</span>
    </div>
    <?php else: ?>

    <!-- Settings Bar -->
    <div class="inv-settings-bar" style="margin-bottom:20px">
        <div class="inv-setting-item">
            <span class="inv-setting-item__lbl">حداقل سرمایه‌گذاری</span>
            <span class="inv-setting-item__val" dir="ltr"><?= number_format($settings['min_amount']) ?> USDT</span>
        </div>
        <div class="inv-setting-item">
            <span class="inv-setting-item__lbl">حداکثر سرمایه‌گذاری</span>
            <span class="inv-setting-item__val" dir="ltr"><?= number_format($settings['max_amount']) ?> USDT</span>
        </div>
        <div class="inv-setting-item">
            <span class="inv-setting-item__lbl">کارمزد پلتفرم</span>
            <span class="inv-setting-item__val"><?= $settings['site_fee_percent'] ?>%</span>
        </div>
        <div class="inv-setting-item">
            <span class="inv-setting-item__lbl">دوره cooldown برداشت</span>
            <span class="inv-setting-item__val">هر <?= $settings['withdrawal_cooldown'] ?> روز</span>
        </div>
    </div>

    <div class="inv-create-layout">

        <!-- FORM -->
        <div class="inv-form-card">
            <div class="inv-form-card__header">
                <i class="material-icons">edit_note</i>
                اطلاعات سرمایه‌گذاری
            </div>
            <form id="investForm" class="inv-form">

                <div class="inv-field">
                    <label class="inv-label" for="amount">
                        مبلغ سرمایه‌گذاری <span class="inv-req">*</span>
                    </label>
                    <div class="inv-amount-wrap">
                        <input type="number" name="amount" id="amount" class="inv-input"
                               dir="ltr" style="padding-left:52px"
                               min="<?= e($settings['min_amount']) ?>"
                               max="<?= e($settings['max_amount']) ?>"
                               step="0.01"
                               placeholder="مثال: 100"
                               required>
                        <span class="inv-amount-unit">USDT</span>
                    </div>
                    <div class="inv-range-hint">
                        <span>حداقل: <?= number_format($settings['min_amount']) ?> USDT</span>
                        <span>حداکثر: <?= number_format($settings['max_amount']) ?> USDT</span>
                    </div>
                </div>

                <!-- Fee preview -->
                <div id="feePreview" style="display:none">
                    <div class="inv-settings-bar">
                        <div class="inv-setting-item">
                            <span class="inv-setting-item__lbl">مبلغ سرمایه‌گذاری</span>
                            <span class="inv-setting-item__val" id="previewAmount" dir="ltr">—</span>
                        </div>
                        <div class="inv-setting-item">
                            <span class="inv-setting-item__lbl">کارمزد پلتفرم (<?= $settings['site_fee_percent'] ?>%)</span>
                            <span class="inv-setting-item__val" id="previewFee" dir="ltr">—</span>
                        </div>
                    </div>
                </div>

                <div class="inv-field">
                    <label class="inv-risk-check" for="risk_accepted">
                        <input type="checkbox" name="risk_accepted" id="risk_accepted" value="1" required>
                        <span class="inv-risk-check__text">
                            <strong>هشدار ریسک را مطالعه کردم</strong> و با آگاهی کامل از احتمال ضرر، این سرمایه‌گذاری را انجام می‌دهم. مسئولیت هرگونه ضرر و زیان با اینجانب است.
                        </span>
                    </label>
                </div>

                <button type="submit" id="submitBtn" class="inv-submit-btn" disabled>
                    <i class="material-icons">trending_up</i>
                    ثبت سرمایه‌گذاری
                </button>

            </form>
        </div>

        <!-- RISK CARD -->
        <div class="inv-risk-card">
            <div class="inv-risk-card__header">
                <i class="material-icons">warning_amber</i>
                هشدار ریسک — لطفاً مطالعه کنید
            </div>
            <ul class="inv-risk-list">
                <li><i class="material-icons">dangerous</i><span>احتمال ضرر تا <strong>۱۰۰٪</strong> سرمایه وجود دارد.</span></li>
                <li><i class="material-icons">remove_circle_outline</i><span>سیستم هیچ <strong>تضمینی</strong> برای سودآوری نمی‌دهد.</span></li>
                <li><i class="material-icons">history</i><span>عملکرد گذشته تضمینی برای آینده نیست.</span></li>
                <li><i class="material-icons">wallet</i><span>فقط پولی سرمایه‌گذاری کنید که توان از دست دادنش را دارید.</span></li>
                <li><i class="material-icons">lock</i><span>پس از برداشت، <strong><?= $settings['deposit_lock'] ?> روز</strong> امکان سرمایه‌گذاری جدید نیست.</span></li>
                <li><i class="material-icons">gavel</i><span>مسئولیت کامل با <strong>سرمایه‌گذار</strong> است.</span></li>
            </ul>
        </div>

    </div>

    <?php endif; ?>
</div>

<script>
(function() {
    const amountInput = document.getElementById('amount');
    const riskCheck   = document.getElementById('risk_accepted');
    const submitBtn   = document.getElementById('submitBtn');
    const feePreview  = document.getElementById('feePreview');
    const feeRate     = <?= (float)$settings['site_fee_percent'] ?> / 100;

    function updateBtn() {
        submitBtn.disabled = !riskCheck?.checked || !amountInput?.value;
    }

    amountInput?.addEventListener('input', function() {
        const amt = parseFloat(this.value) || 0;
        if (amt > 0) {
            document.getElementById('previewAmount').textContent = amt.toFixed(2) + ' USDT';
            document.getElementById('previewFee').textContent = (amt * feeRate).toFixed(2) + ' USDT';
            feePreview.style.display = 'block';
        } else {
            feePreview.style.display = 'none';
        }
        updateBtn();
    });

    riskCheck?.addEventListener('change', updateBtn);

    document.getElementById('investForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="material-icons inv-spin">refresh</i> در حال ثبت...';

        const data = {
            amount:        parseFloat(amountInput.value),
            risk_accepted: riskCheck.checked ? 1 : 0,
        };

        fetch('<?= url('/investment/store') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '<?= csrf_token() ?>'
            },
            body: JSON.stringify(data)
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                notyf.success(res.message);
                setTimeout(() => window.location.href = '<?= url('/investment') ?>', 1500);
            } else {
                notyf.error(res.message);
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="material-icons">trending_up</i> ثبت سرمایه‌گذاری';
            }
        })
        .catch(() => {
            notyf.error('خطا در ارتباط با سرور');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="material-icons">trending_up</i> ثبت سرمایه‌گذاری';
        });
    });
})();
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>