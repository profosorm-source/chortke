<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  نمایش خطاهای Validation
 * ═══════════════════════════════════════════════════════════════
 */

$errors = errors();

if (!empty($errors)):
?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <h5 class="alert-heading">
        <i class="bi bi-exclamation-triangle-fill"></i>
        خطا در اعتبارسنجی
    </h5>
    <ul class="mb-0">
        <?php foreach ($errors as $field => $fieldErrors): ?>
            <?php foreach ((array)$fieldErrors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>