/**
 * چرتکه - اسکریپت صفحه هوم (حرفه‌ای)
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        initCounters();
        initFeaturedSlider();
        initInfluencerScroll();
        initFAQ();
        initParticles();
        initBannerTracking();
        initScrollAnimations();
    });

    // ═══ شمارنده آمار ═══
    function initCounters() {
        var cards = document.querySelectorAll('.ch-stat-card');
        if (!cards.length) return;

        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var target = parseInt(entry.target.dataset.target) || 0;
                    var numEl = entry.target.querySelector('.ch-stat-number');
                    if (numEl && target > 0) {
                        animateNum(numEl, 0, target, 2200);
                    }
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.4 });

        cards.forEach(function(c) { observer.observe(c); });
    }

    function animateNum(el, start, end, dur) {
        var startTime = null;
        function step(now) {
            if (!startTime) startTime = now;
            var p = Math.min((now - startTime) / dur, 1);
            var ease = 1 - Math.pow(1 - p, 4);
            el.textContent = formatNum(Math.floor(ease * (end - start) + start));
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    function formatNum(n) {
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // ═══ اسلایدر تبلیغات ═══
    function initFeaturedSlider() {
        var slider = document.getElementById('featuredSlider');
        var prev = document.getElementById('sliderPrev');
        var next = document.getElementById('sliderNext');
        var dots = document.getElementById('sliderDots');

        if (!slider) return;

        var slides = slider.querySelectorAll('.ch-slide');
        if (!slides.length) return;

        var current = 0;
        var timer = null;

        // نقاط
        if (dots && slides.length > 1) {
            for (var i = 0; i < slides.length; i++) {
                var dot = document.createElement('span');
                dot.className = 'ch-slider-dot' + (i === 0 ? ' active' : '');
                dot.dataset.index = i;
                dot.addEventListener('click', function() {
                    go(parseInt(this.dataset.index));
                });
                dots.appendChild(dot);
            }
        }

        function go(idx) {
            current = idx;
            slider.style.transform = 'translateX(' + (current * 100) + '%)';
            updateDots();
        }

        function updateDots() {
            if (!dots) return;
            var allDots = dots.querySelectorAll('.ch-slider-dot');
            allDots.forEach(function(d, idx) {
                d.classList.toggle('active', idx === current);
            });
        }

        function goNext() { go((current + 1) % slides.length); }
        function goPrev() { go((current - 1 + slides.length) % slides.length); }

        if (prev) prev.addEventListener('click', function() { goPrev(); resetTimer(); });
        if (next) next.addEventListener('click', function() { goNext(); resetTimer(); });

        function startTimer() {
            if (slides.length > 1) {
                timer = setInterval(goNext, 5500);
            }
        }

        function resetTimer() {
            clearInterval(timer);
            startTimer();
        }

        startTimer();

        // Touch
        var startX = 0;
        slider.addEventListener('touchstart', function(e) {
            startX = e.changedTouches[0].clientX;
        }, { passive: true });

        slider.addEventListener('touchend', function(e) {
            var diff = startX - e.changedTouches[0].clientX;
            if (Math.abs(diff) > 50) {
                diff > 0 ? goPrev() : goNext();
                resetTimer();
            }
        }, { passive: true });
    }

    // ═══ اسکرول اینفلوئنسرها ═══
    function initInfluencerScroll() {
        var scroll = document.getElementById('infScroll');
        var prev = document.getElementById('infPrev');
        var next = document.getElementById('infNext');

        if (!scroll) return;

        var amount = 230;

        if (prev) prev.addEventListener('click', function() {
            scroll.scrollBy({ left: amount, behavior: 'smooth' });
        });
        if (next) next.addEventListener('click', function() {
            scroll.scrollBy({ left: -amount, behavior: 'smooth' });
        });

        // اسکرول خودکار
        var autoScroll = null;
        function startAuto() {
            autoScroll = setInterval(function() {
                if (scroll.scrollLeft <= 0) {
                    scroll.scrollTo({ left: scroll.scrollWidth, behavior: 'smooth' });
                } else {
                    scroll.scrollBy({ left: -200, behavior: 'smooth' });
                }
            }, 3000);
        }

        function stopAuto() { clearInterval(autoScroll); }

        startAuto();
        scroll.addEventListener('mouseenter', stopAuto);
        scroll.addEventListener('mouseleave', startAuto);
    }

    // ═══ FAQ ═══
    function initFAQ() {
        var questions = document.querySelectorAll('.ch-faq-q');
        questions.forEach(function(q) {
            q.addEventListener('click', function() {
                var item = this.closest('.ch-faq-item');
                var wasActive = item.classList.contains('active');

                // بستن همه
                document.querySelectorAll('.ch-faq-item.active').forEach(function(i) {
                    i.classList.remove('active');
                });

                if (!wasActive) item.classList.add('active');
            });
        });
    }

    // ═══ ذرات هیرو ═══
    function initParticles() {
        var container = document.getElementById('heroParticles');
        if (!container) return;

        for (var i = 0; i < 25; i++) {
            var p = document.createElement('div');
            var size = Math.random() * 5 + 2;
            p.style.cssText =
                'position:absolute;' +
                'width:' + size + 'px;' +
                'height:' + size + 'px;' +
                'background:rgba(255,255,255,' + (Math.random() * 0.25 + 0.08) + ');' +
                'border-radius:50%;' +
                'top:' + (Math.random() * 100) + '%;' +
                'left:' + (Math.random() * 100) + '%;' +
                'animation:chParticle ' + (Math.random() * 10 + 5) + 's ease-in-out infinite;' +
                'animation-delay:' + (Math.random() * 5) + 's;';
            container.appendChild(p);
        }

        if (!document.getElementById('chParticleCSS')) {
            var s = document.createElement('style');
            s.id = 'chParticleCSS';
            s.textContent = '@keyframes chParticle{0%,100%{transform:translateY(0) scale(1);opacity:0.4;}50%{transform:translateY(-25px) scale(1.3);opacity:1;}}';
            document.head.appendChild(s);
        }
    }

    // ═══ ثبت کلیک بنر ═══
    function initBannerTracking() {
        var links = document.querySelectorAll('[data-banner-id]');
        links.forEach(function(link) {
            link.addEventListener('click', function() {
                var id = this.dataset.bannerId;
                if (!id || !window.BASE_URL) return;
                var csrf = document.querySelector('meta[name="csrf-token"]');
                fetch(window.BASE_URL + '/api/banners/' + id + '/click', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf ? csrf.content : ''
                    },
                    body: JSON.stringify({ banner_id: id })
                }).catch(function() {});
            });
        });
    }

    // ═══ انیمیشن اسکرول ═══
    function initScrollAnimations() {
        var elements = document.querySelectorAll('.ch-earn-card, .ch-why-card, .ch-tier, .ch-winner-row, .ch-faq-item');
        if (!elements.length) return;

        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

        elements.forEach(function(el) {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(el);
        });
    }

})();