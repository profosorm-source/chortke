<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Tahoma, Arial, sans-serif; direction: rtl; text-align: right; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 45px 20px; text-align: center; }
        .header .icon { font-size: 64px; margin-bottom: 10px; }
        .header h1 { margin: 0 0 8px; font-size: 26px; }
        .header p { margin: 0; opacity: 0.9; font-size: 15px; }
        .content { padding: 35px 30px; line-height: 1.9; color: #333; }
        .prize-box { background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 2px solid #fcd34d; border-radius: 12px; padding: 25px; margin: 20px 0; text-align: center; }
        .prize-box .label { font-size: 14px; color: #92400e; margin-bottom: 8px; }
        .prize-box .prize { font-size: 36px; font-weight: 700; color: #d97706; }
        .confetti { font-size: 28px; letter-spacing: 8px; margin: 12px 0; }
        .info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f5f5f5; font-size: 14px; }
        .info-row:last-child { border-bottom: none; }
        .button { display: block; width: fit-content; margin: 25px auto 0; background: linear-gradient(135deg, #f59e0b, #d97706); color: white !important; padding: 14px 40px; text-decoration: none; border-radius: 8px; font-size: 15px; font-weight: 600; }
        .footer { background: #f9f9f9; padding: 20px; text-align: center; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #f59e0b 0%, #d97706 100%)"; $headerTitle="🏆 تبریک! شما برنده شدید!"; include __DIR__ . "/_header.php"; ?>
        <div class="content">
            <p>اخبار فوق‌العاده! شما در قرعه‌کشی امروز چرتکه <strong>برنده</strong> شدید. 🎉</p>

            <div class="prize-box">
                <div class="confetti">🎊 🎉 🎊</div>
                <div class="label">جایزه شما</div>
                <div class="prize"><?= e($prize ?? '—') ?> تومان</div>
                <div class="confetti">🎊 🎉 🎊</div>
            </div>

            <div class="info-row">
                <span style="color:#666">تاریخ قرعه‌کشی:</span>
                <span style="font-weight:600"><?= e($date ?? to_jalali(date('Y-m-d'))) ?></span>
            </div>
            <div class="info-row">
                <span style="color:#666">وضعیت جایزه:</span>
                <span style="font-weight:600; color:#10b981">واریز شده به کیف پول</span>
            </div>

            <p style="margin-top:20px; color:#666; font-size:14px;">
                جایزه شما به کیف پول تومانی‌تان اضافه شده و می‌توانید همین الان از آن استفاده کنید.
            </p>

            <a href="<?= e($wallet_url ?? '') ?>" class="button">مشاهده کیف پول</a>
        </div>
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>