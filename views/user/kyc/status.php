<?php

$title = 'وضعیت احراز هویت';
$layout = 'user';
ob_start();
?>

<div class="row mb-4">
    <div class="col-12">
        <h3>
            <i class="material-icons-outlined">verified_user</i>
            وضعیت احراز هویت
        </h3>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        
        <?php if (!$kyc): ?>
            
            <div class="card border-0 shadow-sm text-center p-5">
                <i class="material-icons-outlined text-muted" style="font-size: 80px;">cloud_off</i>
                <h4 class="mt-3">هنوز درخواستی ثبت نشده</h4>
                <p class="text-muted">برای استفاده کامل از امکانات، احراز هویت کنید.</p>
                <a href="<?= url('kyc/upload') ?>" class="btn btn-primary">
                    شروع احراز هویت
                </a>
            </div>
            
        <?php else: ?>
            
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    
                    <!-- وضعیت -->
                    <div class="text-center mb-4">
                        <?php
                        $statusIcons = [
                            'pending' => ['icon' => 'hourglass_empty', 'color' => 'warning', 'label' => 'در انتظار بررسی'],
                            'under_review' => ['icon' => 'search', 'color' => 'info', 'label' => 'در حال بررسی'],
                            'verified' => ['icon' => 'check_circle', 'color' => 'success', 'label' => 'تایید شده'],
                            'rejected' => ['icon' => 'cancel', 'color' => 'danger', 'label' => 'رد شده'],
                            'expired' => ['icon' => 'event_busy', 'color' => 'secondary', 'label' => 'منقضی شده']
                        ];
                        
                        $status = $statusIcons[$kyc->status] ?? $statusIcons['pending'];
                        ?>
                        
                        <i class="material-icons-outlined text-<?= e($status['color']) ?>" style="font-size: 80px;">
                            <?= e($status['icon']) ?>
                        </i>
                        <h3 class="mt-3 text-<?= e($status['color']) ?>">
                            <?= e($status['label']) ?>
                        </h3>
                    </div>
                    
                    <!-- جزئیات -->
                    <table class="table table-borderless">
                        <tr>
                            <td class="fw-bold">تاریخ ثبت:</td>
                            <td><?= to_jalali($kyc->submitted_at, 'Y/m/d H:i') ?></td>
                        </tr>
                        
                        <?php if ($kyc->reviewed_at): ?>
                        <tr>
                            <td class="fw-bold">تاریخ بررسی:</td>
                            <td><?= to_jalali($kyc->reviewed_at, 'Y/m/d H:i') ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($kyc->status === 'verified' && $kyc->verified_at): ?>
                        <tr>
                            <td class="fw-bold">تاریخ تایید:</td>
                            <td><?= to_jalali($kyc->verified_at, 'Y/m/d H:i') ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">انقضا:</td>
                            <td><?= to_jalali($kyc->expires_at, 'Y/m/d') ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($kyc->status === 'rejected' && $kyc->rejection_reason): ?>
                        <tr>
                            <td class="fw-bold text-danger">دلیل رد:</td>
                            <td class="text-danger"><?= e($kyc->rejection_reason) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <?php if ($kyc->status === 'rejected'): ?>
                        <div class="text-center mt-4">
                            <a href="<?= url('kyc/upload') ?>" class="btn btn-primary">
                                ارسال مجدد مدارک
                            </a>
                        </div>
                    <?php endif; ?>
                    
                </div>
            </div>
            
        <?php endif; ?>
        
    </div>
</div>
<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>