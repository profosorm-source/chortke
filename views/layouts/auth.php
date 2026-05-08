<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'چرتکه' ?></title>
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    
    <!-- Favicon (از تنظیمات سیستم) -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" type="image/png" href="<?= asset('images/favicon.png') ?>">
    <?php endif; ?>
    
    <!-- Material Icons (local) -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/materialicons/material-icons.css') ?>">
    <!-- Vazirmatn Font (local) -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/vazirmatn/vazirmatn.css') ?>">
    <!-- Bootstrap RTL -->
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/auth.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/vendor/notyf/notyf.min.css') ?>">
    
    <style>
        :root {
            --primary: #00bcd4;
            --primary-dark: #0097a7;
            --secondary: #03a9f4;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .auth-container {
            width: 100%;
            max-width: 450px;
        }
        
        .auth-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .auth-header {
            background: white;
            padding: 30px;
            text-align: center;
            border-bottom: 3px solid var(--primary);
            position: relative;
        }
        
        .auth-header::before {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            left: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--secondary) 100%);
        }
        
        .auth-header h3 {
            color: var(--primary);
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .auth-header p {
            color: #666;
            font-size: 14px;
            margin: 0;
        }
        
        .auth-body {
            padding: 35px 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control {
            border: 1.5px solid #00bcd4;
            border-radius: 8px;
            padding: 12px 15px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
        }
        
        .form-control.is-invalid {
            border-color: #f44336;
        }
        
        .invalid-feedback {
            font-size: 12px;
            color: #f44336;
            margin-top: 5px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 188, 212, 0.3);
        }
        
        .auth-footer {
            padding: 20px 30px;
            background: #f8f9fa;
            text-align: center;
            border-top: 1px solid #e0e0e0;
        }
        
        .auth-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .auth-footer a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        .alert {
            border: none;
            border-radius: 8px;
            border-right: 4px solid;
            padding: 12px 15px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-danger {
            background: #ffebee;
            color: #c62828;
            border-right-color: #f44336;
        }
        
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-right-color: #4caf50;
        }
        
        .alert-info {
            background: #e1f5fe;
            color: #01579b;
            border-right-color: #03a9f4;
        }
        
        .divider {
            text-align: center;
            margin: 25px 0;
            position: relative;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            right: 0;
            left: 0;
            height: 1px;
            background: #e0e0e0;
        }
        
        .divider span {
            background: white;
            padding: 0 15px;
            position: relative;
            color: #999;
            font-size: 13px;
        }
    </style>
    
    <?= $styles ?? '' ?>
</head>
<body>
    <div class="auth-container">
        <?= $content ?? '' ?>
    </div>
    
    <script src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= asset('assets/vendor/notyf/notyf.min.js') ?>"></script>
    <script src="<?= asset('assets/js/app.js') ?>"></script>
    <?= $scripts ?? '' ?>
<?= captcha_refresh_script() ?>
</body>
</html>