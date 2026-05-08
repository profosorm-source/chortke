<?php
$title = 'اعلان‌ها';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-notifications.css') ?>">

<div class="notifications-page">

    <!-- هدر -->
    <div class="page-header mb-4">
        <div>
            <h4><i class="fas fa-bell"></i> اعلان‌ها</h4>
            <p class="text-muted mb-0">
                <span class="badge bg-danger" id="unreadCountBadge"><?= e($unread_count) ?></span>
                اعلان خوانده نشده
            </p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary btn-sm" id="markAllReadBtn">
                <i class="fas fa-check-double"></i> خواندن همه
            </button>
            <a href="<?= url('/notifications/preferences') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-cog"></i> تنظیمات
            </a>
        </div>
    </div>

    <!-- نوار فیلتر -->
    <div class="d-flex gap-2 mb-3">
        <button class="btn btn-sm btn-primary filter-btn active" data-filter="all">همه</button>
        <button class="btn btn-sm btn-outline-secondary filter-btn" data-filter="unread">نخوانده</button>
    </div>

    <!-- نشانگر polling -->
    <div id="pollingIndicator" class="d-flex align-items-center gap-2 text-muted small mb-3" style="display:none!important">
        <span class="spinner-border spinner-border-sm"></span>
        <span>در انتظار اعلان جدید...</span>
    </div>

    <!-- لیست اعلان‌ها -->
    <div class="notifications-list" id="notificationsList">
        <?php if (!empty($notifications)): ?>
            <?php foreach ($notifications as $notif): ?>
                <?= renderNotification($notif) ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center py-5" id="emptyState">
                <i class="fas fa-bell-slash fa-4x text-muted mb-3"></i>
                <p class="text-muted">اعلانی وجود ندارد</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- pagination -->
    <?php if (($total_pages ?? 1) > 1): ?>
    <div class="d-flex justify-content-center mt-4">
        <nav>
            <ul class="pagination">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <li class="page-item <?= $p === $current_page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= url('/notifications?page=' . $p) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>

</div>

<?php
function renderNotification(object $notif): string
{
    $icons = [
        'system'     => 'fa-info-circle text-secondary',
        'task'       => 'fa-tasks text-info',
        'deposit'    => 'fa-arrow-down text-success',
        'withdrawal' => 'fa-arrow-up text-danger',
        'investment' => 'fa-chart-line text-warning',
        'lottery'    => 'fa-gift text-purple',
        'referral'   => 'fa-users text-teal',
        'kyc'        => 'fa-id-card text-primary',
        'security'   => 'fa-shield-alt text-danger',
        'info'       => 'fa-bell text-info',
        'marketing'  => 'fa-bullhorn text-muted',
    ];
    $icon     = $icons[$notif->type] ?? 'fa-bell text-secondary';
    $unread   = !$notif->is_read ? 'unread' : 'read';
    $priority = 'priority-' . e($notif->priority);
    $id       = (int)$notif->id;
    $actionUrl = !empty($notif->action_url) ? e($notif->action_url) : null;

    $priorityBadge = match ($notif->priority) {
        'urgent' => '<span class="badge bg-danger ms-1">فوری</span>',
        'high'   => '<span class="badge bg-warning ms-1">مهم</span>',
        default  => '',
    };

    $actionBtn = $actionUrl
        ? "<a href=\"/notifications/click?notification_id={$id}\" class=\"notif-action-link\">
               {$notif->action_text} <i class=\"fas fa-arrow-left fa-xs\"></i>
           </a>"
        : '';

    $imageHtml = !empty($notif->image_url)
        ? "<img src=\"" . e($notif->image_url) . "\" class=\"notif-image\" alt=\"\">"
        : '';

    return <<<HTML
<div class="notification-item {$unread} {$priority}" data-id="{$id}" data-type="{$notif->type}">
    <div class="notif-icon {$notif->type}">
        <i class="fas {$icon}"></i>
    </div>
    <div class="notif-content">
        {$imageHtml}
        <div class="notif-title">{$notif->title} {$priorityBadge}</div>
        <p class="notif-message">{$notif->message}</p>
        {$actionBtn}
        <div class="notif-meta">
            <small><i class="far fa-clock"></i> <span class="notif-time" data-time="{$notif->created_at}">{$notif->created_at}</span></small>
        </div>
    </div>
    <div class="notif-actions">
        <button class="btn btn-xs btn-icon mark-read-btn" data-id="{$id}" title="علامت خوانده‌شده" style="display:<?= !$notif->is_read ? 'inline-flex' : 'none' ?>">
            <i class="fas fa-check"></i>
        </button>
        <button class="btn btn-xs btn-icon archive-btn" data-id="{$id}" title="آرشیو">
            <i class="fas fa-archive"></i>
        </button>
        <button class="btn btn-xs btn-icon delete-btn" data-id="{$id}" title="حذف">
            <i class="fas fa-trash"></i>
        </button>
    </div>
</div>
HTML;
}
?>

<script>
(function () {
    'use strict';

    const notyf      = new Notyf({ duration: 2500, position: { x: 'right', y: 'top' } });
    const LIST       = document.getElementById('notificationsList');
    const BADGE      = document.getElementById('unreadCountBadge');
    const INDICATOR  = document.getElementById('pollingIndicator');
    const CSRF       = '<?= csrf_token() ?>';

    // ── آخرین ID برای long polling ───────────────────────────────────────────
    let lastId = <?= !empty($notifications) ? (int)$notifications[0]->id : 0 ?>;

    // ── فیلتر ────────────────────────────────────────────────────────────────
    let currentFilter = 'all';

    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active', 'btn-primary'));
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.add('btn-outline-secondary'));
            this.classList.remove('btn-outline-secondary');
            this.classList.add('active', 'btn-primary');

            currentFilter = this.dataset.filter;
            document.querySelectorAll('.notification-item').forEach(item => {
                item.style.display = (currentFilter === 'unread' && item.classList.contains('read'))
                    ? 'none' : '';
            });
        });
    });

    // ── Long Polling ─────────────────────────────────────────────────────────
    let pollingActive = true;

    async function startPolling() {
        if (!pollingActive) return;

        // فقط اگر last_id داریم polling معنا دارد
        try {
            INDICATOR.style.display = 'flex';

            const res  = await fetch(`<?= url('/notifications/poll') ?>?last_id=${lastId}`, {
                signal: AbortSignal.timeout(35000)   // کمی بیشتر از timeout سرور
            });

            if (!res.ok) throw new Error('HTTP ' + res.status);

            const data = await res.json();

            INDICATOR.style.display = 'none';
            updateBadge(data.unread_count ?? 0);

            if (data.notifications && data.notifications.length > 0) {
                prependNotifications(data.notifications);
                lastId = data.last_id || lastId;
                notyf.success(`${data.notifications.length} اعلان جدید دریافت شد`);
            }

        } catch (err) {
            INDICATOR.style.display = 'none';
            // قطع شدن connection یا timeout — normal
        }

        // ── reconnect با تأخیر ۱–۲ ثانیه ────────────────────────────────────
        if (pollingActive) {
            setTimeout(startPolling, 1500);
        }
    }

    function prependNotifications(notifications) {
        const empty = document.getElementById('emptyState');
        if (empty) empty.remove();

        notifications.forEach(n => {
            const html = buildNotifHTML(n);
            LIST.insertAdjacentHTML('afterbegin', html);
        });

        // اعمال فیلتر فعلی
        if (currentFilter === 'unread') {
            document.querySelectorAll('.notification-item.read').forEach(i => i.style.display = 'none');
        }
    }

    function buildNotifHTML(n) {
        const icons = {
            system: 'fa-info-circle text-secondary', task: 'fa-tasks text-info',
            deposit: 'fa-arrow-down text-success', withdrawal: 'fa-arrow-up text-danger',
            investment: 'fa-chart-line text-warning', lottery: 'fa-gift',
            referral: 'fa-users text-teal', kyc: 'fa-id-card text-primary',
            security: 'fa-shield-alt text-danger', info: 'fa-bell text-info',
        };
        const icon     = icons[n.type] || 'fa-bell text-secondary';
        const priority = n.priority === 'urgent' ? '<span class="badge bg-danger ms-1">فوری</span>'
                       : n.priority === 'high'   ? '<span class="badge bg-warning ms-1">مهم</span>' : '';
        const actionBtn = n.action_url
            ? `<a href="/notifications/click?notification_id=${n.id}" class="notif-action-link">${n.action_text || 'مشاهده'} <i class="fas fa-arrow-left fa-xs"></i></a>`
            : '';

        return `
        <div class="notification-item unread priority-${n.priority}" data-id="${n.id}" data-type="${n.type}">
            <div class="notif-icon ${n.type}"><i class="fas ${icon}"></i></div>
            <div class="notif-content">
                <div class="notif-title">${n.title} ${priority}</div>
                <p class="notif-message">${n.message}</p>
                ${actionBtn}
                <div class="notif-meta"><small><i class="far fa-clock"></i> همین الان</small></div>
            </div>
            <div class="notif-actions">
                <button class="btn btn-xs btn-icon mark-read-btn" data-id="${n.id}" title="خوانده‌شده">
                    <i class="fas fa-check"></i>
                </button>
                <button class="btn btn-xs btn-icon archive-btn" data-id="${n.id}" title="آرشیو">
                    <i class="fas fa-archive"></i>
                </button>
                <button class="btn btn-xs btn-icon delete-btn" data-id="${n.id}" title="حذف">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>`;
    }

    // ── Event delegation برای همه action buttons ─────────────────────────────
    LIST.addEventListener('click', async function (e) {
        const btn = e.target.closest('button[data-id]');
        if (!btn) return;
        const id = btn.dataset.id;

        if (btn.classList.contains('mark-read-btn')) {
            await markRead(id, btn);
        } else if (btn.classList.contains('archive-btn')) {
            await archiveNotif(id);
        } else if (btn.classList.contains('delete-btn')) {
            await deleteNotif(id);
        }
    });

    async function markRead(id, btn) {
        const res  = await postJSON('/notifications/mark-read', { notification_id: id });
        const data = await res.json();
        if (data.success) {
            const item = LIST.querySelector(`.notification-item[data-id="${id}"]`);
            if (item) {
                item.classList.remove('unread');
                item.classList.add('read');
            }
            btn.style.display = 'none';
            updateBadge(data.unread_count ?? 0);
        }
    }

    async function archiveNotif(id) {
        const res  = await postJSON('/notifications/archive', { notification_id: id });
        const data = await res.json();
        if (data.success) {
            removeItem(id);
            notyf.success('آرشیو شد');
        }
    }

    async function deleteNotif(id) {
        const res  = await postJSON('/notifications/delete', { notification_id: id });
        const data = await res.json();
        if (data.success) {
            removeItem(id);
            notyf.success('حذف شد');
        }
    }

    // ── خواندن همه ───────────────────────────────────────────────────────────
    document.getElementById('markAllReadBtn')?.addEventListener('click', async function () {
        confirmAction({
            type: 'confirm',
            title: 'خواندن همه اعلان‌ها',
            text: 'همه اعلان‌ها به عنوان خوانده شده علامت‌گذاری شوند؟',
            confirmButtonText: 'بله',
            onConfirm: async () => {
                const res  = await postJSON('/notifications/mark-all-read', {});
                const data = await res.json();
                if (data.success) {
                    LIST.querySelectorAll('.notification-item.unread').forEach(item => {
                        item.classList.remove('unread');
                        item.classList.add('read');
                        item.querySelector('.mark-read-btn')?.style.setProperty('display', 'none');
                    });
                    updateBadge(0);
                    notyf.success(data.message);
                }
            }
        });
    });

    // ── helpers ───────────────────────────────────────────────────────────────
    function updateBadge(count) {
        if (BADGE) {
            BADGE.textContent = count;
            BADGE.className   = count > 0 ? 'badge bg-danger' : 'badge bg-secondary';
        }
        // navbar badge
        document.querySelectorAll('.notif-navbar-badge').forEach(el => {
            el.textContent = count;
            el.style.display = count > 0 ? 'inline' : 'none';
        });
    }

    function removeItem(id) {
        const item = LIST.querySelector(`.notification-item[data-id="${id}"]`);
        if (item) {
            item.style.transition = 'opacity .25s';
            item.style.opacity    = '0';
            setTimeout(() => item.remove(), 250);
        }
    }

    function postJSON(path, body) {
        return fetch(`<?= rtrim(url('/'), '/') ?>${path}`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body:    JSON.stringify(body),
        });
    }

    // ── شروع polling ─────────────────────────────────────────────────────────
    // فقط وقتی tab فعال است
    document.addEventListener('visibilitychange', () => {
        pollingActive = !document.hidden;
        if (pollingActive) startPolling();
    });

    startPolling();

})();
</script>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>
