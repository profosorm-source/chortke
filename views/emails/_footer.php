<?php
/**
 * Email Footer Partial
 */
$__siteName = setting('site_name', 'چرتکه');
$__siteUrl  = url('/');
$__year     = to_jalali(date('Y-m-d'), 'Y');
?>
        <div class="footer" style="background: #f9f9f9; padding: 20px; text-align: center; color: #999; font-size: 12px; border-top: 1px solid #eee;">
            <p style="margin: 0 0 6px;">
                &copy; <?= $__year ?> <a href="<?= $__siteUrl ?>" style="color: #667eea; text-decoration: none;"><?= e($__siteName) ?></a>
                — تمامی حقوق محفوظ است.
            </p>
            <?php $__email = setting('contact_email'); if ($__email): ?>
            <p style="margin: 0; font-size: 11px;">
                پشتیبانی: <a href="mailto:<?= e($__email) ?>" style="color: #999;"><?= e($__email) ?></a>
            </p>
            <?php endif; ?>
        </div>