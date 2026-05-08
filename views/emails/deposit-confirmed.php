<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Tahoma, Arial, sans-serif; direction: rtl; text-align: right; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: white; padding: 40px 20px; text-align: center; }
        .header .icon { font-size: 56px; margin-bottom: 10px; }
        .header h1 { margin: 0; font-size: 22px; }
        .content { padding: 35px 30px; line-height: 1.9; color: #333; }
        .amount-box { background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%); border: 2px solid #ddd6fe; border-radius: 12px; padding: 22px; margin: 20px 0; text-align: center; }
        .amount-box .label { font-size: 14px; color: #6d28d9; margin-bottom: 8px; }
        .amount-box .amount { font-size: 32px; font-weight: 700; color: #7c3aed; }
        .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 14px; }
        .info-table tr { border-bottom: 1px solid #f5f5f5; }
        .info-table tr:last-child { border-bottom: none; }
        .info-table td { padding: 10px 4px; }
        .info-table td:first-child { color: #666; }
        .info-table td:last-child { font-weight: 600; text-align: left; }
        .button { display: block; width: fit-content; margin: 25px auto 0; background: #7c3aed; color: white !important; padding: 12px 35px; text-decoration: none; border-radius: 8px; font-size: 15px; }
        .footer { background: #f9f9f9; padding: 20px; text-align: center; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)"; $headerTitle="💳 واریز شما تأیید شد"; include __DIR__ . "/_header.php"; ?>
        <div class="content">
            <p>سلام <?= e($name ?? 'کاربر گرامی') ?>،</p>
            <p>واریز شما با موفقیت تأیید شد و مبلغ به کیف پول شما اضافه گردید.</p>

            <div class="amount-box">
                <div class="label">مبلغ واریز‌شده</div>
                <div class="amount"><?= e($amount ?? '—') ?> <?= e(!empty($currency) && strtolower($currency) === 'usdt' ? 'USDT' : 'تومان') ?></div>
            </div>

            <table class="info-table">
                <tr>
                    <td>روش واریز:</td>
                    <td><?= e($method ?? 'واریز دستی') ?></td>
                </tr>
                <tr>
                    <td>تاریخ تأیید:</td>
                    <td><?= e($date ?? to_jalali(date('Y-m-d'))) ?></td>
                </tr>
                <?php if (!empty($reference)): ?>
                <tr>
                    <td>شماره پیگیری:</td>
                    <td><?= e($reference) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>وضعیت:</td>
                    <td style="color:#10b981">تأیید و اعتبارگذاری شده ✓</td>
                </tr>
            </table>

            <p style="color:#666; font-size:14px;">
                موجودی کیف پول شما به‌روز شده و می‌توانید همین الان از آن استفاده کنید.
            </p>

            <a href="<?= e($wallet_url ?? url('/wallet')) ?>" class="button">مشاهده کیف پول</a>
        </div>
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>