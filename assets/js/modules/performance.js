/**
 * Icefox 性能监控模块
 * 监控页面性能指标和用户体验
 */

class IcefoxPerformanceMonitor {
    constructor() {
        this.metrics = {};
        this.observers = {};
        this.isEnabled = true;
        this.init();
    }

    init() {
        if (!this.isEnabled || !window.performance) return;

        // 监控页面加载性能
        if (document.readyState === 'complete') {
            this.collectMetrics();
        } else {
            window.addEventListener('load', () => {
                setTimeout(() => this.collectMetrics(), 100);
            });
        }

        // 监控 Core Web Vitals
        this.observeCoreWebVitals();
        
        // 监控资源加载
        this.observeResourceTiming();
        
        // 监控用户交互
        this.observeUserInteractions();
    }

    collectMetrics() {
        try {
            const navigation = performance.getEntriesByType('navigation')[0];
            if (!navigation) return;

            this.metrics.navigation = {
                // DNS 查询时间
                dnsTime: navigation.domainLookupEnd - navigation.domainLookupStart,
                // TCP 连接时间
                tcpTime: navigation.connectEnd - navigation.connectStart,
                // SSL 握手时间
                sslTime: navigation.secureConnectionStart > 0 ? 
                    navigation.connectEnd - navigation.secureConnectionStart : 0,
                // 请求响应时间
                requestTime: navigation.responseEnd - navigation.requestStart,
                // DOM 解析时间
                domParseTime: navigation.domContentLoadedEventEnd - navigation.domLoading,
                // 资源加载时间
                resourceTime: navigation.loadEventEnd - navigation.domContentLoadedEventEnd,
                // 总加载时间
                totalTime: navigation.loadEventEnd - navigation.fetchStart,
                // 首字节时间 (TTFB)
                ttfb: navigation.responseStart - navigation.requestStart
            };

            this.reportMetrics();
        } catch (error) {
            console.warn('Performance metrics collection failed:', error);
        }
    }

    observeCoreWebVitals() {
        // First Contentful Paint (FCP)
        this.observeFCP();
        
        // Largest Contentful Paint (LCP)
        this.observeLCP();
        
        // Cumulative Layout Shift (CLS)
        this.observeCLS();
        
        // First Input Delay (FID)
        this.observeFID();
    }

    observeFCP() {
        try {
            const observer = new PerformanceObserver((list) => {
                const entries = list.getEntries();
                const fcp = entries.find(entry => entry.name === 'first-contentful-paint');
                if (fcp) {
                    this.metrics.fcp = fcp.startTime;
                    observer.disconnect();
                }
            });
            observer.observe({ entryTypes: ['paint'] });
        } catch (error) {
            console.warn('FCP observation failed:', error);
        }
    }

    observeLCP() {
        try {
            const observer = new PerformanceObserver((list) => {
                const entries = list.getEntries();
                const lastEntry = entries[entries.length - 1];
                this.metrics.lcp = lastEntry.startTime;
            });
            observer.observe({ entryTypes: ['largest-contentful-paint'] });
            
            // 在页面隐藏时停止观察
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    observer.disconnect();
                }
            });
        } catch (error) {
            console.warn('LCP observation failed:', error);
        }
    }

    observeCLS() {
        try {
            let clsValue = 0;
            const observer = new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    if (!entry.hadRecentInput) {
                        clsValue += entry.value;
                    }
                }
                this.metrics.cls = clsValue;
            });
            observer.observe({ entryTypes: ['layout-shift'] });
        } catch (error) {
            console.warn('CLS observation failed:', error);
        }
    }

    observeFID() {
        try {
            const observer = new PerformanceObserver((list) => {
                const entries = list.getEntries();
                const fid = entries[0];
                if (fid) {
                    this.metrics.fid = fid.processingStart - fid.startTime;
                    observer.disconnect();
                }
            });
            observer.observe({ entryTypes: ['first-input'] });
        } catch (error) {
            console.warn('FID observation failed:', error);
        }
    }

    observeResourceTiming() {
        try {
            const observer = new PerformanceObserver((list) => {
                const entries = list.getEntries();
                const resources = {
                    images: [],
                    scripts: [],
                    stylesheets: [],
                    other: []
                };

                entries.forEach(entry => {
                    const resource = {
                        name: entry.name,
                        duration: entry.duration,
                        size: entry.transferSize || 0,
                        cached: entry.transferSize === 0 && entry.decodedBodySize > 0
                    };

                    if (entry.initiatorType === 'img') {
                        resources.images.push(resource);
                    } else if (entry.initiatorType === 'script') {
                        resources.scripts.push(resource);
                    } else if (entry.initiatorType === 'link') {
                        resources.stylesheets.push(resource);
                    } else {
                        resources.other.push(resource);
                    }
                });

                this.metrics.resources = resources;
            });
            observer.observe({ entryTypes: ['resource'] });
        } catch (error) {
            console.warn('Resource timing observation failed:', error);
        }
    }

    observeUserInteractions() {
        let interactionCount = 0;
        let totalInteractionTime = 0;

        const trackInteraction = (event) => {
            const startTime = performance.now();
            
            requestAnimationFrame(() => {
                const endTime = performance.now();
                const duration = endTime - startTime;
                
                interactionCount++;
                totalInteractionTime += duration;
                
                this.metrics.interactions = {
                    count: interactionCount,
                    averageTime: totalInteractionTime / interactionCount,
                    lastInteractionTime: duration
                };
            });
        };

        ['click', 'keydown', 'touchstart'].forEach(eventType => {
            document.addEventListener(eventType, trackInteraction, { passive: true });
        });
    }

    reportMetrics() {
        if (!this.isEnabled) return;

        // 延迟发送，确保所有指标都收集完成
        setTimeout(() => {
            const report = {
                url: window.location.href,
                userAgent: navigator.userAgent,
                timestamp: Date.now(),
                metrics: this.metrics,
                connection: this.getConnectionInfo(),
                device: this.getDeviceInfo()
            };

            this.sendReport(report);
        }, 2000);
    }

    getConnectionInfo() {
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (connection) {
            return {
                effectiveType: connection.effectiveType,
                downlink: connection.downlink,
                rtt: connection.rtt,
                saveData: connection.saveData
            };
        }
        return null;
    }

    getDeviceInfo() {
        return {
            isMobile: /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent),
            screenWidth: screen.width,
            screenHeight: screen.height,
            viewportWidth: window.innerWidth,
            viewportHeight: window.innerHeight,
            devicePixelRatio: window.devicePixelRatio || 1
        };
    }

    sendReport(report) {
        try {
            // 使用 sendBeacon API 发送数据（更可靠）
            if (navigator.sendBeacon) {
                const blob = new Blob([JSON.stringify(report)], { type: 'application/json' });
                navigator.sendBeacon('/api/performance', blob);
            } else {
                // 降级到 fetch
                fetch('/api/performance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(report),
                    keepalive: true
                }).catch(err => console.warn('Performance reporting failed:', err));
            }
        } catch (error) {
            console.warn('Failed to send performance report:', error);
        }
    }

    // 手动标记性能点
    mark(name) {
        if (performance.mark) {
            performance.mark(name);
        }
        
        if (!this.metrics.customMarks) {
            this.metrics.customMarks = {};
        }
        this.metrics.customMarks[name] = performance.now();
    }

    // 测量两个标记之间的时间
    measure(name, startMark, endMark) {
        if (performance.measure) {
            try {
                performance.measure(name, startMark, endMark);
                const measure = performance.getEntriesByName(name, 'measure')[0];
                
                if (!this.metrics.customMeasures) {
                    this.metrics.customMeasures = {};
                }
                this.metrics.customMeasures[name] = measure.duration;
            } catch (error) {
                console.warn('Performance measure failed:', error);
            }
        }
    }

    // 获取当前性能指标
    getMetrics() {
        return { ...this.metrics };
    }

    // 启用/禁用性能监控
    setEnabled(enabled) {
        this.isEnabled = enabled;
    }
}

// 自动初始化性能监控
document.addEventListener('DOMContentLoaded', () => {
    if (window.ICEFOX_CONFIG && window.ICEFOX_CONFIG.enablePerformanceMonitoring !== false) {
        window.icefoxPerformanceMonitor = new IcefoxPerformanceMonitor();
    }
});

// 导出类
window.IcefoxPerformanceMonitor = IcefoxPerformanceMonitor;