<?php
// 如果将来需要扩展功能，可以考虑添加更完善的 HTTP 功能支持（如完整 HTTP 协议实现、路由系统、中间件架构等），或者实现更多协议支持（如 WebSocket）。
require_once __DIR__ . '/Worker.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/AsyncLogger.php';
require_once __DIR__ . '/HttpParser.php';
require_once __DIR__ . '/Connection.php';
require_once __DIR__ . '/MiddlewareManager.php';
// require_once __DIR__ . '/Middleware/LoggerMiddleware.php';
// require_once __DIR__ . '/Middleware/RouterMiddleware.php';
// require_once __DIR__ . '/Middleware/ResponseMiddleware.php';
require_once __DIR__ . '/Middleware/HealthCheckMiddleware.php';
require_once __DIR__ . '/Middleware/WebSocketMiddleware.php';

// ab 测试
// ab -n100000 -c100 -k http://127.0.0.1:2345/

if (!extension_loaded('event')) 
{
    exit("Event extension is not loaded.");
}

if (!class_exists('EventBase')) 
{
    exit("Class EventBase() is not available.");
}

$worker = new Worker(new Logger(), new HttpParser());
// 应该是第一个中间件，优先处理 WebSocket 握手
$worker->use(new WebSocketMiddleware($worker));

// 设置 WebSocket 连接回调
$worker->onMessage = function($worker, $connection, $request) {
    // 发送数据给客户端
    $worker->sendData($connection, "hello world \n");
    return true; // 表示处理完成
};

// 设置 WebSocket 连接回调
$worker->onWebSocketConnect(function($worker, $connection) {
    $worker->logger->log("新 WebSocket 连接: " . $connection->id);
    $connection->sendWebSocket("欢迎使用 WebSocket 服务!");
});

$worker->onWebSocketMessage(function($worker, $connection, $data, $opcode) {
    $worker->logger->log("收到 WebSocket 消息: " . $data);
    $worker->logger->log("来自: " . ($connection->serverInfo['REMOTE_ADDR'] ?? 'unknown'));
        
    // 如果需要访问更多信息
    if (!empty($connection->serverInfo)) {
        // 使用连接的 serverInfo
        $worker->logger->log("客户端版本: " . ($connection->serverInfo['WEBSOCKET_VERSION'] ?? 'unknown'));
        $worker->logger->log("客户端 User-Agent: " . ($connection->serverInfo['HTTP_USER_AGENT'] ?? 'unknown'));
        $worker->logger->log("客户端 Origin: " . ($connection->serverInfo['HTTP_ORIGIN'] ?? 'unknown'));
    }

    // 简单的聊天室：将消息广播给所有客户端
    $worker->broadcast($data);
    
    return true;
});

$worker->onWebSocketClose(function($worker, $connection, $code, $reason) {
    $worker->logger->log("WebSocket 连接关闭: " . $connection->id . ", 代码: $code, 原因: $reason");

    // 使用计数器替代遍历
    $worker->logger->log("当前 WebSocket 连接数: " . $worker->websocketConnectionCount);

    return true;
});

// 添加其他中间件
$worker->use(new HealthCheckMiddleware($worker));

// 解析并执行命令
$worker->run();
