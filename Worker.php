<?php
require_once __DIR__ . '/ProcessManager.php';

class Worker
{
    public $count = 1;  // 子进程数，2 最高, 可以达到 2W5; 4 低一点，2W4; 8 更低，只有 1W 多
    public $localSocket = 'tcp://0.0.0.0:2345'; // 监听地址
    public $onMessage = null; // 处理函数
    public $onWebSocketMessage = null; // WebSocket 消息处理回调
    public $onWebSocketConnect = null; // WebSocket 连接建立回调
    public $onWebSocketClose = null;   // WebSocket 连接关闭回调
    public $logger;
    public $connections = [];      // 用于管理所有 Connection 实例
    public $connectionCount = 0;   // 每个子进程到连接数
    public $requestNum = 0;        // 每个子进程总请求数
    public $websocketConnectionCount = 0; // WebSocket 连接计数
    private $webSocketConnections = null; // WebSocket 连接集合

    private $masterPidFile = 'masterPidFile.pid'; // 主进程pid
    private $masterStatusFile = 'masterStatusFile.status'; // 主进程状态文件
    private $socket = null;         // 监听 socket
        
    /**
     * 进程管理器
     * @var ProcessManager
     */
    private $processManager;

    /**
     * 退出标志
     * @var bool
     */
    private $exiting = false;       // 进程是否退出中
    private $maxConnections = 1024; // 最大连接数
    private $httpParser;
    private $middlewareManager;
    private $eventBase;

    /**
     * 可用的命令列表
     */
    private $availableCommands = [
        'start',
        'stop',
        'reload',
        'status'
    ];

    public function __construct($logger, $httpParser)
    {
        $this->logger = $logger;
        $this->httpParser = $httpParser;
        $this->middlewareManager = new MiddlewareManager($this);
        $this->webSocketConnections = new \SplObjectStorage();

        if (!$this->onMessage) 
        {
            // 默认处理
            $this->onMessage = function($worker, $connection, $request)
            {
                $worker->logger->log("处理连接: {$connection->id}");
                print_r($request);
                // 发送数据给客户端
                $worker->sendData($connection, "hello world \n");
                return true; // 表示处理完成
            };
        }

        // 设置默认 WebSocket 消息处理回调
        if (!$this->onWebSocketMessage) {
            $this->onWebSocketMessage = function($worker, $connection, $data) {
                $worker->logger->log("WebSocket 消息: " . $data);
                $connection->sendWebSocket("Echo: " . $data);
                return true;
            };
        }
        
        if (!$this->onWebSocketConnect) {
            $this->onWebSocketConnect = function($worker, $connection) {
                $worker->logger->log("WebSocket 连接已建立: " . $connection->id);
                return true;
            };
        }
        
        if (!$this->onWebSocketClose) {
            $this->onWebSocketClose = function($worker, $connection, $code, $reason) {
                $worker->logger->log("WebSocket 连接已关闭: " . $connection->id . ", 代码: $code, 原因: $reason");
                return true;
            };
        }
    }

    /**
     * 解析并执行命令
     * @param array $argv 命令行参数
     * @return void
     */
    public function run($argv = null)
    {
        // 如果未提供参数，使用全局变量
        if ($argv === null) {
            global $argv;
        }

        $command = trim($argv[1] ?? '');
        $usage = "Usage: php http_server.php {" . implode('|', $this->availableCommands) . "}\n";
        
        if (empty($command) || !in_array($command, $this->availableCommands)) {
            exit($usage);
        }
        
        switch ($command) {
            case 'start':
                $this->start();
                break;
            case 'stop':
            case 'reload':
            case 'status':
                $this->sendSignalToMaster($command);
                break;
        }
    }

    /**
     * 添加中间件
     * @param MiddlewareInterface|callable $middleware
     * @return $this
     */
    public function use($middleware)
    {
        $this->middlewareManager->add($middleware);
        return $this;
    }

    /**
     * 主进程启动
     */
    public function start()
    {
        // 创建 ProcessManager 实例
        $this->processManager = new ProcessManager(
            $this->logger, 
            $this->count, 
            $this->masterPidFile, 
            $this->masterStatusFile
        );

        // 主进程创建tcp服务器
        $errno = 0;
        $errmsg = '';
        $socket = stream_socket_server($this->localSocket, $errno, $errmsg);

        // 尝试打开 KeepAlive TCP 和禁用 Nagle 算法
        if (function_exists('socket_import_stream')) 
        {
            $socketImport = socket_import_stream($socket);
            @socket_set_option($socketImport, SOL_SOCKET, SO_KEEPALIVE, 1);
            @socket_set_option($socketImport, SOL_TCP, TCP_NODELAY, 1);
        }

        // Non blocking.
        stream_set_blocking($socket, 0);
        $this->socket = $socket;

        // 设置 Worker 实例和 socket
        $this->processManager->setWorker($this)
                             ->setSocket($socket);
        
        // 启动进程管理器
        $this->processManager->start();
    }

    /**
     * 启动工作进程
     * 
     * @param resource $socket 监听socket
     * @return void
     */
    public function runWorker($socket)
    {
        $this->socket = $socket;
        
        // 创建 EventBase 实例
        $this->eventBase = new EventBase();
        $base = $this->eventBase;

        // 创建异步日志记录器
        $this->logger = new AsyncLogger($base);

        // 添加资源检查事件
        $this->addResourceCheckEvent($base);
        
        // 添加ping事件
        $this->addPingEvent($base);
        
        // 添加统计事件
        $this->addStatEvent($base);

        // 创建并添加连接接受事件
        $event = new Event(
            $base,
            $this->socket,
            Event::READ | Event::PERSIST,
            [$this, "acceptConnect"],
            [$base]
        );
        $event->add();
        
        // 启动事件循环
        $this->startEventLoop($base);
    }
        
    /**
     * 停止工作进程
     * 
     * @return void
     */
    public function stopWorker()
    {
        $this->logger->log("工作进程停止中...");
        $this->exiting = true;
        
        // 关闭监听socket
        if ($this->socket && is_resource($this->socket)) {
            @fclose($this->socket);
            $this->socket = null;
        }
        
        // 主动关闭空闲连接
        $this->closeIdleConnections();
        
        // 如果没有活跃连接，立即退出
        if (empty($this->connections)) {
            $this->logger->log("没有活跃连接，工作进程立即退出");
            return;
        }
        
        // 添加退出检查事件
        $this->addExitCheckEvent($this->eventBase);
    }

    /**
     * 添加资源检查事件
     * 
     * @param EventBase $base 事件基础实例
     * @return void
     */
    private function addResourceCheckEvent($base)
    {
        // $timeoutEvent = new Event(
        //     $base,
        //     -1,
        //     Event::TIMEOUT | Event::PERSIST, function() {
        //         foreach ($this->connections as $id => $connection) {
        //             if (!$connection->isActive() && !$this->testConnection($connection)) 
        //             {
        //                 $this->logger->log("连接超时关闭: $id");
        //                 $this->cleanupConnection($id);
        //             }
        //         }
        //     }
        // );
        // $timeoutEvent->add(10); // 每10秒检查一次

        $resourceCheckEvent = new Event(
            $base,
            -1,
            Event::TIMEOUT | Event::PERSIST,
            function() {
                $this->logger->log("执行资源检查...");
                $closed = 0;
                
                // 检查所有连接
                foreach ($this->connections as $id => $connection) 
                {
                    // 检查连接有效性
                    if (!$connection->isValid() || !$connection->isActive()) 
                    {
                        $this->logger->log("资源检查：关闭不活跃连接 {$id}");
                        $this->cleanupConnection($id);
                        $closed++;
                    }
                }
                
                if ($closed > 0) 
                {
                    $this->logger->log("资源检查：共关闭 {$closed} 个连接");
                    gc_collect_cycles(); // 强制垃圾回收
                }
                
                // 检查内存使用情况
                $memory = memory_get_usage(true) / 1024 / 1024;
                if ($memory > 100) { // 内存超过100MB
                    $this->logger->log("内存使用过高: {$memory}MB，执行垃圾回收");
                    gc_collect_cycles();
                }
            }
        );
        $resourceCheckEvent->add(10);
    }
    
    /**
     * 添加ping事件
     * 
     * @param EventBase $base 事件基础实例
     * @return void
     */
    private function addPingEvent($base)
    {
        $pingEvent = new Event(
            $base,
            -1,
            Event::TIMEOUT | Event::PERSIST,
            function() {
                foreach ($this->connections as $connection) {
                    if ($connection->isWebSocket && $connection->isValid()) {
                        // 发送 ping，如果长时间未收到 pong 可以考虑关闭连接
                        $connection->ping();
                    }
                }
            }
        );
        $pingEvent->add(5);
    }
    
    /**
     * 添加统计事件
     * 
     * @param EventBase $base 事件基础实例
     * @return void
     */
    private function addStatEvent($base)
    {
        $statEvent = new Event(
            $base,
            -1,
            Event::TIMEOUT | Event::PERSIST, function() {
                $memoryUsage = memory_get_usage(true);
                $peakUsage = memory_get_peak_usage(true);
                $this->logger->log(sprintf(
                    "Memory: %sMB, Peak: %sMB, Connections: %d, WebSocket: %d, Requests: %d", 
                    round($memoryUsage/1024/1024, 2),
                    round($peakUsage/1024/1024, 2),
                    $this->connectionCount, 
                    $this->websocketConnectionCount,
                    $this->requestNum
                ));
            }
        );
        $statEvent->add(10);
    }
    
    /**
     * 添加退出检查事件
     * 
     * @param EventBase $base 事件基础实例
     * @return void
     */
    private function addExitCheckEvent($base)
    {
        $exitCheckEvent = new Event(
            $base,
            -1,
            Event::TIMEOUT | Event::PERSIST,
            function() {
                $this->tryGracefulExit();
            }
        );
        $exitCheckEvent->add(0.5);
    }
    
    /**
     * 启动事件循环
     * 
     * @param EventBase $base 事件基础实例
     * @return void
     */
    private function startEventLoop($base)
    {
        while (!$this->exiting || !empty($this->connections)) {
            pcntl_signal_dispatch();
            $base->loop(EventBase::LOOP_ONCE | EventBase::LOOP_NONBLOCK);
            pcntl_signal_dispatch();
            
            // 如果需要退出且没有连接，则退出循环
            if ($this->exiting && empty($this->connections)) {
                $this->logger->log("所有连接已关闭，事件循环退出");
                break;
            }
            
            // 短暂休眠，避免CPU占用过高
            usleep(10000); // 10ms
        }
    }
    
    /**
     * 关闭所有空闲连接
     * 
     * @return int 关闭的连接数
     */
    private function closeIdleConnections()
    {
        $count = 0;
        $idleConnections = [];
        
        foreach ($this->connections as $id => $connection) {
            if ($connection->isIdle()) {
                $idleConnections[] = $id;
            }
        }
        
        foreach ($idleConnections as $id) {
            $this->logger->log("关闭空闲连接: $id");
            $this->cleanupConnection($id);
            $count++;
        }
        
        return $count;
    }
    
    /**
     * 发送命令给主进程
     * 
     * @param string $command 命令
     * @return void
     */
    public function sendSignalToMaster($command)
    {
        $processManager = new ProcessManager(
            $this->logger, 
            $this->count, 
            $this->masterPidFile, 
            $this->masterStatusFile
        );
        
        $processManager->sendCommand($command);
        exit;
    }

    // 新增方法
    private function tryGracefulExit()
    {
        try {
            // 优雅退出时，可以先关闭所有空闲连接
            $idleConnections = [];
            foreach ($this->connections as $id => $connection) {
                if ($connection->isIdle()) {
                    $idleConnections[] = $id;
                }
            }
            
            // 先关闭所有空闲连接
            foreach ($idleConnections as $id) {
                $this->cleanupConnection($id);
            }
            
            // 检查剩余连接
            if (empty($this->connections)) {
                $this->logger->log("所有连接处理完毕，进程安全退出");
                exit(0);
            }
            
            return; // 还有未处理完的连接
        } catch (\Throwable $e) {
            $this->logger->log("优雅退出异常: " . $e->getMessage());
            exit(1); // 异常退出
        }
    }

    /**
     * 子进程接受连接
     * @param $socket
     * @param $events
     * @param $arg
     */
    public function acceptConnect($socket, $events, $args)
    {
        $this->logger->log("acceptConnect");
        try {
            $base = $args[0]; // 获取传递的 EventBase 实例
            $newSocket = @stream_socket_accept($socket, 0, $remote_address); // 第二个参数设置 0，不阻塞，未获取到会警告
            // 有一个连接过来时，子进程都会触发本函数，但只有一个子进程获取到连接并处理
            if (!$newSocket) return;

            if ($this->connectionCount >= $this->maxConnections) 
            {
                $this->logger->log("连接数达到上限，拒绝新连接");
                @fclose($newSocket);
                return;
            }

            $this->connectionCount++;

            stream_set_blocking($newSocket, 0);
            // 兼容 hhvm
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($newSocket, 0);
            }
            if (function_exists('stream_set_write_buffer')) {
                stream_set_write_buffer($newSocket, 0);
            }

            $connection = new Connection($newSocket);

            // 注册读事件
            $readEvent = new Event(
                $base,
                $newSocket,
                Event::READ | Event::PERSIST, // 监听可读事件并保持持久化
                [$this, "acceptData"],
                [$base, $newSocket]
            );
            // 添加事件
            $readEvent->add();
            $connection->readEvent = $readEvent;
            
            // 写事件先不注册，只有 send 时才注册
            $connection->writeEvent = null;
            // // 注册写事件
            // $writeEvent = new Event(
            //     $base,
            //     $newSocket,
            //     Event::WRITE | Event::PERSIST,
            //     function($fd, $events, $args) {
            //         $connection = $args[0];
            //         $connection->flush();
            //         if (empty($connection->writeBuffer)) {
            //             $connection->event->del();
            //         }
            //     },
            //     [$connection]
            // );
            // $writeEvent->add();
            // $connection->event = $writeEvent;
            $this->connections[$connection->id] = $connection;
        } catch (\Throwable $e) {
            $this->logger->log("acceptConnect异常: " . $e->getMessage());
        }
    }

    /**
     * 处理 WebSocket 握手
     * @param Connection $connection 客户端连接
     * @param array $request HTTP请求数据
     * @return bool 是否处理成功
     */
    public function handleWebSocketHandshake($connection, $request)
    {
        // $origin = $request['server']['HTTP_ORIGIN'] ?? '';
        // // 如果需要检查 Origin（CSRF 保护）
        // if ($this->checkOrigin && $origin) {
        //     $allowedOrigins = ['http://localhost:8080', 'https://example.com'];
        //     if (!in_array($origin, $allowedOrigins)) {
        //         $this->logger->log("WebSocket 请求 Origin 不被允许: $origin");
        //         return false;
        //     }
        // }

        if (!WebSocketParser::isWebSocketHandshake($request)) {
            return false;
        }
        
        $response = WebSocketParser::generateHandshakeResponse($request);
        if (!$response) {
            return false;
        }
        
        // 特殊处理：确保握手响应立即发送
        $success = false;
        
        if ($connection->isValid()) {
            // 直接发送，不使用 Connection::send 方法
            $bytesWritten = @fwrite($connection->socket, $response);
            $success = ($bytesWritten === strlen($response));
            
            if (!$success && $bytesWritten > 0) {
                // 部分发送，将剩余部分加入缓冲区
                $connection->writeBuffer .= substr($response, $bytesWritten);
                $success = true; // 认为基本成功，剩余部分会由事件循环处理
            }
        }
        
        if (!$success) {
            $this->logger->log("WebSocket 握手响应发送失败");
            return false;
        }
        
        // 更新连接状态
        $connection->isWebSocket = true;
        $connection->updateActive();
    
        // 增加 WebSocket 连接计数
        $this->websocketConnectionCount++;    
        // 将连接添加到 WebSocket 连接集合
        $this->webSocketConnections->attach($connection);
        
        // 确保 serverInfo 完整
        if (empty($connection->serverInfo)) {
            $connection->serverInfo = [];
        }
        
        // 补充 WebSocket 特有的服务器信息
        $connection->serverInfo['WEBSOCKET_VERSION'] = '';
        foreach ($request['headers'] as $key => $value) {
            if (strtolower($key) === 'sec-websocket-version') {
                $connection->serverInfo['WEBSOCKET_VERSION'] = $value;
                break;
            }
        }
            
        // 确保连接信息完整
        $connection->serverInfo['REMOTE_ADDR'] = $connection->serverInfo['REMOTE_ADDR'] ?? stream_socket_get_name($connection->socket, true);
        $connection->serverInfo['REQUEST_TIME'] = time();
        $connection->serverInfo['IS_WEBSOCKET'] = true;

        // 触发 WebSocket 连接回调
        call_user_func($this->onWebSocketConnect, $this, $connection);
        
        return true;
    }
    
    /**
     * 处理 WebSocket 数据帧
     * @param Connection $connection 客户端连接
     * @param string $buffer 接收到的数据
     */
    public function handleWebSocketFrame($connection, $buffer)
    {
        if (empty($buffer)) {
            return; // 忽略空数据
        }
        
        try {
            $offset = 0;
            $bufferLen = strlen($buffer);
            
            while ($offset < $bufferLen) {
                $frame = WebSocketParser::decode(substr($buffer, $offset));
                if (!$frame) {
                    break;
                }
                
                $offset += $frame['length'];
                
                switch ($frame['opcode']) {
                    case WebSocketParser::OPCODE_TEXT:
                    case WebSocketParser::OPCODE_BINARY:
                        // 如果不是片段的最后一部分，存储片段
                        if (!$frame['FIN']) {
                            $connection->appendFragment($frame['payload']);
                        } else {
                            $data = $frame['FIN'] ? $frame['payload'] : $connection->getAndClearFragments() . $frame['payload'];
                            // 触发消息回调
                            call_user_func($this->onWebSocketMessage, $this, $connection, $data, $frame['opcode']);
                        }
                        break;
                        
                    case WebSocketParser::OPCODE_CONTINUATION:
                        // 处理消息的后续片段
                        $connection->appendFragment($frame['payload']);
                        
                        // 如果是最后一个片段，处理完整消息
                        if ($frame['FIN']) {
                            $data = $connection->getAndClearFragments();
                            call_user_func($this->onWebSocketMessage, $this, $connection, $data, WebSocketParser::OPCODE_TEXT);
                        }
                        break;
                        
                    case WebSocketParser::OPCODE_PING:
                        // 自动回复pong
                        $connection->pong($frame['payload']);
                        break;
                        
                    case WebSocketParser::OPCODE_PONG:
                        // 可以更新活动时间
                        $connection->updateActive();
                        break;
                        
                    case WebSocketParser::OPCODE_CLOSE:
                        // 解析关闭代码和原因
                        $code = 1000;
                        $reason = '';
                        if (strlen($frame['payload']) >= 2) {
                            $code = unpack('n', substr($frame['payload'], 0, 2))[1];
                            $reason = substr($frame['payload'], 2);
                        }
                        
                        try {
                            // 触发关闭回调
                            call_user_func($this->onWebSocketClose, $this, $connection, $code, $reason);
                        } catch (\Throwable $e) {
                            $this->logger->log("WebSocket 关闭回调异常: " . $e->getMessage());
                        }

                        // 彻底清理连接资源
                        $this->cleanupConnection($connection->id);
                        break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->log("处理 WebSocket 帧异常: " . $e->getMessage());
            // 考虑在异常情况下关闭连接
            if ($connection->isValid()) {
                $connection->close(1011, "服务器内部错误");
            }
        }
    }

    /**
     * 子进程处理数据，一个 HTTP 请求可能会有多次数据到来
     * 例如：WebSocket 握手请求，或者 HTTP 请求的多次数据
     * @param $newSocket
     * @param $events
     * @param $arg
     */
    public function acceptData($newSocket, $events, $args)
    {
        try {
            $id = (int)$newSocket;
            if (!isset($this->connections[$id])) return;
            $connection = $this->connections[$id];
            $connection->updateActive();
        
            // 获取远程地址
            $remoteAddress = stream_socket_get_name($newSocket, true);
        
            $buffer = @fread($newSocket, 65535);

            if ($buffer === '' || $buffer === false) 
            {
                // 对于 WebSocket 连接，空数据是正常的，不应立即关闭
                if ($connection->isWebSocket) {
                    return; // WebSocket 连接读取到空数据时，不做处理继续保持连接
                }

                if (feof($newSocket) || !is_resource($newSocket)) 
                {
                    $this->logger->log("客户端关闭连接: " . $id);
                    $this->cleanupConnection($id);
                    return;
                }
            }

            // 已经是 WebSocket 连接，处理 WebSocket 帧
            if ($connection->isWebSocket) {
                $this->handleWebSocketFrame($connection, $buffer);
                return;
            }

            // HTTP请求处理
            $this->requestNum++;
            $parsed = $this->httpParser->parse($buffer, $remoteAddress);
                
            // 保存服务器信息到连接对象
            $connection->serverInfo = $parsed['server'];

            // 注意：移除直接调用 onMessage 的代码，因为它现在是中间件链的一部分
            // call_user_func_array($this->onMessage, [$this, $connection, $parsed]);
            // 使用中间件处理请求
            $this->middlewareManager->dispatch($parsed, $connection);

            // TCP 服务器
            // $buffer = fread($newSocket, 1024);
            // if ($buffer === '' || $buffer === false)
            // {
            //     if (feof($newSocket) || !is_resource($newSocket) || $buffer === false)
            //     {
            //         $this->logger->log("客户端关闭连接");
            //         // 删除事件对象
            //         if (isset($this->connectionEvents[$socketId]))
            //         {
            //             $this->connectionEvents[$socketId]->del();
            //             unset($this->connectionEvents[$socketId]);
            //         }
            //         @fclose($newSocket);
            //     }
            // }
            // else
            // {
            //     $this->logger->log("获取客户端数据: " . $buffer);
            //     // 直接发送数据
            //     fwrite($newSocket, "hello client\n");
            // }
        } catch (\Throwable $e) {
            $this->logger->log("acceptData异常: " . $e->getMessage());
        }
    }

    /**
     * 设置 WebSocket 消息处理回调
     * @param callable $callback
     * @return $this
     */
    public function onWebSocketMessage($callback)
    {
        $this->onWebSocketMessage = $callback;
        return $this;
    }
    
    /**
     * 设置 WebSocket 连接回调
     * @param callable $callback
     * @return $this
     */
    public function onWebSocketConnect($callback)
    {
        $this->onWebSocketConnect = $callback;
        return $this;
    }
    
    /**
     * 设置 WebSocket 关闭回调
     * @param callable $callback
     * @return $this
     */
    public function onWebSocketClose($callback)
    {
        $this->onWebSocketClose = $callback;
        return $this;
    }
    
    /**
     * 向所有 WebSocket 客户端广播消息
     * @param string $message 消息内容
     * @param int $opcode 操作码
     * @return int 发送成功的连接数
     */
    public function broadcast($message, $opcode = WebSocketParser::OPCODE_TEXT)
    {
        $count = 0;

        // 使用专用的 WebSocket 连接集合，避免遍历所有连接
        foreach ($this->webSocketConnections as $connection) {
            if ($connection->sendWebSocket($message, $opcode)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 发送数据给客户端
     * @param Connection $connection
     * @param string $sendBuffer
     * @param int $status
     * @param array $headers
     * @return bool
     */
    public function sendData($connection, $sendBuffer, $status = 200, $headers = [])
    {
        $msg = HttpParser::encode($sendBuffer, $status, $headers);
        // fwrite($connection->socket, $msg, 8192);
        $connection->send($msg);
        
        // 如果连接已经不可用，清理它
        if (!$connection->isValid()) {
            $this->cleanupConnection($connection->id);
            return false;
        }

        // 注册写事件（仅当有数据时）
        if (!empty($connection->writeBuffer) && $connection->writeEvent === null) 
        {
            $writeEvent = new Event(
                $this->eventBase,
                $connection->socket,
                Event::WRITE | Event::PERSIST,
                function($fd, $events, $args) {
                    $conn = $args[0];
                    $conn->flush();
                    if (empty($conn->writeBuffer)) {
                        $conn->writeEvent->del();
                        $conn->writeEvent = null;
                    }
                },
                [$connection]
            );
            $writeEvent->add();
            $connection->writeEvent = $writeEvent;
        }
        return true;
    }

    /**
     * 获取当前连接数
     * @return int
     */
    public function getConnectionCount()
    {
        return $this->connectionCount;
    }

    /**
     * 获取总请求数
     * @return int
     */
    public function getRequestNum()
    {
        return $this->requestNum;
    }

    /**
     * 清理连接
     * @param int $id 连接ID
     */
    // 这个方法用于在连接关闭时清理资源
    // 例如在 acceptData 中检测到连接关闭时调用
    // 或者在子进程退出时调用
    // 也可以在主进程收到 SIGINT 信号时调用
    // 例如在主进程收到 SIGINT 信号时调用 cleanupConnection
    // 或者在子进程退出时调用 cleanupConnection
    // 或者在子进程处理完请求后调用 cleanupConnection
    private function cleanupConnection($id)
    {
        if (isset($this->connections[$id])) {
            $connection = $this->connections[$id];
        
            // 调用连接关闭回调（如果是WebSocket连接）
            if ($connection->isWebSocket && $this->onWebSocketClose) {
                $this->websocketConnectionCount--;
                // 从 WebSocket 连接集合中移除
                $this->webSocketConnections->detach($connection);

                try {
                    call_user_func($this->onWebSocketClose, $this, $connection, 1001, "Server closing connection");
                } catch (\Throwable $e) {
                    $this->logger->log("WebSocket 关闭回调异常: " . $e->getMessage());
                }
            }
            
            // 确保连接彻底关闭
            $connection->close();
            
            // 移除引用
            unset($this->connections[$id]);
            $this->connectionCount--;
            
            // 触发垃圾回收
            if ($this->connectionCount % 100 === 0) {
                gc_collect_cycles();
            }
        }
    }
}
