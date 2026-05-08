/**
 * Behavioral CAPTCHA
 * تشخیص رفتار طبیعی کاربر
 */

class BehavioralCaptcha {
    constructor(token) {
        this.token = token;
        this.events = [];
        this.startTime = Date.now();
        this.score = 0;
        this.isActive = true;
        
        this.init();
    }
    
    init() {
        // Mouse Movement
        document.addEventListener('mousemove', (e) => this.recordMouseMove(e));
        
        // Mouse Click
        document.addEventListener('click', (e) => this.recordClick(e));
        
        // Keyboard
        document.addEventListener('keypress', (e) => this.recordKeypress(e));
        
        // Scroll
        window.addEventListener('scroll', () => this.recordScroll());
        
        // Focus/Blur
        window.addEventListener('focus', () => this.recordFocus(true));
        window.addEventListener('blur', () => this.recordFocus(false));
    }
    
    recordMouseMove(e) {
        if (!this.isActive) return;
        
        this.events.push({
            type: 'mouse_move',
            x: e.clientX,
            y: e.clientY,
            time: Date.now() - this.startTime
        });
        
        this.score += 0.5;
        
        // حداکثر 50 رویداد
        if (this.events.length > 50) {
            this.events.shift();
        }
    }
    
    recordClick(e) {
        if (!this.isActive) return;
        
        this.events.push({
            type: 'click',
            x: e.clientX,
            y: e.clientY,
            time: Date.now() - this.startTime
        });
        
        this.score += 2;
    }
    
    recordKeypress(e) {
        if (!this.isActive) return;
        
        this.events.push({
            type: 'keypress',
            time: Date.now() - this.startTime
        });
        
        this.score += 1.5;
    }
    
    recordScroll() {
        if (!this.isActive) return;
        
        this.events.push({
            type: 'scroll',
            scrollY: window.scrollY,
            time: Date.now() - this.startTime
        });
        
        this.score += 1;
    }
    
    recordFocus(hasFocus) {
        if (!this.isActive) return;
        
        this.events.push({
            type: hasFocus ? 'focus' : 'blur',
            time: Date.now() - this.startTime
        });
    }
    
    /**
     * تحلیل رفتار
     */
    analyze() {
        let humanScore = 0;
        
        // 1. تعداد رویدادها
        if (this.events.length > 10) {
            humanScore += 20;
        }
        
        // 2. تنوع رویدادها
        const types = [...new Set(this.events.map(e => e.type))];
        humanScore += types.length * 5;
        
        // 3. زمان صرف شده (حداقل 3 ثانیه)
        const elapsed = (Date.now() - this.startTime) / 1000;
        if (elapsed >= 3) {
            humanScore += 20;
        }
        
        // 4. الگوی حرکت موس (نوسان)
        const mouseEvents = this.events.filter(e => e.type === 'mouse_move');
        if (mouseEvents.length > 5) {
            const jitter = this.calculateJitter(mouseEvents);
            if (jitter > 10) {
                humanScore += 15;
            }
        }
        
        // 5. تعامل واقعی (کلیک + کیبورد)
        const interactions = this.events.filter(e => e.type === 'click' || e.type === 'keypress');
        if (interactions.length > 2) {
            humanScore += 20;
        }
        
        return {
            score: Math.min(humanScore, 100),
            isHuman: humanScore >= 60,
            events: this.events.length
        };
    }
    
    /**
     * محاسبه نوسان (Jitter)
     */
    calculateJitter(mouseEvents) {
        if (mouseEvents.length < 2) return 0;
        
        let totalChange = 0;
        
        for (let i = 1; i < mouseEvents.length; i++) {
            const dx = mouseEvents[i].x - mouseEvents[i-1].x;
            const dy = mouseEvents[i].y - mouseEvents[i-1].y;
            totalChange += Math.sqrt(dx*dx + dy*dy);
        }
        
        return totalChange / mouseEvents.length;
    }
    
    /**
     * دریافت نتیجه
     */
    getResult() {
        this.isActive = false;
        return this.analyze();
    }
}

// Auto-init برای فرم‌ها
document.addEventListener('DOMContentLoaded', () => {
    const captchaToken = document.querySelector('input[name="captcha_token"]');
    
    if (captchaToken && captchaToken.value) {
        window.behavioralCaptcha = new BehavioralCaptcha(captchaToken.value);
        
        // قبل از Submit، بررسی
        const form = captchaToken.closest('form');
        if (form) {
            form.addEventListener('submit', (e) => {
                const result = window.behavioralCaptcha.getResult();
                
                if (!result.isHuman) {
                    e.preventDefault();
                    alert('لطفاً به صورت طبیعی با صفحه تعامل کنید و دوباره تلاش کنید.');
                    return false;
                }
                
                // اضافه کردن نتیجه به فرم
                const scoreInput = document.createElement('input');
                scoreInput.type = 'hidden';
                scoreInput.name = 'behavioral_score';
                scoreInput.value = result.score;
                form.appendChild(scoreInput);
            });
        }
    }
});