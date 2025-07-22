<?php
/**
 * Icefox API 批量处理器
 * 优化 API 请求，支持批量操作和响应缓存
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class IcefoxApiBatchProcessor {
    private static $instance = null;
    private $batchQueue = [];
    private $batchSize = 10;
    private $batchTimeout = 100; // 100ms
    private $cache;
    
    private function __construct() {
        $this->cache = IcefoxCache::getInstance();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 批量获取文章数据
     */
    public function batchGetArticles($cids, $fields = ['title', 'excerpt', 'created']) {
        if (empty($cids)) return [];
        
        $cacheKey = 'batch_articles_' . md5(implode(',', $cids) . implode(',', $fields));
        
        return $this->cache->get($cacheKey, function() use ($cids, $fields) {
            $db = IcefoxDatabasePool::getInstance()->getConnection();
            
            try {
                $placeholders = str_repeat('?,', count($cids) - 1) . '?';
                $fieldList = implode(',', array_map(function($field) {
                    return "`{$field}`";
                }, $fields));
                
                $sql = "SELECT cid, {$fieldList} FROM " . __TYPECHO_DB_PREFIX__ . "contents 
                        WHERE cid IN ({$placeholders}) AND status = 'publish'";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($cids);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // 按 cid 索引结果
                $indexed = [];
                foreach ($results as $row) {
                    $indexed[$row['cid']] = $row;
                }
                
                return $indexed;
                
            } finally {
                IcefoxDatabasePool::getInstance()->releaseConnection($db);
            }
        }, 300); // 5分钟缓存
    }
    
    /**
     * 批量获取评论数据
     */
    public function batchGetComments($cids) {
        if (empty($cids)) return [];
        
        $cacheKey = 'batch_comments_' . md5(implode(',', $cids));
        
        return $this->cache->get($cacheKey, function() use ($cids) {
            $db = IcefoxDatabasePool::getInstance()->getConnection();
            
            try {
                $placeholders = str_repeat('?,', count($cids) - 1) . '?';
                
                $sql = "SELECT cid, COUNT(*) as comment_count 
                        FROM " . __TYPECHO_DB_PREFIX__ . "comments 
                        WHERE cid IN ({$placeholders}) AND status = 'approved'
                        GROUP BY cid";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($cids);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // 转换为关联数组
                $counts = [];
                foreach ($results as $row) {
                    $counts[$row['cid']] = intval($row['comment_count']);
                }
                
                // 确保所有 cid 都有值
                foreach ($cids as $cid) {
                    if (!isset($counts[$cid])) {
                        $counts[$cid] = 0;
                    }
                }
                
                return $counts;
                
            } finally {
                IcefoxDatabasePool::getInstance()->releaseConnection($db);
            }
        }, 180); // 3分钟缓存
    }
    
    /**
     * 批量获取自定义字段
     */
    public function batchGetFields($cids, $fieldNames = null) {
        if (empty($cids)) return [];
        
        $cacheKey = 'batch_fields_' . md5(implode(',', $cids) . ($fieldNames ? implode(',', $fieldNames) : 'all'));
        
        return $this->cache->get($cacheKey, function() use ($cids, $fieldNames) {
            $db = IcefoxDatabasePool::getInstance()->getConnection();
            
            try {
                $placeholders = str_repeat('?,', count($cids) - 1) . '?';
                $params = $cids;
                
                $sql = "SELECT cid, name, str_value FROM " . __TYPECHO_DB_PREFIX__ . "fields 
                        WHERE cid IN ({$placeholders})";
                
                if ($fieldNames) {
                    $fieldPlaceholders = str_repeat('?,', count($fieldNames) - 1) . '?';
                    $sql .= " AND name IN ({$fieldPlaceholders})";
                    $params = array_merge($params, $fieldNames);
                }
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // 按 cid 和字段名组织数据
                $fields = [];
                foreach ($results as $row) {
                    $fields[$row['cid']][$row['name']] = $row['str_value'];
                }
                
                return $fields;
                
            } finally {
                IcefoxDatabasePool::getInstance()->releaseConnection($db);
            }
        }, 600); // 10分钟缓存
    }
    
    /**
     * 批量获取用户头像
     */
    public function batchGetAvatars($userIds) {
        if (empty($userIds)) return [];
        
        $cacheKey = 'batch_avatars_' . md5(implode(',', $userIds));
        
        return $this->cache->get($cacheKey, function() use ($userIds) {
            $db = IcefoxDatabasePool::getInstance()->getConnection();
            
            try {
                $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
                
                $sql = "SELECT uid, mail FROM " . __TYPECHO_DB_PREFIX__ . "users 
                        WHERE uid IN ({$placeholders})";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($userIds);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $avatarSource = Helper::options()->avatarSource ?: "https://cravatar.cn/avatar/";
                $avatars = [];
                
                foreach ($results as $row) {
                    $avatars[$row['uid']] = $avatarSource . md5(strtolower(trim($row['mail']))) . "?s=64&d=identicon";
                }
                
                return $avatars;
                
            } finally {
                IcefoxDatabasePool::getInstance()->releaseConnection($db);
            }
        }, 1800); // 30分钟缓存
    }
    
    /**
     * 批量处理点赞操作
     */
    public function batchProcessLikes($operations) {
        $db = IcefoxDatabasePool::getInstance()->getConnection();
        
        try {
            $db->beginTransaction();
            
            $results = [];
            foreach ($operations as $op) {
                $result = $this->processLikeOperation($db, $op);
                $results[] = $result;
            }
            
            $db->commit();
            
            // 清理相关缓存
            $this->clearLikeCache($operations);
            
            return $results;
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        } finally {
            IcefoxDatabasePool::getInstance()->releaseConnection($db);
        }
    }
    
    /**
     * 处理单个点赞操作
     */
    private function processLikeOperation($db, $operation) {
        $cid = intval($operation['cid']);
        $action = $operation['action']; // 'like' or 'unlike'
        $ip = $operation['ip'];
        
        // 检查是否已经点赞
        $checkSql = "SELECT id FROM " . __TYPECHO_DB_PREFIX__ . "icefox_likes 
                     WHERE cid = ? AND ip = ?";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([$cid, $ip]);
        $exists = $checkStmt->fetch();
        
        if ($action === 'like' && !$exists) {
            // 添加点赞记录
            $insertSql = "INSERT INTO " . __TYPECHO_DB_PREFIX__ . "icefox_likes 
                          (cid, ip, created) VALUES (?, ?, ?)";
            $insertStmt = $db->prepare($insertSql);
            $insertStmt->execute([$cid, $ip, time()]);
            
            // 更新文章点赞数
            $updateSql = "UPDATE " . __TYPECHO_DB_PREFIX__ . "contents 
                          SET likes = likes + 1 WHERE cid = ?";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([$cid]);
            
            return ['success' => true, 'action' => 'liked', 'cid' => $cid];
            
        } elseif ($action === 'unlike' && $exists) {
            // 删除点赞记录
            $deleteSql = "DELETE FROM " . __TYPECHO_DB_PREFIX__ . "icefox_likes 
                          WHERE cid = ? AND ip = ?";
            $deleteStmt = $db->prepare($deleteSql);
            $deleteStmt->execute([$cid, $ip]);
            
            // 更新文章点赞数
            $updateSql = "UPDATE " . __TYPECHO_DB_PREFIX__ . "contents 
                          SET likes = GREATEST(0, likes - 1) WHERE cid = ?";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([$cid]);
            
            return ['success' => true, 'action' => 'unliked', 'cid' => $cid];
        }
        
        return ['success' => false, 'message' => 'No action needed', 'cid' => $cid];
    }
    
    /**
     * 清理点赞相关缓存
     */
    private function clearLikeCache($operations) {
        foreach ($operations as $op) {
            $cid = $op['cid'];
            $this->cache->delete("article_likes_{$cid}");
            $this->cache->delete("article_data_{$cid}");
        }
    }
    
    /**
     * 批量获取文章统计数据
     */
    public function batchGetArticleStats($cids) {
        if (empty($cids)) return [];
        
        $cacheKey = 'batch_article_stats_' . md5(implode(',', $cids));
        
        return $this->cache->get($cacheKey, function() use ($cids) {
            $comments = $this->batchGetComments($cids);
            $likes = $this->batchGetLikes($cids);
            $views = $this->batchGetViews($cids);
            
            $stats = [];
            foreach ($cids as $cid) {
                $stats[$cid] = [
                    'comments' => $comments[$cid] ?? 0,
                    'likes' => $likes[$cid] ?? 0,
                    'views' => $views[$cid] ?? 0
                ];
            }
            
            return $stats;
        }, 300);
    }
    
    /**
     * 批量获取点赞数
     */
    private function batchGetLikes($cids) {
        $db = IcefoxDatabasePool::getInstance()->getConnection();
        
        try {
            $placeholders = str_repeat('?,', count($cids) - 1) . '?';
            
            $sql = "SELECT cid, COUNT(*) as like_count 
                    FROM " . __TYPECHO_DB_PREFIX__ . "icefox_likes 
                    WHERE cid IN ({$placeholders})
                    GROUP BY cid";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($cids);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $likes = [];
            foreach ($results as $row) {
                $likes[$row['cid']] = intval($row['like_count']);
            }
            
            // 确保所有 cid 都有值
            foreach ($cids as $cid) {
                if (!isset($likes[$cid])) {
                    $likes[$cid] = 0;
                }
            }
            
            return $likes;
            
        } finally {
            IcefoxDatabasePool::getInstance()->releaseConnection($db);
        }
    }
    
    /**
     * 批量获取浏览量
     */
    private function batchGetViews($cids) {
        $db = IcefoxDatabasePool::getInstance()->getConnection();
        
        try {
            $placeholders = str_repeat('?,', count($cids) - 1) . '?';
            
            $sql = "SELECT cid, views FROM " . __TYPECHO_DB_PREFIX__ . "contents 
                    WHERE cid IN ({$placeholders})";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($cids);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $views = [];
            foreach ($results as $row) {
                $views[$row['cid']] = intval($row['views'] ?? 0);
            }
            
            return $views;
            
        } finally {
            IcefoxDatabasePool::getInstance()->releaseConnection($db);
        }
    }
    
    /**
     * 获取批处理统计信息
     */
    public function getStats() {
        return [
            'queue_size' => count($this->batchQueue),
            'batch_size' => $this->batchSize,
            'batch_timeout' => $this->batchTimeout,
            'cache_hits' => $this->cache->getHits(),
            'cache_misses' => $this->cache->getMisses()
        ];
    }
}

/**
 * API 响应优化器
 */
class IcefoxApiResponseOptimizer {
    
    /**
     * 压缩 JSON 响应
     */
    public static function compressResponse($data) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        // 启用 gzip 压缩
        if (function_exists('gzencode') && !headers_sent()) {
            $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
            
            if (strpos($acceptEncoding, 'gzip') !== false) {
                header('Content-Encoding: gzip');
                return gzencode($json);
            }
        }
        
        return $json;
    }
    
    /**
     * 设置缓存头部
     */
    public static function setCacheHeaders($maxAge = 300, $etag = null) {
        if (headers_sent()) return;
        
        header("Cache-Control: public, max-age={$maxAge}");
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
        
        if ($etag) {
            header("ETag: \"{$etag}\"");
            
            // 检查 If-None-Match
            $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if ($ifNoneMatch === "\"{$etag}\"") {
                http_response_code(304);
                exit;
            }
        }
    }
    
    /**
     * 分页响应优化
     */
    public static function paginateResponse($data, $page, $pageSize, $total) {
        $totalPages = ceil($total / $pageSize);
        
        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'page_size' => $pageSize,
                'total_items' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ]
        ];
    }
    
    /**
     * 错误响应标准化
     */
    public static function errorResponse($message, $code = 400, $details = null) {
        http_response_code($code);
        
        $response = [
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $code
            ]
        ];
        
        if ($details) {
            $response['error']['details'] = $details;
        }
        
        return $response;
    }
    
    /**
     * 成功响应标准化
     */
    public static function successResponse($data = null, $message = null) {
        $response = ['success' => true];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        if ($message) {
            $response['message'] = $message;
        }
        
        return $response;
    }
}