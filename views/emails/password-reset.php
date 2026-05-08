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
        .header h1 {
            margin: 0;
            font-size: 28px;
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
            font-weight: bold;
        }
        .button:hover {
            background: #5568d3;
        }
        .footer {
            background: #f9f9f9;
            padding: 20px;
            text-align: center;
            color: #999;
            font-size: 12px;
        }
        .warning {
            background: #fff3cd;
            border-right: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .info-box {
            background: #e7f3ff;
            border-right: 4px solid #2196f3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php $headerColor="linear-gradient(135deg, #f093fb 0%, #f5576c 100%)"; $headerTitle="بازیابی رمز عبور"; include __DIR__ . "/_header.php"; ?>
        
        <div class="content">
            <h2>بازیابی رمز عبور</h2>
            
            <p>
                درخواست بازیابی رمز عبور برای حساب شما دریافت شد.
            </p>
            
            <p>
                برای تنظیم رمز عبور جدید، روی دکمه زیر کلیک کنید:
            </p>
            
            <div style="text-align: center;">
                <a href="<?= e($reset_url) ?>" class="button">
                    بازیابی رمز عبور
                </a>
            </div>
            
            <p style="color: #999; font-size: 12px;">
                اگر دکمه کار نکرد، لینک زیر را کپی کرده و در مرورگر باز کنید:
                <br>
                <a href="<?= e($reset_url) ?>"><?= e($reset_url) ?></a>
            </p>
            
            <div class="info-box">
                <strong>ℹ️ نکته:</strong>
                این لینک فقط برای <strong>1 ساعت</strong> معتبر است.
            </div>
            
            <div class="warning">
                <strong>⚠️ هشدار امنیتی:</strong>
                اگر شما درخواست بازیابی رمز نداده‌اید، این ایمیل را نادیده بگیرید 
                و فوراً رمز عبور خود را تغییر دهید.
            </div>
        </div>
        
        <?php include __DIR__ . "/_footer.php"; ?>
    </div>
</body>
</html>