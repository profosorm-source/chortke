<?php
$title = 'اعلان‌ها';
$layout = 'admin';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/admin-notifications.css') ?>">


<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">همه اعلان‌ها</h5>
                <button type="button" class="btn btn-sm btn-primary" id="markAllRead">
                    <i class="material-icons" style="font-size: 16px; vertical-align: middle;">done_all</i>
                    علامت همه به عنوان خوانده شده
                </button>
            </div>

            <!-- فیلترها -->
            <div class="card-body border-bottom">
                <div class="row g-3">
                    <div class="col-md-4">
                        <select id="filterType" class="form-select form-select-sm">
                            <option value="">همه انواع</option>
                            <option value="kyc_submitted">احراز هویت</option>
                            <option value="bank_card_submitted">کارت بانکی</option>
                            <option value="withdrawal_request">درخواست برداشت</option>
                            <option value="deposit_manual">واریز دستی</option>
                            <option value="new_user">کاربر جدید</option>
                            <option value="new_ticket">تیکت جدید</option>
                            <option value="task_submitted">تسک</option>
                            <option value="story_order">سفارش استوری</option>
                            <option value="content_submitted">محتوا</option>
                            <option value="system_alert">هشدار سیستم</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select id="filterStatus" class="form-select form-select-sm">
                            <option value="">همه وضعیت‌ها</option>
                            <option value="unread">خوانده نشده</option>
                            <option value="read">خوانده شده</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-secondary btn-sm w-100" id="applyFilter">
                            <i class="material-icons" style="font-size: 16px; vertical-align: middle;">filter_list</i>
                            اعمال فیلتر
                        </button>
                    </div>
                </div>
            </div>

            <div class="card-body p-0">
                <?php if (empty($notifications)): ?>
                    <div class="text-center py-5">
                        <i class="material-icons text-muted" style="font-size: 60px;">notifications_none</i>
                        <p class="text-muted mt-3">هیچ اعلانی وجود ندارد</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush" id="notificationList">
                        <?php
                        // نگاشت type → رنگ و آیکون (چون ستون‌های color/icon در جدول نیستند)
                        $typeMap = [
                            'deposit'            => ['color' => 'success',   'icon' => 'account_balance_wallet'],
                            'withdrawal'         => ['color' => 'warning',   'icon' => 'payments'],
                            'task'               => ['color' => 'info',      'icon' => 'task_alt'],
                            'kyc'                => ['color' => 'warning',   'icon' => 'verified_user'],
                            'lottery'            => ['color' => 'purple',    'icon' => 'casino'],
                            'referral'           => ['color' => 'primary',   'icon' => 'people'],
                            'security'           => ['color' => 'danger',    'icon' => 'security'],
                            'investment'         => ['color' => 'success',   'icon' => 'trending_up'],
                            'info'               => ['color' => 'info',      'icon' => 'info'],
                            'system'             => ['color' => 'secondary', 'icon' => 'settings'],
                            'kyc_submitted'      => ['color' => 'warning',   'icon' => 'verified_user'],
                            'bank_card_submitted'=> ['color' => 'info',      'icon' => 'credit_card'],
                            'withdrawal_request' => ['color' => 'danger',    'icon' => 'payments'],
                            'deposit_manual'     => ['color' => 'success',   'icon' => 'account_balance_wallet'],
                            'new_user'           => ['color' => 'primary',   'icon' => 'person_add'],
                            'new_ticket'         => ['color' => 'warning',   'icon' => 'confirmation_number'],
                            'task_submitted'     => ['color' => 'info',      'icon' => 'task_alt'],
                            'story_order'        => ['color' => 'primary',   'icon' => 'auto_stories'],
                            'content_submitted'  => ['color' => 'success',   'icon' => 'article'],
                            'system_alert'       => ['color' => 'danger',    'icon' => 'warning'],
                        ];
                        ?>
                        <?php foreach ($notifications as $notif): ?>
                            <?php
                            // استخراج link، color و icon از ستون‌های واقعی دیتابیس
                            $notifLink  = $notif->action_url ?? null;
                            $notifType  = $notif->type ?? 'system';
                            $notifMeta  = $typeMap[$notifType] ?? ['color' => 'secondary', 'icon' => 'notifications'];
                            $notifColor = $notifMeta['color'];
                            $notifIcon  = $notifMeta['icon'];
                            ?>
                            <a href="<?= $notifLink ?: '#' ?>" 
                               class="list-group-item list-group-item-action notification-item <?= $notif->is_read ? '' : 'unread' ?>"
                               data-id="<?= e($notif->id) ?>"
                               onclick="markAsRead(<?= e($notif->id) ?>)">
                                <div class="d-flex align-items-start">
                                    <!-- آیکون -->
                                    <div class="flex-shrink-0">
                                        <div class="rounded-circle bg-<?= e($notifColor) ?> bg-opacity-10 p-3">
                                            <i class="material-icons text-<?= e($notifColor) ?>" style="font-size: 24px;">
                                                <?= e($notifIcon) ?>
                                            </i>
                                        </div>
                                    </div>

                                    <!-- محتوا -->
                                    <div class="flex-grow-1 ms-3">
                                        <div class="d-flex justify-content-between align-items-start mb-1">
                                            <h6 class="mb-0 <?= $notif->is_read ? 'text-muted' : '' ?>">
                                                <?= e($notif->title) ?>
                                            </h6>
                                            <small class="text-muted">
                                                <?= e(time_ago($notif->created_at)) ?>
                                            </small>
                                        </div>
                                        <p class="mb-1 text-muted small">
                                            <?= e($notif->message) ?>
                                        </p>

                                        <!-- Badge نوع -->
                                        <?php
                                        $typeLabels = [
                                            'kyc_submitted'       => ['label' => 'احراز هویت', 'color' => 'warning'],
                                            'bank_card_submitted'  => ['label' => 'کارت بانکی', 'color' => 'info'],
                                            'withdrawal_request'   => ['label' => 'برداشت',      'color' => 'danger'],
                                            'deposit_manual'       => ['label' => 'واریز',        'color' => 'success'],
                                            'new_user'             => ['label' => 'کاربر جدید',  'color' => 'primary'],
                                            'new_ticket'           => ['label' => 'تیکت',         'color' => 'warning'],
                                            'task_submitted'       => ['label' => 'تسک',          'color' => 'info'],
                                            'story_order'          => ['label' => 'استوری',       'color' => 'primary'],
                                            'content_submitted'    => ['label' => 'محتوا',        'color' => 'success'],
                                            'system_alert'         => ['label' => 'هشدار',        'color' => 'danger'],
                                            'deposit'              => ['label' => 'واریز',        'color' => 'success'],
                                            'withdrawal'           => ['label' => 'برداشت',       'color' => 'warning'],
                                            'task'                 => ['label' => 'تسک',          'color' => 'info'],
                                            'kyc'                  => ['label' => 'احراز هویت',   'color' => 'warning'],
                                            'lottery'              => ['label' => 'قرعه‌کشی',     'color' => 'primary'],
                                            'referral'             => ['label' => 'معرفی',        'color' => 'primary'],
                                            'security'             => ['label' => 'امنیتی',       'color' => 'danger'],
                                            'investment'           => ['label' => 'سرمایه‌گذاری', 'color' => 'success'],
                                            'info'                 => ['label' => 'اطلاع‌رسانی',  'color' => 'info'],
                                            'system'               => ['label' => 'سیستم',        'color' => 'secondary'],
                                        ];
                                        $typeBadge = $typeLabels[$notifType] ?? ['label' => $notifType, 'color' => 'secondary'];
                                        ?>
                                        <span class="badge bg-<?= e($typeBadge['color']) ?> badge-sm">
                                            <?= e($typeBadge['label']) ?>
                                        </span>

                                        <?php if (!$notif->is_read): ?>
                                            <span class="badge bg-primary badge-sm ms-1">جدید</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- دکمه حذف -->
                                    <div class="flex-shrink-0 ms-2">
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-danger btn-delete"
                                                data-id="<?= e($notif->id) ?>"
                                                onclick="event.preventDefault(); event.stopPropagation(); deleteNotification(<?= e($notif->id) ?>)">
                                            <i class="material-icons" style="font-size: 16px;">delete</i>
                                        </button>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($notifications)): ?>
                <div class="card-footer text-center">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="loadMore">
                        بارگذاری بیشتر
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// علامت‌گذاری به عنوان خوانده شده
function markAsRead(id) {
    fetch(`<?= url('/admin/notifications/mark-read/') ?>${id}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '<?= csrf_token() ?>'
        }
    });
}

// علامت‌گذاری همه
document.getElementById('markAllRead')?.addEventListener('click', async function() {
    const btn = this;
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>در حال پردازش...';

    try {
        const response = await fetch('<?= url('/admin/notifications/mark-all-read') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '<?= csrf_token() ?>'
            }
        });

        const data = await response.json();

        if (data.success) {
            notyf.success(data.message);
            setTimeout(() => location.reload(), 1000);
        } else {
            notyf.error(data.message);
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    } catch (error) {
        notyf.error('خطا در ارتباط با سرور');
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

// حذف نوتیفیکیشن
async function deleteNotification(id) {
    const result = await Swal.fire({
        title: 'حذف اعلان',
        text: 'آیا مطمئنید که می‌خواهید این اعلان را حذف کنید؟',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'بله، حذف شود',
        cancelButtonText: 'انصراف'
    });

    if (!result.isConfirmed) return;

    try {
        const response = await fetch(`<?= url('/admin/notifications/delete/') ?>${id}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '<?= csrf_token() ?>'
            }
        });

        const data = await response.json();

        if (data.success) {
            notyf.success(data.message);
            document.querySelector(`[data-id="${id}"]`).closest('.notification-item').remove();
            
            // اگر لیست خالی شد
            if (document.querySelectorAll('.notification-item').length === 0) {
                document.getElementById('notificationList').innerHTML = `
                    <div class="text-center py-5">
                        <i class="material-icons text-muted" style="font-size: 60px;">notifications_none</i>
                        <p class="text-muted mt-3">هیچ اعلانی وجود ندارد</p>
                    </div>
                `;
            }
        } else {
            notyf.error(data.message);
        }
    } catch (error) {
        notyf.error('خطا در ارتباط با سرور');
    }
}

// فیلتر
document.getElementById('applyFilter')?.addEventListener('click', function() {
    const type = document.getElementById('filterType').value;
    const status = document.getElementById('filterStatus').value;
    
    // فیلتر سمت کلاینت (ساده)
    document.querySelectorAll('.notification-item').forEach(item => {
        let show = true;
        
        if (type && !item.querySelector(`.badge:contains('${type}')`)) {
            show = false;
        }
        
        if (status === 'unread' && !item.classList.contains('unread')) {
            show = false;
        } else if (status === 'read' && item.classList.contains('unread')) {
            show = false;
        }
        
        item.style.display = show ? 'block' : 'none';
    });
});

// تابع زمان نسبی
function timeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    
    if (seconds < 60) return 'همین الان';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' دقیقه پیش';
    if (seconds < 86400) return Math.floor(seconds / 3600) + ' ساعت پیش';
    if (seconds < 2592000) return Math.floor(seconds / 86400) + ' روز پیش';
    if (seconds < 31536000) return Math.floor(seconds / 2592000) + ' ماه پیش';
    return Math.floor(seconds / 31536000) + ' سال پیش';
}

// نمایش زمان نسبی برای همه
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.notification-item small.text-muted').forEach(el => {
        const dateStr = el.textContent.trim();
        if (dateStr && dateStr.match(/\d{4}\/\d{2}\/\d{2}/)) {
            // اگر تاریخ کامل باشد، نگه‌دار
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>