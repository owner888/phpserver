<?php
class Connection
{
    public $id;
    public $socket;
    public $lastActive;
    public $writeBuffer = '';
    public $readBuffer = '';
    public $readEvent = null;
    public $writeEvent = null;
    public $readEventId = null;  // 读事件ID
    public $writeEventId = null; // 写事件ID
    private $maxBufferSize = 10485760; // 10MB
    private $connectionTimeout = 60;   // 60秒

    public $isWebSocket = false;
    public $webSocketVersion = null;
    public $webSocketProtocol = null; // 存储子协议
    public $closeCode = null;   // 关闭码
    public $closeReason = null; // 关闭原因
    private $fragments = ''; // 存储分片的消息

    public $serverInfo = [];

    public function __construct($socket)
    {
        $this->id = (int)$socket;
        $this->socket = $socket;
        $this->lastActive = time();
    }

    public function updateActive()
    {
        $this->lastActive = time();
    }

    /**
     * 检查连接是否有效
     * @return bool
     */
    public function isValid()
    {
        return $this->socket && is_resource($this->socket);
    }

    /**
     * 检查连接是否空闲
     * @return bool
     */
    public function isIdle() 
    {
        return empty($this->writeBuffer) && empty($this->readBuffer);
    }

    /**
     * 检查连接是否在最近60秒内有活动
     * @return bool
     */
    public function isActive()
    {
        return $this->lastActive > time() - $this->connectionTimeout; // 60秒内有活动
    }

    /**
     * 测试连接是否正常
     * 通过尝试向连接写入和读取数据、检查socket状态来判断连接是否有效
     * 
     * @return bool 连接正常返回true，连接异常返回false
     */
    public function testConnection()
    {
        // 检查socket是否可读可写
        $read = [$this->socket];
        $write = [$this->socket];
        $except = [];
        
        // 使用select检查连接状态，设置超时为0，立即返回结果
        if (@stream_select($read, $write, $except, 0, 0) === false) {
            // select失败，连接可能已断开
            return false;
        }
        
        // 对于HTTP连接，可以通过发送HTTP ping来测试
        // 但这可能不适用于所有协议，取决于你的应用场景
        // 这里我们只简单检查socket状态
        
        // 检查是否存在错误
        $status = @stream_get_meta_data($this->socket);
        
        // 如果有错误或已关闭，连接无效
        if (isset($status['eof']) && $status['eof']) {
            return false;
        }
        
        // 如果有未处理的数据，说明连接还是活跃的
        if (!empty($connection->readBuffer) || !empty($connection->writeBuffer)) {
            return true;
        }
        
        // 保存当前阻塞状态
        $isBlocking = !$status['blocked'];
    
        // 临时设置为非阻塞
        if ($isBlocking) {
            stream_set_blocking($this->socket, 0);
        }
        
        // 尝试从socket读取数据(非阻塞)
        $data = @fread($this->socket, 1);
        
        // 恢复原来的阻塞状态
        if ($isBlocking) {
            stream_set_blocking($this->socket, 1);
        }
        
        // 如果读取失败或读到EOF，连接可能已断开
        if ($data === false || ($data === '' && feof($this->socket))) {
            return false;
        }
        
        // 连接正常
        return true;
    }

    /**
     * 向写缓冲区添加数据
     * @param string $data 要发送的数据
     */
    public function send($data)
    {
        if (!$this->isValid()) {
            return false;
        }
        
        // 直接尝试发送数据
        $bytesWritten = @fwrite($this->socket, $data);
        
        // 写入完全成功
        if ($bytesWritten === strlen($data)) {
            $this->updateActive();
            return true;
        }
        
        // 写入失败
        if ($bytesWritten === false) {
            return false;
        }
        
        // 限制缓冲区大小
        if ((strlen($this->writeBuffer) + strlen($data) - $bytesWritten) > $this->maxBufferSize) {
            return false; // 缓冲区过大，拒绝添加
        }
        
        // 部分写入，将剩余数据加入缓冲区
        $this->writeBuffer .= substr($data, $bytesWritten);
        
        $this->updateActive();
        return true;
    }  

    /**
     * 读取数据到读缓冲区
     */
    public function read()
    {
        $data = '';
        if ($this->socket && is_resource($this->socket)) 
        {
            $data = fread($this->socket, 8192);
            if ($data === false || $data === '') 
            {
                return false; // 读取失败或连接关闭
            }
            $this->readBuffer .= $data;
        }
        return $data;
    }

    /**
     * 尝试将写缓冲区数据写入 socket
     * 在事件回调中定期调用
     */
    public function flush()
    {
        if ($this->socket && is_resource($this->socket) && !empty($this->writeBuffer)) 
        {
            $bytesWritten = fwrite($this->socket, $this->writeBuffer);
            if ($bytesWritten === false) 
            {
                return false; // 写入失败
            }
            $this->writeBuffer = substr($this->writeBuffer, $bytesWritten);
        }
        return true;
    }
    
    /**
     * 发送 WebSocket 消息
     * @param string $message 消息内容
     * @param int $opcode 操作码
     * @return bool 是否成功
     */
    public function sendWebSocket($message, $opcode = WebSocketParser::OPCODE_TEXT)
    {
        if (!$this->isWebSocket) {
            return false;
        }
        
        $frame = WebSocketParser::encode($message, $opcode);
        return $this->send($frame);
    }
        
    /**
     * 发送 WebSocket Ping
     * @param string $data 可选数据
     * @return bool 是否成功
     */
    public function ping($data = '')
    {
        if (!$this->isWebSocket) {
            return false;
        }
        
        return $this->send(WebSocketParser::ping($data));
    }
    
    /**
     * 发送 WebSocket Pong
     * @param string $data 可选数据
     * @return bool 是否成功
     */
    public function pong($data = '')
    {
        if (!$this->isWebSocket) {
            return false;
        }
        
        return $this->send(WebSocketParser::pong($data));
    }    
    /**
     * 追加消息片段
     * @param string $fragment 消息片段
     */
    public function appendFragment($fragment)
    {
        $this->fragments .= $fragment;
    }
    
    /**
     * 获取并清空消息片段
     * @return string 完整消息
     */
    public function getAndClearFragments()
    {
        $fragments = $this->fragments;
        $this->fragments = '';
        return $fragments;
    }

    public function reset()
    {
        $this->writeBuffer = '';
        $this->readBuffer = '';
        $this->lastActive = time();
    }
    
    public function close($code = 1000, $reason = '')
    {
        try {
            if ($this->isWebSocket && $this->isValid()) 
            {
                // 发送关闭帧
                $this->send(WebSocketParser::close($code, $reason));
            }

            if ($this->readEvent) 
            {
                $this->readEvent->del();
                $this->readEvent = null;
            }
            if ($this->writeEvent) 
            {
                $this->writeEvent->del();
                $this->writeEvent = null;
            }
            if ($this->isValid()) 
            {
                @fclose($this->socket);
                $this->socket = null;
            }
            
            // 清空缓冲区和其他状态
            $this->writeBuffer = '';
            $this->readBuffer = '';
            $this->fragments = '';
            $this->isWebSocket = false;
            $this->webSocketVersion = null;
            $this->serverInfo = [];

            return true;
        } catch (\Throwable $e) {
            // $this->logger->log("链接关闭异常: " . $e->getMessage());
            return false;
        }
    }
}