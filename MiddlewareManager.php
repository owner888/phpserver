<?php
class MiddlewareManager
{
    /**
     * @var array 中间件队列
     */
    protected $middlewares = [];
    
    /**
     * @var Worker 服务器实例
     */
    protected $worker;
    
    /**
     * 构造函数
     * @param Worker $worker 服务器实例
     */
    public function __construct($worker)
    {
        $this->worker = $worker;
    }
    
    /**
     * 添加中间件到队列
     * @param MiddlewareInterface|callable $middleware 中间件实例或回调函数
     * @return $this
     */
    public function add($middleware)
    {
        $this->middlewares[] = $middleware;
        return $this;
    }
    
    /**
     * 执行中间件链
     * 
     * @param array $request 解析后的HTTP请求
     * @param Connection $connection 客户端连接
     */
    public function dispatch(array $request, Connection $connection)
    {    
        // 将 serverInfo 添加到请求上下文
        if (!empty($connection->serverInfo) && empty($request['server'])) {
            $request['server'] = $connection->serverInfo;
        }

        // 执行中间件链
        $this->process($request, $connection, 0);
    }
    
    /**
     * 处理当前中间件并传递到下一个
     * @param array $request 解析后的HTTP请求
     * @param Connection $connection 客户端连接
     * @param int $index 当前中间件索引
     * @return mixed
     */
    protected function process(array $request, Connection $connection, int $index)
    {
        // 所有中间件处理完后，执行默认处理
        if ($index >= count($this->middlewares)) {
            // 最后一个中间件后的默认处理
            // 执行 Worker 的 onMessage 回调
            return call_user_func($this->worker->onMessage, $this->worker, $connection, $request);
        }
        
        $middleware = $this->middlewares[$index];
        
        if ($middleware instanceof MiddlewareInterface) {
            // 处理实现了MiddlewareInterface接口的中间件
            return $middleware->process(
                $request, 
                $connection, 
                function($req, $conn) use ($request, $connection, $index) {
                    // $req 和 $conn 可能被修改过，所以传递它们而不是原始值
                    return $this->process($req ?? $request, $conn ?? $connection, $index + 1);
                }
            );
        } elseif (is_callable($middleware)) {
            // 处理回调形式的中间件
            return $middleware(
                $request, 
                $connection, 
                function($req, $conn) use ($request, $connection, $index) {
                    return $this->process($req ?? $request, $conn ?? $connection, $index + 1);
                },
                $this->worker
            );
        }
        
        // 无效中间件，跳过
        return $this->process($request, $connection, $index + 1);
    }
}