/**
 * Icefox Service Worker
 * 提供离线缓存、后台同步等功能
 */

const CACHE_NAME = 'icefox-v2.2.0';
const STATIC_CACHE = 'icefox-static-v2.2.0';
const DYNAMIC_CACHE = 'icefox-dynamic-v2.2.0';
const IMAGE_CACHE = 'icefox-images-v2.2.0';

// 需要缓存的静态资源
const STATIC_ASSETS = [
    '/',
    '/assets/css/uno.css',
    '/assets/js/icefox-optimized.js',
    '/assets/js/modules/utils.js',
    '/assets/js/modules/performance.js',
    '/assets/js/modules/cache.js',
    '/assets/js/modules/lazyload.js',
    '/assets/fonts/iconfont.woff2',
    '/assets/svgs/logo.svg'
];

// 缓存策略配置
const CACHE_STRATEGIES = {
    // 静态资源：缓存优先
    static: {
        pattern: /\.(css|js|woff2?|svg|png|jpg|jpeg|gif|webp|avif)$/,
        strategy: 'cacheFirst',
        maxAge: 30 * 24 * 60 * 60 * 1000 // 30天
    },
    
    // API请求：网络优先
    api: {
        pattern: /\/api\//,
        strategy: 'networkFirst',
        maxAge: 5 * 60 * 1000 // 5分钟
    },
    
    // 页面：网络优先，离线时使用缓存
    pages: {
        pattern: /\.(php|html)$/,
        strategy: 'networkFirst',
        maxAge: 60 * 60 * 1000 // 1小时
    },
    
    // 图片：缓存优先
    images: {
        pattern: /\.(png|jpg|jpeg|gif|webp|avif)$/,
        strategy: 'cacheFirst',
        maxAge: 7 * 24 * 60 * 60 * 1000 // 7天
    }
};

/**
 * Service Worker 安装事件
 */
self.addEventListener('install', event => {
    console.log('[SW] Installing...');
    
    event.waitUntil(
        Promise.all([
            // 缓存静态资源
            caches.open(STATIC_CACHE).then(cache => {
                return cache.addAll(STATIC_ASSETS);
            }),
            
            // 跳过等待，立即激活
            self.skipWaiting()
        ])
    );
});

/**
 * Service Worker 激活事件
 */
self.addEventListener('activate', event => {
    console.log('[SW] Activating...');
    
    event.waitUntil(
        Promise.all([
            // 清理旧缓存
            cleanupOldCaches(),
            
            // 立即控制所有客户端
            self.clients.claim()
        ])
    );
});

/**
 * 拦截网络请求
 */
self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    
    // 只处理同源请求
    if (url.origin !== location.origin) {
        return;
    }
    
    // 根据请求类型选择缓存策略
    const strategy = getStrategy(request);
    
    if (strategy) {
        event.respondWith(handleRequest(request, strategy));
    }
});

/**
 * 后台同步事件
 */
self.addEventListener('sync', event => {
    console.log('[SW] Background sync:', event.tag);
    
    switch (event.tag) {
        case 'comment-sync':
            event.waitUntil(syncComments());
            break;
        case 'like-sync':
            event.waitUntil(syncLikes());
            break;
        case 'performance-sync':
            event.waitUntil(syncPerformanceData());
            break;
    }
});

/**
 * 推送消息事件
 */
self.addEventListener('push', event => {
    if (!event.data) return;
    
    const data = event.data.json();
    const options = {
        body: data.body,
        icon: '/assets/svgs/logo.svg',
        badge: '/assets/svgs/badge.svg',
        tag: data.tag || 'icefox-notification',
        data: data.data || {},
        actions: data.actions || []
    };
    
    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

/**
 * 通知点击事件
 */
self.addEventListener('notificationclick', event => {
    event.notification.close();
    
    const data = event.notification.data;
    const action = event.action;
    
    event.waitUntil(
        clients.openWindow(data.url || '/')
    );
});

/**
 * 获取请求的缓存策略
 */
function getStrategy(request) {
    const url = new URL(request.url);
    const pathname = url.pathname;
    
    for (const [name, config] of Object.entries(CACHE_STRATEGIES)) {
        if (config.pattern.test(pathname)) {
            return { name, ...config };
        }
    }
    
    return null;
}

/**
 * 处理请求
 */
async function handleRequest(request, strategy) {
    switch (strategy.strategy) {
        case 'cacheFirst':
            return cacheFirst(request, strategy);
        case 'networkFirst':
            return networkFirst(request, strategy);
        case 'staleWhileRevalidate':
            return staleWhileRevalidate(request, strategy);
        default:
            return fetch(request);
    }
}

/**
 * 缓存优先策略
 */
async function cacheFirst(request, strategy) {
    const cacheName = getCacheName(strategy.name);
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    
    if (cached) {
        // 检查缓存是否过期
        const cacheTime = await getCacheTime(request, cache);
        if (cacheTime && (Date.now() - cacheTime) < strategy.maxAge) {
            return cached;
        }
    }
    
    try {
        const response = await fetch(request);
        if (response.ok) {
            await cache.put(request, response.clone());
            await setCacheTime(request, cache, Date.now());
        }
        return response;
    } catch (error) {
        // 网络失败时返回缓存
        if (cached) {
            return cached;
        }
        throw error;
    }
}

/**
 * 网络优先策略
 */
async function networkFirst(request, strategy) {
    const cacheName = getCacheName(strategy.name);
    const cache = await caches.open(cacheName);
    
    try {
        const response = await fetch(request);
        if (response.ok) {
            await cache.put(request, response.clone());
            await setCacheTime(request, cache, Date.now());
        }
        return response;
    } catch (error) {
        // 网络失败时使用缓存
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }
        
        // 如果是页面请求，返回离线页面
        if (request.mode === 'navigate') {
            return cache.match('/offline.html') || new Response('Offline', { status: 503 });
        }
        
        throw error;
    }
}

/**
 * 过期重新验证策略
 */
async function staleWhileRevalidate(request, strategy) {
    const cacheName = getCacheName(strategy.name);
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    
    // 后台更新缓存
    const fetchPromise = fetch(request).then(response => {
        if (response.ok) {
            cache.put(request, response.clone());
            setCacheTime(request, cache, Date.now());
        }
        return response;
    });
    
    // 立即返回缓存，如果有的话
    return cached || fetchPromise;
}

/**
 * 获取缓存名称
 */
function getCacheName(type) {
    switch (type) {
        case 'static':
            return STATIC_CACHE;
        case 'images':
            return IMAGE_CACHE;
        default:
            return DYNAMIC_CACHE;
    }
}

/**
 * 设置缓存时间
 */
async function setCacheTime(request, cache, time) {
    const timeKey = `${request.url}:timestamp`;
    await cache.put(timeKey, new Response(time.toString()));
}

/**
 * 获取缓存时间
 */
async function getCacheTime(request, cache) {
    const timeKey = `${request.url}:timestamp`;
    const timeResponse = await cache.match(timeKey);
    if (timeResponse) {
        const timeText = await timeResponse.text();
        return parseInt(timeText, 10);
    }
    return null;
}

/**
 * 清理旧缓存
 */
async function cleanupOldCaches() {
    const cacheNames = await caches.keys();
    const currentCaches = [CACHE_NAME, STATIC_CACHE, DYNAMIC_CACHE, IMAGE_CACHE];
    
    return Promise.all(
        cacheNames
            .filter(cacheName => !currentCaches.includes(cacheName))
            .map(cacheName => caches.delete(cacheName))
    );
}

/**
 * 同步评论数据
 */
async function syncComments() {
    try {
        const pendingComments = await getStoredData('pendingComments');
        
        for (const comment of pendingComments) {
            const response = await fetch('/api/comment', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(comment)
            });
            
            if (response.ok) {
                await removeStoredData('pendingComments', comment.id);
            }
        }
    } catch (error) {
        console.error('[SW] Comment sync failed:', error);
    }
}

/**
 * 同步点赞数据
 */
async function syncLikes() {
    try {
        const pendingLikes = await getStoredData('pendingLikes');
        
        for (const like of pendingLikes) {
            const response = await fetch('/api/like', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(like)
            });
            
            if (response.ok) {
                await removeStoredData('pendingLikes', like.id);
            }
        }
    } catch (error) {
        console.error('[SW] Like sync failed:', error);
    }
}

/**
 * 同步性能数据
 */
async function syncPerformanceData() {
    try {
        const performanceData = await getStoredData('performanceData');
        
        if (performanceData.length > 0) {
            const response = await fetch('/api/performance', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(performanceData)
            });
            
            if (response.ok) {
                await clearStoredData('performanceData');
            }
        }
    } catch (error) {
        console.error('[SW] Performance sync failed:', error);
    }
}

/**
 * 获取存储的数据
 */
async function getStoredData(key) {
    const cache = await caches.open(DYNAMIC_CACHE);
    const response = await cache.match(`/sw-data/${key}`);
    
    if (response) {
        return response.json();
    }
    
    return [];
}

/**
 * 存储数据
 */
async function storeData(key, data) {
    const cache = await caches.open(DYNAMIC_CACHE);
    const response = new Response(JSON.stringify(data), {
        headers: { 'Content-Type': 'application/json' }
    });
    
    await cache.put(`/sw-data/${key}`, response);
}

/**
 * 移除存储的数据项
 */
async function removeStoredData(key, itemId) {
    const data = await getStoredData(key);
    const filtered = data.filter(item => item.id !== itemId);
    await storeData(key, filtered);
}

/**
 * 清空存储的数据
 */
async function clearStoredData(key) {
    const cache = await caches.open(DYNAMIC_CACHE);
    await cache.delete(`/sw-data/${key}`);
}

/**
 * 向客户端发送消息
 */
async function sendMessageToClients(message) {
    const clients = await self.clients.matchAll();
    clients.forEach(client => {
        client.postMessage(message);
    });
}

console.log('[SW] Service Worker loaded');