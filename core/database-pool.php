<?php
/**
 * Icefox 数据库连接池
 * 优化数据库连接管理，提升并发性能
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class IcefoxDatabasePool {
    private static $instance = null;
    private $connections = [];
    private $activeConnections = [];
    private $maxConnections = 10;
    private $currentConnections = 0;
    private $waitQueue = [];
    private $config = [];
    
    private function __construct() {
        $this->config = [
            'host' => __TYPECHO_DB_HOST__,
            'port' => __TYPECHO_DB_PORT__,
            'database' => __TYPECHO_DB_NAME__,
            'username' => __TYPECHO_DB_USER__,
            'password' => __TYPECHO_DB_PASS__,
            'charset' => __TYPECHO_DB_CHARSET__,
            'prefix' => __TYPECHO_DB_PREFIX__
        ];
        
        // 根据系统配置调整连接池大小
        $this->maxConnections = min(20, max(5, intval(ini_get('max_connections') / 4)));
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 获取数据库连接
     */
    public function getConnection($timeout = 5) {
        // 如果有空闲连接，直接返回
        if (!empty($this->connections)) {
            $connection = array_pop($this->connections);
            $this->activeConnections[spl_object_hash($connection)] = $connection;
            return $connection;
        }
        
        // 如果未达到最大连接数，创建新连接
        if ($this->currentConnections < $this->maxConnections) {
            $connection = $this->createConnection();
            if ($connection) {
                $this->currentConnections++;
                $this->activeConnections[spl_object_hash($connection)] = $connection;
                return $connection;
            }
        }
        
        // 等待可用连接
        return $this->waitForConnection($timeout);
    }
    
    /**
     * 释放数据库连接
     */
    public function releaseConnection($connection) {
        $hash = spl_object_hash($connection);
        
        if (isset($this->activeConnections[$hash])) {
            unset($this->activeConnections[$hash]);
            
            // 检查连接是否仍然有效
            if ($this->isConnectionValid($connection)) {
                // 如果有等待队列，直接分配给等待者
                if (!empty($this->waitQueue)) {
                    $waiter = array_shift($this->waitQueue);
                    $waiter['resolve']($connection);
                    $this->activeConnections[$hash] = $connection;
                    return;
                }
                
                // 否则放回连接池
                if (count($this->connections) < $this->maxConnections / 2) {
                    $this->connections[] = $connection;
                } else {
                    // 连接池已满，关闭连接
                    $this->closeConnection($connection);
                    $this->currentConnections--;
                }
            } else {
                // 连接无效，关闭并减少计数
                $this->closeConnection($connection);
                $this->currentConnections--;
            }
        }
    }
    
    /**
     * 创建新的数据库连接
     */
    private function createConnection() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
                $this->config['charset']
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->config['charset']}",
                PDO::ATTR_TIMEOUT => 5
            ];
            
            $pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $options);
            
            // 设置连接属性
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            
            return $pdo;
            
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 等待可用连接
     */
    private function waitForConnection($timeout) {
        return new Promise(function($resolve, $reject) use ($timeout) {
            $this->waitQueue[] = [
                'resolve' => $resolve,
                'reject' => $reject,
                'timeout' => time() + $timeout
            ];
            
            // 设置超时处理
            $this->processWaitQueue();
        });
    }
    
    /**
     * 处理等待队列
     */
    private function processWaitQueue() {
        $now = time();
        
        foreach ($this->waitQueue as $index => $waiter) {
            if ($now > $waiter['timeout']) {
                // 超时处理
                unset($this->waitQueue[$index]);
                $waiter['reject'](new Exception('Database connection timeout'));
            }
        }
        
        // 重新索引数组
        $this->waitQueue = array_values($this->waitQueue);
    }
    
    /**
     * 检查连接是否有效
     */
    private function isConnectionValid($connection) {
        try {
            $connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * 关闭连接
     */
    private function closeConnection($connection) {
        $connection = null;
    }
    
    /**
     * 获取连接池状态
     */
    public function getStatus() {
        return [
            'total_connections' => $this->currentConnections,
            'active_connections' => count($this->activeConnections),
            'idle_connections' => count($this->connections),
            'waiting_requests' => count($this->waitQueue),
            'max_connections' => $this->maxConnections
        ];
    }
    
    /**
     * 清理连接池
     */
    public function cleanup() {
        // 关闭所有空闲连接
        foreach ($this->connections as $connection) {
            $this->closeConnection($connection);
        }
        $this->connections = [];
        
        // 处理等待队列中的超时请求
        $this->processWaitQueue();
    }
    
    /**
     * 析构函数
     */
    public function __destruct() {
        $this->cleanup();
    }
}

/**
 * Promise 简单实现
 */
class Promise {
    private $state = 'pending';
    private $value = null;
    private $handlers = [];
    
    public function __construct($executor) {
        try {
            $executor(
                function($value) { $this->resolve($value); },
                function($reason) { $this->reject($reason); }
            );
        } catch (Exception $e) {
            $this->reject($e);
        }
    }
    
    private function resolve($value) {
        if ($this->state === 'pending') {
            $this->state = 'fulfilled';
            $this->value = $value;
            $this->executeHandlers();
        }
    }
    
    private function reject($reason) {
        if ($this->state === 'pending') {
            $this->state = 'rejected';
            $this->value = $reason;
            $this->executeHandlers();
        }
    }
    
    private function executeHandlers() {
        foreach ($this->handlers as $handler) {
            $this->executeHandler($handler);
        }
        $this->handlers = [];
    }
    
    private function executeHandler($handler) {
        if ($this->state === 'fulfilled' && isset($handler['onFulfilled'])) {
            $handler['onFulfilled']($this->value);
        } elseif ($this->state === 'rejected' && isset($handler['onRejected'])) {
            $handler['onRejected']($this->value);
        }
    }
    
    public function then($onFulfilled = null, $onRejected = null) {
        if ($this->state === 'fulfilled' && $onFulfilled) {
            $onFulfilled($this->value);
        } elseif ($this->state === 'rejected' && $onRejected) {
            $onRejected($this->value);
        } else {
            $this->handlers[] = [
                'onFulfilled' => $onFulfilled,
                'onRejected' => $onRejected
            ];
        }
        return $this;
    }
}

/**
 * 数据库连接包装器
 */
class IcefoxDatabaseWrapper {
    private $pool;
    private $connection;
    private $inTransaction = false;
    
    public function __construct() {
        $this->pool = IcefoxDatabasePool::getInstance();
    }
    
    /**
     * 获取连接
     */
    private function getConnection() {
        if (!$this->connection) {
            $this->connection = $this->pool->getConnection();
        }
        return $this->connection;
    }
    
    /**
     * 执行查询
     */
    public function query($sql, $params = []) {
        $connection = $this->getConnection();
        
        try {
            if (empty($params)) {
                return $connection->query($sql);
            } else {
                $stmt = $connection->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
        } catch (PDOException $e) {
            error_log("Database query failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 开始事务
     */
    public function beginTransaction() {
        $connection = $this->getConnection();
        $connection->beginTransaction();
        $this->inTransaction = true;
    }
    
    /**
     * 提交事务
     */
    public function commit() {
        if ($this->connection && $this->inTransaction) {
            $this->connection->commit();
            $this->inTransaction = false;
        }
    }
    
    /**
     * 回滚事务
     */
    public function rollback() {
        if ($this->connection && $this->inTransaction) {
            $this->connection->rollback();
            $this->inTransaction = false;
        }
    }
    
    /**
     * 释放连接
     */
    public function release() {
        if ($this->connection) {
            // 如果在事务中，先回滚
            if ($this->inTransaction) {
                $this->rollback();
            }
            
            $this->pool->releaseConnection($this->connection);
            $this->connection = null;
        }
    }
    
    /**
     * 析构函数
     */
    public function __destruct() {
        $this->release();
    }
}