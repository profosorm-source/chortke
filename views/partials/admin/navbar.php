<?php
// ─── Admin Navbar Partial v2 ──────────────────────────────────
// طراحی مبتنی بر نمونه admin-panel.html
?>
<!-- ══════════════════════════════════════════
     ADMIN TOPBAR
     ══════════════════════════════════════════ -->
<header class="topbar" id="adminTopbar">

    <!-- Hamburger (mobile) -->
    <button class="topbar-hamburger" onclick="toggleAdminSidebar()" title="منو">
        <span class="material-icons">menu</span>
    </button>

    <!-- Breadcrumb / Title -->
    <div class="topbar-breadcrumb">
        <span><?= e(setting('site_name', 'چورتکه')) ?></span>
        <span class="material-icons sep">chevron_left</span>
        <span class="current"><?= e($title ?? 'پنل مدیریت') ?></span>
    </div>

    <!-- Actions -->
    <div class="topbar-actions">

        <!-- Global Search -->
        <div class="topbar-search-wrap" id="adminSearchWrap">
            <span class="material-icons topbar-search-icon">search</span>
            <input class="topbar-search" type="text" id="adminSearchInput"
                   placeholder="جستجوی سریع..." autocomplete="off">
            <div id="adminSearchResults" style="display:none"></div>
        </div>

        <!-- Notifications -->
        <div class="notif-bell-wrap" id="notifBellWrap">
            <button class="topbar-btn" id="notifBellBtn" title="اعلان‌ها" onclick="toggleNotifDropdown(event)">
                <span class="material-icons">notifications</span>
                <span class="notif-badge" id="notifBadge" style="display:none">0</span>
            </button>

            <!-- Dropdown -->
            <div class="notif-dropdown" id="notifDropdown" style="display:none">
                <div class="notif-dropdown-header">
                    <span>اعلان‌ها</span>
                    <button class="notif-mark-all-btn" id="notifMarkAllBtn" title="همه را خوانده کن">
                        <span class="material-icons" style="font-size:16px">done_all</span>
                    </button>
                </div>
                <div class="notif-dropdown-list" id="notifDropdownList">
                    <div class="notif-empty">در حال بارگذاری...</div>
                </div>
                <a href="<?= url('/admin/notifications') ?>" class="notif-dropdown-footer">
                    مشاهده همه اعلان‌ها
                    <span class="material-icons" style="font-size:14px">chevron_left</span>
                </a>
            </div>
        </div>

        <!-- Tickets -->
        <a class="topbar-btn" href="<?= url('/admin/tickets') ?>" title="تیکت‌ها">
            <span class="material-icons">support_agent</span>
        </a>

        <!-- Logs -->
        <a class="topbar-btn" href="<?= url('/admin/logs') ?>" title="لاگ‌ها">
            <span class="material-icons">terminal</span>
        </a>

        <!-- Theme Toggle -->
        <button class="topbar-btn theme-toggle-btn" id="themeToggleBtn" onclick="adminToggleTheme()" title="تغییر تم">
            <span class="material-icons" id="themeIcon">light_mode</span>
        </button>

        <!-- Clock -->
        <div class="topbar-time" id="adminClock">--:--</div>

        <!-- User Info -->
        <div class="user-info">
            <div class="user-details">
                <p class="user-name"><?= e($fullName ?? 'مدیر') ?></p>
                <p class="user-role"><?= e($userRole ?? 'مدیر کل') ?></p>
            </div>
            <div class="user-avatar"><?= strtoupper($firstLetter ?? 'م') ?></div>
        </div>

    </div>
</header>

<script>
// ─── Notification Bell ────────────────────────────────────────
(function () {
    const FETCH_URL     = '<?= url('/admin/notifications/fetch') ?>';
    const COUNT_URL     = '<?= url('/admin/notifications/unread-count') ?>';
    const MARK_URL      = '<?= url('/admin/notifications/mark-read/') ?>';
    const MARK_ALL_URL  = '<?= url('/admin/notifications/mark-all-read') ?>';
    const CSRF          = '<?= csrf_token() ?>';

    const typeMap = {
        deposit:'account_balance_wallet', withdrawal:'payments', task:'task_alt',
        kyc:'verified_user', lottery:'casino', referral:'people',
        security:'security', investment:'trending_up', info:'info',
        system:'settings', kyc_submitted:'verified_user', bank_card_submitted:'credit_card',
        withdrawal_request:'payments', deposit_manual:'account_balance_wallet',
        new_user:'person_add', new_ticket:'confirmation_number',
        task_submitted:'task_alt', story_order:'auto_stories',
        content_submitted:'article', system_alert:'warning',
    };

    let dropdownOpen = false;
    let pollTimer    = null;

    /* ── Badge آپدیت ─────────────────────────────────────────── */
    async function updateBadge() {
        try {
            const res   = await fetch(COUNT_URL);
            const data  = await res.json();
            if (!data.success) return;
            const count = parseInt(data.count || 0);
            const badge = document.getElementById('notifBadge');
            if (!badge) return;
            if (count > 0) {
                badge.textContent    = count > 99 ? '99+' : count;
                badge.style.display  = 'flex';
            } else {
                badge.style.display  = 'none';
            }
        } catch(e) {}
    }

    /* ── بارگذاری لیست dropdown ──────────────────────────────── */
    async function loadDropdown() {
        const list = document.getElementById('notifDropdownList');
        if (!list) return;
        list.innerHTML = '<div class="notif-empty">در حال بارگذاری...</div>';
        try {
            const res  = await fetch(FETCH_URL);
            const data = await res.json();
            if (!data.success || !data.notifications?.length) {
                list.innerHTML = '<div class="notif-empty">اعلانی وجود ندارد</div>';
                return;
            }
            list.innerHTML = data.notifications.map(n => {
                const icon  = typeMap[n.type] || 'notifications';
                const unread = !n.is_read ? 'notif-item--unread' : '';
                const time  = n.created_at || '';
                return `
                <div class="notif-item ${unread}" data-id="${n.id}" onclick="adminNotifRead(${n.id}, this)">
                    <div class="notif-item-icon"><span class="material-icons">${icon}</span></div>
                    <div class="notif-item-body">
                        <div class="notif-item-title">${escHtml(n.title || '')}</div>
                        <div class="notif-item-msg">${escHtml(n.message || '')}</div>
                        <div class="notif-item-time">${escHtml(time)}</div>
                    </div>
                    ${!n.is_read ? '<div class="notif-item-dot"></div>' : ''}
                </div>`;
            }).join('');
            // آپدیت badge با تعداد واقعی
            const badge = document.getElementById('notifBadge');
            const uc    = parseInt(data.unread_count || 0);
            if (badge) {
                if (uc > 0) { badge.textContent = uc > 99 ? '99+' : uc; badge.style.display = 'flex'; }
                else        { badge.style.display = 'none'; }
            }
        } catch(e) {
            list.innerHTML = '<div class="notif-empty">خطا در بارگذاری</div>';
        }
    }

    /* ── باز/بسته کردن dropdown ──────────────────────────────── */
    window.toggleNotifDropdown = function(e) {
        e.stopPropagation();
        const dd = document.getElementById('notifDropdown');
        if (!dd) return;
        dropdownOpen = !dropdownOpen;
        dd.style.display = dropdownOpen ? 'block' : 'none';
        if (dropdownOpen) loadDropdown();
    };

    document.addEventListener('click', function(e) {
        const wrap = document.getElementById('notifBellWrap');
        if (wrap && !wrap.contains(e.target)) {
            const dd = document.getElementById('notifDropdown');
            if (dd) dd.style.display = 'none';
            dropdownOpen = false;
        }
    });

    /* ── خواندن یک نوتیف ─────────────────────────────────────── */
    window.adminNotifRead = async function(id, el) {
        try {
            await fetch(MARK_URL + id, { method:'POST', headers:{'X-CSRF-TOKEN':CSRF,'Content-Type':'application/json'} });
        } catch(e) {}
        if (el) {
            el.classList.remove('notif-item--unread');
            const dot = el.querySelector('.notif-item-dot');
            if (dot) dot.remove();
        }
        updateBadge();
    };

    /* ── خواندن همه ──────────────────────────────────────────── */
    document.getElementById('notifMarkAllBtn')?.addEventListener('click', async function(e) {
        e.stopPropagation();
        try {
            await fetch(MARK_ALL_URL, { method:'POST', headers:{'X-CSRF-TOKEN':CSRF,'Content-Type':'application/json'} });
        } catch(e) {}
        document.querySelectorAll('.notif-item--unread').forEach(el => {
            el.classList.remove('notif-item--unread');
            el.querySelector('.notif-item-dot')?.remove();
        });
        const badge = document.getElementById('notifBadge');
        if (badge) badge.style.display = 'none';
    });

    /* ── helper ──────────────────────────────────────────────── */
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ── poll هر ۳۰ ثانیه ───────────────────────────────────── */
    updateBadge();
    pollTimer = setInterval(updateBadge, 30000);
})();

// ─── Theme Toggle ─────────────────────────────────────────────
function adminToggleTheme() {
    const html = document.documentElement;
    const icon = document.getElementById('themeIcon');
    if (html.classList.contains('light')) {
        html.classList.remove('light');
        icon.textContent = 'light_mode';
        localStorage.setItem('adminTheme', 'dark');
    } else {
        html.classList.add('light');
        icon.textContent = 'dark_mode';
        localStorage.setItem('adminTheme', 'light');
    }
}

// Apply saved theme
(function() {
    const saved = localStorage.getItem('adminTheme');
    const prefersDark = window.matchMedia('(prefers-color-scheme:dark)').matches;
    if (saved === 'light' || (saved === null && !prefersDark)) {
        document.documentElement.classList.add('light');
        const icon = document.getElementById('themeIcon');
        if (icon) icon.textContent = 'dark_mode';
    }
})();

// ─── Clock ────────────────────────────────────────────────────
function updateAdminClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    const s = String(now.getSeconds()).padStart(2, '0');
    const el = document.getElementById('adminClock');
    if (el) el.textContent = `${h}:${m}:${s}`;
}
setInterval(updateAdminClock, 1000);
updateAdminClock();

// ─── Sidebar Toggle ───────────────────────────────────────────
function toggleAdminSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    if (sidebar) sidebar.classList.toggle('open');
}

// ─── Global Search ─────────────────────────────────────────────
(function() {
    const input   = document.getElementById('adminSearchInput');
    const results = document.getElementById('adminSearchResults');
    if (!input || !results) return;

    let timer = null;

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { results.style.display = 'none'; results.innerHTML = ''; return; }
        timer = setTimeout(() => doSearch(q), 300);
    });

    input.addEventListener('focus', () => {
        if (results.innerHTML) results.style.display = '';
    });

    document.addEventListener('click', e => {
        if (!input.contains(e.target) && !results.contains(e.target)) {
            results.style.display = 'none';
        }
    });

    async function doSearch(q) {
        results.style.display = '';
        results.innerHTML = '<div style="padding:12px;text-align:center;color:var(--text-muted);font-size:12px">در حال جستجو...</div>';

        let data = null;
        try {
            const r = await fetch(`<?= url('/admin/search') ?>?q=${encodeURIComponent(q)}`);
            data = await r.json();
        } catch(e) {
            results.innerHTML = '<div style="padding:12px;text-align:center;color:var(--red);font-size:12px">خطا در جستجو</div>';
            return;
        }

        if (!data || !data.results) {
            results.innerHTML = '<div style="padding:12px;text-align:center;color:var(--text-muted);font-size:12px">نتیجه‌ای یافت نشد</div>';
            return;
        }

        const sections = [
            { key:'users',        label:'کاربران',   icon:'person',              url: id => `<?= url('/admin/users') ?>/${id}/edit` },
            { key:'transactions', label:'تراکنش‌ها', icon:'receipt',             url: id => `<?= url('/admin/transactions') ?>` },
            { key:'tickets',      label:'تیکت‌ها',   icon:'confirmation_number', url: id => `<?= url('/admin/tickets') ?>/${id}` },
            { key:'withdrawals',  label:'برداشت‌ها', icon:'payments',            url: id => `<?= url('/admin/withdrawals') ?>` },
        ];

        let html = '';
        for (const sec of sections) {
            const items = data.results[sec.key] ?? [];
            if (!items.length) continue;
            html += `<div class="search-section-header">
                        <span class="material-icons" style="font-size:13px!important;vertical-align:middle">${sec.icon}</span>
                        ${sec.label}
                     </div>`;
            for (const item of items) {
                const label = item.full_name || item.subject || item.title || `#${item.id}`;
                const sub   = item.email || item.description || item.status || '';
                html += `<a href="${sec.url(item.id)}" class="search-result-item">
                            <span class="material-icons" style="font-size:16px!important;flex-shrink:0">${sec.icon}</span>
                            <div>
                                <div style="font-size:13px;font-weight:500">${label}</div>
                                ${sub ? `<div style="font-size:11px;color:var(--text-muted)">${sub}</div>` : ''}
                            </div>
                         </a>`;
            }
        }

        if (!html) {
            html = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:12px">نتیجه‌ای یافت نشد</div>';
        }

        results.innerHTML = html;
    }
})();
</script>