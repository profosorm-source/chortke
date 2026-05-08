/**
 * Advanced Browser Fingerprinting
 */

class AdvancedFingerprint {
    constructor() {
        this.data = {};
    }
    
    /**
     * جمع‌آوری تمام داده‌ها
     */
    async collect() {
        this.data.user_agent = navigator.userAgent;
        this.data.language = navigator.language;
        this.data.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        this.data.screen = `${screen.width}x${screen.height}x${screen.colorDepth}`;
        this.data.hardware_concurrency = navigator.hardwareConcurrency || 'unknown';
        this.data.device_memory = navigator.deviceMemory || 'unknown';
        this.data.touch_support = 'ontouchstart' in window;
        
        // Canvas Fingerprint
        this.data.canvas = await this.getCanvasFingerprint();
        
        // WebGL Fingerprint
        this.data.webgl = await this.getWebGLFingerprint();
        
        // Audio Fingerprint
        this.data.audio = await this.getAudioFingerprint();
        
        // Fonts
        this.data.fonts = await this.getInstalledFonts();
        
        // Plugins
        this.data.plugins = this.getPlugins();
        
        return this.data;
    }
    
    /**
     * Canvas Fingerprint
     */
    async getCanvasFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            if (!ctx) return 'unsupported';
            
            canvas.width = 200;
            canvas.height = 50;
            
            // متن
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillStyle = '#f60';
            ctx.fillRect(125, 1, 62, 20);
            ctx.fillStyle = '#069';
            ctx.fillText('چرتکه 🎯', 2, 15);
            ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
            ctx.fillText('چرتکه 🎯', 4, 17);
            
            // شکل
            ctx.beginPath();
            ctx.arc(50, 25, 20, 0, Math.PI * 2, true);
            ctx.closePath();
            ctx.fill();
            
            return canvas.toDataURL();
        } catch (e) {
            return 'error';
        }
    }
    
    /**
     * WebGL Fingerprint
     */
    async getWebGLFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            
            if (!gl) return 'unsupported';
            
            const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            
            if (!debugInfo) return 'no_debug_info';
            
            return {
                vendor: gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL),
                renderer: gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL)
            };
        } catch (e) {
            return 'error';
        }
    }
    
    /**
     * Audio Fingerprint
     */
    async getAudioFingerprint() {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            
            if (!AudioContext) return 'unsupported';
            
            const context = new AudioContext();
            const oscillator = context.createOscillator();
            const analyser = context.createAnalyser();
            const gainNode = context.createGain();
            const scriptProcessor = context.createScriptProcessor(4096, 1, 1);
            
            gainNode.gain.value = 0; // بدون صدا
            oscillator.connect(analyser);
            analyser.connect(scriptProcessor);
            scriptProcessor.connect(gainNode);
            gainNode.connect(context.destination);
            
            oscillator.start(0);
            
            return new Promise((resolve) => {
                scriptProcessor.onaudioprocess = function(event) {
                    const output = event.inputBuffer.getChannelData(0);
                    const sum = output.reduce((a, b) => a + b, 0);
                    
                    oscillator.stop();
                    scriptProcessor.disconnect();
                    context.close();
                    
                    resolve(sum.toString());
                };
            });
        } catch (e) {
            return 'error';
        }
    }
    
    /**
     * فونت‌های نصب‌شده
     */
    async getInstalledFonts() {
        const baseFonts = ['monospace', 'sans-serif', 'serif'];
        const testFonts = [
            'Arial', 'Verdana', 'Courier New', 'Georgia', 'Times New Roman',
            'Trebuchet MS', 'Comic Sans MS', 'Impact', 'Tahoma', 'Calibri'
        ];
        
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        
        if (!ctx) return [];
        
        const baseSizes = {};
        const testString = 'mmmmmmmmmmlli';
        
        // اندازه پیش‌فرض
        baseFonts.forEach(font => {
            ctx.font = `72px ${font}`;
            baseSizes[font] = ctx.measureText(testString).width;
        });
        
        // تست فونت‌ها
        const detectedFonts = [];
        
        testFonts.forEach(font => {
            let detected = false;
            
            baseFonts.forEach(baseFont => {
                ctx.font = `72px ${font}, ${baseFont}`;
                const size = ctx.measureText(testString).width;
                
                if (size !== baseSizes[baseFont]) {
                    detected = true;
                }
            });
            
            if (detected) {
                detectedFonts.push(font);
            }
        });
        
        return detectedFonts;
    }
    
    /**
     * پلاگین‌ها
     */
    getPlugins() {
        if (!navigator.plugins) return [];
        
        const plugins = [];
        
        for (let i = 0; i < navigator.plugins.length; i++) {
            plugins.push(navigator.plugins[i].name);
        }
        
        return plugins;
    }
    
    /**
     * دریافت Hash
     */
    async getHash() {
        const data = await this.collect();
        const str = JSON.stringify(data);
        
        // SHA-256 Hash (ساده)
        const encoder = new TextEncoder();
        const dataBuffer = encoder.encode(str);
        const hashBuffer = await crypto.subtle.digest('SHA-256', dataBuffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        
        return hashHex;
    }
}

// Global Instance
window.advancedFingerprint = new AdvancedFingerprint();

// Auto-collect on load
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const hash = await window.advancedFingerprint.getHash();
        const data = window.advancedFingerprint.data;
        
        // ارسال به سرور (اختیاری)
        if (typeof sendFingerprintToServer === 'function') {
            sendFingerprintToServer(hash, data);
        }
        
        console.log('Fingerprint Hash:', hash);
    } catch (e) {
        console.error('Fingerprint error:', e);
    }
});