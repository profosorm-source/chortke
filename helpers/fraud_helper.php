<?php

/**
 * لود کردن اسکریپت fingerprint
 */
function load_fingerprint_script(): string
{
    return '<script src="' . url('/assets/js/fingerprint.js') . '" defer></script>
<script src="' . url('/assets/js/advanced-fingerprint.js') . '" defer></script>
<script>
    window.AUTO_COLLECT_FINGERPRINT = true;
</script>';
}

/**
 * بررسی اینکه آیا کاربر لاگین است
 */
function should_collect_fingerprint(): bool
{
    return app()->session->has('user_id');
}
