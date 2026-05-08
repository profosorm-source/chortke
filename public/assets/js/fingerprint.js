/**
 * Browser Fingerprinting Library
 * 
 * جمع‌آوری اطلاعات fingerprint مرورگر برای تشخیص دستگاه
 */

(function(window) {
    'use strict';
    
    const FingerprintCollector = {
        /**
         * جمع‌آوری تمام اطلاعات fingerprint
         */
        async collect() {
            const data = {
                user_agent: this.getUserAgent(),
                language: this.getLanguage(),
                timezone: this.getTimezone(),
                screen: this.getScreenInfo(),
                canvas: await this.getCanvasFingerprint(),
                webgl: this.getWebGLFingerprint(),
                audio: await this.getAudioFingerprint(),
                fonts: this.getFonts(),
                plugins: this.getPlugins(),
                touch_support: this.getTouchSupport(),
                hardware_concurrency: this.getHardwareConcurrency(),
                device_memory: this.getDeviceMemory(),
                platform: this.getPlatform(),
                do_not_track: this.getDoNotTrack(),
                color_depth: this.getColorDepth(),
                pixel_ratio: this.getPixelRatio(),
                session_storage: this.hasSessionStorage(),
                local_storage: this.hasLocalStorage(),
                indexed_db: this.hasIndexedDB(),
                cpu_class: this.getCPUClass(),
                timestamp: Date.now()
            };
            
            return data;
        },
        
        /**
         * User Agent
         */
        getUserAgent() {
            return navigator.userAgent || '';
        },
        
        /**
         * زبان مرورگر
         */
        getLanguage() {
            return navigator.language || navigator.userLanguage || '';
        },
        
        /**
         * منطقه زمانی
         */
        getTimezone() {
            try {
                return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            } catch (e) {
                return new Date().getTimezoneOffset().toString();
            }
        },
        
        /**
         * اطلاعات صفحه نمایش
         */
        getScreenInfo() {
            return `${screen.width}x${screen.height}x${screen.colorDepth}`;
        },
        
        /**
         * Canvas Fingerprint
         */
        async getCanvasFingerprint() {
            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                
                canvas.width = 200;
                canvas.height = 50;
                
                // رسم متن با فونت‌های مختلف
                ctx.textBaseline = 'top';
                ctx.font = '14px "Arial"';
                ctx.textBaseline = 'alphabetic';
                ctx.fillStyle = '#f60';
                ctx.fillRect(125, 1, 62, 20);
                ctx.fillStyle = '#069';
                ctx.fillText('🌐 Browser Fingerprint', 2, 15);
                ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
                ctx.fillText('🌐 Browser Fingerprint', 4, 17);
                
                return canvas.toDataURL();
            } catch (e) {
                return '';
            }
        },
        
        /**
         * WebGL Fingerprint
         */
        getWebGLFingerprint() {
            try {
                const canvas = document.createElement('canvas');
                const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                
                if (!gl) return '';
                
                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                if (!debugInfo) return '';
                
                const vendor = gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL);
                const renderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL);
                
                return `${vendor}~${renderer}`;
            } catch (e) {
                return '';
            }
        },
        
        /**
         * Audio Fingerprint
         */
        async getAudioFingerprint() {
            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return '';
                
                const context = new AudioContext();
                const oscillator = context.createOscillator();
                const analyser = context.createAnalyser();
                const gainNode = context.createGain();
                const scriptProcessor = context.createScriptProcessor(4096, 1, 1);
                
                gainNode.gain.value = 0;
                oscillator.type = 'triangle';
                oscillator.connect(analyser);
                analyser.connect(scriptProcessor);
                scriptProcessor.connect(gainNode);
                gainNode.connect(context.destination);
                oscillator.start(0);
                
                return new Promise((resolve) => {
                    scriptProcessor.onaudioprocess = function(event) {
                        const output = event.outputBuffer.getChannelData(0);
                        const hash = Array.from(output.slice(0, 30))
                            .reduce((acc, val) => acc + Math.abs(val), 0);
                        oscillator.stop();
                        scriptProcessor.disconnect();
                        resolve(hash.toString());
                    };
                });
            } catch (e) {
                return '';
            }
        },
        
        /**
         * فونت‌های نصب شده
         */
        getFonts() {
            const baseFonts = ['monospace', 'sans-serif', 'serif'];
            const fontList = [
                'Arial', 'Verdana', 'Times New Roman', 'Courier New',
                'Georgia', 'Palatino', 'Garamond', 'Bookman', 'Comic Sans MS',
                'Trebuchet MS', 'Impact', 'Tahoma', 'Helvetica', 'Geneva'
            ];
            
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            const detect = (font) => {
                const baseWidths = baseFonts.map(baseFont => {
                    ctx.font = `72px ${baseFont}`;
                    return ctx.measureText('mmmmmmmmmmlli').width;
                });
                
                return baseFonts.some((baseFont, i) => {
                    ctx.font = `72px ${font}, ${baseFont}`;
                    const width = ctx.measureText('mmmmmmmmmmlli').width;
                    return width !== baseWidths[i];
                });
            };
            
            return fontList.filter(detect).join(',');
        },
        
        /**
         * پلاگین‌های نصب شده
         */
        getPlugins() {
            if (!navigator.plugins || navigator.plugins.length === 0) {
                return '';
            }
            
            return Array.from(navigator.plugins)
                .map(p => p.name)
                .sort()
                .join(',');
        },
        
        /**
         * پشتیبانی از Touch
         */
        getTouchSupport() {
            return (
                'ontouchstart' in window ||
                navigator.maxTouchPoints > 0 ||
                navigator.msMaxTouchPoints > 0
            ).toString();
        },
        
        /**
         * تعداد هسته‌های CPU
         */
        getHardwareConcurrency() {
            return navigator.hardwareConcurrency || '';
        },
        
        /**
         * حافظه دستگاه
         */
        getDeviceMemory() {
            return navigator.deviceMemory || '';
        },
        
        /**
         * پلتفرم
         */
        getPlatform() {
            return navigator.platform || '';
        },
        
        /**
         * Do Not Track
         */
        getDoNotTrack() {
            return navigator.doNotTrack || '';
        },
        
        /**
         * عمق رنگ
         */
        getColorDepth() {
            return screen.colorDepth || '';
        },
        
        /**
         * نسبت پیکسل
         */
        getPixelRatio() {
            return window.devicePixelRatio || '';
        },
        
        /**
         * Session Storage
         */
        hasSessionStorage() {
            try {
                return !!window.sessionStorage;
            } catch (e) {
                return false;
            }
        },
        
        /**
         * Local Storage
         */
        hasLocalStorage() {
            try {
                return !!window.localStorage;
            } catch (e) {
                return false;
            }
        },
        
        /**
         * IndexedDB
         */
        hasIndexedDB() {
            return !!window.indexedDB;
        },
        
        /**
         * CPU Class (IE only)
         */
        getCPUClass() {
            return navigator.cpuClass || '';
        },
        
        /**
         * ارسال fingerprint به سرور
         */
        async send(endpoint = '/api/fingerprint') {
            try {
                const data = await this.collect();
                
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(data)
                });
                
                if (!response.ok) {
                    console.warn('Fingerprint submission failed');
                }
                
                return response.json();
            } catch (e) {
                console.error('Fingerprint error:', e);
                return null;
            }
        }
    };
    
    // Export
    window.FingerprintCollector = FingerprintCollector;
    
    // Auto-collect on page load if enabled
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            if (window.AUTO_COLLECT_FINGERPRINT) {
                FingerprintCollector.send();
            }
        });
    } else {
        if (window.AUTO_COLLECT_FINGERPRINT) {
            FingerprintCollector.send();
        }
    }
    
})(window);
