(function(){
  'use strict';

  document.addEventListener('DOMContentLoaded', function(){

    function closeAll(){
      document.querySelectorAll('[data-u-dd-menu].is-open').forEach(function(menu){
        menu.classList.remove('is-open');
        menu.style.left = '';
        menu.style.right = '';
      });

      document.querySelectorAll('[data-u-dd-toggle]').forEach(function(btn){
        btn.setAttribute('aria-expanded', 'false');
      });
    }

    function clampMenu(menu){
      // reset to default
      menu.style.right = '0px';
      menu.style.left = 'auto';

      var pad = 12;
      var rect = menu.getBoundingClientRect();

      // اگر از چپ بیرون زد
      if (rect.left < pad) {
        menu.style.left = pad + 'px';
        menu.style.right = 'auto';
      }

      // اگر از راست بیرون زد
      rect = menu.getBoundingClientRect();
      if (rect.right > (window.innerWidth - pad)) {
        menu.style.right = pad + 'px';
        menu.style.left = 'auto';
      }
    }

    document.addEventListener('click', function(e){
      var btn = e.target.closest('[data-u-dd-toggle]');
      var insideMenu = e.target.closest('[data-u-dd-menu]');

      // کلیک داخل منو => بسته نشود
      if (insideMenu && !btn) return;

      // کلیک روی دکمه
      if (btn) {
        e.preventDefault();

        var key = btn.getAttribute('data-u-dd-toggle');
        var menu = document.querySelector('[data-u-dd-menu="' + key + '"]');
        if (!menu) return;

        var wasOpen = menu.classList.contains('is-open');
        closeAll();

        if (!wasOpen) {
          menu.classList.add('is-open');
          btn.setAttribute('aria-expanded', 'true');
          clampMenu(menu);
        }
        return;
      }

      // کلیک بیرون => بستن همه
      closeAll();
    });

    // ESC
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') closeAll();
    });

    // Resize => clamp مجدد اگر باز بود
    window.addEventListener('resize', function(){
      var openMenu = document.querySelector('[data-u-dd-menu].is-open');
      if (openMenu) clampMenu(openMenu);
    });

  });
})();