<?php
require_once __DIR__ . '/../MiddlewareInterface.php';

class HealthCheckMiddleware implements MiddlewareInterface
{
    private $worker;
    
    public function __construct($worker)
    {
        $this->worker = $worker;
    }
    
    public function process(array $request, $connection, callable $next)
    {
        // 健康检查 URL 处理
        if ($_SERVER['REQUEST_URI'] == '/health') {
            $health = [
                'status' => 'ok',
                'memory' => round(memory_get_usage()/1024/1024, 2) . 'MB',
                'peak_memory' => round(memory_get_peak_usage()/1024/1024, 2) . 'MB',
                'connections' => $this->worker->getConnectionCount(),
                'requests' => $this->worker->getRequestNum(),
                'time' => date('Y-m-d H:i:s')
            ];
            
            $this->worker->sendData(
                $connection, 
                json_encode($health), 
                200, 
                ['Content-Type' => 'application/json']
            );
            
            return true;  // 表示响应已处理，不需要继续执行中间件链
        }
        
        // 继续执行下一个中间件
        return $next($request, $connection);
    }
}