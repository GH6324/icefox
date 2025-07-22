/**
 * Icefox Service Worker 管理器
 * 负责注册、更新和管理 Service Worker
 */

class IcefoxServiceWorkerManager {
    constructor() {
        this.swRegistration = null;
        this.isSupported = 'serviceWorker' in navigator;
        this.isEnabled = window.ICEFOX_CONFIG?.enableServiceWorker !== false;
        
        if (this.isSupported && this.isEnabled) {
            this.init();
        }
    }

    async init() {
        try {
            await this.registerServiceWorker();
            this.setupEventListeners();
            this.setupBackgroundSync();
            this.setupPushNotifications();
        } catch (error) {
            console.error('[SW Manager] Initialization failed:', error);
        }
    }

    /**
     * 注册 Service Worker
     */
    async registerServiceWorker() {
        try {
            this.swRegistration = await navigator.serviceWorker.register('/assets/js/sw.js', {
                scope: '/'
            });

            console.log('[SW Manager] Service Worker registered:', this.swRegistration.scope);

            // 检查更新
            this.swRegistration.addEventListener('updatefound', () => {
                this.handleUpdate();
            });

            // 检查是否有等待中的 Service Worker
            if (this.swRegistration.waiting) {
                this.showUpdateAvailable();
            }

        } catch (error) {
            console.error('[SW Manager] Registration failed:', error);
        }
    }

    /**
     * 设置事件监听器
     */
    setupEventListeners() {
        // 监听 Service Worker 消息
        navigator.serviceWorker.addEventListener('message', event => {
            this.handleMessage(event.data);
        });

        // 监听控制器变化
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            console.log('[SW Manager] Controller changed, reloading...');
            window.location.reload();
        });

        // 监听页面可见性变化
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.swRegistration) {
                this.swRegistration.update();
            }
        });
    }

    /**
     * 设置后台同步
     */
    setupBackgroundSync() {
        if (!this.swRegistration || !this.swRegistration.sync) {
            console.warn('[SW Manager] Background sync not supported');
            return;
        }

        // 监听离线状态
        window.addEventListener('online', () => {
            this.triggerSync('comment-sync');
            this.triggerSync('like-sync');
            this.triggerSync('performance-sync');
        });

        // 定期同步性能数据
        setInterval(() => {
            if (navigator.onLine) {
                this.triggerSync('performance-sync');
            }
        }, 5 * 60 * 1000); // 5分钟
    }

    /**
     * 设置推送通知
     */
    async setupPushNotifications() {
        if (!this.swRegistration || !('PushManager' in window)) {
            console.warn('[SW Manager] Push notifications not supported');
            return;
        }

        try {
            const permission = await Notification.requestPermission();
            if (permission === 'granted') {
                await this.subscribeToPush();
            }
        } catch (error) {
            console.error('[SW Manager] Push setup failed:', error);
        }
    }

    /**
     * 订阅推送通知
     */
    async subscribeToPush() {
        try {
            const subscription = await this.swRegistration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array(window.ICEFOX_CONFIG.vapidPublicKey || '')
            });

            // 发送订阅信息到服务器
            await fetch('/api/push/subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(subscription)
            });

            console.log('[SW Manager] Push subscription successful');
        } catch (error) {
            console.error('[SW Manager] Push subscription failed:', error);
        }
    }

    /**
     * 处理 Service Worker 更新
     */
    handleUpdate() {
        const newWorker = this.swRegistration.installing;
        
        newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                this.showUpdateAvailable();
            }
        });
    }

    /**
     * 显示更新可用提示
     */
    showUpdateAvailable() {
        if (window.icefox && window.icefox.getModule('utils')) {
            const utils = window.icefox.getModule('utils');
            utils.showNotification('新版本可用', {
                type: 'info',
                actions: [{
                    text: '立即更新',
                    action: () => this.applyUpdate()
                }, {
                    text: '稍后',
                    action: () => {}
                }]
            });
        } else {
            // 降级处理
            if (confirm('发现新版本，是否立即更新？')) {
                this.applyUpdate();
            }
        }
    }

    /**
     * 应用更新
     */
    applyUpdate() {
        if (this.swRegistration && this.swRegistration.waiting) {
            this.swRegistration.waiting.postMessage({ type: 'SKIP_WAITING' });
        }
    }

    /**
     * 触发后台同步
     */
    async triggerSync(tag) {
        if (this.swRegistration && this.swRegistration.sync) {
            try {
                await this.swRegistration.sync.register(tag);
                console.log(`[SW Manager] Sync registered: ${tag}`);
            } catch (error) {
                console.error(`[SW Manager] Sync registration failed: ${tag}`, error);
            }
        }
    }

    /**
     * 处理 Service Worker 消息
     */
    handleMessage(data) {
        switch (data.type) {
            case 'CACHE_UPDATED':
                console.log('[SW Manager] Cache updated:', data.url);
                break;
            case 'OFFLINE':
                this.handleOffline();
                break;
            case 'ONLINE':
                this.handleOnline();
                break;
            default:
                console.log('[SW Manager] Unknown message:', data);
        }
    }

    /**
     * 处理离线状态
     */
    handleOffline() {
        if (window.icefox && window.icefox.getModule('utils')) {
            const utils = window.icefox.getModule('utils');
            utils.showNotification('已离线', {
                type: 'warning',
                message: '您当前处于离线状态，部分功能可能受限'
            });
        }

        // 启用离线模式
        document.body.classList.add('offline-mode');
    }

    /**
     * 处理在线状态
     */
    handleOnline() {
        if (window.icefox && window.icefox.getModule('utils')) {
            const utils = window.icefox.getModule('utils');
            utils.showNotification('已连接', {
                type: 'success',
                message: '网络连接已恢复'
            });
        }

        // 禁用离线模式
        document.body.classList.remove('offline-mode');
        
        // 触发同步
        this.triggerSync('comment-sync');
        this.triggerSync('like-sync');
    }

    /**
     * 缓存资源
     */
    async cacheResource(url, cacheName = 'dynamic') {
        if (this.swRegistration && this.swRegistration.active) {
            this.swRegistration.active.postMessage({
                type: 'CACHE_RESOURCE',
                url: url,
                cacheName: cacheName
            });
        }
    }

    /**
     * 预缓存页面
     */
    async precachePage(url) {
        await this.cacheResource(url, 'pages');
    }

    /**
     * 清理缓存
     */
    async clearCache(cacheName) {
        if (this.swRegistration && this.swRegistration.active) {
            this.swRegistration.active.postMessage({
                type: 'CLEAR_CACHE',
                cacheName: cacheName
            });
        }
    }

    /**
     * 获取缓存统计
     */
    async getCacheStats() {
        return new Promise((resolve) => {
            if (this.swRegistration && this.swRegistration.active) {
                const messageChannel = new MessageChannel();
                
                messageChannel.port1.onmessage = (event) => {
                    resolve(event.data);
                };
                
                this.swRegistration.active.postMessage({
                    type: 'GET_CACHE_STATS'
                }, [messageChannel.port2]);
            } else {
                resolve(null);
            }
        });
    }

    /**
     * 存储离线数据
     */
    async storeOfflineData(type, data) {
        if (this.swRegistration && this.swRegistration.active) {
            this.swRegistration.active.postMessage({
                type: 'STORE_OFFLINE_DATA',
                dataType: type,
                data: data
            });
        }
    }

    /**
     * Base64 转 Uint8Array
     */
    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    /**
     * 检查 Service Worker 状态
     */
    getStatus() {
        return {
            supported: this.isSupported,
            enabled: this.isEnabled,
            registered: !!this.swRegistration,
            active: !!(this.swRegistration && this.swRegistration.active),
            scope: this.swRegistration ? this.swRegistration.scope : null
        };
    }

    /**
     * 卸载 Service Worker
     */
    async unregister() {
        if (this.swRegistration) {
            const result = await this.swRegistration.unregister();
            console.log('[SW Manager] Unregistered:', result);
            return result;
        }
        return false;
    }
}

// 自动初始化
document.addEventListener('DOMContentLoaded', () => {
    if (window.ICEFOX_CONFIG?.enableServiceWorker !== false) {
        window.icefoxSWManager = new IcefoxServiceWorkerManager();
    }
});

// 导出类
window.IcefoxServiceWorkerManager = IcefoxServiceWorkerManager;