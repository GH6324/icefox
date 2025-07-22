<?php
/**
 * Icefox 图片优化器
 * 提供响应式图片、格式转换和智能压缩功能
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class IcefoxImageOptimizer {
    private static $supportedFormats = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
    private static $qualitySettings = [
        'high' => 90,
        'medium' => 75,
        'low' => 60
    ];
    
    /**
     * 生成响应式图片 HTML
     */
    public static function generateResponsiveImage($src, $alt = '', $options = []) {
        $options = array_merge([
            'sizes' => '(max-width: 768px) 100vw, 50vw',
            'widths' => [320, 640, 768, 1024, 1200, 1600],
            'formats' => ['avif', 'webp', 'jpg'],
            'quality' => 'medium',
            'lazy' => true,
            'placeholder' => true
        ], $options);
        
        $webpSupported = self::isWebPSupported();
        $avifSupported = self::isAVIFSupported();
        
        $sources = [];
        
        foreach ($options['formats'] as $format) {
            if ($format === 'avif' && !$avifSupported) continue;
            if ($format === 'webp' && !$webpSupported) continue;
            
            $srcset = [];
            foreach ($options['widths'] as $width) {
                $optimizedUrl = self::getOptimizedUrl($src, [
                    'width' => $width,
                    'format' => $format,
                    'quality' => $options['quality']
                ]);
                $srcset[] = "{$optimizedUrl} {$width}w";
            }
            
            if (!empty($srcset)) {
                $sources[] = [
                    'type' => "image/{$format}",
                    'srcset' => implode(', ', $srcset)
                ];
            }
        }
        
        return self::buildPictureElement($sources, $src, $alt, $options);
    }
    
    /**
     * 构建 picture 元素
     */
    private static function buildPictureElement($sources, $fallback, $alt, $options) {
        $html = '<picture>';
        
        foreach ($sources as $source) {
            $html .= sprintf(
                '<source type="%s" %ssrcset="%s"%s>',
                $source['type'],
                $options['lazy'] ? 'data-' : '',
                $source['srcset'],
                $options['sizes'] ? " sizes=\"{$options['sizes']}\"" : ''
            );
        }
        
        // 生成 img 标签
        $imgAttributes = [
            $options['lazy'] ? 'data-src' : 'src' => IcefoxSecurity::safeOutput($fallback),
            'alt' => IcefoxSecurity::safeOutput($alt)
        ];
        
        if ($options['sizes']) {
            $imgAttributes['sizes'] = $options['sizes'];
        }
        
        if ($options['lazy']) {
            $imgAttributes['loading'] = 'lazy';
            $imgAttributes['class'] = 'lazy-load';
            
            if ($options['placeholder']) {
                $imgAttributes['src'] = self::generatePlaceholder($options['widths'][0] ?? 400, 300);
            }
        }
        
        $imgHtml = '<img';
        foreach ($imgAttributes as $attr => $value) {
            $imgHtml .= " {$attr}=\"{$value}\"";
        }
        $imgHtml .= '>';
        
        $html .= $imgHtml;
        $html .= '</picture>';
        
        return $html;
    }
    
    /**
     * 获取优化后的图片 URL
     */
    public static function getOptimizedUrl($originalUrl, $options = []) {
        $options = array_merge([
            'width' => null,
            'height' => null,
            'format' => null,
            'quality' => 'medium',
            'crop' => false
        ], $options);
        
        // 如果是外部 URL，直接返回
        if (self::isExternalUrl($originalUrl)) {
            return $originalUrl;
        }
        
        // 构建优化参数
        $params = [];
        
        if ($options['width']) {
            $params['w'] = $options['width'];
        }
        
        if ($options['height']) {
            $params['h'] = $options['height'];
        }
        
        if ($options['format']) {
            $params['f'] = $options['format'];
        }
        
        if ($options['quality']) {
            $quality = is_numeric($options['quality']) ? 
                $options['quality'] : 
                self::$qualitySettings[$options['quality']] ?? 75;
            $params['q'] = $quality;
        }
        
        if ($options['crop']) {
            $params['c'] = '1';
        }
        
        // 如果没有参数，返回原始 URL
        if (empty($params)) {
            return $originalUrl;
        }
        
        // 构建优化 URL
        $baseUrl = Helper::options()->siteUrl . 'api/image';
        $params['src'] = $originalUrl;
        
        return $baseUrl . '?' . http_build_query($params);
    }
    
    /**
     * 处理图片优化请求
     */
    public static function handleImageRequest($request) {
        $src = $request->get('src');
        $width = (int) $request->get('w');
        $height = (int) $request->get('h');
        $format = $request->get('f');
        $quality = (int) $request->get('q', 75);
        $crop = $request->get('c') === '1';
        
        if (!$src) {
            http_response_code(400);
            exit('Missing src parameter');
        }
        
        try {
            $optimizedImage = self::processImage($src, [
                'width' => $width,
                'height' => $height,
                'format' => $format,
                'quality' => $quality,
                'crop' => $crop
            ]);
            
            // 设置缓存头
            $etag = md5($src . serialize($request->getParams()));
            header("ETag: \"{$etag}\"");
            header('Cache-Control: public, max-age=31536000'); // 1年
            
            // 检查 If-None-Match
            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && 
                trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
                http_response_code(304);
                exit;
            }
            
            // 输出图片
            header('Content-Type: ' . $optimizedImage['mime']);
            header('Content-Length: ' . strlen($optimizedImage['data']));
            echo $optimizedImage['data'];
            
        } catch (Exception $e) {
            http_response_code(500);
            exit('Image processing failed: ' . $e->getMessage());
        }
    }
    
    /**
     * 处理图片
     */
    private static function processImage($src, $options) {
        // 获取原始图片路径
        $imagePath = self::getImagePath($src);
        
        if (!file_exists($imagePath)) {
            throw new Exception('Image not found');
        }
        
        // 生成缓存键
        $cacheKey = 'img_' . md5($imagePath . serialize($options));
        
        // 尝试从缓存获取
        $cached = IcefoxCache::get($cacheKey);
        if ($cached) {
            return $cached;
        }
        
        // 处理图片
        $result = self::doProcessImage($imagePath, $options);
        
        // 存储到缓存
        IcefoxCache::set($cacheKey, $result, 86400 * 7); // 缓存7天
        
        return $result;
    }
    
    /**
     * 实际处理图片
     */
    private static function doProcessImage($imagePath, $options) {
        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            throw new Exception('Invalid image');
        }
        
        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];
        $originalType = $imageInfo[2];
        
        // 计算新尺寸
        $newDimensions = self::calculateDimensions(
            $originalWidth, 
            $originalHeight, 
            $options['width'], 
            $options['height'], 
            $options['crop']
        );
        
        // 创建原始图片资源
        $sourceImage = self::createImageFromFile($imagePath, $originalType);
        if (!$sourceImage) {
            throw new Exception('Failed to create image resource');
        }
        
        // 创建目标图片资源
        $targetImage = imagecreatetruecolor($newDimensions['width'], $newDimensions['height']);
        
        // 保持透明度
        self::preserveTransparency($targetImage, $sourceImage, $originalType);
        
        // 调整图片大小
        imagecopyresampled(
            $targetImage, $sourceImage,
            0, 0, 
            $newDimensions['src_x'], $newDimensions['src_y'],
            $newDimensions['width'], $newDimensions['height'],
            $newDimensions['src_width'], $newDimensions['src_height']
        );
        
        // 输出格式
        $outputFormat = $options['format'] ?: self::getOriginalFormat($originalType);
        $outputData = self::outputImage($targetImage, $outputFormat, $options['quality']);
        
        // 清理资源
        imagedestroy($sourceImage);
        imagedestroy($targetImage);
        
        return [
            'data' => $outputData,
            'mime' => self::getMimeType($outputFormat),
            'width' => $newDimensions['width'],
            'height' => $newDimensions['height']
        ];
    }
    
    /**
     * 计算新尺寸
     */
    private static function calculateDimensions($origWidth, $origHeight, $newWidth, $newHeight, $crop) {
        $dimensions = [
            'src_x' => 0,
            'src_y' => 0,
            'src_width' => $origWidth,
            'src_height' => $origHeight
        ];
        
        if (!$newWidth && !$newHeight) {
            $dimensions['width'] = $origWidth;
            $dimensions['height'] = $origHeight;
            return $dimensions;
        }
        
        if (!$newWidth) {
            $newWidth = ($newHeight / $origHeight) * $origWidth;
        }
        
        if (!$newHeight) {
            $newHeight = ($newWidth / $origWidth) * $origHeight;
        }
        
        if ($crop) {
            // 裁剪模式：填满目标尺寸
            $scale = max($newWidth / $origWidth, $newHeight / $origHeight);
            $scaledWidth = $origWidth * $scale;
            $scaledHeight = $origHeight * $scale;
            
            $dimensions['src_x'] = ($scaledWidth - $newWidth) / 2 / $scale;
            $dimensions['src_y'] = ($scaledHeight - $newHeight) / 2 / $scale;
            $dimensions['src_width'] = $newWidth / $scale;
            $dimensions['src_height'] = $newHeight / $scale;
        } else {
            // 缩放模式：保持比例
            $scale = min($newWidth / $origWidth, $newHeight / $origHeight);
            $newWidth = $origWidth * $scale;
            $newHeight = $origHeight * $scale;
        }
        
        $dimensions['width'] = round($newWidth);
        $dimensions['height'] = round($newHeight);
        
        return $dimensions;
    }
    
    /**
     * 从文件创建图片资源
     */
    private static function createImageFromFile($path, $type) {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($path);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($path);
            case IMAGETYPE_WEBP:
                return function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false;
            default:
                return false;
        }
    }
    
    /**
     * 保持透明度
     */
    private static function preserveTransparency($targetImage, $sourceImage, $originalType) {
        if ($originalType === IMAGETYPE_PNG || $originalType === IMAGETYPE_GIF) {
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);
            
            $transparent = imagecolorallocatealpha($targetImage, 255, 255, 255, 127);
            imagefill($targetImage, 0, 0, $transparent);
        }
    }
    
    /**
     * 输出图片
     */
    private static function outputImage($image, $format, $quality) {
        ob_start();
        
        switch ($format) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($image, null, $quality);
                break;
            case 'png':
                $pngQuality = round((100 - $quality) / 10);
                imagepng($image, null, $pngQuality);
                break;
            case 'gif':
                imagegif($image);
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    imagewebp($image, null, $quality);
                } else {
                    imagejpeg($image, null, $quality);
                }
                break;
            case 'avif':
                if (function_exists('imageavif')) {
                    imageavif($image, null, $quality);
                } else {
                    imagewebp($image, null, $quality);
                }
                break;
            default:
                imagejpeg($image, null, $quality);
        }
        
        return ob_get_clean();
    }
    
    /**
     * 生成占位符图片
     */
    public static function generatePlaceholder($width, $height, $color = '#f0f0f0') {
        $svg = sprintf(
            '<svg width="%d" height="%d" viewBox="0 0 %d %d" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="%d" height="%d" fill="%s"/>
                <text x="50%%" y="50%%" text-anchor="middle" dy=".3em" fill="#999" font-family="Arial" font-size="14">
                    %dx%d
                </text>
            </svg>',
            $width, $height, $width, $height, $width, $height, $color, $width, $height
        );
        
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
    
    /**
     * 检查 WebP 支持
     */
    private static function isWebPSupported() {
        return strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'image/webp') !== false;
    }
    
    /**
     * 检查 AVIF 支持
     */
    private static function isAVIFSupported() {
        return strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'image/avif') !== false;
    }
    
    /**
     * 检查是否是外部 URL
     */
    private static function isExternalUrl($url) {
        return strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0;
    }
    
    /**
     * 获取图片文件路径
     */
    private static function getImagePath($url) {
        if (self::isExternalUrl($url)) {
            throw new Exception('External URLs not supported');
        }
        
        $siteUrl = Helper::options()->siteUrl;
        $relativePath = str_replace($siteUrl, '', $url);
        
        return __TYPECHO_ROOT_DIR__ . '/' . ltrim($relativePath, '/');
    }
    
    /**
     * 获取原始格式
     */
    private static function getOriginalFormat($imageType) {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                return 'jpg';
            case IMAGETYPE_PNG:
                return 'png';
            case IMAGETYPE_GIF:
                return 'gif';
            case IMAGETYPE_WEBP:
                return 'webp';
            default:
                return 'jpg';
        }
    }
    
    /**
     * 获取 MIME 类型
     */
    private static function getMimeType($format) {
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif'
        ];
        
        return $mimeTypes[$format] ?? 'image/jpeg';
    }
}

/**
 * 图片优化助手函数
 */
function responsiveImage($src, $alt = '', $options = []) {
    return IcefoxImageOptimizer::generateResponsiveImage($src, $alt, $options);
}

function optimizedImageUrl($src, $options = []) {
    return IcefoxImageOptimizer::getOptimizedUrl($src, $options);
}