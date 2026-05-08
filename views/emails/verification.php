<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Tahoma, Arial, sans-serif; direction: rtl; text-align: right; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .content { padding: 40px 30px; line-height: 1.8; }
        .button { display: inline-block; background: #667eea; color: white !important; padding: 15px 40px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; font-size: 16px; }
        .code-box { background: #f8f9ff; border: 2px dashed #667eea; border-radius: 12px; padding: 20px; text-align: center; margin: 24px 0; }
        .code-box .label { font-size: 13px; color: #888; margin-bottom: 8px; }
        .code-box .code { font-size: 36px; font-weight: bold; letter-spacing: 10px; color: #667eea; font-family: monospace; direction: ltr; }
        .divider { text-align: center; margin: 24px 0; color: #ccc; font-size: 13px; }
        .divider::before { content: ''; display: inline-block; width: 80px; height: 1px; background: #eee; vertical-align: middle; margin-left: 10px; }
        .divider::after  { content: ''; display: inline-block; width: 80px; height: 1px; background: #eee; vertical-align: middle; margin-right: 10px; }
        .warning { background: #fff3cd; border-right: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 5px; font-size: 13px; }
        .link-box { background: #f8f8f8; border-radius: 8px; padding: 10px 14px; font-size: 11px; color: #999; word-break: break-all; direction: ltr; text-align: left; }
        .footer { background: #f9f9f9; padding: 20px; text-align: center; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #667eea 0%, #764ba2 100%)"; $headerTitle="تأیید ایمیل"; include __DIR__ . "/_header.php"; ?>

        <div class="content">
            <h2>سلام <?= e($name) ?>! 👋</h2>
            <p>از اینکه به جمع چرتکه پیوستید خوشحالیم 🎉</p>
            <p>برای فعال‌سازی حساب کاربری خود، از یکی از دو روش زیر استفاده کنید:</p>

            <!-- روش اول: دکمه لینک -->
            <p><strong>روش اول — کلیک روی دکمه:</strong></p>
            <div style="text-align:center;">
                <a href="<?= e($verify_url) ?>" class="button">✅ تأیید ایمیل</a>
            </div>

            <div class="divider">یا</div>

            <!-- روش دوم: کد ۶ رقمی -->
            <p><strong>روش دوم — وارد کردن کد:</strong></p>
            <p style="font-size:13px;color:#666;">اگر دکمه بالا کار نکرد، این کد را در صفحه تأیید ایمیل وارد کنید:</p>
            <div class="code-box">
                <div class="label">کد تأیید شما</div>
                <div class="code"><?= e($verify_code) ?></div>
            </div>

            <p style="font-size:12px; color:#aaa;">لینک مستقیم:</p>
            <div class="link-box"><?= e($verify_url) ?></div>

            <div class="warning">
                <strong>⚠️ توجه:</strong> اگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید.
            </div>
        </div>

        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>