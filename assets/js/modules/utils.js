/**
 * Icefox 工具函数模块
 */

class IcefoxUtils {
    /**
     * 防抖函数
     */
    static debounce(func, wait, immediate = false) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                timeout = null;
                if (!immediate) func.apply(this, args);
            };
            const callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func.apply(this, args);
        };
    }

    /**
     * 节流函数
     */
    static throttle(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }

    /**
     * 异步加载脚本
     */
    static loadScript(src, callback) {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        
        if (callback) {
            script.onload = callback;
            script.onerror = () => console.error(`Failed to load script: ${src}`);
        }
        
        document.head.appendChild(script);
        return script;
    }

    /**
     * 异步加载样式
     */
    static loadStyle(href, callback) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        
        if (callback) {
            link.onload = callback;
            link.onerror = () => console.error(`Failed to load style: ${href}`);
        }
        
        document.head.appendChild(link);
        return link;
    }

    /**
     * 检查元素是否在视窗内
     */
    static isInViewport(element, threshold = 0) {
        const rect = element.getBoundingClientRect();
        const windowHeight = window.innerHeight || document.documentElement.clientHeight;
        const windowWidth = window.innerWidth || document.documentElement.clientWidth;
        
        return (
            rect.top >= -threshold &&
            rect.left >= -threshold &&
            rect.bottom <= windowHeight + threshold &&
            rect.right <= windowWidth + threshold
        );
    }

    /**
     * 平滑滚动到指定元素
     */
    static scrollToElement(element, offset = 0, duration = 500) {
        const targetPosition = element.offsetTop - offset;
        const startPosition = window.scrollY || window.pageYOffset;
        const distance = targetPosition - startPosition;
        let startTime = null;

        function animation(currentTime) {
            if (startTime === null) startTime = currentTime;
            const timeElapsed = currentTime - startTime;
            const run = IcefoxUtils.easeInOutQuad(timeElapsed, startPosition, distance, duration);
            window.scrollTo(0, run);
            if (timeElapsed < duration) requestAnimationFrame(animation);
        }

        requestAnimationFrame(animation);
    }

    /**
     * 缓动函数
     */
    static easeInOutQuad(t, b, c, d) {
        t /= d / 2;
        if (t < 1) return c / 2 * t * t + b;
        t--;
        return -c / 2 * (t * (t - 2) - 1) + b;
    }

    /**
     * 格式化时间
     */
    static formatTime(timestamp) {
        const now = Date.now();
        const diff = now - timestamp * 1000;
        const minute = 60 * 1000;
        const hour = minute * 60;
        const day = hour * 24;
        const week = day * 7;
        const month = day * 30;
        const year = day * 365;

        if (diff < minute) {
            return '刚刚';
        } else if (diff < hour) {
            return Math.floor(diff / minute) + '分钟前';
        } else if (diff < day) {
            return Math.floor(diff / hour) + '小时前';
        } else if (diff < week) {
            return Math.floor(diff / day) + '天前';
        } else if (diff < month) {
            return Math.floor(diff / week) + '周前';
        } else if (diff < year) {
            return Math.floor(diff / month) + '个月前';
        } else {
            return Math.floor(diff / year) + '年前';
        }
    }

    /**
     * 本地存储封装
     */
    static storage = {
        set(key, value, expire = null) {
            const data = {
                value: value,
                expire: expire ? Date.now() + expire : null
            };
            localStorage.setItem(key, JSON.stringify(data));
        },

        get(key) {
            try {
                const item = localStorage.getItem(key);
                if (!item) return null;

                const data = JSON.parse(item);
                if (data.expire && Date.now() > data.expire) {
                    localStorage.removeItem(key);
                    return null;
                }
                return data.value;
            } catch (e) {
                return null;
            }
        },

        remove(key) {
            localStorage.removeItem(key);
        },

        clear() {
            localStorage.clear();
        }
    };

    /**
     * 错误处理
     */
    static handleError(error, context = '') {
        console.error(`[Icefox Error] ${context}:`, error);
        
        // 显示用户友好的错误提示
        this.showToast('操作失败，请稍后重试', 'error');
        
        // 可以在这里添加错误上报逻辑
        // this.reportError(error, context);
    }

    /**
     * 显示提示消息
     */
    static showToast(message, type = 'info', duration = 3000) {
        // 移除已存在的toast
        const existingToast = document.querySelector('.icefox-toast');
        if (existingToast) {
            existingToast.remove();
        }

        const toast = document.createElement('div');
        toast.className = `icefox-toast icefox-toast-${type}`;
        toast.textContent = message;
        
        // 添加样式
        Object.assign(toast.style, {
            position: 'fixed',
            top: '20px',
            right: '20px',
            padding: '12px 20px',
            borderRadius: '6px',
            color: 'white',
            fontSize: '14px',
            zIndex: '9999',
            opacity: '0',
            transform: 'translateX(100%)',
            transition: 'all 0.3s ease'
        });

        // 根据类型设置背景色
        const colors = {
            info: '#2196F3',
            success: '#4CAF50',
            warning: '#FF9800',
            error: '#F44336'
        };
        toast.style.backgroundColor = colors[type] || colors.info;

        document.body.appendChild(toast);

        // 显示动画
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(0)';
        });

        // 自动隐藏
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    /**
     * 检查设备类型
     */
    static isMobile() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    }

    /**
     * 检查是否支持WebP
     */
    static supportsWebP() {
        return new Promise(resolve => {
            const webP = new Image();
            webP.onload = webP.onerror = () => resolve(webP.height === 2);
            webP.src = 'data:image/webp;base64,UklGRjoAAABXRUJQVlA4IC4AAACyAgCdASoCAAIALmk0mk0iIiIiIgBoSygABc6WWgAA/veff/0PP8bA//LwYAAA';
        });
    }

    /**
     * 复制文本到剪贴板
     */
    static async copyToClipboard(text) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
            } else {
                // 降级方案 - 使用现代方法
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                textArea.style.top = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                
                // 尝试使用现代选择API
                if (document.getSelection) {
                    const selection = document.getSelection();
                    const range = document.createRange();
                    range.selectNodeContents(textArea);
                    selection.removeAllRanges();
                    selection.addRange(range);
                }
                
                // 使用现代复制方法
                const successful = document.execCommand('copy');
                document.body.removeChild(textArea);
                
                if (!successful) {
                    throw new Error('Copy command failed');
                }
            }
            this.showToast('复制成功', 'success');
            return true;
        } catch (err) {
            this.showToast('复制失败', 'error');
            return false;
        }
    }
}

// 导出工具类
window.IcefoxUtils = IcefoxUtils;