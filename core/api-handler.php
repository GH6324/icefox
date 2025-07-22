<?php
/**
 * Icefox API 处理器
 * 处理前端 AJAX 请求和性能数据收集
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class IcefoxAPIHandler {
    private $db;
    private $options;
    
    public function __construct() {
        $this->db = Typecho_Db::get();
        $this->options = Helper::options();
    }
    
    /**
     * 处理 API 请求
     */
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['REQUEST_URI'];
        
        // 解析路径
        $pathInfo = parse_url($path, PHP_URL_PATH);
        $pathParts = explode('/', trim($pathInfo, '/'));
        
        if (count($pathParts) < 2 || $pathParts[0] !== 'api') {
            return false;
        }
        
        $endpoint = $pathParts[1];
        
        // 设置响应头
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        
        try {
            switch ($endpoint) {
                case 'performance':
                    $this->handlePerformance($method);
                    break;
                    
                case 'cache':
                    $this->handleCache($method);
                    break;
                    
                case 'comment':
                    $this->handleComment($method);
                    break;
                    
                case 'like':
                    $this->handleLike($method);
                    break;
                    
                case 'upload':
                    $this->handleUpload($method);
                    break;
                    
                default:
                    $this->sendError('Unknown endpoint', 404);
            }
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
        
        return true;
    }
    
    /**
     * 处理性能数据收集
     */
    private function handlePerformance($method) {
        if ($method !== 'POST') {
            $this->sendError('Method not allowed', 405);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $this->sendError('Invalid JSON', 400);
            return;
        }
        
        // 验证必要字段
        $required = ['url', 'metrics', 'timestamp'];
        foreach ($required as $field) {
            if (!isset($input[$field])) {
                $this->sendError("Missing field: $field", 400);
                return;
            }
        }
        
        // 存储性能数据
        $this->storePerformanceData($input);
        
        $this->sendSuccess(['message' => 'Performance data recorded']);
    }
    
    /**
     * 存储性能数据
     */
    private function storePerformanceData($data) {
        // 创建性能数据表（如果不存在）
        $this->createPerformanceTable();
        
        // 插入数据
        $this->db->query($this->db->insert('table.icefox_performance')->rows([
            'url' => $data['url'],
            'user_agent' => $data['userAgent'] ?? '',
            'metrics' => json_encode($data['metrics']),
            'connection_info' => json_encode($data['connection'] ?? []),
            'device_info' => json_encode($data['device'] ?? []),
            'timestamp' => $data['timestamp'],
            'created' => time()
        ]));
        
        // 定期清理旧数据（保留30天）
        $this->cleanupOldPerformanceData();
    }
    
    /**
     * 创建性能数据表
     */
    private function createPerformanceTable() {
        $sql = "CREATE TABLE IF NOT EXISTS `" . $this->db->getPrefix() . "icefox_performance` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `url` varchar(500) NOT NULL,
            `user_agent` text,
            `metrics` longtext,
            `connection_info` text,
            `device_info` text,
            `timestamp` bigint(20) NOT NULL,
            `created` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_url` (`url`(255)),
            KEY `idx_created` (`created`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $this->db->query($sql);
    }
    
    /**
     * 清理旧的性能数据
     */
    private function cleanupOldPerformanceData() {
        $thirtyDaysAgo = time() - (30 * 24 * 60 * 60);
        $this->db->query($this->db->delete('table.icefox_performance')
            ->where('created < ?', $thirtyDaysAgo));
    }
    
    /**
     * 处理缓存操作
     */
    private function handleCache($method) {
        switch ($method) {
            case 'DELETE':
                $this->clearCache();
                break;
            case 'GET':
                $this->getCacheStats();
                break;
            default:
                $this->sendError('Method not allowed', 405);
        }
    }
    
    /**
     * 清理缓存
     */
    private function clearCache() {
        // 验证权限
        if (!$this->isAdmin()) {
            $this->sendError('Unauthorized', 401);
            return;
        }
        
        // 清理文件缓存
        IcefoxCache::clear();
        
        // 清理 Typecho 缓存
        $cacheDir = __TYPECHO_ROOT_DIR__ . '/usr/cache/';
        if (is_dir($cacheDir)) {
            $this->clearDirectory($cacheDir);
        }
        
        $this->sendSuccess(['message' => 'Cache cleared successfully']);
    }
    
    /**
     * 获取缓存统计
     */
    private function getCacheStats() {
        $stats = [
            'file_cache' => $this->getFileCacheStats(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
        
        $this->sendSuccess($stats);
    }
    
    /**
     * 获取文件缓存统计
     */
    private function getFileCacheStats() {
        $cacheDir = __TYPECHO_ROOT_DIR__ . '/usr/cache/icefox/';
        if (!is_dir($cacheDir)) {
            return ['files' => 0, 'size' => 0];
        }
        
        $files = glob($cacheDir . '*.cache');
        $totalSize = 0;
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }
        
        return [
            'files' => count($files),
            'size' => $totalSize,
            'size_formatted' => $this->formatBytes($totalSize)
        ];
    }
    
    /**
     * 处理评论提交
     */
    private function handleComment($method) {
        if ($method !== 'POST') {
            $this->sendError('Method not allowed', 405);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $this->sendError('Invalid JSON', 400);
            return;
        }
        
        // 验证 CSRF Token
        if (!IcefoxSecurity::validateCSRFToken($input['csrf_token'] ?? '')) {
            $this->sendError('Invalid CSRF token', 403);
            return;
        }
        
        // 验证必要字段
        $required = ['cid', 'author', 'mail', 'text'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                $this->sendError("Missing field: $field", 400);
                return;
            }
        }
        
        // 安全过滤
        $comment = [
            'cid' => (int)$input['cid'],
            'author' => IcefoxSecurity::safeOutput($input['author']),
            'mail' => filter_var($input['mail'], FILTER_VALIDATE_EMAIL),
            'url' => !empty($input['url']) ? filter_var($input['url'], FILTER_VALIDATE_URL) : '',
            'text' => IcefoxSecurity::filterCommentContent($input['text']),
            'parent' => isset($input['parent']) ? (int)$input['parent'] : 0
        ];
        
        if (!$comment['mail']) {
            $this->sendError('Invalid email address', 400);
            return;
        }
        
        // 防刷机制
        if ($this->isCommentSpam($comment)) {
            $this->sendError('Comment rejected as spam', 429);
            return;
        }
        
        // 插入评论
        $commentId = $this->insertComment($comment);
        
        $this->sendSuccess([
            'message' => 'Comment submitted successfully',
            'comment_id' => $commentId
        ]);
    }
    
    /**
     * 检查评论是否为垃圾评论
     */
    private function isCommentSpam($comment) {
        // 检查频率限制
        $recentComments = $this->db->fetchAll($this->db->select('created')
            ->from('table.comments')
            ->where('mail = ?', $comment['mail'])
            ->where('created > ?', time() - 300) // 5分钟内
            ->limit(5));
        
        if (count($recentComments) >= 3) {
            return true; // 5分钟内超过3条评论
        }
        
        // 检查内容长度
        if (strlen($comment['text']) < 2 || strlen($comment['text']) > 5000) {
            return true;
        }
        
        // 检查是否包含过多链接
        $linkCount = preg_match_all('/<a\s+[^>]*href/i', $comment['text']);
        if ($linkCount > 3) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 插入评论
     */
    private function insertComment($comment) {
        return $this->db->query($this->db->insert('table.comments')->rows([
            'cid' => $comment['cid'],
            'created' => time(),
            'author' => $comment['author'],
            'authorId' => 0,
            'ownerId' => 1,
            'mail' => $comment['mail'],
            'url' => $comment['url'],
            'ip' => $this->getClientIP(),
            'agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'text' => $comment['text'],
            'type' => 'comment',
            'status' => 'approved',
            'parent' => $comment['parent']
        ]));
    }
    
    /**
     * 处理点赞功能
     */
    private function handleLike($method) {
        if ($method !== 'POST') {
            $this->sendError('Method not allowed', 405);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $cid = (int)($input['cid'] ?? 0);
        
        if (!$cid) {
            $this->sendError('Invalid article ID', 400);
            return;
        }
        
        // 检查文章是否存在
        $article = $this->db->fetchRow($this->db->select('cid')
            ->from('table.contents')
            ->where('cid = ? AND status = ?', $cid, 'publish'));
        
        if (!$article) {
            $this->sendError('Article not found', 404);
            return;
        }
        
        // 防重复点赞
        $clientIP = $this->getClientIP();
        $cacheKey = "like_{$cid}_{$clientIP}";
        
        if (IcefoxCache::get($cacheKey)) {
            $this->sendError('Already liked', 429);
            return;
        }
        
        // 增加点赞数
        $this->incrementLikeCount($cid);
        
        // 设置防重复缓存（24小时）
        IcefoxCache::set($cacheKey, true, 86400);
        
        $this->sendSuccess(['message' => 'Liked successfully']);
    }
    
    /**
     * 增加点赞数
     */
    private function incrementLikeCount($cid) {
        // 检查是否已有点赞记录
        $field = $this->db->fetchRow($this->db->select('str_value')
            ->from('table.fields')
            ->where('cid = ? AND name = ?', $cid, 'likes'));
        
        $currentLikes = $field ? (int)$field['str_value'] : 0;
        $newLikes = $currentLikes + 1;
        
        if ($field) {
            // 更新现有记录
            $this->db->query($this->db->update('table.fields')
                ->rows(['str_value' => $newLikes])
                ->where('cid = ? AND name = ?', $cid, 'likes'));
        } else {
            // 插入新记录
            $this->db->query($this->db->insert('table.fields')->rows([
                'cid' => $cid,
                'name' => 'likes',
                'type' => 'str',
                'str_value' => $newLikes
            ]));
        }
    }
    
    /**
     * 处理文件上传
     */
    private function handleUpload($method) {
        if ($method !== 'POST') {
            $this->sendError('Method not allowed', 405);
            return;
        }
        
        // 验证权限
        if (!$this->isAdmin()) {
            $this->sendError('Unauthorized', 401);
            return;
        }
        
        if (empty($_FILES['file'])) {
            $this->sendError('No file uploaded', 400);
            return;
        }
        
        $file = $_FILES['file'];
        
        // 验证文件
        if (!$this->validateUploadFile($file)) {
            $this->sendError('Invalid file', 400);
            return;
        }
        
        // 处理上传
        $result = $this->processUpload($file);
        
        $this->sendSuccess($result);
    }
    
    /**
     * 验证上传文件
     */
    private function validateUploadFile($file) {
        // 检查上传错误
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }
        
        // 检查文件大小（10MB限制）
        if ($file['size'] > 10 * 1024 * 1024) {
            return false;
        }
        
        // 检查文件类型
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        return in_array($mimeType, $allowedTypes);
    }
    
    /**
     * 处理文件上传
     */
    private function processUpload($file) {
        $uploadDir = __TYPECHO_ROOT_DIR__ . '/usr/uploads/' . date('Y/m/');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $extension;
        $filepath = $uploadDir . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception('Failed to move uploaded file');
        }
        
        // 生成URL
        $url = $this->options->siteUrl . 'usr/uploads/' . date('Y/m/') . $filename;
        
        return [
            'url' => $url,
            'filename' => $filename,
            'size' => $file['size']
        ];
    }
    
    /**
     * 获取客户端IP
     */
    private function getClientIP() {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * 检查是否为管理员
     */
    private function isAdmin() {
        // 简单的管理员检查，实际应该检查用户登录状态
        return isset($_SESSION['user_id']) && $_SESSION['user_group'] === 'administrator';
    }
    
    /**
     * 清理目录
     */
    private function clearDirectory($dir) {
        if (!is_dir($dir)) return;
        
        $files = glob($dir . '*', GLOB_MARK);
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->clearDirectory($file);
                rmdir($file);
            } else {
                unlink($file);
            }
        }
    }
    
    /**
     * 格式化字节数
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * 发送成功响应
     */
    private function sendSuccess($data) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => time()
        ]);
        exit;
    }
    
    /**
     * 发送错误响应
     */
    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => $code,
            'timestamp' => time()
        ]);
        exit;
    }
}

// 自动处理 API 请求
if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    $handler = new IcefoxAPIHandler();
    if ($handler->handleRequest()) {
        exit; // API 请求已处理
    }
}