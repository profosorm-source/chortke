<?php
// فقط برای کاربر لاگین‌شده
if (!auth()) return;
?>
<div class="topbar-icon" id="notifBell">
    <a href="<?= url('/notifications') ?>" class="btn btn-light position-relative">
        <i class="fas fa-bell"></i>
        <span class="position-absolute top-0 start-0 translate-middle badge rounded-pill bg-danger" id="notifBadge" style="display:none">
            0
        </span>
    </a>
</div>

<script>
(function () {
    async function refreshBadge() {
        try {
            const res = await fetch('<?= url('/notifications/unread-count') ?>', { method: 'GET' });
            const data = await res.json();
            if (!data.success) return;

            const badge = document.getElementById('notifBadge');
            if (!badge) return;

            const count = parseInt(data.count || 0);
            if (count > 0) {
                badge.style.display = 'inline-block';
                badge.textContent = count > 99 ? '99+' : count;
            } else {
                badge.style.display = 'none';
                badge.textContent = '0';
            }
        } catch (e) {}
    }

    refreshBadge();
    setInterval(refreshBadge, 15000); // هر 15 ثانیه
})();
</script>