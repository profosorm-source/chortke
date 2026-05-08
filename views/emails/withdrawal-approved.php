<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Tahoma, Arial, sans-serif; direction: rtl; text-align: right; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 40px 20px; text-align: center; }
        .header .icon { font-size: 56px; margin-bottom: 10px; }
        .header h1 { margin: 0; font-size: 22px; }
        .content { padding: 35px 30px; line-height: 1.9; color: #333; }
        .amount-box { background: #f0fdf4; border: 2px solid #bbf7d0; border-radius: 10px; padding: 20px 25px; margin: 20px 0; text-align: center; }
        .amount-box .label { font-size: 14px; color: #666; margin-bottom: 6px; }
        .amount-box .amount { font-size: 28px; font-weight: 700; color: #059669; }
        .info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f5f5f5; font-size: 14px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #666; }
        .info-value { font-weight: 600; color: #333; }
        .button { display: block; width: fit-content; margin: 25px auto 0; background: #10b981; color: white !important; padding: 12px 35px; text-decoration: none; border-radius: 8px; font-size: 15px; }
        .footer { background: #f9f9f9; padding: 20px; text-align: center; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #10b981 0%, #059669 100%)"; $headerTitle="✅ برداشت شما تأیید شد"; include __DIR__ . "/_header.php"; ?>
        <div class="content">
            <p>درخواست برداشت شما با موفقیت پردازش و مبلغ به حساب شما واریز شد.</p>

            <div class="amount-box">
                <div class="label">مبلغ برداشت‌شده</div>
                <div class="amount"><?= e($amount ?? '—') ?> <?= e($currency === 'usdt' ? 'USDT' : 'تومان') ?></div>
            </div>

            <div class="info-row">
                <span class="info-label">تاریخ پردازش:</span>
                <span class="info-value"><?= e($date ?? to_jalali(date('Y-m-d'))) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">وضعیت:</span>
                <span class="info-value" style="color:#10b981">تأیید و واریز شده</span>
            </div>

            <p style="margin-top:20px; color:#666; font-size:14px;">
                معمولاً وجه ظرف ۱ تا ۳ روز کاری به حساب بانکی شما واریز می‌شود.
                در صورت عدم دریافت، با پشتیبانی تماس بگیرید.
            </p>

            <a href="<?= e($wallet_url ?? '') ?>" class="button">مشاهده کیف پول</a>
        </div>
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>