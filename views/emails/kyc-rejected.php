<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Tahoma, Arial, sans-serif; direction: rtl; text-align: right; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; padding: 40px 20px; text-align: center; }
        .header .icon { font-size: 56px; margin-bottom: 10px; }
        .header h1 { margin: 0; font-size: 22px; }
        .content { padding: 35px 30px; line-height: 1.9; color: #333; }
        .reason-box { background: #fef2f2; border: 2px solid #fecaca; border-radius: 10px; padding: 18px 22px; margin: 20px 0; }
        .reason-box .label { font-size: 13px; color: #991b1b; font-weight: 600; margin-bottom: 8px; }
        .reason-box .reason { color: #7f1d1d; font-size: 14px; }
        .steps-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 18px 22px; margin: 20px 0; }
        .steps-box h3 { margin: 0 0 12px; font-size: 15px; color: #14532d; }
        .steps-box li { color: #166534; font-size: 14px; margin-bottom: 6px; }
        .button { display: block; width: fit-content; margin: 25px auto 0; background: #ef4444; color: white !important; padding: 12px 35px; text-decoration: none; border-radius: 8px; font-size: 15px; }
        .footer { background: #f9f9f9; padding: 20px; text-align: center; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #ef4444 0%, #dc2626 100%)"; $headerTitle="❌ احراز هویت رد شد"; include __DIR__ . "/_header.php"; ?>
        <div class="content">
            <p>سلام <?= e($name ?? 'کاربر گرامی') ?>،</p>
            <p>متأسفانه مدارک احراز هویت شما پس از بررسی توسط تیم ما تأیید نشد.</p>

            <?php if (!empty($reason)): ?>
            <div class="reason-box">
                <div class="label">دلیل رد:</div>
                <div class="reason"><?= nl2br(e($reason)) ?></div>
            </div>
            <?php endif; ?>

            <div class="steps-box">
                <h3>📋 برای احراز هویت مجدد:</h3>
                <ul>
                    <li>تصویر کارت ملی باید واضح و خوانا باشد</li>
                    <li>سلفی با کارت ملی در دست باید به وضوح چهره را نشان دهد</li>
                    <li>اطمینان حاصل کنید نور کافی وجود دارد</li>
                    <li>اطلاعات وارد‌شده با مدارک تطابق داشته باشد</li>
                </ul>
            </div>

            <p style="color:#666; font-size:14px;">
                می‌توانید مدارک اصلاح‌شده را مجدداً ارسال کنید. در صورت نیاز به راهنمایی، با پشتیبانی تماس بگیرید.
            </p>

            <a href="<?= e($kyc_url ?? url('/kyc')) ?>" class="button">ارسال مجدد مدارک</a>
        </div>
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>