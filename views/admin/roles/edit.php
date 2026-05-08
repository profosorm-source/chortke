<?php
$title = 'ویرایش نقش: ' . e($role->name);
$layout = 'admin';
$session = \Core\Session::getInstance();
$old = $session->getFlash('old') ?? [];
$old = \is_array($old) ? (object) $old : $old;
ob_start();
?>

<div class="content-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="page-title mb-1">
                <i class="material-icons text-primary">edit</i>
                ویرایش نقش: <?= e($role->name) ?>
            </h4>
            <p class="text-muted mb-0" style="font-size:12px;">
                شناسه: <code><?= e($role->slug) ?></code>
                <?php if ($role->is_system): ?>
                    <span class="badge" style="background:#fff3e0;color:#ff9800;font-size:10px;">سیستمی</span>
                <?php endif; ?>
            </p>
        </div>
        <a href="<?= url('/admin/roles') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">arrow_forward</i>
            بازگشت
        </a>
    </div>
</div>

<!-- پیام‌ها -->
<?php if ($flash = $session->getFlash('error')): ?>
<div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
    <i class="material-icons" style="font-size:18px;vertical-align:middle;">error</i>
    <?= e($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form action="<?= url('/admin/roles/' . $role->id . '/update') ?>" method="POST">
    <?= csrf_field() ?>
    
    <!-- اطلاعات پایه -->
    <div class="card mt-3">
        <div class="card-header">
            <h6 class="card-title mb-0">
                <i class="material-icons text-primary" style="font-size:18px;vertical-align:middle;">info</i>
                اطلاعات نقش
            </h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">نام نقش <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" 
                           value="<?= e($old->name ?? $role->name) ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">شناسه (slug)</label>
                    <input type="text" class="form-control" value="<?= e($role->slug) ?>" 
                           disabled dir="ltr" style="text-align:left;background:#f5f5f5;">
                    <small class="text-muted">شناسه نقش قابل تغییر نیست</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">توضیحات</label>
                    <input type="text" name="description" class="form-control" 
                           value="<?= e($old->description ?? $role->description ?? '') ?>">
                </div>
            </div>
            
            <?php if (!$role->is_system): ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input"
                               id="is_active" <?= ($old->is_active ?? $role->is_active) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">نقش فعال</label>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- دسترسی‌ها -->
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0">
                <i class="material-icons text-primary" style="font-size:18px;vertical-align:middle;">vpn_key</i>
                دسترسی‌ها
            </h6>
            <div>
                <button type="button" class="btn btn-sm btn-outline-success" id="btn-select-all">
                    انتخاب همه
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-deselect-all">
                    حذف همه
                </button>
            </div>
        </div>
        <div class="card-body">
            <?php foreach ($groupedPermissions as $group => $perms): ?>
            <?php
                $groupPermIds = \array_map(function($p) { return $p->id; }, $perms);
                $allChecked = empty(\array_diff($groupPermIds, $rolePermissionIds));
                $someChecked = !empty(\array_intersect($groupPermIds, $rolePermissionIds));
            ?>
            <div class="permission-group mb-4">
                <div class="d-flex align-items-center mb-2">
                    <input type="checkbox" class="form-check-input me-2 group-toggle" 
                           data-group="<?= e($group) ?>" id="group-<?= e($group) ?>"
                           <?= $allChecked ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="group-<?= e($group) ?>" style="font-size:14px;">
                        <?= e($groupLabels[$group] ?? $group) ?>
                    </label>
                    <span class="text-muted ms-2" style="font-size:11px;">
                        (<?= \count(\array_intersect($groupPermIds, $rolePermissionIds)) ?>/<?= \count($perms) ?>)
                    </span>
                </div>
                <div class="row pe-4" style="margin-right:20px;">
                    <?php foreach ($perms as $perm): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-2">
                        <div class="form-check">
                            <input type="checkbox" name="permissions[]" 
                                   value="<?= e($perm->id) ?>"
                                   class="form-check-input perm-checkbox perm-group-<?= e($group) ?>"
                                   id="perm-<?= e($perm->id) ?>"
                                   <?= \in_array($perm->id, $rolePermissionIds) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="perm-<?= e($perm->id) ?>" style="font-size:12px;">
                                <?= e($perm->name) ?>
                                <?php if ($perm->description): ?>
                                <i class="material-icons text-muted" style="font-size:14px;vertical-align:middle;cursor:help;" 
                                   title="<?= e($perm->description) ?>">help_outline</i>
                                <?php endif; ?>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($group !== \array_key_last($groupedPermissions)): ?>
            <hr style="border-color:#f0f0f0;">
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- دکمه‌ها -->
    <div class="d-flex justify-content-end gap-2 mt-3 mb-4">
        <a href="<?= url('/admin/roles') ?>" class="btn btn-outline-secondary">انصراف</a>
        <button type="submit" class="btn btn-primary">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">save</i>
            ذخیره تغییرات
        </button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // انتخاب همه
    document.getElementById('btn-select-all').addEventListener('click', function() {
        document.querySelectorAll('.perm-checkbox').forEach(function(cb) { cb.checked = true; });
        document.querySelectorAll('.group-toggle').forEach(function(cb) { cb.checked = true; cb.indeterminate = false; });
    });
    
    // حذف همه
    document.getElementById('btn-deselect-all').addEventListener('click', function() {
        document.querySelectorAll('.perm-checkbox').forEach(function(cb) { cb.checked = false; });
        document.querySelectorAll('.group-toggle').forEach(function(cb) { cb.checked = false; cb.indeterminate = false; });
    });
    
    // تاگل گروه
    document.querySelectorAll('.group-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            var group = this.dataset.group;
            var checked = this.checked;
            this.indeterminate = false;
            document.querySelectorAll('.perm-group-' + group).forEach(function(cb) {
                cb.checked = checked;
            });
        });
        
        // وضعیت اولیه indeterminate
        var group = toggle.dataset.group;
        var allInGroup = document.querySelectorAll('.perm-group-' + group);
        var checkedInGroup = document.querySelectorAll('.perm-group-' + group + ':checked');
        if (checkedInGroup.length > 0 && checkedInGroup.length < allInGroup.length) {
            toggle.indeterminate = true;
            toggle.checked = false;
        }
    });
    
    // بروزرسانی تاگل گروه
    document.querySelectorAll('.perm-checkbox').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var classes = this.className.split(' ');
            var groupClass = classes.find(function(c) { return c.startsWith('perm-group-'); });
            if (!groupClass) return;
            var group = groupClass.replace('perm-group-', '');
            var allInGroup = document.querySelectorAll('.perm-group-' + group);
            var checkedInGroup = document.querySelectorAll('.perm-group-' + group + ':checked');
            var groupToggle = document.querySelector('.group-toggle[data-group="' + group + '"]');
            if (groupToggle) {
                if (checkedInGroup.length === 0) {
                    groupToggle.checked = false;
                    groupToggle.indeterminate = false;
                } else if (allInGroup.length === checkedInGroup.length) {
                    groupToggle.checked = true;
                    groupToggle.indeterminate = false;
                } else {
                    groupToggle.checked = false;
                    groupToggle.indeterminate = true;
                }
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>