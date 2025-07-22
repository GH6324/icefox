<?php
/**
 * Icefox 优化核心文件
 * 提供缓存、安全、性能优化功能
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 缓存管理类
 */
class IcefoxCache {
    private static $cache = [];
    private static $cacheDir = null;
    
    public static function init() {
        self::$cacheDir = __TYPECHO_ROOT_DIR__ . '/usr/cache/icefox/';
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    /**
     * 多级缓存获取
     */
    public static function get($key, $callback = null, $ttl = 3600) {
        // 1. 内存缓存
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        
        // 2. Redis 缓存（如果可用）
        if (self::isRedisAvailable()) {
            $value = self::getFromRedis($key);
            if ($value !== false) {
                self::$cache[$key] = $value;
                return $value;
            }
        }
        
        // 3. 文件缓存
        $cacheFile = self::$cacheDir . md5($key) . '.cache';
        if (file_exists($cacheFile)) {
            $data = unserialize(file_get_contents($cacheFile));
            if ($data && $data['expire'] > time()) {
                self::$cache[$key] = $data['value'];
                
                // 异步回写到 Redis
                if (self::isRedisAvailable()) {
                    self::setToRedis($key, $data['value'], $data['expire'] - time());
                }
                
                return $data['value'];
            } else {
                @unlink($cacheFile);
            }
        }
        
        // 4. 回调生成
        if ($callback) {
            $value = $callback();
            self::set($key, $value, $ttl);
            return $value;
        }
        
        return null;
    }
    
    /**
     * 设置缓存
     */
    public static function set($key, $value, $ttl = 3600) {
        // 内存缓存
        self::$cache[$key] = $value;
        
        // Redis 缓存
        if (self::isRedisAvailable()) {
            self::setToRedis($key, $value, $ttl);
        }
        
        // 文件缓存
        $cacheFile = self::$cacheDir . md5($key) . '.cache';
        $data = [
            'value' => $value,
            'expire' => time() + $ttl,
            'created' => time()
        ];
        
        @file_put_contents($cacheFile, serialize($data), LOCK_EX);
    }
    
    /**
     * 删除缓存
     */
    public static function delete($key) {
        // 内存缓存
        unset(self::$cache[$key]);
        
        // Redis 缓存
        if (self::isRedisAvailable()) {
            self::deleteFromRedis($key);
        }
        
        // 文件缓存
        $cacheFile = self::$cacheDir . md5($key) . '.cache';
        @unlink($cacheFile);
    }
    
    /**
     * 清空缓存
     */
    public static function clear($pattern = null) {
        // 清空内存缓存
        if ($pattern) {
            foreach (self::$cache as $key => $value) {
                if (fnmatch($pattern, $key)) {
                    unset(self::$cache[$key]);
                }
            }
        } else {
            self::$cache = [];
        }
        
        // 清空 Redis 缓存
        if (self::isRedisAvailable()) {
            self::clearRedis($pattern);
        }
        
        // 清空文件缓存
        self::clearFileCache($pattern);
    }
    
    /**
     * 检查 Redis 是否可用
     */
    private static function isRedisAvailable() {
        static $available = null;
        
        if ($available === null) {
            $available = class_exists('Redis') && 
                        extension_loaded('redis') && 
                        defined('ICEFOX_REDIS_ENABLED') && 
                        ICEFOX_REDIS_ENABLED;
        }
        
        return $available;
    }
    
    /**
     * 从 Redis 获取数据
     */
    private static function getFromRedis($key) {
        try {
            $redis = self::getRedisConnection();
            $value = $redis->get('icefox:' . $key);
            return $value !== false ? unserialize($value) : false;
        } catch (Exception $e) {
            error_log("Redis get failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 设置 Redis 数据
     */
    private static function setToRedis($key, $value, $ttl) {
        try {
            $redis = self::getRedisConnection();
            $redis->setex('icefox:' . $key, $ttl, serialize($value));
        } catch (Exception $e) {
            error_log("Redis set failed: " . $e->getMessage());
        }
    }
    
    /**
     * 从 Redis 删除数据
     */
    private static function deleteFromRedis($key) {
        try {
            $redis = self::getRedisConnection();
            $redis->del('icefox:' . $key);
        } catch (Exception $e) {
            error_log("Redis delete failed: " . $e->getMessage());
        }
    }
    
    /**
     * 清空 Redis 缓存
     */
    private static function clearRedis($pattern = null) {
        try {
            $redis = self::getRedisConnection();
            if ($pattern) {
                $keys = $redis->keys('icefox:' . $pattern);
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            } else {
                $keys = $redis->keys('icefox:*');
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            }
        } catch (Exception $e) {
            error_log("Redis clear failed: " . $e->getMessage());
        }
    }
    
    /**
     * 获取 Redis 连接
     */
    private static function getRedisConnection() {
        static $redis = null;
        
        if ($redis === null) {
            $redis = new Redis();
            $redis->connect(
                defined('ICEFOX_REDIS_HOST') ? ICEFOX_REDIS_HOST : '127.0.0.1',
                defined('ICEFOX_REDIS_PORT') ? ICEFOX_REDIS_PORT : 6379
            );
            
            if (defined('ICEFOX_REDIS_PASSWORD') && ICEFOX_REDIS_PASSWORD) {
                $redis->auth(ICEFOX_REDIS_PASSWORD);
            }
            
            if (defined('ICEFOX_REDIS_DATABASE')) {
                $redis->select(ICEFOX_REDIS_DATABASE);
            }
        }
        
        return $redis;
    }
    
    /**
     * 清空文件缓存
     */
    private static function clearFileCache($pattern = null) {
        $files = glob(self::$cacheDir . '*.cache');
        
        foreach ($files as $file) {
            if ($pattern) {
                $key = basename($file, '.cache');
                if (!fnmatch(md5($pattern), $key)) {
                    continue;
                }
            }
            @unlink($file);
        }
    }
    
    public static function set($key, $value, $ttl = 3600) {
        self::$cache[$key] = $value;
        
        // 写入文件缓存
        $cacheFile = self::$cacheDir . md5($key) . '.cache';
        $data = [
            'value' => $value,
            'expire' => time() + $ttl
        ];
        @file_put_contents($cacheFile, serialize($data));
    }
    
    public static function delete($key) {
        unset(self::$cache[$key]);
        $cacheFile = self::$cacheDir . md5($key) . '.cache';
        @unlink($cacheFile);
    }
    
    public static function clear() {
        self::$cache = [];
        $files = glob(self::$cacheDir . '*.cache');
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}

/**
 * 安全工具类
 */
class IcefoxSecurity {
    /**
     * 生成 CSRF Token
     */
    public static function generateCSRFToken() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['icefox_csrf_token'])) {
            $_SESSION['icefox_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['icefox_csrf_token'];
    }
    
    /**
     * 验证 CSRF Token
     */
    public static function validateCSRFToken($token) {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['icefox_csrf_token']) && 
               hash_equals($_SESSION['icefox_csrf_token'], $token);
    }
    
    /**
     * 安全输出
     */
    public static function safeOutput($content) {
        return htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * 过滤评论内容
     */
    public static function filterCommentContent($content) {
        // 允许的标签
        $allowedTags = '<p><br><strong><em><a><code>';
        $content = strip_tags($content, $allowedTags);
        
        // 过滤危险属性
        $content = preg_replace('/on\w+="[^"]*"/i', '', $content);
        $content = preg_replace('/javascript:/i', '', $content);
        
        return $content;
    }
    
    /**
     * 验证邮箱格式
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * 验证URL格式
     */
    public static function validateUrl($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

/**
 * 性能优化工具类
 */
class IcefoxPerformance {
    /**
     * 压缩HTML输出
     */
    public static function compressHTML($html) {
        // 移除多余的空白字符
        $html = preg_replace('/\s+/', ' ', $html);
        // 移除注释
        $html = preg_replace('/<!--(?!<!)[^\[>].*?-->/', '', $html);
        // 移除标签间的空白
        $html = preg_replace('/>\s+</', '><', $html);
        
        return trim($html);
    }
    
    /**
     * 生成资源版本号
     */
    public static function getAssetVersion($file) {
        $filePath = Helper::options()->themeDir . $file;
        if (file_exists($filePath)) {
            return filemtime($filePath);
        }
        return __THEME_VERSION__;
    }
    
    /**
     * 检查WebP支持
     */
    public static function supportsWebP() {
        return strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
    }
    
    /**
     * 获取优化后的图片URL
     */
    public static function getOptimizedImageUrl($url, $width = null, $height = null, $format = null) {
        // 检查是否为外部URL
        if (strpos($url, 'http') === 0 && strpos($url, $_SERVER['HTTP_HOST']) === false) {
            return $url; // 外部图片不处理
        }
        
        $originalUrl = $url;
        $params = [];
        
        // 添加尺寸参数
        if ($width) $params['w'] = $width;
        if ($height) $params['h'] = $height;
        
        // 格式优化
        if (!$format) {
            if (self::supportsWebP()) {
                $format = 'webp';
            } elseif (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'image/avif') !== false) {
                $format = 'avif';
            }
        }
        
        if ($format && $format !== 'original') {
            $params['f'] = $format;
        }
        
        // 质量优化（根据网络状况）
        $quality = self::getOptimalQuality();
        if ($quality < 90) {
            $params['q'] = $quality;
        }
        
        // 如果有参数，构建优化URL
        if (!empty($params)) {
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $url .= $separator . http_build_query($params);
        }
        
        return $url;
    }
    
    /**
     * 生成响应式图片HTML
     */
    public static function generateResponsiveImage($src, $alt = '', $sizes = null, $lazy = true) {
        $webpSupported = self::supportsWebP();
        $avifSupported = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'image/avif') !== false;
        
        $sources = [];
        $widths = [320, 640, 768, 1024, 1200, 1600];
        
        // 生成不同格式的源
        foreach (['avif', 'webp', 'jpg'] as $format) {
            if ($format === 'avif' && !$avifSupported) continue;
            if ($format === 'webp' && !$webpSupported) continue;
            
            $srcset = [];
            foreach ($widths as $width) {
                $optimizedUrl = self::getOptimizedImageUrl($src, $width, null, $format);
                $srcset[] = "{$optimizedUrl} {$width}w";
            }
            
            $sources[] = [
                'type' => "image/{$format}",
                'srcset' => implode(', ', $srcset)
            ];
        }
        
        return self::buildPictureElement($sources, $src, $alt, $sizes, $lazy);
    }
    
    /**
     * 构建picture元素
     */
    private static function buildPictureElement($sources, $fallback, $alt, $sizes, $lazy) {
        $html = '<picture>';
        
        foreach ($sources as $source) {
            $sizeAttr = $sizes ? " sizes=\"{$sizes}\"" : '';
            $html .= sprintf(
                '<source type="%s" srcset="%s"%s>',
                $source['type'],
                $source['srcset'],
                $sizeAttr
            );
        }
        
        $lazyAttr = $lazy ? ' loading="lazy"' : '';
        $sizeAttr = $sizes ? " sizes=\"{$sizes}\"" : '';
        
        $html .= sprintf(
            '<img src="%s" alt="%s"%s%s>',
            IcefoxSecurity::safeOutput($fallback),
            IcefoxSecurity::safeOutput($alt),
            $lazyAttr,
            $sizeAttr
        );
        
        $html .= '</picture>';
        return $html;
    }
    
    /**
     * 根据网络状况获取最佳图片质量
     */
    public static function getOptimalQuality() {
        // 检查 Save-Data 头
        if (isset($_SERVER['HTTP_SAVE_DATA']) && $_SERVER['HTTP_SAVE_DATA'] === 'on') {
            return 60;
        }
        
        // 检查网络类型（如果有相关头信息）
        $networkType = $_SERVER['HTTP_DOWNLINK'] ?? null;
        if ($networkType && $networkType < 1.5) {
            return 70; // 慢网络
        }
        
        return 85; // 默认质量
    }
}

/**
 * 数据库优化工具类
 */
class IcefoxDatabase {
    /**
     * 获取用户头像（带缓存）
     */
    public static function getUserAvatar($authorId) {
        return IcefoxCache::get("avatar_$authorId", function() use ($authorId) {
            $db = Typecho_Db::get();
            $user = $db->fetchRow($db->select('mail')->from('table.users')->where('uid = ?', $authorId)->limit(1));
            
            if (!$user) {
                return Helper::options()->avatarSource ?: "https://cravatar.cn/avatar/";
            }
            
            $avatarSource = Helper::options()->avatarSource ?: "https://cravatar.cn/avatar/";
            return $avatarSource . md5(strtolower(trim($user['mail']))) . "?s=64&d=identicon";
        }, 1800); // 缓存30分钟
    }
    
    /**
     * 获取文章自定义字段（带缓存）
     */
    public static function getArticleFields($cid, $fieldName = null) {
        $cacheKey = "article_fields_{$cid}" . ($fieldName ? "_$fieldName" : "");
        
        return IcefoxCache::get($cacheKey, function() use ($cid, $fieldName) {
            $db = Typecho_Db::get();
            $query = $db->select()->from('table.fields')->where('cid = ?', $cid);
            
            if ($fieldName) {
                $query->where('name = ?', $fieldName);
            }
            
            return $db->fetchAll($query);
        }, 3600); // 缓存1小时
    }
    
    /**
     * 批量获取用户信息
     */
    public static function getUsersBatch($userIds) {
        $cacheKey = "users_batch_" . md5(implode(',', $userIds));
        
        return IcefoxCache::get($cacheKey, function() use ($userIds) {
            $db = Typecho_Db::get();
            $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
            
            return $db->fetchAll($db->select('uid', 'screenName', 'mail')
                ->from('table.users')
                ->where("uid IN ($placeholders)", ...$userIds));
        }, 1800);
    }
}

/**
 * 输出缓冲管理类
 */
class IcefoxOutputBuffer {
    private static $buffers = [];
    private static $cacheDir = null;
    
    public static function init() {
        self::$cacheDir = __TYPECHO_ROOT_DIR__ . '/usr/cache/icefox/output/';
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    public static function start($key, $ttl = 3600) {
        $cacheFile = self::$cacheDir . md5($key) . '.html';
        
        // 检查缓存是否有效
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            readfile($cacheFile);
            return false; // 不需要继续渲染
        }
        
        ob_start();
        self::$buffers[$key] = $cacheFile;
        return true;
    }
    
    public static function end($key) {
        if (!isset(self::$buffers[$key])) return;
        
        $content = ob_get_clean();
        $cacheFile = self::$buffers[$key];
        
        // 压缩HTML
        $content = IcefoxPerformance::compressHTML($content);
        
        // 写入缓存
        @file_put_contents($cacheFile, $content);
        
        echo $content;
        unset(self::$buffers[$key]);
    }
}

/**
 * 资源优化管理类
 */
class IcefoxResourceOptimizer {
    private static $inlinedCSS = [];
    private static $deferredJS = [];
    
    /**
     * 内联关键CSS
     */
    public static function inlineCriticalCSS($cssFile) {
        if (in_array($cssFile, self::$inlinedCSS)) return '';
        
        $filePath = Helper::options()->themeDir . $cssFile;
        if (file_exists($filePath)) {
            $css = file_get_contents($filePath);
            // 压缩CSS
            $css = preg_replace('/\s+/', ' ', $css);
            $css = str_replace(['; ', ' {', '{ ', ' }', '} '], [';', '{', '{', '}', '}'], $css);
            
            self::$inlinedCSS[] = $cssFile;
            return "<style>{$css}</style>";
        }
        
        return '';
    }
    
    /**
     * 延迟加载JavaScript
     */
    public static function deferJS($jsFile, $condition = null) {
        $url = Helper::options()->themeUrl($jsFile);
        $version = IcefoxPerformance::getAssetVersion($jsFile);
        
        $script = "
        <script>
        (function() {
            function loadScript() {
                var script = document.createElement('script');
                script.src = '{$url}?v={$version}';
                script.async = true;
                document.head.appendChild(script);
            }
            
            if (document.readyState === 'complete') {
                loadScript();
            } else {
                window.addEventListener('load', loadScript);
            }
        })();
        </script>";
        
        return $script;
    }
    
    /**
     * 生成预加载链接
     */
    public static function preloadResource($resource, $type = 'script') {
        $url = Helper::options()->themeUrl($resource);
        $version = IcefoxPerformance::getAssetVersion($resource);
        
        return "<link rel=\"preload\" href=\"{$url}?v={$version}\" as=\"{$type}\">";
    }
    
    /**
     * 生成响应式图片标签
     */
    public static function responsiveImage($src, $alt = '', $sizes = '(max-width: 768px) 100vw, 50vw', $lazy = true) {
        $widths = [320, 640, 768, 1024, 1200];
        $srcset = [];
        
        foreach ($widths as $width) {
            $optimizedUrl = IcefoxPerformance::getOptimizedImageUrl($src, $width);
            $srcset[] = "{$optimizedUrl} {$width}w";
        }
        
        $srcsetAttr = implode(', ', $srcset);
        $lazyAttr = $lazy ? 'loading="lazy"' : '';
        
        return sprintf(
            '<img src="%s" srcset="%s" sizes="%s" alt="%s" %s>',
            IcefoxSecurity::safeOutput($src),
            $srcsetAttr,
            $sizes,
            IcefoxSecurity::safeOutput($alt),
            $lazyAttr
        );
    }
}

/**
 * API 性能优化类
 */
class IcefoxAPIOptimizer {
    /**
     * 批量处理API请求
     */
    public static function batchProcess($requests) {
        $results = [];
        
        foreach ($requests as $key => $request) {
            $cacheKey = "api_batch_{$key}_" . md5(serialize($request));
            
            $result = IcefoxCache::get($cacheKey, function() use ($request) {
                return self::processRequest($request);
            }, 300); // 5分钟缓存
            
            $results[$key] = $result;
        }
        
        return $results;
    }
    
    private static function processRequest($request) {
        // 根据请求类型处理
        switch ($request['type']) {
            case 'user_info':
                return IcefoxDatabase::getUserAvatar($request['user_id']);
            case 'article_fields':
                return IcefoxDatabase::getArticleFields($request['cid'], $request['field']);
            default:
                return null;
        }
    }
    
    /**
     * 压缩JSON响应
     */
    public static function compressJSON($data) {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

/**
 * 初始化优化功能
 */
function initIcefoxOptimizations() {
    IcefoxCache::init();
    IcefoxOutputBuffer::init();
    
    // 设置安全头部
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    
    // 启用Gzip压缩
    if (!ob_get_level() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
        ob_start('ob_gzhandler');
    } elseif (!ob_get_level()) {
        ob_start();
    }
    
    // 注册关闭时的清理函数
    register_shutdown_function(function() {
        $output = ob_get_clean();
        if ($output) {
            // 只在非缓存页面压缩HTML
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo IcefoxPerformance::compressHTML($output);
        }
    });
}

// 自动初始化
initIcefoxOptimizations();