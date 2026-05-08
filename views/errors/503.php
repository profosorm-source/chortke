<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>در دست تعمیر | چرتکه</title>
    
    <!-- Bootstrap RTL -->
    <link href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>" rel="stylesheet">
    
    <!-- Material Icons -->
    
    <style>
        body {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Vazir', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .maintenance-container {
            text-align: center;
            color: white;
            animation: fadeIn 0.5s ease-in;
            max-width: 600px;
            padding: 20px;
        }
        
        .maintenance-icon {
            font-size: 120px;
            margin-bottom: 20px;
            animation: rotate 4s linear infinite;
        }
        
        .maintenance-title {
            font-size: 40px;
            font-weight: bold;
            margin: 20px 0;
            text-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }
        
        .maintenance-description {
            font-size: 18px;
            margin-bottom: 30px;
            opacity: 0.95;
            line-height: 1.8;
        }
        
        .countdown {
            background: rgba(255, 255, 255, 0.2);
            padding: 20px;
            border-radius: 15px;
            margin: 30px 0;
            backdrop-filter: blur(10px);
        }
        
        .countdown-item {
            display: inline-block;
            margin: 0 15px;
        }
        
        .countdown-value {
            font-size: 48px;
            font-weight: bold;
            display: block;
        }
        
        .countdown-label {
            font-size: 14px;
            opacity: 0.9;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <i class="material-icons maintenance-icon">build_circle</i>
        <h1 class="maintenance-title">سایت در دست تعمیر است</h1>
        <p class="maintenance-description">
            ما در حال بهبود سرویس‌های خود هستیم.<br>
            لطفاً چند لحظه دیگر مراجعه فرمایید.
        </p>
        
        <div class="countdown">
            <div class="countdown-item">
                <span class="countdown-value" id="hours">00</span>
                <span class="countdown-label">ساعت</span>
            </div>
            <div class="countdown-item">
                <span class="countdown-value" id="minutes">00</span>
                <span class="countdown-label">دقیقه</span>
            </div>
            <div class="countdown-item">
                <span class="countdown-value" id="seconds">00</span>
                <span class="countdown-label">ثانیه</span>
            </div>
        </div>
        
        <p style="opacity: 0.8;">
            <i class="material-icons align-middle">email</i>
            support@chortke.com
        </p>
    </div>
    
    <script>
        // Countdown Timer (مثال: 2 ساعت)
        let countDownDate = new Date().getTime() + (2 * 60 * 60 * 1000);
        
        let x = setInterval(function() {
            let now = new Date().getTime();
            let distance = countDownDate - now;
            
            let hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            let minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            let seconds = Math.floor((distance % (1000 * 60)) / 1000);
            
            document.getElementById("hours").innerText = String(hours).padStart(2, '0');
            document.getElementById("minutes").innerText = String(minutes).padStart(2, '0');
            document.getElementById("seconds").innerText = String(seconds).padStart(2, '0');
            
            if (distance < 0) {
                clearInterval(x);
                location.reload();
            }
        }, 1000);
    </script>
</body>
</html>