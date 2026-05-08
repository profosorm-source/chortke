<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حالت ایمن</title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Vazirmatn', sans-serif;
            background: linear-gradient(135deg, #ffa726 0%, #fb8c00 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            text-align: center;
            color: white;
        }
        
        .icon {
            font-size: 100px;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        h1 {
            font-size: 36px;
            margin-bottom: 16px;
        }
        
        p {
            font-size: 18px;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <i class="material-icons icon">warning</i>
        <h1>سیستم در حالت ایمن قرار دارد</h1>
        <p>برخی عملیات‌ها موقتاً غیرفعال شده‌اند</p>
    </div>
</body>
</html>
