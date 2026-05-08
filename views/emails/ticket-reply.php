<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Tahoma, Arial, sans-serif; direction: rtl; text-align: right; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 40px 20px; text-align: center; }
        .header .icon { font-size: 52px; margin-bottom: 10px; }
        .header h1 { margin: 0; font-size: 22px; }
        .content { padding: 35px 30px; line-height: 1.9; color: #333; }
        .ticket-info { background: #eff6ff; border-right: 4px solid #3b82f6; border-radius: 0 8px 8px 0; padding: 14px 18px; margin: 18px 0; font-size: 14px; }
        .ticket-info strong { color: #1e40af; }
        .reply-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin: 20px 0; }
        .reply-box .reply-label { font-size: 13px; color: #64748b; margin-bottom: 10px; font-weight: 600; }
        .reply-box .reply-text { color: #334155; font-size: 14px; line-height: 1.8; white-space: pre-wrap; }
        .button { display: block; width: fit-content; margin: 25px auto 0; background: #3b82f6; color: white !important; padding: 12px 35px; text-decoration: none; border-radius: 8px; font-size: 15px; }
        .footer { background: #f9f9f9; padding: 20px; text-align: center; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)"; $headerTitle="💬 پاسخ جدید برای تیکت شما"; include __DIR__ . "/_header.php"; ?>
        <div class="content">
            <p>سلام <?= e($name ?? 'کاربر گرامی') ?>،</p>
            <p>تیم پشتیبانی چرتکه به تیکت شما پاسخ داد.</p>

            <div class="ticket-info">
                <strong>موضوع تیکت:</strong> <?= e($ticket_subject ?? '—') ?>
                <?php if (!empty($ticket_id)): ?>
                <br><strong>شماره تیکت:</strong> #<?= (int)$ticket_id ?>
                <?php endif; ?>
            </div>

            <div class="reply-box">
                <div class="reply-label">پاسخ پشتیبانی:</div>
                <div class="reply-text"><?= e($reply_text ?? '—') ?></div>
            </div>

            <p style="color:#666; font-size:14px;">
                برای پاسخ به این پیام یا مشاهده کامل تیکت روی دکمه زیر کلیک کنید.
            </p>

            <a href="<?= e($ticket_url ?? url('/tickets')) ?>" class="button">مشاهده تیکت</a>
        </div>
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>