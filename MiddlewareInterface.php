<?php
interface MiddlewareInterface
{
    /**
     * 处理HTTP请求并将控制传递给下一个中间件
     * @param array $request 解析后的HTTP请求
     * @param Connection $connection 客户端连接
     * @param callable $next 下一个中间件处理函数
     * @return mixed
     */
    public function process(array $request, $connection, callable $next);
}