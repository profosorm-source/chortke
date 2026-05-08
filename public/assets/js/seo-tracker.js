/**
 * SeoTracker - WebView Engagement Tracker
 */
class SeoTracker {
    constructor(options) {
        this.frameId = options.frameId;
        this.minDuration = options.minDuration || 60;
        this.minScore = options.minScore || 40;
        this.onUpdate = options.onUpdate || (() => {});
        this.onReady = options.onReady || (() => {});
        
        this.data = {
            duration: 0,
            scrollDepth: 0,
            interactions: 0,
            estimatedScore: 0,
            scroll_speed: 0,
            mouse_pattern: 'normal',
            pause_count: 0,
            interaction_types: []
        };
        
        this.startTime = null;
        this.timer = null;
        this.active = false;
        this.lastScrollY = 0;
        this.lastScrollTime = Date.now();
        this.mouseMoves = [];
        this.pauses = [];
    }
    
    start() {
        this.startTime = Date.now();
        this.active = true;
        
        const frame = document.getElementById(this.frameId);
        if (!frame) return;
        
        // Track scroll
        try {
            frame.contentWindow.addEventListener('scroll', () => this.trackScroll(frame));
        } catch (e) {
            console.warn('Cannot track iframe scroll (CORS)');
        }
        
        // Track clicks
        try {
            frame.contentWindow.addEventListener('click', () => this.trackInteraction('click'));
        } catch (e) {}
        
        // Track mouse movement
        try {
            frame.contentWindow.addEventListener('mousemove', (e) => this.trackMouse(e));
        } catch (e) {}
        
        // Timer
        this.timer = setInterval(() => {
            this.data.duration = Math.floor((Date.now() - this.startTime) / 1000);
            this.calculateScore();
            this.onUpdate(this.data);
            
            if (this.data.duration >= this.minDuration && this.data.estimatedScore >= this.minScore) {
                this.onReady();
            }
        }, 1000);
    }
    
    trackScroll(frame) {
        try {
            const doc = frame.contentWindow.document;
            const scrollTop = doc.documentElement.scrollTop || doc.body.scrollTop;
            const scrollHeight = doc.documentElement.scrollHeight || doc.body.scrollHeight;
            const clientHeight = doc.documentElement.clientHeight || doc.body.clientHeight;
            
            this.data.scrollDepth = Math.min(100, (scrollTop + clientHeight) / scrollHeight * 100);
            
            // Calculate scroll speed
            const now = Date.now();
            const timeDiff = (now - this.lastScrollTime) / 1000;
            const scrollDiff = Math.abs(scrollTop - this.lastScrollY);
            this.data.scroll_speed = timeDiff > 0 ? scrollDiff / timeDiff : 0;
            
            this.lastScrollY = scrollTop;
            this.lastScrollTime = now;
            
            // Track pauses
            if (this.data.scroll_speed < 10) {
                this.pauses.push(now);
            }
        } catch (e) {}
    }
    
    trackInteraction(type) {
        this.data.interactions++;
        if (!this.data.interaction_types.includes(type)) {
            this.data.interaction_types.push(type);
        }
    }
    
    trackMouse(e) {
        this.mouseMoves.push({x: e.clientX, y: e.clientY, t: Date.now()});
        if (this.mouseMoves.length > 50) this.mouseMoves.shift();
        
        // Detect linear movement
        if (this.mouseMoves.length > 10) {
            const isLinear = this.isLinearMovement();
            this.data.mouse_pattern = isLinear ? 'linear' : 'normal';
        }
    }
    
    isLinearMovement() {
        if (this.mouseMoves.length < 10) return false;
        const recent = this.mouseMoves.slice(-10);
        let sumDiffX = 0, sumDiffY = 0;
        for (let i = 1; i < recent.length; i++) {
            sumDiffX += Math.abs(recent[i].x - recent[i-1].x);
            sumDiffY += Math.abs(recent[i].y - recent[i-1].y);
        }
        return (sumDiffX < 5 && sumDiffY > 50) || (sumDiffY < 5 && sumDiffX > 50);
    }
    
    calculateScore() {
        // Time score (0-30)
        let timeScore = 0;
        if (this.data.duration >= 300) timeScore = 30;
        else if (this.data.duration >= 120) timeScore = 20;
        else if (this.data.duration >= 60) timeScore = 10;
        
        // Scroll score (0-25)
        let scrollScore = 0;
        if (this.data.scrollDepth >= 80) scrollScore = 25;
        else if (this.data.scrollDepth >= 50) scrollScore = 18;
        else if (this.data.scrollDepth >= 20) scrollScore = 10;
        
        // Interaction score (0-25)
        let interactionScore = 0;
        if (this.data.interactions >= 7) interactionScore = 25;
        else if (this.data.interactions >= 4) interactionScore = 18;
        else if (this.data.interactions >= 1) interactionScore = 10;
        
        // Quality score (0-20)
        let qualityScore = 20;
        if (this.data.scroll_speed > 5000) qualityScore -= 7;
        else if (this.data.scroll_speed > 3000) qualityScore -= 3;
        if (this.data.mouse_pattern === 'linear') qualityScore -= 5;
        if (this.pauses.length < 2) qualityScore -= 4;
        if (this.data.interaction_types.length < 2) qualityScore -= 4;
        qualityScore = Math.max(0, qualityScore);
        
        this.data.estimatedScore = timeScore + scrollScore + interactionScore + qualityScore;
        this.data.pause_count = this.pauses.length;
    }
    
    getData() {
        return {
            duration: this.data.duration,
            scroll_depth: this.data.scrollDepth,
            interactions: this.data.interactions,
            scroll_speed: this.data.scroll_speed,
            mouse_pattern: this.data.mouse_pattern,
            pause_count: this.data.pause_count,
            interaction_types: this.data.interaction_types,
            behavior: {
                scroll_speed: this.data.scroll_speed,
                mouse_pattern: this.data.mouse_pattern,
                pause_count: this.data.pause_count,
                interaction_types: this.data.interaction_types
            }
        };
    }
    
    stop() {
        this.active = false;
        if (this.timer) clearInterval(this.timer);
    }
    
    isActive() {
        return this.active;
    }
}

window.SeoTracker = SeoTracker;
