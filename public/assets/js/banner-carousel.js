/**
 * Chortke Banner Carousel System
 * سیستم چرخش خودکار بنرها
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const containers = document.querySelectorAll('.chortke-banner-container');

        containers.forEach(function(container) {
            const slides = container.querySelectorAll('.chortke-banner-slide');
            const dots = container.querySelectorAll('.chortke-banner-dot');
            
            if (slides.length <= 1) return; // بدون چرخش اگر فقط 1 بنر

            const speed = parseInt(container.dataset.speed) || 5000;
            let currentIndex = 0;
            let interval = null;

            function showSlide(index) {
                slides.forEach(function(s, i) {
                    s.classList.remove('active');
                    if (dots[i]) dots[i].classList.remove('active');
                });

                slides[index].classList.add('active');
                if (dots[index]) dots[index].classList.add('active');
                currentIndex = index;
            }

            function nextSlide() {
                let next = currentIndex + 1;
                if (next >= slides.length) next = 0;
                showSlide(next);
            }

            function startAutoPlay() {
                if (interval) clearInterval(interval);
                interval = setInterval(nextSlide, speed);
            }

            function stopAutoPlay() {
                if (interval) clearInterval(interval);
            }

            // کلیک روی نقاط
            dots.forEach(function(dot) {
                dot.addEventListener('click', function() {
                    const index = parseInt(this.dataset.index);
                    showSlide(index);
                    stopAutoPlay();
                    startAutoPlay();
                });
            });

            // توقف هنگام hover
            container.addEventListener('mouseenter', stopAutoPlay);
            container.addEventListener('mouseleave', startAutoPlay);

            // شروع چرخش
            startAutoPlay();
        });
    });
})();