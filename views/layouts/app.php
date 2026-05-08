<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    
    <title><?= e($title ?? 'چرتکه') ?> - چرتکه</title>
    
    <meta name="description" content="پلتفرم کسب درآمد آنلاین چرتکه">
    <meta name="keywords" content="کسب درآمد, تسک, تبلیغات, سرمایه گذاری">
    <meta name="author" content="چرتکه">
    
    <!-- CSRF Token -->
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    
    <!-- Favicon (از تنظیمات سیستم) -->
    <?= render_site_favicons() ?>
    <?php if (!site_favicon()): ?>
    <link rel="icon" type="image/png" href="<?= asset('images/favicon.png') ?>">
    <?php endif; ?>
    
    <!-- Bootstrap 5 RTL -->
    <link href="<?= asset('assets/vendor/bootstrap/css/bootstrap.rtl.min.css') ?>" rel="stylesheet">
    
    <!-- Material Icons -->
    
    <!-- Google Fonts - Vazir -->
    
    <!-- Custom CSS -->
    <link href="<?= asset('css/style.css') ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset('assets/css/chortke.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/pages.css') ?>">
    
    <style>
        * {
            font-family: 'Vazir', 'Tahoma', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .material-icons, .material-icons-outlined {
            vertical-align: middle;
            font-size: 20px;
        }
    </style>
    
    <?php if (isset($styles)): ?>
        <?= e($styles) ?>
    <?php endif; ?>
</head>
<body>

    <?php if (isset($content)): ?>
        <?= e($content) ?>
    <?php endif; ?>

    <!-- jQuery -->
    
    <!-- Bootstrap JS -->
    <script src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
    
    <!-- Custom JS -->
    <script src="<?= asset('assets/js/app.js') ?>"></script>
    
    <script>
        // CSRF Token برای AJAX
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });
        
        // Toast Notification
        function showToast(message, type = 'success') {
            const toast = `
                <div class="toast align-items-center text-white bg-${type} border-0" role="alert" style="position: fixed; top: 20px; left: 20px; z-index: 9999;">
                    <div class="d-flex">
                        <div class="toast-body">
                            ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            
            $('body').append(toast);
            const toastEl = $('.toast').last();
            const bsToast = new bootstrap.Toast(toastEl[0]);
            bsToast.show();
            
            setTimeout(() => {
                toastEl.remove();
            }, 5000);
        }
    </script>
    
    <?php if (isset($scripts)): ?>
        <?= e($scripts) ?>
    <?php endif; ?>
<?= captcha_refresh_script() ?>
</body>
</html>