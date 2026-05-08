/**
 * Admin Panel — admin.js
 * Sidebar toggle (mobile) + Menu section accordion
 */
(function () {
  'use strict';

  /* ── Sidebar (mobile) ── */
  const sidebar   = document.querySelector('.sidebar');
  const toggleBtn = document.getElementById('adminSidebarToggle');

  /* inject overlay */
  let overlay = document.querySelector('.admin-sidebar-overlay');
  if (!overlay && sidebar) {
    overlay = document.createElement('div');
    overlay.className = 'admin-sidebar-overlay';
    document.body.appendChild(overlay);
  }

  function openSidebar() {
    if (!sidebar) return;
    sidebar.classList.add('is-open');
    overlay && overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('is-open');
    overlay && overlay.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
  if (overlay)   overlay.addEventListener('click', closeSidebar);

  window.addEventListener('resize', function () {
    if (window.innerWidth > 768) closeSidebar();
  });

  /* ── Menu Sections accordion ── */
  document.addEventListener('DOMContentLoaded', function () {
    const sections = document.querySelectorAll('.menu-section');

    sections.forEach(function (section) {
      const title    = section.querySelector('.section-title');
      const hasActive = section.querySelector('.submenu li.active');

      /* auto-open if there's an active item */
      if (hasActive) {
        section.classList.add('open');
        if (title) title.classList.add('has-active');
      }

      if (title) {
        title.addEventListener('click', function () {
          const isOpen = section.classList.contains('open');

          /* close siblings */
          sections.forEach(function (s) {
            s.classList.remove('open');
          });

          if (!isOpen) section.classList.add('open');
        });
      }
    });
  });

})();
