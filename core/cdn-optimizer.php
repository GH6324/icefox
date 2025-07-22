<?php
/**
 * Icefox CDN 优化器
 * 智能 CDN 资源管理和优化
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class IcefoxCDNOptimizer {
    private static $instance = null;
    private $cdnConfig = [];
    private $fallbackEnabled = true;
    
    private function __construct() {
        $this->cdnConfig = [
            'enabled' => Helper::options()->cdnEnabled ?? false,
            'domain' => Helper::options()->cdnDomain ?? '',
            'regions' => Helper::options()->cdnRegions ?? ['default'],
            'types' => [
                'css' => Helper::options()->cdnCss ?? true,
                'js' => Helper::options()->cdnJs ?? true,
                'images' => Helper::options()->cdnImages ?? true,
                'fonts' => Helper::options()->cdnFonts ?? true
            ]
        ];
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 获取优化后的资源 URL
     */
    public function getOptimizedUrl($url, $type = 'auto') {
        if (!$this->cdnConfig['enabled']) {
            return $url;
        }
        
        // 自动检测资源类型
        if ($type === 'auto') {
            $type = $this->detectResourceType($url);
        }
        
        // 检查是否启用该类型的 CDN
        if (!($this->cdnConfig['types'][$type] ?? false)) {
            return $url;
        }
        
        // 获取最佳 CDN 节点
        $cdnDomain = $this->getBestCDNNode();
        
        // 构建 CDN URL
        $cdnUrl = $this->buildCDNUrl($url, $cdnDomain, $type);
        
        // 添加版本参数和优化参数
        return $this->addOptimizationParams($cdnUrl, $type);
    }
    
    /**
     * 检测资源类型
     */
    private function detectResourceType($url) {
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        
        $typeMap = [
            'css' => 'css',
            'js' => 'js',
            'png' => 'images',
            'jpg' => 'images',
            'jpeg' => 'images',
            'gif' => 'images',
            'webp' => 'images',
            'avif' => 'images',
            'svg' => 'images',
            'woff' => 'fonts',
            'woff2' => 'fonts',
            'ttf' => 'fonts',
            'eot' => 'fonts'
        ];
        
        return $typeMap[$extension] ?? 'other';
    }
    
    /**
     * 获取最佳 CDN 节点
     */
    private function getBestCDNNode() {
        // 简单的地理位置检测
        $userRegion = $this->detectUserRegion();
        
        // 根据用户地区选择最佳节点
        $regionMap = [
            'CN' => $this->cdnConfig['domain'] . '-cn',
            'US' => $this->cdnConfig['domain'] . '-us',
            'EU' => $this->cdnConfig['domain'] . '-eu',
            'default' => $this->cdnConfig['domain']
        ];
        
        return $regionMap[$userRegion] ?? $regionMap['default'];
    }
    
    /**
     * 检测用户地区
     */
    private function detectUserRegion() {
        // 通过 IP 或 Accept-Language 检测
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        
        if (strpos($acceptLanguage, 'zh') !== false) {
            return 'CN';
        } elseif (strpos($acceptLanguage, 'en-US') !== false) {
            return 'US';
        } elseif (strpos($acceptLanguage, 'en-GB') !== false || 
                  strpos($acceptLanguage, 'de') !== false || 
                  strpos($acceptLanguage, 'fr') !== false) {
            return 'EU';
        }
        
        return 'default';
    }
    
    /**
     * 构建 CDN URL
     */
    private function buildCDNUrl($originalUrl, $cdnDomain, $type) {
        // 移除域名，保留路径
        $path = parse_url($originalUrl, PHP_URL_PATH);
        $query = parse_url($originalUrl, PHP_URL_QUERY);
        
        $cdnUrl = 'https://' . $cdnDomain . $path;
        
        if ($query) {
            $cdnUrl .= '?' . $query;
        }
        
        return $cdnUrl;
    }
    
    /**
     * 添加优化参数
     */
    private function addOptimizationParams($url, $type) {
        $params = [];
        
        // 添加版本号
        $params['v'] = __THEME_VERSION__;
        
        // 图片优化参数
        if ($type === 'images') {
            $params['format'] = 'auto'; // 自动选择最佳格式
            $params['quality'] = '85';   // 压缩质量
        }
        
        // CSS/JS 压缩参数
        if (in_array($type, ['css', 'js'])) {
            $params['minify'] = '1';
        }
        
        // 构建最终 URL
        $separator = strpos($url, '?') !== false ? '&' : '?';
        return $url . $separator . http_build_query($params);
    }
    
    /**
     * 预加载关键资源
     */
    public function preloadCriticalResources() {
        $criticalResources = [
            '/assets/css/uno.css' => 'style',
            '/assets/js/icefox-optimized.js' => 'script',
            '/assets/fonts/main.woff2' => 'font'
        ];
        
        foreach ($criticalResources as $url => $type) {
            $optimizedUrl = $this->getOptimizedUrl($url);
            echo "<link rel=\"preload\" href=\"{$optimizedUrl}\" as=\"{$type}\"";
            
            if ($type === 'font') {
                echo " crossorigin";
            }
            
            echo ">\n";
        }
    }
    
    /**
     * 生成资源提示
     */
    public function generateResourceHints() {
        if (!$this->cdnConfig['enabled']) return;
        
        $cdnDomain = $this->getBestCDNNode();
        
        // DNS 预解析
        echo "<link rel=\"dns-prefetch\" href=\"//{$cdnDomain}\">\n";
        
        // 预连接
        echo "<link rel=\"preconnect\" href=\"https://{$cdnDomain}\" crossorigin>\n";
    }
    
    /**
     * 检查 CDN 可用性
     */
    public function checkCDNAvailability($url) {
        $cacheKey = 'cdn_check_' . md5($url);
        
        return IcefoxCache::get($cacheKey, function() use ($url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
        }, 300); // 5分钟缓存
    }
    
    /**
     * 获取 CDN 统计信息
     */
    public function getStats() {
        return [
            'enabled' => $this->cdnConfig['enabled'],
            'domain' => $this->cdnConfig['domain'],
            'types_enabled' => array_filter($this->cdnConfig['types']),
            'fallback_enabled' => $this->fallbackEnabled
        ];
    }
}

/**
 * 资源压缩器
 */
class IcefoxResourceCompressor {
    
    /**
     * 压缩 CSS
     */
    public static function compressCSS($css) {
        // 移除注释
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // 移除多余的空白
        $css = preg_replace('/\s+/', ' ', $css);
        
        // 移除不必要的分号和空格
        $css = str_replace(['; ', ' {', '{ ', ' }', '} ', ': ', ', '], [';', '{', '{', '}', '}', ':', ','], $css);
        
        return trim($css);
    }
    
    /**
     * 压缩 JavaScript
     */
    public static function compressJS($js) {
        // 简单的 JS 压缩（生产环境建议使用专业工具）
        
        // 移除单行注释
        $js = preg_replace('/\/\/.*$/m', '', $js);
        
        // 移除多行注释
        $js = preg_replace('/\/\*[\s\S]*?\*\//', '', $js);
        
        // 移除多余的空白
        $js = preg_replace('/\s+/', ' ', $js);
        
        // 移除不必要的空格
        $js = str_replace([' = ', ' + ', ' - ', ' * ', ' / ', ' == ', ' != ', ' && ', ' || '], ['=', '+', '-', '*', '/', '==', '!=', '&&', '||'], $js);
        
        return trim($js);
    }
    
    /**
     * 合并多个文件
     */
    public static function combineFiles($files, $type = 'css') {
        $combined = '';
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                
                if ($type === 'css') {
                    $content = self::compressCSS($content);
                } elseif ($type === 'js') {
                    $content = self::compressJS($content);
                }
                
                $combined .= $content . "\n";
            }
        }
        
        return $combined;
    }
}