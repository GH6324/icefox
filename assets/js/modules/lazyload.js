/**
 * Icefox 懒加载模块
 * 优化的图片和视频懒加载实现
 */

class IcefoxLazyLoad {
    constructor(options = {}) {
        this.options = {
            selector: '[data-src]',
            rootMargin: '50px 0px',
            threshold: 0.01,
            enableWebP: true,
            placeholder: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMSIgaGVpZ2h0PSIxIiB2aWV3Qm94PSIwIDAgMSAxIiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxIiBoZWlnaHQ9IjEiIGZpbGw9IiNGNUY1RjUiLz48L3N2Zz4=',
            ...options
        };

        this.observer = null;
        this.webpSupported = false;
        this.init();
    }

    async init() {
        // 检查WebP支持
        if (this.options.enableWebP) {
            this.webpSupported = await this.checkWebPSupport();
        }

        // 创建Intersection Observer
        this.createObserver();

        // 开始观察元素
        this.observe();
    }

    checkWebPSupport() {
        return new Promise(resolve => {
            const webP = new Image();
            webP.onload = webP.onerror = () => resolve(webP.height === 2);
            webP.src = 'data:image/webp;base64,UklGRjoAAABXRUJQVlA4IC4AAACyAgCdASoCAAIALmk0mk0iIiIiIgBoSygABc6WWgAA/veff/0PP8bA//LwYAAA';
        });
    }

    createObserver() {
        this.observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    this.loadElement(entry.target);
                    this.observer.unobserve(entry.target);
                }
            });
        }, {
            rootMargin: this.options.rootMargin,
            threshold: this.options.threshold
        });
    }

    observe() {
        const elements = document.querySelectorAll(this.options.selector);
        elements.forEach(element => {
            // 设置占位符
            this.setPlaceholder(element);
            this.observer.observe(element);
        });
    }

    setPlaceholder(element) {
        if (element.tagName === 'IMG' && !element.src) {
            element.src = this.options.placeholder;
            element.classList.add('lazy-loading');
        }
    }

    async loadElement(element) {
        const src = element.dataset.src;
        if (!src) return;

        try {
            if (element.tagName === 'IMG') {
                await this.loadImage(element, src);
            } else if (element.tagName === 'VIDEO') {
                this.loadVideo(element, src);
            } else {
                // 背景图片
                this.loadBackgroundImage(element, src);
            }
        } catch (error) {
            console.error('Lazy load error:', error);
            this.handleLoadError(element);
        }
    }

    loadImage(img, src) {
        return new Promise((resolve, reject) => {
            const image = new Image();
            
            image.onload = () => {
                // 获取优化后的图片URL
                const optimizedSrc = this.getOptimizedImageUrl(src);
                
                img.src = optimizedSrc;
                img.classList.remove('lazy-loading');
                img.classList.add('lazy-loaded');
                
                // 淡入效果
                this.fadeIn(img);
                resolve();
            };

            image.onerror = () => {
                reject(new Error(`Failed to load image: ${src}`));
            };

            image.src = src;
        });
    }

    loadVideo(video, src) {
        video.src = src;
        video.classList.remove('lazy-loading');
        video.classList.add('lazy-loaded');
        
        // 预加载视频元数据
        video.preload = 'metadata';
        
        this.fadeIn(video);
    }

    loadBackgroundImage(element, src) {
        const optimizedSrc = this.getOptimizedImageUrl(src);
        element.style.backgroundImage = `url(${optimizedSrc})`;
        element.classList.remove('lazy-loading');
        element.classList.add('lazy-loaded');
        
        this.fadeIn(element);
    }

    getOptimizedImageUrl(src) {
        // 如果支持WebP，尝试获取WebP版本
        if (this.webpSupported && this.options.enableWebP) {
            // 检查是否已经是WebP格式
            if (!src.includes('.webp')) {
                // 尝试替换扩展名为WebP
                const webpSrc = src.replace(/\.(jpg|jpeg|png)$/i, '.webp');
                // 这里可以添加检查WebP文件是否存在的逻辑
                // 或者集成CDN的图片处理服务
                return webpSrc;
            }
        }
        
        return src;
    }

    fadeIn(element) {
        element.style.opacity = '0';
        element.style.transition = 'opacity 0.3s ease';
        
        requestAnimationFrame(() => {
            element.style.opacity = '1';
        });
    }

    handleLoadError(element) {
        element.classList.remove('lazy-loading');
        element.classList.add('lazy-error');
        
        if (element.tagName === 'IMG') {
            // 设置错误占位图
            element.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgdmlld0JveD0iMCAwIDIwMCAyMDAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHJlY3Qgd2lkdGg9IjIwMCIgaGVpZ2h0PSIyMDAiIGZpbGw9IiNGNUY1RjUiLz48cGF0aCBkPSJNMTAwIDUwQzEyNy42MTQgNTAgMTUwIDcyLjM4NTggMTUwIDEwMEMxNTAgMTI3LjYxNCAxMjcuNjE0IDE1MCAxMDAgMTUwQzcyLjM4NTggMTUwIDUwIDEyNy42MTQgNTAgMTAwQzUwIDcyLjM4NTggNzIuMzg1OCA1MCAxMDAgNTBaIiBzdHJva2U9IiNEREREREQiIHN0cm9rZS13aWR0aD0iMiIvPjxwYXRoIGQ9Ik04NSA4NUwxMTUgMTE1TTExNSA4NUw4NSAxMTUiIHN0cm9rZT0iI0RERERERCIgc3Ryb2tlLXdpZHRoPSIyIiBzdHJva2UtbGluZWNhcD0icm91bmQiLz48L3N2Zz4=';
        }
    }

    // 更新观察器，用于动态添加的元素
    update() {
        const newElements = document.querySelectorAll(`${this.options.selector}:not(.lazy-loaded):not(.lazy-loading):not(.lazy-error)`);
        newElements.forEach(element => {
            this.setPlaceholder(element);
            this.observer.observe(element);
        });
    }

    // 销毁观察器
    destroy() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
    }

    // 立即加载所有元素（用于调试）
    loadAll() {
        const elements = document.querySelectorAll(this.options.selector);
        elements.forEach(element => {
            this.loadElement(element);
        });
    }
}

// 自动初始化懒加载
document.addEventListener('DOMContentLoaded', () => {
    window.icefoxLazyLoad = new IcefoxLazyLoad({
        selector: '[data-src], .lazy-load',
        rootMargin: '100px 0px',
        threshold: 0.01
    });
});

// 导出类
window.IcefoxLazyLoad = IcefoxLazyLoad;