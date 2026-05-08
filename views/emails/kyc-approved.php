<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            direction: rtl;
            text-align: right;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
        }
        .content {
            padding: 40px 30px;
            line-height: 1.8;
        }
        .button {
            display: inline-block;
            background: #667eea;
            color: white !important;
            padding: 15px 40px;
            text-decoration: none;
            border-radius: 8px;
            margin: 20px 0;
        }
        .footer {
            background: #f9f9f9;
            padding: 20px;
            text-align: center;
            color: #999;
            font-size: 12px;
        }
        .feature {
            background: #f8f9fa;
            padding: 15px;
            margin: 10px 0;
            border-right: 4px solid #667eea;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #11998e 0%, #38ef7d 100%)"; $headerTitle="خوش آمدید! 🎉"; include __DIR__ . "/_header.php"; ?>
        
        <div class="content">
            <h2>سلام <?= e($name ?? $email ?? 'کاربر گرامی') ?>!</h2>
            
            <p>
                از اینکه به خانواده چرتکه پیوستید خوشحالیم! حساب کاربری شما با موفقیت ایجاد شد.
            </p>
            
            <h3>چه کارهایی می‌توانید انجام دهید؟</h3>
            
            <div class="feature">
                <strong>💼 انجام تسک‌ها</strong><br>
                با انجام تسک‌های ساده درآمد کسب کنید
            </div>
            
            <div class="feature">
                <strong>📈 سرمایه‌گذاری</strong><br>
                سرمایه‌گذاری امن و کسب سود هفتگی
            </div>
            
            <div class="feature">
                <strong>🎲 قرعه‌کشی روزانه</strong><br>
                شانس برنده شدن جوایز نقدی
            </div>
            
            <div class="feature">
                <strong>👥 دعوت دوستان</strong><br>
                از معرفی دوستان کمیسیون دریافت کنید
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="<?= url('dashboard') ?>" class="button">
                    شروع کنید
                </a>
            </div>
            
            <p>
                <strong>کد معرف شما:</strong> <?= e($referral_code ?? '') ?>
            </p>
            
            <p style="color: #999; font-size: 12px;">
                این لینک را با دوستان خود به اشتراک بگذارید:<br>
                <?= url('register?ref=' . ($referral_code ?? '')) ?>
            </p>
        </div>
        
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>