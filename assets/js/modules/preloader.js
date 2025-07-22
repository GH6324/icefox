/**
 * Icefox 智能预加载模块
 * 基于用户行为和网络状况的智能预加载系统
 */

class IcefoxPreloader {
    constructor(options = {}) {
        this.options = {
            // 预加载策略
            enableHoverPreload: true,
            enableScrollPreload: true,
            enableIdlePreload: true,
            
            // 预加载阈值
            hoverDelay: 100,
            scrollThreshold: 200,
            idleTimeout: 2000,
            
            // 网络适配
            adaptToConnection: true,
            maxPreloadSize: 5 * 1024 * 1024, // 5MB
            
            ...options
        };

        this.preloadQueue = [];
        this.preloadedResources = new Set();
        this.isPreloading = false;
        this.connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        
        this.init();
    }

    init() {
        // 检查浏览器支持
        if (!('IntersectionObserver' in window)) {
            console.warn('IntersectionObserver not supported, preloader disabled');
            return;
        }

        // 基于用户行为预测预加载
        if (this.options.enableHoverPreload) {
            this.setupHoverPreload();
        }
        
        // 滚动预测预加载
        if (this.options.enableScrollPreload) {
            this.setupScrollPreload();
        }
        
        // 空闲时间预加载
        if (this.options.enableIdlePreload) {
            this.scheduleIdlePreload();
        }
        
        // 网络状态适应
        if (this.options.adaptToConnection) {
            this.adaptToNetworkCondition();
        }

        // 监听页面可见性变化
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.pausePreloading();
            } else {
                this.resumePreloading();
            }
        });
    }

    setupHoverPreload() {
        let hoverTimeout;
        
        document.addEventListener('mouseover', (e) => {
            const link = e.target.closest('a[href]');
            if (!link) return;
            
            clearTimeout(hoverTimeout);
            hoverTimeout = setTimeout(() => {
                if (this.shouldPreload(link.href)) {
                    this.preloadPage(link.href);
                }
            }, this.options.hoverDelay);
        });

        document.addEventListener('mouseout', (e) => {
            const link = e.target.closest('a[href]');
            if (link) {
                clearTimeout(hoverTimeout);
            }
        });
    }

    setupScrollPreload() {
        let scrollTimeout;
        let lastScrollY = window.scrollY;
        
        const scrollHandler = IcefoxUtils.throttle(() => {
            const currentScrollY = window.scrollY;
            const scrollDirection = currentScrollY > lastScrollY ? 'down' : 'up';
            const scrollSpeed = Math.abs(currentScrollY - lastScrollY);
            
            lastScrollY = currentScrollY;
            
            // 快速向下滚动时预加载下一页内容
            if (scrollDirection === 'down' && scrollSpeed > 50) {
                this.preloadNextPageContent();
            }
            
            // 预加载即将进入视窗的图片
            this.preloadVisibleContent();
            
        }, 150);
        
        window.addEventListener('scroll', scrollHandler, { passive: true });
    }

    scheduleIdlePreload() {
        const scheduleCallback = () => {
            if ('requestIdleCallback' in window) {
                requestIdleCallback((deadline) => {
                    this.preloadCriticalResources(deadline);
                }, { timeout: 5000 });
            } else {
                setTimeout(() => {
                    this.preloadCriticalResources();
                }, this.options.idleTimeout);
            }
        };

        // 页面加载完成后开始空闲预加载
        if (document.readyState === 'complete') {
            scheduleCallback();
        } else {
            window.addEventListener('load', scheduleCallback);
        }
    }

    adaptToNetworkCondition() {
        if (!this.connection) return;

        const updateStrategy = () => {
            const effectiveType = this.connection.effectiveType;
            const saveData = this.connection.saveData;
            
            if (saveData || effectiveType === 'slow-2g' || effectiveType === '2g') {
                // 低速网络，禁用预加载
                this.options.enableHoverPreload = false;
                this.options.enableScrollPreload = false;
                this.options.maxPreloadSize = 1024 * 1024; // 1MB
            } else if (effectiveType === '3g') {
                // 中速网络，减少预加载
                this.options.hoverDelay = 300;
                this.options.maxPreloadSize = 2 * 1024 * 1024; // 2MB
            } else {
                // 高速网络，启用所有预加载
                this.options.enableHoverPreload = true;
                this.options.enableScrollPreload = true;
                this.options.maxPreloadSize = 5 * 1024 * 1024; // 5MB
            }
        };

        updateStrategy();
        this.connection.addEventListener('change', updateStrategy);
    }

    shouldPreload(url) {
        try {
            const urlObj = new URL(url, window.location.origin);
            
            // 只预加载同域名的页面
            if (urlObj.origin !== window.location.origin) {
                return false;
            }
            
            // 避免预加载当前页面
            if (urlObj.pathname === window.location.pathname) {
                return false;
            }
            
            // 检查是否已经预加载
            if (this.preloadedResources.has(url)) {
                return false;
            }
            
            // 检查是否是有效的页面链接
            const validExtensions = ['.html', '.php', ''];
            const hasValidExtension = validExtensions.some(ext => 
                urlObj.pathname.endsWith(ext) || !urlObj.pathname.includes('.')
            );
            
            return hasValidExtension;
        } catch (e) {
            return false;
        }
    }

    preloadPage(url) {
        if (this.preloadedResources.has(url) || this.isPreloading) return;
        
        this.isPreloading = true;
        
        // 使用 link prefetch
        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = url;
        link.onload = () => {
            this.preloadedResources.add(url);
            this.isPreloading = false;
        };
        link.onerror = () => {
            this.isPreloading = false;
        };
        
        document.head.appendChild(link);
        
        // 记录预加载行为
        this.logPreloadAction('page', url);
    }

    preloadResource(url, type = 'script') {
        if (this.preloadedResources.has(url)) return;
        
        const link = document.createElement('link');
        link.rel = 'preload';
        link.href = url;
        link.as = type;
        
        link.onload = () => {
            this.preloadedResources.add(url);
        };
        
        document.head.appendChild(link);
        this.logPreloadAction('resource', url, type);
    }

    preloadCriticalResources(deadline) {
        const criticalResources = [
            { url: '/assets/js/modules/comments.js', type: 'script' },
            { url: '/assets/js/modules/player.js', type: 'script' },
            { url: '/assets/css/components.css', type: 'style' },
            { url: '/assets/js/viewer.js', type: 'script' },
            { url: '/assets/js/fancybox.umd.js', type: 'script' }
        ];
        
        for (const resource of criticalResources) {
            // 检查空闲时间
            if (deadline && deadline.timeRemaining() < 10) {
                break;
            }
            
            if (!this.preloadedResources.has(resource.url)) {
                this.preloadResource(resource.url, resource.type);
            }
        }
    }

    preloadVisibleContent() {
        // 预加载即将进入视窗的图片
        const images = document.querySelectorAll('img[data-src]:not(.lazy-loaded)');
        const threshold = window.innerHeight + this.options.scrollThreshold;
        
        images.forEach(img => {
            const rect = img.getBoundingClientRect();
            if (rect.top < threshold && rect.bottom > -this.options.scrollThreshold) {
                // 触发懒加载
                if (window.icefoxLazyLoad) {
                    window.icefoxLazyLoad.loadElement(img);
                }
            }
        });
    }

    preloadNextPageContent() {
        // 检查是否有分页链接
        const nextPageLink = document.querySelector('.next-page, .pagination .next, [rel="next"]');
        if (nextPageLink && nextPageLink.href) {
            this.preloadPage(nextPageLink.href);
        }
        
        // 检查是否有无限滚动
        const loadMoreTrigger = document.querySelector('.load-more-trigger, .infinite-scroll-trigger');
        if (loadMoreTrigger && IcefoxUtils.isInViewport(loadMoreTrigger, 300)) {
            this.preloadMoreContent();
        }
    }

    preloadMoreContent() {
        // 预加载更多内容的逻辑
        if (window.icefoxInfiniteScroll) {
            window.icefoxInfiniteScroll.preloadNext();
        }
    }

    pausePreloading() {
        this.isPreloading = false;
        // 暂停所有预加载活动
    }

    resumePreloading() {
        // 恢复预加载活动
        if (this.options.enableIdlePreload) {
            this.scheduleIdlePreload();
        }
    }

    logPreloadAction(type, url, extra = null) {
        if (window.ICEFOX_CONFIG?.debug) {
            console.log(`[Preloader] ${type} preloaded:`, url, extra);
        }
        
        // 发送预加载统计数据
        if (window.icefoxPerformanceMonitor) {
            window.icefoxPerformanceMonitor.mark(`preload-${type}-${Date.now()}`);
        }
    }

    // 获取预加载统计信息
    getStats() {
        return {
            preloadedCount: this.preloadedResources.size,
            queueLength: this.preloadQueue.length,
            isPreloading: this.isPreloading,
            preloadedResources: Array.from(this.preloadedResources)
        };
    }

    // 清理预加载缓存
    clearCache() {
        this.preloadedResources.clear();
        this.preloadQueue = [];
        
        // 移除预加载的 link 标签
        const preloadLinks = document.querySelectorAll('link[rel="prefetch"], link[rel="preload"]');
        preloadLinks.forEach(link => {
            if (link.href.includes(window.location.origin)) {
                link.remove();
            }
        });
    }

    // 销毁预加载器
    destroy() {
        this.clearCache();
        // 移除事件监听器等清理工作
    }
}

// 自动初始化预加载器
document.addEventListener('DOMContentLoaded', () => {
    if (window.ICEFOX_CONFIG?.enablePreloader !== false) {
        window.icefoxPreloader = new IcefoxPreloader();
    }
});

// 导出类
window.IcefoxPreloader = IcefoxPreloader;