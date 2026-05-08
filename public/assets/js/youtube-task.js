// public/assets/js/youtube-task.js

/**
 * سیستم تسک بازدید ویدیو یوتیوب
 * ضد تقلب چندلایه
 */
class YouTubeTaskController {
    constructor(config) {
        this.executionId = config.executionId;
        this.csrfToken = config.csrfToken;
        this.submitUrl = config.submitUrl;
        this.minWatchPercent = config.minWatchPercent || 90; // حداقل 90% ویدیو
        this.videoUrl = config.videoUrl;
        this.videoDuration = config.videoDuration || 0; // ثانیه

        // متغیرهای ردیابی
        this.startTime = Date.now();
        this.watchedSeconds = 0;
        this.isTabActive = true;
        this.isMinimized = false;
        this.speedChanged = false;
        this.seekDetected = false;
        this.tabSwitchCount = 0;
        this.mousePositions = [];
        this.mouseMovements = 0;
        this.lastMouseTime = 0;
        this.scrollEvents = [];
        this.focusLossCount = 0;
        this.warningCount = 0;
        this.maxWarnings = 3;
        this.watchInterval = null;
        this.behaviorCheckInterval = null;
        this.completed = false;

        this.init();
    }

    init() {
        this.setupVisibilityDetection();
        this.setupMouseTracking();
        this.setupKeyboardBlock();
        this.setupBeforeUnload();
        this.setupSpeedDetection();
        this.startWatchTimer();
        this.startBehaviorCheck();
        this.renderUI();
    }

    /**
     * تشخیص تغییر تب / Minimize
     */
    setupVisibilityDetection() {
        // Page Visibility API
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.isTabActive = false;
                this.tabSwitchCount++;
                this.focusLossCount++;
                this.pauseWatch();
                this.showWarning('لطفاً از صفحه خارج نشوید. ویدیو متوقف شد.');
            } else {
                this.isTabActive = true;
                this.resumeWatch();
            }
        });

        // Focus/Blur
        window.addEventListener('blur', () => {
            this.isTabActive = false;
            this.focusLossCount++;
            this.pauseWatch();
        });

        window.addEventListener('focus', () => {
            this.isTabActive = true;
            this.resumeWatch();
        });

        // Resize detection (minimize)
        window.addEventListener('resize', () => {
            if (window.outerWidth - window.innerWidth > 200 || 
                window.outerHeight - window.innerHeight > 200) {
                this.isMinimized = true;
                this.pauseWatch();
                this.showWarning('لطفاً پنجره مرورگر را کوچک نکنید.');
            } else {
                this.isMinimized = false;
                this.resumeWatch();
            }
        });
    }

    /**
     * ردیابی حرکت موس (Anti-Bot)
     */
    setupMouseTracking() {
        let lastX = 0, lastY = 0;
        let samePositionCount = 0;
        let straightLineCount = 0;
        let lastAngle = null;

        document.addEventListener('mousemove', (e) => {
            const now = Date.now();

            // ثبت موقعیت
            this.mousePositions.push({
                x: e.clientX,
                y: e.clientY,
                t: now
            });

            // فقط 200 رکورد آخر
            if (this.mousePositions.length > 200) {
                this.mousePositions.shift();
            }

            this.mouseMovements++;
            this.lastMouseTime = now;

            // تشخیص حرکت خطی (ربات)
            if (lastX !== 0 && lastY !== 0) {
                const dx = e.clientX - lastX;
                const dy = e.clientY - lastY;
                const angle = Math.atan2(dy, dx);

                if (lastAngle !== null) {
                    const angleDiff = Math.abs(angle - lastAngle);
                    if (angleDiff < 0.01) { // زاویه تقریباً یکسان = خط صاف
                        straightLineCount++;
                    } else {
                        straightLineCount = Math.max(0, straightLineCount - 1);
                    }
                }
                lastAngle = angle;

                // بدون حرکت
                if (dx === 0 && dy === 0) {
                    samePositionCount++;
                } else {
                    samePositionCount = 0;
                }
            }

            lastX = e.clientX;
            lastY = e.clientY;

            // هشدار حرکت رباتیک
            if (straightLineCount > 20) {
                this.showWarning('حرکت موس غیرطبیعی تشخیص داده شد.');
                straightLineCount = 0;
            }
        });
    }

    /**
     * جلوگیری از میانبرهای صفحه‌کلید
     */
    setupKeyboardBlock() {
        document.addEventListener('keydown', (e) => {
            // جلوگیری از F5 (Refresh)
            if (e.key === 'F5') {
                e.preventDefault();
                this.showWarning('تازه‌سازی صفحه مجاز نیست.');
                return false;
            }

            // جلوگیری از Ctrl+W (Close Tab)
            if (e.ctrlKey && e.key === 'w') {
                e.preventDefault();
                this.showWarning('بستن صفحه مجاز نیست.');
                return false;
            }

            // جلوگیری از Ctrl+T (New Tab)
            if (e.ctrlKey && e.key === 't') {
                e.preventDefault();
                return false;
            }

            // تشخیص DevTools
            if (e.key === 'F12' || 
                (e.ctrlKey && e.shiftKey && e.key === 'I') ||
                (e.ctrlKey && e.shiftKey && e.key === 'J')) {
                e.preventDefault();
                this.showWarning('استفاده از ابزار توسعه‌دهنده مجاز نیست.');
                this.warningCount++;
                return false;
            }
        });
    }

    /**
     * هشدار قبل از بستن صفحه
     */
    setupBeforeUnload() {
        window.addEventListener('beforeunload', (e) => {
            if (!this.completed) {
                e.preventDefault();
                e.returnValue = 'ویدیو هنوز تمام نشده است. آیا مطمئن هستید؟';
                return e.returnValue;
            }
        });
    }

    /**
     * تشخیص تغییر سرعت پخش
     */
    setupSpeedDetection() {
        // بررسی iframe یوتیوب
        this.speedCheckInterval = setInterval(() => {
            try {
                const iframe = document.getElementById('ytVideoFrame');
                if (iframe && iframe.contentWindow) {
                    // نمی‌توانیم مستقیم به iframe یوتیوب دسترسی داشته باشیم
                    // اما با YouTube IFrame API می‌توانیم
                }
            } catch (e) {
                // Cross-origin limitation
            }

            // بررسی جایگزین: مقایسه زمان واقعی با زمان ویدیو
            if (this.videoDuration > 0) {
                const elapsed = (Date.now() - this.startTime) / 1000;
                const expectedProgress = elapsed / this.videoDuration;

                // اگر پیشرفت خیلی سریع باشد → سرعت بالا
                if (this.watchedSeconds > elapsed * 1.3 && elapsed > 30) {
                    this.speedChanged = true;
                    this.showWarning('تغییر سرعت پخش تشخیص داده شد. تسک رد خواهد شد.');
                }
            }
        }, 5000);
    }

    /**
     * تایمر تماشا
     */
    startWatchTimer() {
        this.watchInterval = setInterval(() => {
            if (this.isTabActive && !this.isMinimized) {
                this.watchedSeconds++;
                this.updateProgressUI();

                // بررسی تکمیل
                if (this.videoDuration > 0) {
                    const percent = (this.watchedSeconds / this.videoDuration) * 100;
                    if (percent >= this.minWatchPercent && !this.completed) {
                        this.onComplete();
                    }
                }
            }
        }, 1000);
    }

    /**
     * بررسی رفتاری دوره‌ای
     */
    startBehaviorCheck() {
        this.behaviorCheckInterval = setInterval(() => {
            // بررسی عدم حرکت موس (ربات ممکن است موس را حرکت ندهد)
            const now = Date.now();
            const timeSinceLastMouse = now - this.lastMouseTime;

            // اگر بیش از 2 دقیقه بدون حرکت موس
            if (timeSinceLastMouse > 120000 && this.watchedSeconds > 60) {
                this.showWarning('لطفاً نشان دهید که حضور دارید. موس را حرکت دهید.');
            }

            // بررسی نسبت حرکت موس به زمان
            if (this.watchedSeconds > 30) {
                const movementsPerMinute = this.mouseMovements / (this.watchedSeconds / 60);

                // ربات‌ها: حرکت بسیار زیاد و یکنواخت
                if (movementsPerMinute > 500) {
                    this.showWarning('فعالیت موس غیرطبیعی است.');
                }

                // ربات‌ها: بدون هیچ حرکتی
                if (movementsPerMinute < 2 && this.watchedSeconds > 120) {
                    this.showWarning('لطفاً فعال باشید.');
                }
            }
        }, 30000); // هر 30 ثانیه
    }

    /**
     * توقف تماشا
     */
    pauseWatch() {
        // تایمر ادامه نمی‌یابد وقتی isTabActive = false
    }

    /**
     * ادامه تماشا
     */
    resumeWatch() {
        // تایمر خودکار ادامه می‌یابد
    }

    /**
     * تکمیل تسک
     */
    onComplete() {
        this.completed = true;
        clearInterval(this.watchInterval);
        clearInterval(this.behaviorCheckInterval);
        clearInterval(this.speedCheckInterval);

        // فعال کردن دکمه ارسال
        const submitBtn = document.getElementById('btnSubmitVideo');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.add('btn-pulse');
        }

        this.showSuccess('ویدیو کامل مشاهده شد! اکنون می‌توانید مدرک ارسال کنید.');
    }

    /**
     * ارسال نتیجه
     */
    submit() {
        if (!this.completed) {
            this.showWarning('لطفاً ابتدا ویدیو را کامل مشاهده کنید.');
            return;
        }

        if (this.warningCount >= this.maxWarnings) {
            this.showError('به دلیل تخلفات متعدد، این تسک رد شده است.');
            return;
        }

        const behaviorData = this.collectBehaviorData();

        const submitBtn = document.getElementById('btnSubmitVideo');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="material-icons spin">sync</i> در حال ارسال...';
        }

        // آپلود فرم
        const formData = new FormData();
        const proofInput = document.getElementById('proofImageVideo');
        if (proofInput && proofInput.files[0]) {
            formData.append('proof_image', proofInput.files[0]);
        }
        formData.append('_csrf_token', this.csrfToken);
        formData.append('behavior_data', JSON.stringify(behaviorData));
        formData.append('time_on_page', Math.floor((Date.now() - this.startTime) / 1000));
        formData.append('mouse_data', JSON.stringify(this.mousePositions.slice(-50)));

        fetch(this.submitUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': this.csrfToken },
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (typeof notyf !== 'undefined') notyf.success(data.message);
                setTimeout(() => window.location.href = data.redirect || '/user/tasks/history', 2000);
            } else {
                if (typeof notyf !== 'undefined') notyf.error(data.message);
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="material-icons">send</i> ارسال مدرک';
                }
            }
        })
        .catch(() => {
            if (typeof notyf !== 'undefined') notyf.error('خطا در ارتباط');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="material-icons">send</i> ارسال مدرک';
            }
        });
    }

    /**
     * جمع‌آوری داده‌های رفتاری
     */
    collectBehaviorData() {
        // محاسبه Jitter (نوسان طبیعی موس)
        let jitterScore = 0;
        if (this.mousePositions.length > 10) {
            let totalJitter = 0;
            for (let i = 2; i < this.mousePositions.length; i++) {
                const p0 = this.mousePositions[i - 2];
                const p1 = this.mousePositions[i - 1];
                const p2 = this.mousePositions[i];

                const angle1 = Math.atan2(p1.y - p0.y, p1.x - p0.x);
                const angle2 = Math.atan2(p2.y - p1.y, p2.x - p1.x);
                totalJitter += Math.abs(angle2 - angle1);
            }
            jitterScore = totalJitter / this.mousePositions.length;
        }

        return {
            watched_seconds: this.watchedSeconds,
            video_duration: this.videoDuration,
            watch_percent: this.videoDuration > 0 
                ? Math.round((this.watchedSeconds / this.videoDuration) * 100) 
                : 0,
            tab_switch_count: this.tabSwitchCount,
            focus_loss_count: this.focusLossCount,
            speed_changed: this.speedChanged,
            seek_detected: this.seekDetected,
            warning_count: this.warningCount,
            mouse_movements: this.mouseMovements,
            mouse_jitter_score: Math.round(jitterScore * 1000) / 1000,
            is_bot_suspect: this.warningCount >= 2 || this.speedChanged || jitterScore < 0.01,
            total_time_on_page: Math.floor((Date.now() - this.startTime) / 1000),
            screen_width: window.innerWidth,
            screen_height: window.innerHeight,
        };
    }

    /**
     * رندر UI
     */
    renderUI() {
        const container = document.getElementById('videoTaskUI');
        if (!container) return;

        container.innerHTML = `
            <div class="vt-progress-bar">
                <div class="vt-progress-fill" id="vtProgressFill" style="width:0%"></div>
            </div>
            <div class="vt-info">
                <span id="vtWatched">00:00</span>
                <span id="vtStatus" class="vt-status">در حال تماشا...</span>
                <span id="vtPercent">0%</span>
            </div>
            <div class="vt-warnings" id="vtWarnings"></div>
        `;
    }

    /**
     * بروزرسانی UI پیشرفت
     */
    updateProgressUI() {
        const percent = this.videoDuration > 0 
            ? Math.min(100, Math.round((this.watchedSeconds / this.videoDuration) * 100))
            : 0;

        const fill = document.getElementById('vtProgressFill');
        const watched = document.getElementById('vtWatched');
        const percentEl = document.getElementById('vtPercent');
        const statusEl = document.getElementById('vtStatus');

        if (fill) fill.style.width = percent + '%';
        if (watched) {
            const m = Math.floor(this.watchedSeconds / 60).toString().padStart(2, '0');
            const s = (this.watchedSeconds % 60).toString().padStart(2, '0');
            watched.textContent = m + ':' + s;
        }
        if (percentEl) percentEl.textContent = percent + '%';

        if (statusEl) {
            if (!this.isTabActive) statusEl.textContent = '⏸ متوقف (صفحه غیرفعال)';
            else if (this.completed) statusEl.textContent = '✅ تکمیل شد!';
            else statusEl.textContent = '▶ در حال تماشا...';
        }
    }

    /**
     * نمایش هشدار
     */
    showWarning(message) {
        this.warningCount++;
        const container = document.getElementById('vtWarnings');
        if (container) {
            const div = document.createElement('div');
            div.className = 'vt-warning';
            div.innerHTML = `<i class="material-icons">warning</i> ${message}`;
            container.appendChild(div);
            setTimeout(() => div.remove(), 8000);
        }

        if (this.warningCount >= this.maxWarnings) {
            this.showError('تعداد هشدارهای شما به حد مجاز رسید. این تسک رد خواهد شد.');
        }
    }

    showSuccess(message) {
        if (typeof notyf !== 'undefined') notyf.success(message);
    }

    showError(message) {
        if (typeof notyf !== 'undefined') notyf.error(message);
    }

    /**
     * پاکسازی
     */
    destroy() {
        clearInterval(this.watchInterval);
        clearInterval(this.behaviorCheckInterval);
        clearInterval(this.speedCheckInterval);
    }
}