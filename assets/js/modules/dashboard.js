/**
 * Icefox 性能监控仪表板
 * 实时监控和展示性能指标
 * 
 * 使用方法：
 * - Ctrl+Shift+P: 切换仪表板显示
 * - 控制台命令: showPerfDashboard() / hidePerfDashboard()
 */

class IcefoxPerformanceDashboard {
    constructor(options = {}) {
        this.options = {
            // 监控间隔 (毫秒)
            updateInterval: 5000,
            // 数据保留时间 (毫秒)
            dataRetention: 300000, // 5分钟
            // 警告阈值
            thresholds: {
                memory: 50 * 1024 * 1024, // 50MB
                lcp: 2500, // 2.5秒
                fid: 100, // 100ms
                cls: 0.1,
                errorRate: 0.05 // 5%
            },
            // 是否显示详细信息
            showDetails: false,
            // 容器选择器
            container: '#performance-dashboard',
            ...options
        };

        this.metrics = {
            performance: [],
            memory: [],
            network: [],
            errors: [],
            cache: []
        };

        this.alerts = [];
        this.isVisible = false;
        this.updateTimer = null;

        this.init();
    }

    init() {
        this.createDashboard();
        this.setupEventListeners();
        this.startMonitoring();
    }

    createDashboard() {
        const container = document.querySelector(this.options.container) || this.createContainer();
        
        container.innerHTML = `
            <div class="perf-dashboard">
                <div class="perf-header">
                    <h3>性能监控</h3>
                    <div class="perf-controls">
                        <button class="perf-toggle-details" title="切换详细信息">📊</button>
                        <button class="perf-clear-data" title="清除数据">🗑️</button>
                        <button class="perf-close" title="关闭">✕</button>
                    </div>
                </div>
                
                <div class="perf-alerts"></div>
                
                <div class="perf-metrics">
                    <div class="perf-metric-group">
                        <h4>Core Web Vitals</h4>
                        <div class="perf-metric">
                            <span class="perf-label">LCP:</span>
                            <span class="perf-value" data-metric="lcp">-</span>
                            <span class="perf-unit">ms</span>
                        </div>
                        <div class="perf-metric">
                            <span class="perf-label">FID:</span>
                            <span class="perf-value" data-metric="fid">-</span>
                            <span class="perf-unit">ms</span>
                        </div>
                        <div class="perf-metric">
                            <span class="perf-label">CLS:</span>
                            <span class="perf-value" data-metric="cls">-</span>
                        </div>
                    </div>
                    
                    <div class="perf-metric-group">
                        <h4>内存使用</h4>
                        <div class="perf-metric">
                            <span class="perf-label">已用:</span>
                            <span class="perf-value" data-metric="memory-used">-</span>
                            <span class="perf-unit">MB</span>
                        </div>
                        <div class="perf-metric">
                            <span class="perf-label">限制:</span>
                            <span class="perf-value" data-metric="memory-limit">-</span>
                            <span class="perf-unit">MB</span>
                        </div>
                    </div>
                    
                    <div class="perf-metric-group">
                        <h4>网络状态</h4>
                        <div class="perf-metric">
                            <span class="perf-label">类型:</span>
                            <span class="perf-value" data-metric="connection-type">-</span>
                        </div>
                        <div class="perf-metric">
                            <span class="perf-label">RTT:</span>
                            <span class="perf-value" data-metric="connection-rtt">-</span>
                            <span class="perf-unit">ms</span>
                        </div>
                    </div>
                    
                    <div class="perf-metric-group">
                        <h4>缓存状态</h4>
                        <div class="perf-metric">
                            <span class="perf-label">命中率:</span>
                            <span class="perf-value" data-metric="cache-hit-rate">-</span>
                            <span class="perf-unit">%</span>
                        </div>
                        <div class="perf-metric">
                            <span class="perf-label">大小:</span>
                            <span class="perf-value" data-metric="cache-size">-</span>
                            <span class="perf-unit">MB</span>
                        </div>
                    </div>
                </div>
                
                <div class="perf-details" style="display: none;">
                    <div class="perf-charts">
                        <canvas id="perf-chart-performance" width="300" height="150"></canvas>
                        <canvas id="perf-chart-memory" width="300" height="150"></canvas>
                    </div>
                    
                    <div class="perf-logs">
                        <h4>最近事件</h4>
                        <div class="perf-log-list"></div>
                    </div>
                </div>
            </div>
        `;

        this.addStyles();
    }

    createContainer() {
        const container = document.createElement('div');
        container.id = 'performance-dashboard';
        container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: none;
        `;
        document.body.appendChild(container);
        return container;
    }

    addStyles() {
        if (document.getElementById('perf-dashboard-styles')) return;

        const styles = document.createElement('style');
        styles.id = 'perf-dashboard-styles';
        styles.textContent = `
            .perf-dashboard {
                background: rgba(0, 0, 0, 0.9);
                color: #fff;
                border-radius: 8px;
                padding: 16px;
                min-width: 320px;
                max-width: 500px;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            }
            
            .perf-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 12px;
                border-bottom: 1px solid #333;
                padding-bottom: 8px;
            }
            
            .perf-header h3 {
                margin: 0;
                font-size: 14px;
                color: #4CAF50;
            }
            
            .perf-controls button {
                background: none;
                border: none;
                color: #fff;
                cursor: pointer;
                padding: 4px;
                margin-left: 4px;
                border-radius: 3px;
                font-size: 12px;
            }
            
            .perf-controls button:hover {
                background: rgba(255, 255, 255, 0.1);
            }
            
            .perf-alerts {
                margin-bottom: 12px;
            }
            
            .perf-alert {
                background: #f44336;
                color: #fff;
                padding: 6px 8px;
                border-radius: 4px;
                margin-bottom: 4px;
                font-size: 11px;
                animation: perf-alert-pulse 2s infinite;
            }
            
            @keyframes perf-alert-pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.7; }
            }
            
            .perf-metric-group {
                margin-bottom: 12px;
                border: 1px solid #333;
                border-radius: 4px;
                padding: 8px;
            }
            
            .perf-metric-group h4 {
                margin: 0 0 6px 0;
                font-size: 12px;
                color: #2196F3;
                border-bottom: 1px solid #333;
                padding-bottom: 4px;
            }
            
            .perf-metric {
                display: flex;
                justify-content: space-between;
                margin-bottom: 2px;
            }
            
            .perf-label {
                color: #ccc;
            }
            
            .perf-value {
                font-weight: bold;
                color: #4CAF50;
            }
            
            .perf-value.warning {
                color: #FF9800;
            }
            
            .perf-value.error {
                color: #f44336;
            }
            
            .perf-unit {
                color: #999;
                font-size: 10px;
            }
            
            .perf-details {
                border-top: 1px solid #333;
                padding-top: 12px;
                margin-top: 12px;
            }
            
            .perf-charts {
                display: flex;
                gap: 10px;
                margin-bottom: 12px;
            }
            
            .perf-charts canvas {
                border: 1px solid #333;
                border-radius: 4px;
            }
            
            .perf-logs {
                max-height: 150px;
                overflow-y: auto;
            }
            
            .perf-log-list {
                font-size: 10px;
            }
            
            .perf-log-item {
                padding: 2px 0;
                border-bottom: 1px solid #333;
                color: #ccc;
            }
            
            .perf-log-time {
                color: #666;
            }
        `;
        
        document.head.appendChild(styles);
    }

    setupEventListeners() {
        const dashboard = document.querySelector('.perf-dashboard');
        if (!dashboard) return;

        // 切换详细信息
        dashboard.querySelector('.perf-toggle-details').addEventListener('click', () => {
            const details = dashboard.querySelector('.perf-details');
            const isVisible = details.style.display !== 'none';
            details.style.display = isVisible ? 'none' : 'block';
            this.options.showDetails = !isVisible;
        });

        // 清除数据
        dashboard.querySelector('.perf-clear-data').addEventListener('click', () => {
            this.clearData();
        });

        // 关闭仪表板
        dashboard.querySelector('.perf-close').addEventListener('click', () => {
            this.hide();
        });

        // 键盘快捷键
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.shiftKey && e.key === 'P') {
                e.preventDefault();
                this.toggle();
            }
        });
    }

    startMonitoring() {
        this.collectMetrics();
        this.updateTimer = setInterval(() => {
            this.collectMetrics();
        }, this.options.updateInterval);
    }

    stopMonitoring() {
        if (this.updateTimer) {
            clearInterval(this.updateTimer);
            this.updateTimer = null;
        }
    }

    collectMetrics() {
        const timestamp = Date.now();
        
        // 收集性能指标
        const perfMetrics = this.collectPerformanceMetrics();
        if (perfMetrics) {
            this.metrics.performance.push({ timestamp, ...perfMetrics });
        }

        // 收集内存指标
        const memoryMetrics = this.collectMemoryMetrics();
        if (memoryMetrics) {
            this.metrics.memory.push({ timestamp, ...memoryMetrics });
        }

        // 收集网络指标
        const networkMetrics = this.collectNetworkMetrics();
        if (networkMetrics) {
            this.metrics.network.push({ timestamp, ...networkMetrics });
        }

        // 收集缓存指标
        const cacheMetrics = this.collectCacheMetrics();
        if (cacheMetrics) {
            this.metrics.cache.push({ timestamp, ...cacheMetrics });
        }

        // 清理过期数据
        this.cleanupOldData();

        // 检查警告
        this.checkAlerts();

        // 更新显示
        if (this.isVisible) {
            this.updateDisplay();
        }
    }

    collectPerformanceMetrics() {
        if (!window.icefoxPerformanceMonitor) return null;

        const metrics = window.icefoxPerformanceMonitor.getMetrics();
        return {
            lcp: metrics.lcp || 0,
            fid: metrics.fid || 0,
            cls: metrics.cls || 0,
            fcp: metrics.fcp || 0
        };
    }

    collectMemoryMetrics() {
        if (!performance.memory) return null;

        return {
            used: performance.memory.usedJSHeapSize,
            total: performance.memory.totalJSHeapSize,
            limit: performance.memory.jsHeapSizeLimit
        };
    }

    collectNetworkMetrics() {
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (!connection) return null;

        return {
            effectiveType: connection.effectiveType,
            downlink: connection.downlink,
            rtt: connection.rtt,
            saveData: connection.saveData
        };
    }

    collectCacheMetrics() {
        if (!window.icefoxCache) return null;

        const stats = window.icefoxCache.getStats();
        return {
            memorySize: stats.memoryCache.sizeBytes,
            memoryCount: stats.memoryCache.size,
            hitRate: this.calculateCacheHitRate()
        };
    }

    calculateCacheHitRate() {
        // 这里需要实现缓存命中率计算逻辑
        // 可以基于缓存访问统计
        return Math.random() * 100; // 临时实现
    }

    cleanupOldData() {
        const cutoff = Date.now() - this.options.dataRetention;
        
        Object.keys(this.metrics).forEach(key => {
            this.metrics[key] = this.metrics[key].filter(item => item.timestamp > cutoff);
        });
    }

    checkAlerts() {
        const latest = this.getLatestMetrics();
        const newAlerts = [];

        // 检查内存使用
        if (latest.memory && latest.memory.used > this.options.thresholds.memory) {
            newAlerts.push({
                type: 'memory',
                message: `内存使用过高: ${this.formatBytes(latest.memory.used)}`,
                severity: 'error'
            });
        }

        // 检查 LCP
        if (latest.performance && latest.performance.lcp > this.options.thresholds.lcp) {
            newAlerts.push({
                type: 'lcp',
                message: `LCP 过慢: ${latest.performance.lcp}ms`,
                severity: 'warning'
            });
        }

        // 检查 FID
        if (latest.performance && latest.performance.fid > this.options.thresholds.fid) {
            newAlerts.push({
                type: 'fid',
                message: `FID 过高: ${latest.performance.fid}ms`,
                severity: 'warning'
            });
        }

        // 检查 CLS
        if (latest.performance && latest.performance.cls > this.options.thresholds.cls) {
            newAlerts.push({
                type: 'cls',
                message: `CLS 过高: ${latest.performance.cls}`,
                severity: 'warning'
            });
        }

        // 更新警告列表
        this.alerts = newAlerts;
    }

    getLatestMetrics() {
        const latest = {};
        
        Object.keys(this.metrics).forEach(key => {
            const data = this.metrics[key];
            if (data.length > 0) {
                latest[key] = data[data.length - 1];
            }
        });
        
        return latest;
    }

    updateDisplay() {
        const latest = this.getLatestMetrics();
        
        // 更新指标值
        this.updateMetricValue('lcp', latest.performance?.lcp, 'ms');
        this.updateMetricValue('fid', latest.performance?.fid, 'ms');
        this.updateMetricValue('cls', latest.performance?.cls);
        
        if (latest.memory) {
            this.updateMetricValue('memory-used', this.formatBytes(latest.memory.used, false), 'MB');
            this.updateMetricValue('memory-limit', this.formatBytes(latest.memory.limit, false), 'MB');
        }
        
        if (latest.network) {
            this.updateMetricValue('connection-type', latest.network.effectiveType);
            this.updateMetricValue('connection-rtt', latest.network.rtt, 'ms');
        }
        
        if (latest.cache) {
            this.updateMetricValue('cache-hit-rate', latest.cache.hitRate?.toFixed(1), '%');
            this.updateMetricValue('cache-size', this.formatBytes(latest.cache.memorySize, false), 'MB');
        }

        // 更新警告
        this.updateAlerts();

        // 更新图表
        if (this.options.showDetails) {
            this.updateCharts();
        }
    }

    updateMetricValue(metric, value, unit) {
        const element = document.querySelector(`[data-metric="${metric}"]`);
        if (!element) return;

        if (value === undefined || value === null) {
            element.textContent = '-';
            element.className = 'perf-value';
            return;
        }

        element.textContent = typeof value === 'number' ? value.toFixed(value < 10 ? 2 : 0) : value;
        
        // 设置颜色状态
        let status = '';
        if (metric === 'lcp' && value > this.options.thresholds.lcp) status = 'error';
        else if (metric === 'fid' && value > this.options.thresholds.fid) status = 'warning';
        else if (metric === 'cls' && value > this.options.thresholds.cls) status = 'warning';
        
        element.className = `perf-value ${status}`;
    }

    updateAlerts() {
        const alertsContainer = document.querySelector('.perf-alerts');
        if (!alertsContainer) return;

        alertsContainer.innerHTML = '';
        
        this.alerts.forEach(alert => {
            const alertElement = document.createElement('div');
            alertElement.className = `perf-alert perf-alert-${alert.severity}`;
            alertElement.textContent = alert.message;
            alertsContainer.appendChild(alertElement);
        });
    }

    updateCharts() {
        // 这里可以实现图表更新逻辑
        // 使用 Canvas 或集成 Chart.js 等图表库
    }

    formatBytes(bytes, includeUnit = true) {
        if (bytes === 0) return includeUnit ? '0 B' : 0;
        
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        const value = parseFloat((bytes / Math.pow(k, i)).toFixed(2));
        
        return includeUnit ? `${value} ${sizes[i]}` : value;
    }

    show() {
        const container = document.querySelector(this.options.container);
        if (container) {
            container.style.display = 'block';
            this.isVisible = true;
            this.updateDisplay();
        }
    }

    hide() {
        const container = document.querySelector(this.options.container);
        if (container) {
            container.style.display = 'none';
            this.isVisible = false;
        }
    }

    toggle() {
        if (this.isVisible) {
            this.hide();
        } else {
            this.show();
        }
    }

    clearData() {
        Object.keys(this.metrics).forEach(key => {
            this.metrics[key] = [];
        });
        this.alerts = [];
        this.updateDisplay();
    }

    destroy() {
        this.stopMonitoring();
        const container = document.querySelector(this.options.container);
        if (container) {
            container.remove();
        }
        
        const styles = document.getElementById('perf-dashboard-styles');
        if (styles) {
            styles.remove();
        }
    }
}

// 自动初始化性能仪表板（仅在开发环境或调试模式下）
document.addEventListener('DOMContentLoaded', () => {
    if (window.ICEFOX_CONFIG?.debug || window.location.hostname === 'localhost') {
        window.icefoxDashboard = new IcefoxPerformanceDashboard();
        
        // 添加控制台命令
        window.showPerfDashboard = () => window.icefoxDashboard.show();
        window.hidePerfDashboard = () => window.icefoxDashboard.hide();
        
        console.log('Performance Dashboard initialized. Use Ctrl+Shift+P to toggle.');
    }
});

// 导出类
window.IcefoxPerformanceDashboard = IcefoxPerformanceDashboard;