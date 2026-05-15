/**
 * Chortke Security Utilities
 * 
 * این فایل شامل توابع امنیتی برای:
 * - تولید Idempotency Key
 * - تولید Device Fingerprint
 * - Request Tracking
 * 
 * نسخه: 2.0.0
 * تاریخ: 2026-02-21
 */

(function(window) {
    'use strict';

    /**
     * تولید Idempotency Key یکتا
     * 
     * @param {string} prefix - پیشوند (مثلاً WTH, DEP, MDP)
     * @returns {string} کلید یکتا
     */
    function generateIdempotencyKey(prefix = 'REQ') {
        const now = new Date();
        
        // Format: PREFIX_YYYYMMDD_HHMMSS_RANDOM
        const timestamp = now.getFullYear() +
            String(now.getMonth() + 1).padStart(2, '0') +
            String(now.getDate()).padStart(2, '0') + '_' +
            String(now.getHours()).padStart(2, '0') +
            String(now.getMinutes()).padStart(2, '0') +
            String(now.getSeconds()).padStart(2, '0');
        
        // Random component (cryptographically secure alphanumeric, 12 chars)
        const charset = 'abcdefghijklmnopqrstuvwxyz0123456789';
        let random = '';
        const randomValues = new Uint8Array(12);
        window.crypto.getRandomValues(randomValues);
        for (let i = 0; i < 12; i++) {
            random += charset[randomValues[i] % charset.length];
        }
        
        return `${prefix}_${timestamp}_${random}`;
    }

    /**
     * تولید Device Fingerprint
     * 
     * این fingerprint بر اساس مشخصات مرورگر و دستگاه کاربر تولید می‌شود
     * 
     * @returns {string} رشته hex 16 کاراکتری
     */
    function generateDeviceFingerprint() {
        const components = [
            navigator.userAgent || 'unknown',
            navigator.language || navigator.userLanguage || 'unknown',
            screen.width + 'x' + screen.height + 'x' + (screen.colorDepth || 24),
            new Date().getTimezoneOffset().toString(),
            (navigator.hardwareConcurrency || 2).toString(),
            (navigator.deviceMemory || 4).toString(),
            navigator.platform || 'unknown',
            (window.devicePixelRatio || 1).toString()
        ];
        
        // اضافه کردن canvas fingerprint (اگر ممکن باشد)
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            if (ctx) {
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.fillText('fingerprint', 2, 2);
                components.push(canvas.toDataURL().slice(-50));
            }
        } catch (e) {
            // Canvas fingerprinting blocked
            components.push('canvas-blocked');
        }
        
        // Hash ساده (FNV-1a algorithm)
        const str = components.join('|');
        let hash = 2166136261; // FNV offset basis
        
        for (let i = 0; i < str.length; i++) {
            hash ^= str.charCodeAt(i);
            hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
        }
        
        // Convert to unsigned 32-bit integer and then to hex
        hash = hash >>> 0;
        
        return hash.toString(16).padStart(16, '0').substring(0, 16);
    }

    /**
     * تولید Request ID
     * 
     * @returns {string}
     */
    function generateRequestId() {
        const randomValues = new Uint32Array(1);
        window.crypto.getRandomValues(randomValues);
        return 'REQ_' + Date.now() + '_' + randomValues[0].toString(36);
    }

    /**
     * دریافت timestamp فعلی
     * 
     * @returns {number}
     */
    function getTimestamp() {
        return Date.now();
    }

    /**
     * Initialize security fields in a form
     * 
     * @param {string} formId - ID فرم
     * @param {string} prefix - پیشوند idempotency key
     */
    function initializeSecurityFields(formId, prefix = 'REQ') {
        const form = document.getElementById(formId);
        if (!form) {
            console.warn(`Form with ID "${formId}" not found`);
            return;
        }

        // Idempotency Key
        let idempotencyInput = form.querySelector('#idempotencyKey, [name="idempotency_key"]');
        if (idempotencyInput && !idempotencyInput.value) {
            idempotencyInput.value = generateIdempotencyKey(prefix);
        }

        // Device Fingerprint
        let deviceInput = form.querySelector('#deviceFingerprint, [name="device_fingerprint"]');
        if (deviceInput) {
            deviceInput.value = generateDeviceFingerprint();
        }

        // Request Timestamp (set on submit)
        form.addEventListener('submit', function() {
            let timestampInput = form.querySelector('#requestTimestamp, [name="request_timestamp"]');
            if (timestampInput) {
                timestampInput.value = getTimestamp();
            }
        });
    }

    /**
     * Auto-initialize برای فرم‌های استاندارد
     */
    function autoInitialize() {
        // فرم‌های شناخته شده
        const forms = [
            { id: 'withdrawalForm', prefix: 'WTH' },
            { id: 'depositForm', prefix: 'MDP' },
            { id: 'cryptoDepositForm', prefix: 'CDP' },
            { id: 'transferForm', prefix: 'TRF' }
        ];

        forms.forEach(form => {
            if (document.getElementById(form.id)) {
                initializeSecurityFields(form.id, form.prefix);
            }
        });
    }

    /**
     * Get Client Info (for debugging)
     * 
     * @returns {object}
     */
    function getClientInfo() {
        return {
            userAgent: navigator.userAgent,
            language: navigator.language,
            platform: navigator.platform,
            screen: `${screen.width}x${screen.height}`,
            colorDepth: screen.colorDepth,
            timezone: new Date().getTimezoneOffset(),
            hardwareConcurrency: navigator.hardwareConcurrency,
            deviceMemory: navigator.deviceMemory,
            connection: navigator.connection?.effectiveType || 'unknown',
            devicePixelRatio: window.devicePixelRatio
        };
    }

    /**
     * Detect Suspicious Activity
     * 
     * @returns {boolean}
     */
    function detectSuspiciousActivity() {
        const suspiciousIndicators = [];

        // بررسی DevTools
        if (window.outerWidth - window.innerWidth > 200 || 
            window.outerHeight - window.innerHeight > 200) {
            suspiciousIndicators.push('devtools_open');
        }

        // بررسی automation (Selenium, Puppeteer)
        if (navigator.webdriver) {
            suspiciousIndicators.push('webdriver_detected');
        }

        // بررسی headless browser
        if (navigator.plugins?.length === 0) {
            suspiciousIndicators.push('no_plugins');
        }

        // بررسی canvas fingerprint
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillText('test', 2, 2);
            
            if (canvas.toDataURL() === 'data:,') {
                suspiciousIndicators.push('canvas_blocked');
            }
        } catch (e) {
            suspiciousIndicators.push('canvas_error');
        }

        if (suspiciousIndicators.length > 0) {
            console.warn('🚨 Suspicious activity detected:', suspiciousIndicators);
            return true;
        }

        return false;
    }

    /**
     * Send Security Event to Server
     * 
     * @param {string} eventType
     * @param {object} data
     */
    async function sendSecurityEvent(eventType, data = {}) {
        try {
            await fetch('/api/security/event', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Request-ID': generateRequestId()
                },
                body: JSON.stringify({
                    event_type: eventType,
                    device_fingerprint: generateDeviceFingerprint(),
                    timestamp: Date.now(),
                    data: data
                })
            });
        } catch (e) {
            console.error('Failed to send security event:', e);
        }
    }

    // Export به window object
    window.ChortkeSecuritysecurity = {
        generateIdempotencyKey: generateIdempotencyKey,
        generateDeviceFingerprint: generateDeviceFingerprint,
        generateRequestId: generateRequestId,
        getTimestamp: getTimestamp,
        initializeSecurityFields: initializeSecurityFields,
        autoInitialize: autoInitialize,
        getClientInfo: getClientInfo,
        detectSuspiciousActivity: detectSuspiciousActivity,
        sendSecurityEvent: sendSecurityEvent
    };

    // Auto-initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInitialize);
    } else {
        autoInitialize();
    }

    // Export توابع به سطح global (برای backward compatibility)
    window.generateIdempotencyKey = generateIdempotencyKey;
    window.generateDeviceFingerprint = generateDeviceFingerprint;

})(window);
