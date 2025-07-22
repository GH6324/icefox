/**
 * Icefox 优化版主文件
 * 整合所有优化模块的主入口文件
 */

(function(window, document) {
    'use strict';

    // 性能标记
    if (window.performance && window.performance.mark) {
        window.performance.mark('icefox-script-start');
    }

    // 全局配置
    window.ICEFOX_CONFIG = window.ICEFOX_CONFIG || {};
    
    // 性能追踪对象
    window.ICEFOX_PERF = window.ICEFOX_PERF || {
        marks: {},
        measures: {},
        errors: []
    };
    
    // 默认配置
    const defaultConfig = {
        enablePerformanceMonitoring: true,
        enablePreloader: true,
        enableLazyLoad: true,
        enableCache: true,
        enableSecurity: true,
        debug: false,
        version: '2.2.0'
    };
    
    // 合并配置
    Object.assign(window.ICEFOX_CONFIG, defaultConfig, window.ICEFOX_CONFIG);

    /**
     * Icefox 主类
     */
    class Icefox {
        constructor() {
            this.modules = {};
            this.initialized = false;
            this.loadQueue = [];
            
            this.init();
        }

        init() {
            if (this.initialized) return;
            
            // 标记性能点
            this.markPerformance('icefox-init-start');
            
            // 检查依赖
            this.checkDependencies();
            
            // 初始化核心模块
            this.initCoreModules();
            
            // 初始化优化模块
            this.initOptimizationModules();
            
            // 设置事件监听
            this.setupEventListeners();
            
            // 标记初始化完成
            this.markPerformance('icefox-init-end');
            this.initialized = true;
            
            // 触发初始化完成事件
            this.trigger('icefox:initialized');
            
            console.log(`[Icefox] Initialized v${window.ICEFOX_CONFIG.version}`);
        }

        checkDependencies() {
            const required = ['jQuery'];
            const missing = [];
            
            required.forEach(dep => {
                if (dep === 'jQuery' && typeof $ === 'undefined') {
                    missing.push(dep);
                }
            });
            
            if (missing.length > 0) {
                console.error('[Icefox] Missing dependencies:', missing);
                throw new Error('Missing required dependencies: ' + missing.join(', '));
            }
        }

        initCoreModules() {
            // 初始化工具模块
            if (window.IcefoxUtils) {
                this.modules.utils = window.IcefoxUtils;
            }
            
            // 初始化安全模块
            if (window.ICEFOX_CONFIG.enableSecurity && window.IcefoxSecurity) {
                this.modules.security = new window.IcefoxSecurity();
            }
            
            // 初始化缓存模块
            if (window.ICEFOX_CONFIG.enableCache && window.IcefoxCache) {
                this.modules.cache = new window.IcefoxCache();
            }
        }

        initOptimizationModules() {
            // 初始化懒加载
            if (window.ICEFOX_CONFIG.enableLazyLoad && window.IcefoxLazyLoad) {
                this.modules.lazyLoad = new window.IcefoxLazyLoad();
            }
            
            // 初始化预加载器
            if (window.ICEFOX_CONFIG.enablePreloader && window.IcefoxPreloader) {
                this.modules.preloader = new window.IcefoxPreloader();
            }
            
            // 初始化性能监控
            if (window.ICEFOX_CONFIG.enablePerformanceMonitoring && window.IcefoxPerformanceMonitor) {
                this.modules.performanceMonitor = new window.IcefoxPerformanceMonitor();
            }
            
            // 初始化 Service Worker 管理器
            if (window.ICEFOX_CONFIG.enableServiceWorker !== false && window.IcefoxServiceWorkerManager) {
                this.modules.swManager = new window.IcefoxServiceWorkerManager();
            }
            
            // 初始化性能仪表板（仅调试模式）
            if (window.ICEFOX_CONFIG.debug && window.IcefoxPerformanceDashboard) {
                this.modules.dashboard = new window.IcefoxPerformanceDashboard();
            }
        }

        setupEventListeners() {
            // DOM 内容变化监听
            this.observeContentChanges();
            
            // 页面可见性变化
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.onPageHidden();
                } else {
                    this.onPageVisible();
                }
            });
            
            // 页面卸载
            window.addEventListener('beforeunload', () => {
                this.onPageUnload();
            });
            
            // 错误处理
            window.addEventListener('error', (event) => {
                this.handleError(event.error, 'Global error');
            });
            
            window.addEventListener('unhandledrejection', (event) => {
                this.handleError(event.reason, 'Unhandled promise rejection');
            });
        }

        observeContentChanges() {
            if (!window.MutationObserver) return;
            
            const observer = new MutationObserver((mutations) => {
                let hasNewContent = false;
                
                mutations.forEach((mutation) => {
                    if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                        hasNewContent = true;
                    }
                });
                
                if (hasNewContent) {
                    this.onContentChanged();
                }
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }

        onContentChanged() {
            // 更新懒加载观察器
            if (this.modules.lazyLoad && this.modules.lazyLoad.update) {
                this.modules.lazyLoad.update();
            }
            
            // 触发内容变化事件
            this.trigger('icefox:contentChanged');
        }

        onPageHidden() {
            // 暂停非必要的监控
            if (this.modules.performanceMonitor && this.modules.performanceMonitor.pause) {
                this.modules.performanceMonitor.pause();
            }
            
            if (this.modules.preloader && this.modules.preloader.pausePreloading) {
                this.modules.preloader.pausePreloading();
            }
        }

        onPageVisible() {
            // 恢复监控
            if (this.modules.performanceMonitor && this.modules.performanceMonitor.resume) {
                this.modules.performanceMonitor.resume();
            }
            
            if (this.modules.preloader && this.modules.preloader.resumePreloading) {
                this.modules.preloader.resumePreloading();
            }
        }

        onPageUnload() {
            // 清理资源
            Object.values(this.modules).forEach(module => {
                if (module && typeof module.destroy === 'function') {
                    module.destroy();
                }
            });
        }

        handleError(error, context) {
            console.error(`[Icefox] ${context}:`, error);
            
            // 使用工具类处理错误
            if (this.modules.utils && this.modules.utils.handleError) {
                this.modules.utils.handleError(error, context);
            }
            
            // 记录错误到性能监控
            if (this.modules.performanceMonitor && this.modules.performanceMonitor.recordError) {
                this.modules.performanceMonitor.recordError(error, context);
            }
        }

        markPerformance(name) {
            if (window.performance && window.performance.mark) {
                window.performance.mark(name);
            }
            
            if (window.ICEFOX_PERF) {
                window.ICEFOX_PERF.marks[name] = performance.now();
            }
        }

        // 事件系统
        on(event, callback) {
            if (!this.events) this.events = {};
            if (!this.events[event]) this.events[event] = [];
            this.events[event].push(callback);
        }

        off(event, callback) {
            if (!this.events || !this.events[event]) return;
            const index = this.events[event].indexOf(callback);
            if (index > -1) {
                this.events[event].splice(index, 1);
            }
        }

        trigger(event, data) {
            if (!this.events || !this.events[event]) return;
            this.events[event].forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    this.handleError(error, `Event callback: ${event}`);
                }
            });
        }

        // 模块管理
        getModule(name) {
            return this.modules[name];
        }

        hasModule(name) {
            return !!this.modules[name];
        }

        // 延迟加载模块
        loadModule(name, url) {
            return new Promise((resolve, reject) => {
                if (this.modules[name]) {
                    resolve(this.modules[name]);
                    return;
                }
                
                const script = document.createElement('script');
                script.src = url;
                script.onload = () => {
                    // 模块应该自动注册到 window
                    const ModuleClass = window[`Icefox${name}`];
                    if (ModuleClass) {
                        this.modules[name.toLowerCase()] = new ModuleClass();
                        resolve(this.modules[name.toLowerCase()]);
                    } else {
                        reject(new Error(`Module ${name} not found after loading`));
                    }
                };
                script.onerror = () => reject(new Error(`Failed to load module: ${name}`));
                document.head.appendChild(script);
            });
        }

        // 获取性能统计
        getPerformanceStats() {
            const stats = {
                initialized: this.initialized,
                modules: Object.keys(this.modules),
                performance: window.ICEFOX_PERF
            };
            
            // 收集各模块的统计信息
            Object.entries(this.modules).forEach(([name, module]) => {
                if (module && typeof module.getStats === 'function') {
                    stats[name] = module.getStats();
                }
            });
            
            return stats;
        }

        // 调试方法
        debug() {
            console.group('[Icefox Debug Info]');
            console.log('Config:', window.ICEFOX_CONFIG);
            console.log('Modules:', this.modules);
            console.log('Performance:', this.getPerformanceStats());
            console.groupEnd();
        }
    }

    // 自动初始化
    function autoInit() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                window.icefox = new Icefox();
            });
        } else {
            window.icefox = new Icefox();
        }
    }

    // 兼容性检查
    function checkCompatibility() {
        const required = [
            'addEventListener',
            'querySelector',
            'JSON',
            'Promise'
        ];
        
        const missing = required.filter(feature => !(feature in window));
        
        if (missing.length > 0) {
            console.error('[Icefox] Browser compatibility issues:', missing);
            return false;
        }
        
        return true;
    }

    // 启动应用
    if (checkCompatibility()) {
        autoInit();
    } else {
        console.error('[Icefox] Browser not supported');
    }

    // 导出到全局
    window.Icefox = Icefox;

})(window, document);