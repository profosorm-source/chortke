(function () {
  'use strict';

  function qs(sel, ctx){ return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx){ return Array.from((ctx || document).querySelectorAll(sel)); }

  function openSubmenu(toggleBtn, submenu) {
    toggleBtn.classList.add('is-open');
    submenu.classList.add('is-open');
  }

  function closeSubmenu(toggleBtn, submenu) {
    toggleBtn.classList.remove('is-open');
    submenu.classList.remove('is-open');
  }

  function toggleSubmenu(btn) {
    const target = btn.getAttribute('data-target');
    if (!target) return;
    const submenu = qs(target);
    if (!submenu) return;

    const isOpen = submenu.classList.contains('is-open');
    if (isOpen) closeSubmenu(btn, submenu);
    else openSubmenu(btn, submenu);
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Toggle submenus
    qsa('[data-toggle="submenu"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        toggleSubmenu(btn);
      });
    });

    // Auto open parents of active links
    qsa('.u-nav a.is-active, .u-submenu a.is-active').forEach(function (activeLink) {
      let parent = activeLink.closest('.u-submenu');
      while (parent) {
        parent.classList.add('is-open');

        // find related toggle
        const id = parent.getAttribute('id');
        if (id) {
          const toggle = qs('[data-target="#' + id + '"]');
          if (toggle) toggle.classList.add('is-open');
        }

        parent = parent.parentElement ? parent.parentElement.closest('.u-submenu') : null;
      }
    });

    // Mobile open/close (اگر دکمه‌ای در تاپ‌بار دارید با id=sidebarToggle وصل کنید)
    const sidebar = qs('#uSidebar');
    const overlay = qs('#uSidebarOverlay');
    const closeBtn = qs('#uSidebarClose');
    const toggleBtn = qs('#sidebarToggle'); // اگر داری؛ اگر نداری مهم نیست

    function openMobileSidebar(){
      if (!sidebar || !overlay) return;
      sidebar.classList.add('is-mobile-open');
      overlay.classList.add('is-show');
    }
    function closeMobileSidebar(){
      if (!sidebar || !overlay) return;
      sidebar.classList.remove('is-mobile-open');
      overlay.classList.remove('is-show');
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openMobileSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeMobileSidebar);
    if (overlay) overlay.addEventListener('click', closeMobileSidebar);
  });
  document.addEventListener('DOMContentLoaded', function() {
    const menuSections = document.querySelectorAll('.menu-section');
    menuSections.forEach(section => {
        const title = section.querySelector('.section-title');
        // باز کردن منوی فعال به صورت پیش‌فرض
        const hasActive = section.querySelector('.submenu li.active');
        if (hasActive) {
            section.classList.add('open');
        }
        title.addEventListener('click', function() {
            section.classList.toggle('open');
        });
    });
});
})();