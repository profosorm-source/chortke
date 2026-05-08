<?php
$title  = 'احراز هویت';
$layout = 'user';
ob_start();

$statusConfig = [
    'pending'      => ['label' => 'در انتظار بررسی', 'icon' => 'schedule',    'class' => 'kyc-status--pending'],
    'under_review' => ['label' => 'در حال بررسی',    'icon' => 'manage_search','class' => 'kyc-status--review'],
    'verified'     => ['label' => 'تأیید شده',       'icon' => 'verified',     'class' => 'kyc-status--verified'],
    'rejected'     => ['label' => 'رد شده',          'icon' => 'cancel',       'class' => 'kyc-status--rejected'],
    'expired'      => ['label' => 'منقضی شده',       'icon' => 'event_busy',   'class' => 'kyc-status--expired'],
];
?>

<div class="kyc-wrap">

    <!-- HERO -->
    <div class="kyc-hero">
        <div class="kyc-hero__left">
            <div class="kyc-hero__icon">
                <i class="material-icons">verified_user</i>
            </div>
            <div>
                <h1 class="kyc-hero__title">احراز هویت</h1>
                <p class="kyc-hero__sub">تأیید هویت برای دسترسی کامل به امکانات سایت</p>
            </div>
        </div>
        <?php if (!$kyc || $kyc->status === 'rejected'): ?>
        <?php if ($canSubmit['can']): ?>
        <a href="<?= url('/kyc/upload') ?>" class="kyc-btn-start">
            <i class="material-icons"><?= $kyc ? 'refresh' : 'upload' ?></i>
            <?= $kyc ? 'ارسال مجدد مدارک' : 'شروع احراز هویت' ?>
        </a>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- STEPS (اگر KYC نداره) -->
    <?php if (!$kyc): ?>

    <div class="kyc-steps">
        <div class="kyc-step">
            <div class="kyc-step__num">۱</div>
            <div class="kyc-step__icon"><i class="material-icons">edit_note</i></div>
            <div class="kyc-step__title">تکمیل اطلاعات</div>
            <div class="kyc-step__desc">کد ملی و تاریخ تولد</div>
        </div>
        <div class="kyc-step__arrow"><i class="material-icons">chevron_left</i></div>
        <div class="kyc-step">
            <div class="kyc-step__num">۲</div>
            <div class="kyc-step__icon"><i class="material-icons">photo_camera</i></div>
            <div class="kyc-step__title">آپلود سلفی</div>
            <div class="kyc-step__desc">با کارت ملی و برگه دست‌نوشته</div>
        </div>
        <div class="kyc-step__arrow"><i class="material-icons">chevron_left</i></div>
        <div class="kyc-step">
            <div class="kyc-step__num">۳</div>
            <div class="kyc-step__icon"><i class="material-icons">fact_check</i></div>
            <div class="kyc-step__title">بررسی کارشناس</div>
            <div class="kyc-step__desc">حداکثر ۴۸ ساعت</div>
        </div>
        <div class="kyc-step__arrow"><i class="material-icons">chevron_left</i></div>
        <div class="kyc-step">
            <div class="kyc-step__num">۴</div>
            <div class="kyc-step__icon"><i class="material-icons">verified</i></div>
            <div class="kyc-step__title">تأیید نهایی</div>
            <div class="kyc-step__desc">دسترسی کامل</div>
        </div>
    </div>

    <div class="kyc-empty">
        <div class="kyc-empty__icon"><i class="material-icons">assignment_ind</i></div>
        <h3>احراز هویت نشده</h3>
        <p>برای برداشت وجه و استفاده از امکانات کامل، باید هویت خود را تأیید کنید.</p>
        <?php if ($canSubmit['can']): ?>
        <a href="<?= url('/kyc/upload') ?>" class="kyc-btn-start" style="margin-top:20px">
            <i class="material-icons">upload</i>
            شروع احراز هویت
        </a>
        <?php else: ?>
        <div class="kyc-cooldown-notice">
            <i class="material-icons">info_outline</i>
            <?= e($canSubmit['reason'] ?? '') ?>
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>

    <?php
        $cfg = $statusConfig[$kyc->status] ?? $statusConfig['pending'];
    ?>

    <!-- STATUS CARD -->
    <div class="kyc-status-card <?= $cfg['class'] ?>">
        <div class="kyc-status-card__header">
            <div class="kyc-status-card__icon">
                <i class="material-icons"><?= $cfg['icon'] ?></i>
            </div>
            <div>
                <div class="kyc-status-card__label">وضعیت احراز هویت</div>
                <div class="kyc-status-card__val"><?= $cfg['label'] ?></div>
            </div>
            <?php if ($kyc->status === 'verified'): ?>
            <div class="kyc-verified-badge">
                <i class="material-icons">shield</i>
                تأیید شده
            </div>
            <?php endif; ?>
        </div>

        <?php if ($kyc->status === 'pending' || $kyc->status === 'under_review'): ?>
        <div class="kyc-progress-steps">
            <div class="kyc-ps kyc-ps--done"><i class="material-icons">check</i><span>ثبت درخواست</span></div>
            <div class="kyc-ps__line kyc-ps__line--done"></div>
            <div class="kyc-ps kyc-ps--<?= $kyc->status === 'under_review' ? 'active' : 'wait' ?>">
                <i class="material-icons"><?= $kyc->status === 'under_review' ? 'manage_search' : 'schedule' ?></i>
                <span>بررسی مدارک</span>
            </div>
            <div class="kyc-ps__line"></div>
            <div class="kyc-ps kyc-ps--wait"><i class="material-icons">verified</i><span>تأیید نهایی</span></div>
        </div>
        <?php endif; ?>

        <div class="kyc-info-rows">
            <div class="kyc-info-row">
                <span class="kyc-info-row__lbl">کد ملی</span>
                <span class="kyc-info-row__val" dir="ltr">
                    <?= $kyc->national_code
                        ? substr_replace($kyc->national_code, '****', 3, 4)
                        : 'ثبت نشده' ?>
                </span>
            </div>
            <div class="kyc-info-row">
                <span class="kyc-info-row__lbl">تاریخ ثبت</span>
                <span class="kyc-info-row__val"><?= to_jalali($kyc->submitted_at ?? '') ?></span>
            </div>
            <?php if ($kyc->reviewed_at): ?>
            <div class="kyc-info-row">
                <span class="kyc-info-row__lbl">تاریخ بررسی</span>
                <span class="kyc-info-row__val"><?= to_jalali($kyc->reviewed_at) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($kyc->status === 'verified' && $kyc->verified_at): ?>
            <div class="kyc-info-row">
                <span class="kyc-info-row__lbl">تاریخ تأیید</span>
                <span class="kyc-info-row__val kyc-text-green"><?= to_jalali($kyc->verified_at) ?></span>
            </div>
            <div class="kyc-info-row">
                <span class="kyc-info-row__lbl">اعتبار تا</span>
                <span class="kyc-info-row__val"><?= to_jalali($kyc->expires_at ?? '') ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($kyc->status === 'rejected'): ?>
        <div class="kyc-rejection-box">
            <div class="kyc-rejection-box__header">
                <i class="material-icons">report_problem</i>
                دلیل رد درخواست
            </div>
            <p class="kyc-rejection-box__reason">
                <?= nl2br(e($kyc->rejection_reason ?? 'دلیلی ثبت نشده است')) ?>
            </p>
            <?php if ($canSubmit['can']): ?>
            <a href="<?= url('/kyc/upload') ?>" class="kyc-btn-retry">
                <i class="material-icons">refresh</i>
                ارسال مجدد مدارک
            </a>
            <?php else: ?>
            <div class="kyc-cooldown-notice">
                <i class="material-icons">schedule</i>
                <?= e($canSubmit['reason'] ?? '') ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($kyc->status === 'verified'): ?>
        <div class="kyc-benefits">
            <div class="kyc-benefit">
                <i class="material-icons">account_balance_wallet</i>
                <span>برداشت نامحدود</span>
            </div>
            <div class="kyc-benefit">
                <i class="material-icons">workspace_premium</i>
                <span>سطح کاربری ارتقا</span>
            </div>
            <div class="kyc-benefit">
                <i class="material-icons">security</i>
                <span>حساب ایمن‌تر</span>
            </div>
            <div class="kyc-benefit">
                <i class="material-icons">support_agent</i>
                <span>پشتیبانی اولویت‌دار</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>

    <!-- GUIDE SECTION -->
    <div class="kyc-section">
        <div class="kyc-section__header">
            <i class="material-icons">help_outline</i>
            <h2>راهنمای احراز هویت</h2>
        </div>
        <div class="kyc-guides">
            <div class="kyc-guide-card">
                <div class="kyc-guide-card__icon kyc-guide-card__icon--blue">
                    <i class="material-icons">credit_card</i>
                </div>
                <h4>کارت ملی</h4>
                <p>کارت ملی را در دست بگیرید. تصویر باید واضح و تمام متن خوانا باشد.</p>
            </div>
            <div class="kyc-guide-card">
                <div class="kyc-guide-card__icon kyc-guide-card__icon--gold">
                    <i class="material-icons">edit_note</i>
                </div>
                <h4>برگه دست‌نوشته</h4>
                <p>روی برگه سفید بنویسید: <strong>«<?= e($appName) ?> - <?= e($todayJalali) ?>»</strong></p>
            </div>
            <div class="kyc-guide-card">
                <div class="kyc-guide-card__icon kyc-guide-card__icon--green">
                    <i class="material-icons">photo_camera</i>
                </div>
                <h4>سلفی</h4>
                <p>سلفی بگیرید که صورت، کارت ملی و برگه دست‌نوشته هر سه در تصویر باشند.</p>
            </div>
            <div class="kyc-guide-card">
                <div class="kyc-guide-card__icon kyc-guide-card__icon--purple">
                    <i class="material-icons">light_mode</i>
                </div>
                <h4>نور مناسب</h4>
                <p>در نور کافی عکس بگیرید. از فلاش مستقیم و سایه روی چهره خودداری کنید.</p>
            </div>
        </div>

        <div class="kyc-rules">
            <div class="kyc-rule kyc-rule--ok">
                <i class="material-icons">check_circle</i>
                <span>تصویر واضح و خوانا</span>
            </div>
            <div class="kyc-rule kyc-rule--ok">
                <i class="material-icons">check_circle</i>
                <span>فرمت JPG یا PNG، حداکثر ۵MB</span>
            </div>
            <div class="kyc-rule kyc-rule--ok">
                <i class="material-icons">check_circle</i>
                <span>زمان بررسی حداکثر ۴۸ ساعت</span>
            </div>
            <div class="kyc-rule kyc-rule--no">
                <i class="material-icons">cancel</i>
                <span>بدون فیلتر یا ویرایش تصویر</span>
            </div>
            <div class="kyc-rule kyc-rule--no">
                <i class="material-icons">cancel</i>
                <span>بدون سایه یا تاری روی مدارک</span>
            </div>
            <div class="kyc-rule kyc-rule--no">
                <i class="material-icons">cancel</i>
                <span>ارسال مدارک جعلی ممنوع و پیگرد قانونی دارد</span>
            </div>
        </div>
    </div>

</div>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>
