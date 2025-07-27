<?php
require_once __DIR__ . '/../MiddlewareInterface.php';
require_once __DIR__ . '/../WebSocketParser.php';

class WebSocketMiddleware implements MiddlewareInterface
{
    private $worker;
    
    public function __construct($worker)
    {
        $this->worker = $worker;
    }
    
    public function process(array $request, $connection, callable $next)
    {
        // 检查是否是WebSocket握手请求
        if (WebSocketParser::isWebSocketHandshake($request)) {
            return $this->worker->handleWebSocketHandshake($connection, $request);
        }
        
        // 不是WebSocket请求，继续中间件链
        return $next($request, $connection);
    }
}