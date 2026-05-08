// public/assets/js/seo-scroll.js

class SEOScrollEngine {
    constructor(config) {
        this.executionId = config.executionId;
        this.csrfToken = config.csrfToken;
        this.completeUrl = config.completeUrl;
        this.targetUrl = config.targetUrl;

        this.scrollMin = config.scrollMin || 25;
        this.scrollMax = config.scrollMax || 40;
        this.pauseMin = config.pauseMin || 3;
        this.pauseMax = config.pauseMax || 8;
        this.totalBrowse = config.totalBrowse || 60;

        this.startTime = Date.now();
        this.elapsed = 0;
        this.scrollEvents = [];
        this.scrollIntervals = [];
        this.mouseMovements = 0;
        this.mouseJitter = 0;
        this.tabSwitches = 0;
        this.isActive = true;
        this.completed = false;
        this.scrollTimer = null;
        this.tickTimer = null;

        this.init();
    }

    init() {
        this.setupVisibility();
        this.setupMouse();
        this.setupBeforeUnload();
        this.startAutoScroll();
        this.startTick();
    }

    setupVisibility() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.isActive = false;
                this.tabSwitches++;
                this.showStatus('⏸ صفحه غیرفعال — لطفاً برگردید');
            } else {
                this.isActive = true;
                this.showStatus('▶ در حال مرور...');
            }
        });
    }

    setupMouse() {
        let lastX = 0, lastY = 0, lastAngle = null;
        let jitterTotal = 0, jitterCount = 0;

        document.addEventListener('mousemove', (e) => {
            this.mouseMovements++;
            if (lastX !== 0) {
                const dx = e.clientX - lastX;
                const dy = e.clientY - lastY;
                const angle = Math.atan2(dy, dx);
                if (lastAngle !== null) {
                    jitterTotal += Math.abs(angle - lastAngle);
                    jitterCount++;
                }
                lastAngle = angle;
            }
            lastX = e.clientX;
            lastY = e.clientY;
            this.mouseJitter = jitterCount > 0 ? jitterTotal / jitterCount : 0;
        });
    }

    setupBeforeUnload() {
        window.addEventListener('beforeunload', (e) => {
            if (!this.completed) {
                e.preventDefault();
                e.returnValue = 'تسک هنوز تکمیل نشده. مطمئن هستید؟';
            }
        });
    }

    startTick() {
        this.tickTimer = setInterval(() => {
            if (!this.isActive) return;
            this.elapsed = Math.floor((Date.now() - this.startTime) / 1000);
            this.updateUI();

            if (this.elapsed >= this.totalBrowse && !this.completed) {
                this.onComplete();
            }
        }, 1000);
    }

    startAutoScroll() {
        const scrollPage = () => {
            if (!this.isActive || this.completed) return;

            const scrollAmount = this.randomInt(150, 400);
            const scrollDuration = this.randomInt(800, 2000);

            this.smoothScroll(scrollAmount, scrollDuration);

            this.scrollEvents.push({
                amount: scrollAmount,
                time: Date.now() - this.startTime,
            });

            // فاصله رندوم تا اسکرول بعدی
            const pauseMs = this.randomInt(this.pauseMin * 1000, this.pauseMax * 1000);
            this.scrollIntervals.push(pauseMs / 1000);

            this.scrollTimer = setTimeout(scrollPage, scrollDuration + pauseMs);
        };

        // شروع اولین اسکرول بعد از 2-5 ثانیه
        setTimeout(scrollPage, this.randomInt(2000, 5000));
    }

    smoothScroll(amount, duration) {
        const start = window.scrollY;
        const target = start + amount;
        const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
        const finalTarget = Math.min(target, maxScroll);
        const startTime = Date.now();

        const step = () => {
            const progress = Math.min(1, (Date.now() - startTime) / duration);
            const eased = this.easeInOutCubic(progress);
            window.scrollTo(0, start + (finalTarget - start) * eased);

            if (progress < 1) requestAnimationFrame(step);
        };

        requestAnimationFrame(step);
    }

    easeInOutCubic(t) {
        return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    }

    onComplete() {
        this.completed = true;
        clearTimeout(this.scrollTimer);
        clearInterval(this.tickTimer);

        this.showStatus('✅ مرور تکمیل شد! در حال ارسال نتیجه...');

        const data = {
            total_duration: this.elapsed,
            scroll_duration: this.elapsed,
            browse_duration: this.elapsed,
            scroll_data: {
                events: this.scrollEvents.slice(-20),
                intervals: this.scrollIntervals.slice(-20),
                total_scrolls: this.scrollEvents.length,
            },
            behavior_data: {
                mouse_movements: this.mouseMovements,
                mouse_jitter: Math.round(this.mouseJitter * 1000) / 1000,
                tab_switches: this.tabSwitches,
                screen_width: window.innerWidth,
                screen_height: window.innerHeight,
                execution_token: btoa(this.executionId + '_' + Date.now()), // ساده برای شروع
            },
            _csrf_token: this.csrfToken,
        };

        fetch(this.completeUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
            },
            body: JSON.stringify(data),
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                this.showStatus('✅ ' + result.message);
                if (typeof notyf !== 'undefined') notyf.success(result.message);
                setTimeout(() => window.location.href = '/user/seo-tasks/history', 2500);
            } else {
                this.showStatus('❌ ' + result.message);
                if (typeof notyf !== 'undefined') notyf.error(result.message);
            }
        })
        .catch(() => {
            this.showStatus('❌ خطا در ارسال');
            if (typeof notyf !== 'undefined') notyf.error('خطا در ارتباط');
        });
    }

    updateUI() {
        const progress = Math.min(100, Math.round((this.elapsed / this.totalBrowse) * 100));
        const fill = document.getElementById('seoProgressFill');
        const time = document.getElementById('seoElapsed');
        const pct = document.getElementById('seoPercent');

        if (fill) fill.style.width = progress + '%';
        if (time) {
            const m = Math.floor(this.elapsed / 60).toString().padStart(2, '0');
            const s = (this.elapsed % 60).toString().padStart(2, '0');
            time.textContent = m + ':' + s;
        }
        if (pct) pct.textContent = progress + '%';
    }

    showStatus(text) {
        const el = document.getElementById('seoStatus');
        if (el) el.textContent = text;
    }

    randomInt(min, max) {
        return Math.floor(Math.random() * (max - min + 1)) + min;
    }

    destroy() {
        clearTimeout(this.scrollTimer);
        clearInterval(this.tickTimer);
    }
}