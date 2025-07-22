<?php
/**
 * Icefox 服务端渲染优化器
 * 提供组件缓存、流式响应和渲染优化功能
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class IcefoxSSROptimizer {
    private static $componentCache = [];
    private static $renderStats = [];
    private static $streamingEnabled = true;
    
    /**
     * 渲染组件（带缓存）
     */
    public static function renderComponent($name, $data = [], $options = []) {
        $options = array_merge([
            'cache' => true,
            'ttl' => 3600,
            'compress' => true,
            'minify' => true
        ], $options);
        
        $startTime = microtime(true);
        $cacheKey = self::generateCacheKey($name, $data, $options);
        
        // 尝试从缓存获取
        if ($options['cache']) {
            $cached = self::getFromCache($cacheKey);
            if ($cached !== null) {
                self::recordRenderStats($name, microtime(true) - $startTime, true);
                return $cached;
            }
        }
        
        // 渲染组件
        $output = self::doRenderComponent($name, $data);
        
        // 后处理
        if ($options['minify']) {
            $output = self::minifyHTML($output);
        }
        
        if ($options['compress']) {
            $output = self::compressOutput($output);
        }
        
        // 存储到缓存
        if ($options['cache']) {
            self::storeToCache($cacheKey, $output, $options['ttl']);
        }
        
        self::recordRenderStats($name, microtime(true) - $startTime, false);
        return $output;
    }
    
    /**
     * 实际渲染组件
     */
    private static function doRenderComponent($name, $data) {
        $componentPath = __DIR__ . "/../components/{$name}.php";
        
        if (!file_exists($componentPath)) {
            throw new Exception("Component not found: {$name}");
        }
        
        // 创建隔离的作用域
        $render = function() use ($componentPath, $data) {
            extract($data, EXTR_SKIP);
            ob_start();
            include $componentPath;
            return ob_get_clean();
        };
        
        return $render();
    }
    
    /**
     * 流式响应
     */
    public static function streamResponse($content, $chunkSize = 8192) {
        if (!self::$streamingEnabled) {
            echo $content;
            return;
        }
        
        // 确保输出缓冲被清理
        while (ob_get_level()) {
            ob_end_flush();
        }
        
        // 设置流式响应头
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Transfer-Encoding: chunked');
            header('X-Accel-Buffering: no'); // 禁用 Nginx 缓冲
        }
        
        // 分块发送内容
        $contentLength = strlen($content);
        $offset = 0;
        
        while ($offset < $contentLength) {
            $chunk = substr($content, $offset, $chunkSize);
            echo $chunk;
            
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            
            flush();
            $offset += $chunkSize;
            
            // 避免过快发送
            usleep(1000); // 1ms
        }
    }
    
    /**
     * 渐进式页面渲染
     */
    public static function renderProgressively($sections) {
        foreach ($sections as $section) {
            $content = self::renderComponent($section['component'], $section['data'] ?? []);
            
            // 包装为可独立渲染的块
            $wrappedContent = self::wrapProgressiveSection($content, $section['id'] ?? '');
            
            self::streamResponse($wrappedContent);
            
            // 给浏览器时间处理
            if ($section['delay'] ?? 0) {
                usleep($section['delay'] * 1000);
            }
        }
    }
    
    /**
     * 包装渐进式渲染块
     */
    private static function wrapProgressiveSection($content, $id) {
        $wrappedContent = "<div id=\"progressive-{$id}\" class=\"progressive-section\">";
        $wrappedContent .= $content;
        $wrappedContent .= "</div>";
        
        // 添加渐进式加载的 JavaScript
        $wrappedContent .= "<script>";
        $wrappedContent .= "document.getElementById('progressive-{$id}').classList.add('loaded');";
        $wrappedContent .= "</script>";
        
        return $wrappedContent;
    }
    
    /**
     * 服务端组件预渲染
     */
    public static function prerenderComponents($components) {
        $prerendered = [];
        
        foreach ($components as $name => $config) {
            try {
                $output = self::renderComponent($name, $config['data'] ?? [], [
                    'cache' => true,
                    'ttl' => $config['ttl'] ?? 3600
                ]);
                
                $prerendered[$name] = [
                    'html' => $output,
                    'timestamp' => time(),
                    'size' => strlen($output)
                ];
            } catch (Exception $e) {
                error_log("Prerender failed for component {$name}: " . $e->getMessage());
            }
        }
        
        return $prerendered;
    }
    
    /**
     * 生成缓存键
     */
    private static function generateCacheKey($name, $data, $options) {
        $keyData = [
            'component' => $name,
            'data' => $data,
            'options' => $options,
            'version' => __THEME_VERSION__
        ];
        
        return 'ssr_' . md5(serialize($keyData));
    }
    
    /**
     * 从缓存获取
     */
    private static function getFromCache($key) {
        // 优先从内存缓存获取
        if (isset(self::$componentCache[$key])) {
            $item = self::$componentCache[$key];
            if ($item['expire'] > time()) {
                return $item['content'];
            } else {
                unset(self::$componentCache[$key]);
            }
        }
        
        // 从文件缓存获取
        return IcefoxCache::get($key);
    }
    
    /**
     * 存储到缓存
     */
    private static function storeToCache($key, $content, $ttl) {
        // 存储到内存缓存
        self::$componentCache[$key] = [
            'content' => $content,
            'expire' => time() + $ttl
        ];
        
        // 存储到文件缓存
        IcefoxCache::set($key, $content, $ttl);
    }
    
    /**
     * HTML 压缩
     */
    private static function minifyHTML($html) {
        // 保护 pre 和 textarea 标签内容
        $protected = [];
        $html = preg_replace_callback('/<(pre|textarea|script|style)[^>]*>.*?<\/\1>/is', function($matches) use (&$protected) {
            $key = '___PROTECTED_' . count($protected) . '___';
            $protected[$key] = $matches[0];
            return $key;
        }, $html);
        
        // 压缩 HTML
        $html = preg_replace('/\s+/', ' ', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        $html = preg_replace('/<!--(?!<!)[^\[>].*?-->/', '', $html);
        
        // 恢复保护的内容
        foreach ($protected as $key => $content) {
            $html = str_replace($key, $content, $html);
        }
        
        return trim($html);
    }
    
    /**
     * 输出压缩
     */
    private static function compressOutput($output) {
        if (function_exists('gzencode') && !headers_sent()) {
            $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
            
            if (strpos($acceptEncoding, 'gzip') !== false) {
                header('Content-Encoding: gzip');
                return gzencode($output, 6);
            }
        }
        
        return $output;
    }
    
    /**
     * 记录渲染统计
     */
    private static function recordRenderStats($component, $renderTime, $fromCache) {
        if (!isset(self::$renderStats[$component])) {
            self::$renderStats[$component] = [
                'total_renders' => 0,
                'cache_hits' => 0,
                'total_time' => 0,
                'avg_time' => 0
            ];
        }
        
        $stats = &self::$renderStats[$component];
        $stats['total_renders']++;
        $stats['total_time'] += $renderTime;
        $stats['avg_time'] = $stats['total_time'] / $stats['total_renders'];
        
        if ($fromCache) {
            $stats['cache_hits']++;
        }
    }
    
    /**
     * 获取渲染统计
     */
    public static function getRenderStats() {
        return self::$renderStats;
    }
    
    /**
     * 清理组件缓存
     */
    public static function clearComponentCache($component = null) {
        if ($component) {
            // 清理特定组件缓存
            $pattern = "ssr_" . md5(serialize(['component' => $component]));
            foreach (self::$componentCache as $key => $value) {
                if (strpos($key, $pattern) === 0) {
                    unset(self::$componentCache[$key]);
                }
            }
        } else {
            // 清理所有组件缓存
            self::$componentCache = [];
        }
    }
    
    /**
     * 启用/禁用流式响应
     */
    public static function setStreamingEnabled($enabled) {
        self::$streamingEnabled = $enabled;
    }
    
    /**
     * 服务端渲染中间件
     */
    public static function middleware($request, $response, $next) {
        // 检查是否支持流式响应
        $userAgent = $request->getAgent();
        if (strpos($userAgent, 'bot') !== false || strpos($userAgent, 'crawler') !== false) {
            self::setStreamingEnabled(false);
        }
        
        // 设置响应头
        if (!headers_sent()) {
            header('X-Powered-By: Icefox/' . __THEME_VERSION__);
            header('X-Render-Time: ' . microtime(true));
        }
        
        return $next();
    }
}

/**
 * 渲染助手函数
 */
function renderComponent($name, $data = [], $options = []) {
    return IcefoxSSROptimizer::renderComponent($name, $data, $options);
}

function streamOutput($content, $chunkSize = 8192) {
    return IcefoxSSROptimizer::streamResponse($content, $chunkSize);
}

/**
 * 组件缓存装饰器
 */
function cachedComponent($name, $data = [], $ttl = 3600) {
    return IcefoxSSROptimizer::renderComponent($name, $data, [
        'cache' => true,
        'ttl' => $ttl,
        'minify' => true
    ]);
}