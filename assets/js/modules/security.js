/**
 * Icefox 前端安全模块
 * 提供 XSS 防护、CSRF 保护和内容安全策略
 */

class IcefoxSecurity {
    constructor() {
        this.csrfToken = this.getCSRFToken();
        this.trustedDomains = this.getTrustedDomains();
        this.init();
    }

    init() {
        // 设置全局安全策略
        this.setupGlobalSecurity();
        
        // 监听表单提交
        this.setupFormSecurity();
        
        // 监听动态内容插入
        this.setupContentSecurity();
        
        // 设置点击劫持防护
        this.setupClickjackingProtection();
    }

    getCSRFToken() {
        // 从 meta 标签获取 CSRF token
        const metaToken = document.querySelector('meta[name="csrf-token"]');
        if (metaToken) {
            return metaToken.getAttribute('content');
        }
        
        // 从全局配置获取
        if (window.ICEFOX_CONFIG && window.ICEFOX_CONFIG.csrfToken) {
            return window.ICEFOX_CONFIG.csrfToken;
        }
        
        return null;
    }

    getTrustedDomains() {
        const currentDomain = window.location.hostname;
        return [
            currentDomain,
            'cdn.jsdelivr.net',
            'unpkg.com',
            'cdnjs.cloudflare.com'
        ];
    }

    setupGlobalSecurity() {
        // 禁用 eval 和 Function 构造函数（在开发环境中可能需要调整）
        if (typeof window.eval === 'function') {
            const originalEval = window.eval;
            window.eval = function(code) {
                console.warn('eval() usage detected - potential security risk');
                if (this.isDevelopment()) {
                    return originalEval.call(this, code);
                }
                throw new Error('eval() is disabled for security reasons');
            }.bind(this);
        }

        // 监听错误事件，防止信息泄露
        window.addEventListener('error', (event) => {
            // 在生产环境中不显示详细错误信息
            if (!this.isDevelopment()) {
                event.preventDefault();
                console.error('An error occurred');
            }
        });

        // 防止控制台注入攻击
        this.setupConsoleProtection();
    }

    setupConsoleProtection() {
        if (!this.isDevelopment()) {
            const originalLog = console.log;
            console.log = function() {
                originalLog.apply(console, ['%c⚠️ 警告：请勿在此处粘贴或执行任何代码！这可能导致您的账户被盗用。', 'color: red; font-size: 16px; font-weight: bold;']);
            };
        }
    }

    setupFormSecurity() {
        // 为所有表单添加 CSRF 保护
        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (form.tagName === 'FORM') {
                this.addCSRFTokenToForm(form);
                this.validateFormData(form);
            }
        });

        // 监听 AJAX 请求
        this.interceptAjaxRequests();
    }

    addCSRFTokenToForm(form) {
        if (!this.csrfToken) return;

        // 检查是否已经有 CSRF token
        let tokenInput = form.querySelector('input[name="csrf_token"]');
        if (!tokenInput) {
            tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = 'csrf_token';
            form.appendChild(tokenInput);
        }
        tokenInput.value = this.csrfToken;
    }

    validateFormData(form) {
        const inputs = form.querySelectorAll('input, textarea, select');
        
        inputs.forEach(input => {
            if (input.type === 'text' || input.tagName === 'TEXTAREA') {
                // 检查潜在的 XSS 攻击
                if (this.containsXSS(input.value)) {
                    console.warn('Potential XSS detected in form input:', input.name);
                    input.value = this.sanitizeInput(input.value);
                }
            }
        });
    }

    interceptAjaxRequests() {
        // 拦截 fetch 请求
        const originalFetch = window.fetch;
        window.fetch = (url, options = {}) => {
            // 添加 CSRF token 到请求头
            if (this.csrfToken && this.isInternalRequest(url)) {
                options.headers = options.headers || {};
                options.headers['X-CSRF-Token'] = this.csrfToken;
            }

            // 验证请求 URL
            if (!this.isValidRequestUrl(url)) {
                return Promise.reject(new Error('Invalid request URL'));
            }

            return originalFetch.call(this, url, options);
        };

        // 拦截 XMLHttpRequest
        const originalOpen = XMLHttpRequest.prototype.open;
        const originalSend = XMLHttpRequest.prototype.send;
        
        XMLHttpRequest.prototype.open = function(method, url, ...args) {
            this._url = url;
            return originalOpen.call(this, method, url, ...args);
        };

        XMLHttpRequest.prototype.send = function(data) {
            if (this.csrfToken && this.isInternalRequest(this._url)) {
                this.setRequestHeader('X-CSRF-Token', this.csrfToken);
            }
            return originalSend.call(this, data);
        }.bind(this);
    }

    setupContentSecurity() {
        // 监听 DOM 变化，检查动态插入的内容
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        this.sanitizeElement(node);
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    setupClickjackingProtection() {
        // 检查是否在 iframe 中运行
        if (window.self !== window.top) {
            // 检查是否是可信的父页面
            try {
                const parentOrigin = window.parent.location.origin;
                if (!this.trustedDomains.includes(new URL(parentOrigin).hostname)) {
                    // 可能的点击劫持攻击
                    document.body.style.display = 'none';
                    console.error('Potential clickjacking attack detected');
                }
            } catch (e) {
                // 跨域访问被阻止，可能是恶意 iframe
                document.body.style.display = 'none';
                console.error('Suspicious iframe context detected');
            }
        }
    }

    // XSS 检测
    containsXSS(input) {
        const xssPatterns = [
            /<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi,
            /javascript:/gi,
            /on\w+\s*=/gi,
            /<iframe\b[^>]*>/gi,
            /<object\b[^>]*>/gi,
            /<embed\b[^>]*>/gi,
            /<link\b[^>]*>/gi,
            /<meta\b[^>]*>/gi
        ];

        return xssPatterns.some(pattern => pattern.test(input));
    }

    // 输入清理
    sanitizeInput(input) {
        // 移除潜在的危险标签和属性
        let sanitized = input
            .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
            .replace(/javascript:/gi, '')
            .replace(/on\w+\s*=/gi, '')
            .replace(/<iframe\b[^>]*>/gi, '')
            .replace(/<object\b[^>]*>/gi, '')
            .replace(/<embed\b[^>]*>/gi, '')
            .replace(/<link\b[^>]*>/gi, '')
            .replace(/<meta\b[^>]*>/gi, '');

        // HTML 实体编码
        const div = document.createElement('div');
        div.textContent = sanitized;
        return div.innerHTML;
    }

    // 元素清理
    sanitizeElement(element) {
        // 移除危险属性
        const dangerousAttributes = ['onclick', 'onload', 'onerror', 'onmouseover'];
        dangerousAttributes.forEach(attr => {
            if (element.hasAttribute && element.hasAttribute(attr)) {
                element.removeAttribute(attr);
            }
        });

        // 检查子元素
        if (element.children) {
            Array.from(element.children).forEach(child => {
                this.sanitizeElement(child);
            });
        }
    }

    // 检查是否是内部请求
    isInternalRequest(url) {
        try {
            const requestUrl = new URL(url, window.location.origin);
            return requestUrl.origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    // 验证请求 URL
    isValidRequestUrl(url) {
        try {
            const requestUrl = new URL(url, window.location.origin);
            const hostname = requestUrl.hostname;
            
            // 检查是否是可信域名
            return this.trustedDomains.some(domain => 
                hostname === domain || hostname.endsWith('.' + domain)
            );
        } catch (e) {
            return false;
        }
    }

    // 检查是否是开发环境
    isDevelopment() {
        return window.location.hostname === 'localhost' || 
               window.location.hostname === '127.0.0.1' ||
               window.location.hostname.includes('dev') ||
               window.ICEFOX_CONFIG?.environment === 'development';
    }

    // 安全的 HTML 插入
    safeInsertHTML(element, html) {
        // 创建临时容器
        const temp = document.createElement('div');
        temp.innerHTML = html;
        
        // 清理内容
        this.sanitizeElement(temp);
        
        // 插入清理后的内容
        element.innerHTML = temp.innerHTML;
    }

    // 安全的事件绑定
    safeAddEventListener(element, event, handler) {
        // 验证事件类型
        const allowedEvents = [
            'click', 'submit', 'change', 'input', 'focus', 'blur',
            'mouseenter', 'mouseleave', 'keydown', 'keyup'
        ];
        
        if (!allowedEvents.includes(event)) {
            console.warn('Potentially unsafe event type:', event);
            return;
        }
        
        element.addEventListener(event, handler);
    }

    // 生成安全的随机字符串
    generateSecureRandom(length = 32) {
        const array = new Uint8Array(length);
        crypto.getRandomValues(array);
        return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
    }

    // 验证 JSON 数据
    validateJSON(jsonString) {
        try {
            const data = JSON.parse(jsonString);
            
            // 检查是否包含函数或危险内容
            const jsonStr = JSON.stringify(data);
            if (this.containsXSS(jsonStr)) {
                throw new Error('JSON contains potentially dangerous content');
            }
            
            return data;
        } catch (e) {
            console.error('JSON validation failed:', e);
            return null;
        }
    }

    // 更新 CSRF Token
    updateCSRFToken(newToken) {
        this.csrfToken = newToken;
        
        // 更新页面中的 meta 标签
        const metaToken = document.querySelector('meta[name="csrf-token"]');
        if (metaToken) {
            metaToken.setAttribute('content', newToken);
        }
        
        // 更新全局配置
        if (window.ICEFOX_CONFIG) {
            window.ICEFOX_CONFIG.csrfToken = newToken;
        }
    }
}

// 自动初始化安全模块
document.addEventListener('DOMContentLoaded', () => {
    window.icefoxSecurity = new IcefoxSecurity();
});

// 导出类
window.IcefoxSecurity = IcefoxSecurity;