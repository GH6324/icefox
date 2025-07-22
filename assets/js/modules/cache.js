/**
 * Icefox 前端缓存模块
 * 提供多级缓存和智能缓存策略
 */

class IcefoxCache {
    constructor(options = {}) {
        this.options = {
            // 内存缓存大小限制 (MB)
            memoryLimit: 10,
            // 默认过期时间 (秒)
            defaultTTL: 3600,
            // 是否启用 IndexedDB
            enableIndexedDB: true,
            // 是否启用压缩
            enableCompression: true,
            // 缓存键前缀
            keyPrefix: 'icefox_',
            ...options
        };

        this.memoryCache = new Map();
        this.memoryCacheSize = 0;
        this.indexedDB = null;
        this.compressionSupported = typeof CompressionStream !== 'undefined';

        this.init();
    }

    async init() {
        // 初始化 IndexedDB
        if (this.options.enableIndexedDB) {
            await this.initIndexedDB();
        }

        // 定期清理过期缓存
        setInterval(() => this.cleanup(), 60000); // 每分钟清理一次
    }

    async initIndexedDB() {
        try {
            return new Promise((resolve, reject) => {
                const request = indexedDB.open('IcefoxCache', 1);
                
                request.onerror = () => reject(request.error);
                request.onsuccess = () => {
                    this.indexedDB = request.result;
                    resolve();
                };
                
                request.onupgradeneeded = (event) => {
                    const db = event.target.result;
                    if (!db.objectStoreNames.contains('cache')) {
                        const store = db.createObjectStore('cache', { keyPath: 'key' });
                        store.createIndex('expiry', 'expiry', { unique: false });
                    }
                };
            });
        } catch (error) {
            console.warn('IndexedDB initialization failed:', error);
            this.indexedDB = null;
        }
    }

    // 生成缓存键
    generateKey(key) {
        return this.options.keyPrefix + key;
    }

    // 计算数据大小 (字节)
    calculateSize(data) {
        return new Blob([JSON.stringify(data)]).size;
    }

    // 压缩数据
    async compress(data) {
        if (!this.options.enableCompression || !this.compressionSupported) {
            return data;
        }

        try {
            const jsonString = JSON.stringify(data);
            const stream = new CompressionStream('gzip');
            const writer = stream.writable.getWriter();
            const reader = stream.readable.getReader();

            writer.write(new TextEncoder().encode(jsonString));
            writer.close();

            const chunks = [];
            let done = false;
            while (!done) {
                const { value, done: readerDone } = await reader.read();
                done = readerDone;
                if (value) chunks.push(value);
            }

            return new Uint8Array(chunks.reduce((acc, chunk) => [...acc, ...chunk], []));
        } catch (error) {
            console.warn('Compression failed:', error);
            return data;
        }
    }

    // 解压数据
    async decompress(compressedData) {
        if (!this.options.enableCompression || !this.compressionSupported) {
            return compressedData;
        }

        try {
            const stream = new DecompressionStream('gzip');
            const writer = stream.writable.getWriter();
            const reader = stream.readable.getReader();

            writer.write(compressedData);
            writer.close();

            const chunks = [];
            let done = false;
            while (!done) {
                const { value, done: readerDone } = await reader.read();
                done = readerDone;
                if (value) chunks.push(value);
            }

            const decompressed = new Uint8Array(chunks.reduce((acc, chunk) => [...acc, ...chunk], []));
            const jsonString = new TextDecoder().decode(decompressed);
            return JSON.parse(jsonString);
        } catch (error) {
            console.warn('Decompression failed:', error);
            return compressedData;
        }
    }

    // 设置缓存
    async set(key, value, ttl = null) {
        const cacheKey = this.generateKey(key);
        const expiry = Date.now() + (ttl || this.options.defaultTTL) * 1000;
        const size = this.calculateSize(value);

        const cacheItem = {
            key: cacheKey,
            value: value,
            expiry: expiry,
            size: size,
            compressed: false
        };

        // 如果数据较大，尝试压缩
        if (size > 1024 && this.options.enableCompression) {
            const compressed = await this.compress(value);
            if (compressed !== value) {
                cacheItem.value = compressed;
                cacheItem.compressed = true;
                cacheItem.size = this.calculateSize(compressed);
            }
        }

        // 内存缓存
        this.setMemoryCache(cacheKey, cacheItem);

        // IndexedDB 缓存
        if (this.indexedDB) {
            await this.setIndexedDBCache(cacheItem);
        }

        // localStorage 降级
        if (!this.indexedDB) {
            this.setLocalStorageCache(cacheKey, cacheItem);
        }
    }

    setMemoryCache(key, item) {
        // 检查内存限制
        const newSize = this.memoryCacheSize + item.size;
        if (newSize > this.options.memoryLimit * 1024 * 1024) {
            this.evictMemoryCache();
        }

        this.memoryCache.set(key, item);
        this.memoryCacheSize += item.size;
    }

    async setIndexedDBCache(item) {
        try {
            const transaction = this.indexedDB.transaction(['cache'], 'readwrite');
            const store = transaction.objectStore('cache');
            await new Promise((resolve, reject) => {
                const request = store.put(item);
                request.onsuccess = () => resolve();
                request.onerror = () => reject(request.error);
            });
        } catch (error) {
            console.warn('IndexedDB set failed:', error);
        }
    }

    setLocalStorageCache(key, item) {
        try {
            // 简化存储到 localStorage
            const simpleItem = {
                value: item.compressed ? null : item.value, // 不在 localStorage 中存储压缩数据
                expiry: item.expiry
            };
            localStorage.setItem(key, JSON.stringify(simpleItem));
        } catch (error) {
            console.warn('localStorage set failed:', error);
        }
    }

    // 获取缓存
    async get(key) {
        const cacheKey = this.generateKey(key);

        // 1. 内存缓存
        const memoryItem = this.memoryCache.get(cacheKey);
        if (memoryItem && memoryItem.expiry > Date.now()) {
            let value = memoryItem.value;
            if (memoryItem.compressed) {
                value = await this.decompress(value);
            }
            return value;
        }

        // 2. IndexedDB 缓存
        if (this.indexedDB) {
            const dbItem = await this.getIndexedDBCache(cacheKey);
            if (dbItem && dbItem.expiry > Date.now()) {
                let value = dbItem.value;
                if (dbItem.compressed) {
                    value = await this.decompress(value);
                }
                // 回填到内存缓存
                this.setMemoryCache(cacheKey, dbItem);
                return value;
            }
        }

        // 3. localStorage 降级
        const localItem = this.getLocalStorageCache(cacheKey);
        if (localItem && localItem.expiry > Date.now()) {
            return localItem.value;
        }

        return null;
    }

    async getIndexedDBCache(key) {
        try {
            const transaction = this.indexedDB.transaction(['cache'], 'readonly');
            const store = transaction.objectStore('cache');
            return new Promise((resolve, reject) => {
                const request = store.get(key);
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });
        } catch (error) {
            console.warn('IndexedDB get failed:', error);
            return null;
        }
    }

    getLocalStorageCache(key) {
        try {
            const item = localStorage.getItem(key);
            return item ? JSON.parse(item) : null;
        } catch (error) {
            console.warn('localStorage get failed:', error);
            return null;
        }
    }

    // 删除缓存
    async delete(key) {
        const cacheKey = this.generateKey(key);

        // 内存缓存
        const memoryItem = this.memoryCache.get(cacheKey);
        if (memoryItem) {
            this.memoryCache.delete(cacheKey);
            this.memoryCacheSize -= memoryItem.size;
        }

        // IndexedDB 缓存
        if (this.indexedDB) {
            try {
                const transaction = this.indexedDB.transaction(['cache'], 'readwrite');
                const store = transaction.objectStore('cache');
                store.delete(cacheKey);
            } catch (error) {
                console.warn('IndexedDB delete failed:', error);
            }
        }

        // localStorage
        localStorage.removeItem(cacheKey);
    }

    // 清理过期缓存
    async cleanup() {
        const now = Date.now();

        // 清理内存缓存
        for (const [key, item] of this.memoryCache.entries()) {
            if (item.expiry <= now) {
                this.memoryCache.delete(key);
                this.memoryCacheSize -= item.size;
            }
        }

        // 清理 IndexedDB 缓存
        if (this.indexedDB) {
            try {
                const transaction = this.indexedDB.transaction(['cache'], 'readwrite');
                const store = transaction.objectStore('cache');
                const index = store.index('expiry');
                const range = IDBKeyRange.upperBound(now);
                const request = index.openCursor(range);
                
                request.onsuccess = (event) => {
                    const cursor = event.target.result;
                    if (cursor) {
                        cursor.delete();
                        cursor.continue();
                    }
                };
            } catch (error) {
                console.warn('IndexedDB cleanup failed:', error);
            }
        }

        // 清理 localStorage
        for (let i = localStorage.length - 1; i >= 0; i--) {
            const key = localStorage.key(i);
            if (key && key.startsWith(this.options.keyPrefix)) {
                try {
                    const item = JSON.parse(localStorage.getItem(key));
                    if (item.expiry <= now) {
                        localStorage.removeItem(key);
                    }
                } catch (error) {
                    // 删除无效的缓存项
                    localStorage.removeItem(key);
                }
            }
        }
    }

    // 内存缓存淘汰策略 (LRU)
    evictMemoryCache() {
        const entries = Array.from(this.memoryCache.entries());
        // 按访问时间排序，删除最久未使用的
        entries.sort((a, b) => a[1].lastAccess - b[1].lastAccess);
        
        const toEvict = entries.slice(0, Math.ceil(entries.length * 0.3));
        for (const [key, item] of toEvict) {
            this.memoryCache.delete(key);
            this.memoryCacheSize -= item.size;
        }
    }

    // 清空所有缓存
    async clear() {
        // 内存缓存
        this.memoryCache.clear();
        this.memoryCacheSize = 0;

        // IndexedDB 缓存
        if (this.indexedDB) {
            try {
                const transaction = this.indexedDB.transaction(['cache'], 'readwrite');
                const store = transaction.objectStore('cache');
                store.clear();
            } catch (error) {
                console.warn('IndexedDB clear failed:', error);
            }
        }

        // localStorage
        for (let i = localStorage.length - 1; i >= 0; i--) {
            const key = localStorage.key(i);
            if (key && key.startsWith(this.options.keyPrefix)) {
                localStorage.removeItem(key);
            }
        }
    }

    // 获取缓存统计信息
    getStats() {
        return {
            memoryCache: {
                size: this.memoryCache.size,
                sizeBytes: this.memoryCacheSize,
                limit: this.options.memoryLimit * 1024 * 1024
            },
            indexedDBSupported: !!this.indexedDB,
            compressionSupported: this.compressionSupported
        };
    }
}

// 创建全局缓存实例
window.icefoxCache = new IcefoxCache();

// 导出类
window.IcefoxCache = IcefoxCache;