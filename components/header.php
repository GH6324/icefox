<?php

if (!defined('__TYPECHO_ROOT_DIR__'))
    exit;

?>
<!DOCTYPE HTML>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    
    <?php
    // 引入优化核心
    include_once 'core/optimized-core.php';
    
    // 生成页面标题和描述
    if ($this->is('single')) {
        $title = $this->title . ' - ' . $this->options->title;
        $description = IcefoxSecurity::safeOutput(strip_tags($this->excerpt));
    } else {
        $title = $this->options->title;
        $description = $this->options->description;
    }
    ?>
    
    <title><?php echo IcefoxSecurity::safeOutput($title); ?></title>
    <meta name="description" content="<?php echo IcefoxSecurity::safeOutput($description); ?>">
    
    <?php if ($this->is('single')): ?>
    <!-- Open Graph -->
    <meta property="og:title" content="<?php echo IcefoxSecurity::safeOutput($this->title); ?>">
    <meta property="og:description" content="<?php echo IcefoxSecurity::safeOutput($description); ?>">
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?php echo $this->permalink; ?>">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?php echo IcefoxSecurity::safeOutput($this->title); ?>">
    <meta name="twitter:description" content="<?php echo IcefoxSecurity::safeOutput($description); ?>">
    <?php endif; ?>
    
    <!-- 关键CSS内联 -->
    <style>
        /* 关键样式 - 首屏渲染必需 */
        .main-container { min-height: 100vh; background: #f0f0f0; }
        .dark .main-container { background: #262626; }
        .loading { display: flex; justify-content: center; align-items: center; height: 200px; }
        .skeleton-item { animation: skeleton-loading 1.5s infinite; }
        @keyframes skeleton-loading {
            0% { opacity: 1; }
            50% { opacity: 0.4; }
            100% { opacity: 1; }
        }
        .lazy-loading { opacity: 0.6; transition: opacity 0.3s ease; }
        .lazy-loaded { opacity: 1; }
        <?php echo $this->options->css; ?>
    </style>
    
    <!-- 预加载关键资源 -->
    <link rel="preload" href="<?php $this->options->themeUrl('assets/js/jquery.min.js'); ?>" as="script">
    <link rel="preload" href="<?php $this->options->themeUrl('uno.css'); ?>?v=<?php echo IcefoxPerformance::getAssetVersion('uno.css'); ?>" as="style">
    
    <!-- 关键CSS -->
    <link rel="stylesheet" href="<?php $this->options->themeUrl('uno.css'); ?>?v=<?php echo IcefoxPerformance::getAssetVersion('uno.css'); ?>">
    <link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/style.css'); ?>?v=<?php echo IcefoxPerformance::getAssetVersion('assets/css/style.css'); ?>">
    
    <!-- 非关键CSS异步加载 -->
    <link rel="preload" href="<?php $this->options->themeUrl('assets/css/viewer.min.css'); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="preload" href="<?php $this->options->themeUrl('assets/css/fancybox.css'); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="preload" href="<?php $this->options->themeUrl('assets/css/plyr.css'); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    
    <!-- 关键JavaScript -->
    <script src="<?php $this->options->themeUrl('assets/js/jquery.min.js'); ?>"></script>
    
    <!-- CSRF Token -->
    <meta name="csrf-token" content="<?php echo IcefoxSecurity::generateCSRFToken(); ?>">
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="<?php $this->options->themeUrl('manifest.json'); ?>">
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#667eea">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php $this->options->title(); ?>">
    
    <!-- PWA Icons -->
    <link rel="apple-touch-icon" sizes="180x180" href="<?php $this->options->themeUrl('assets/icons/apple-touch-icon.png'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php $this->options->themeUrl('assets/icons/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php $this->options->themeUrl('assets/icons/favicon-16x16.png'); ?>">
    
    <!-- 全局配置 -->
    <script>
        window.ICEFOX_CONFIG = {
            themeUrl: '<?php $this->options->themeUrl(); ?>',
            siteUrl: '<?php $this->options->siteUrl(); ?>',
            version: '<?php echo __THEME_VERSION__; ?>',
            csrfToken: '<?php echo IcefoxSecurity::generateCSRFToken(); ?>',
            isMobile: /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent),
            
            // 优化配置
            enableServiceWorker: true,
            enablePerformanceMonitoring: true,
            enablePreloader: true,
            enableLazyLoad: true,
            enableCache: true,
            enableSecurity: true,
            
            // PWA 配置
            vapidPublicKey: '<?php echo $this->options->vapidPublicKey ?: ""; ?>',
            
            // 调试模式
            debug: <?php echo defined('__TYPECHO_DEBUG__') && __TYPECHO_DEBUG__ ? 'true' : 'false'; ?>
        };
        
        // 性能监控
        window.ICEFOX_PERF = {
            startTime: performance.now(),
            marks: {},
            measures: {},
            errors: []
        };
        
        // 标记性能点
        function markPerf(name) {
            if (window.performance && window.performance.mark) {
                window.performance.mark(name);
            }
            window.ICEFOX_PERF.marks[name] = performance.now();
        }
        
        // 测量性能
        function measurePerf(name, startMark, endMark) {
            if (window.performance && window.performance.measure) {
                try {
                    window.performance.measure(name, startMark, endMark);
                    const measure = window.performance.getEntriesByName(name, 'measure')[0];
                    window.ICEFOX_PERF.measures[name] = measure.duration;
                } catch (e) {
                    console.warn('Performance measure failed:', e);
                }
            }
        }
        
        markPerf('head-end');
    </script>
    
    <!-- Service Worker 注册 -->
    <script>
        if ('serviceWorker' in navigator && window.ICEFOX_CONFIG.enableServiceWorker) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/assets/js/sw.js')
                    .then(function(registration) {
                        console.log('[SW] Registration successful:', registration.scope);
                    })
                    .catch(function(error) {
                        console.log('[SW] Registration failed:', error);
                    });
            });
        }
    </script>
    
    <?php $this->header(); ?>
</head>

<body>
    <div class="bg-[#f0f0f0] dark:bg-[#262626]">
        <div style="min-height:100%">