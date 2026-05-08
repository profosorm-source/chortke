<?php

/**
 * نقطه ورود اصلی مسیرها
 * هر گروه از مسیرها در فایل جداگانه‌ای تعریف شده است.
 */

$app = app();

require_once __DIR__ . '/public.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/user.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/missing.php';
