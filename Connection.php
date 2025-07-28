<?php
class Connection
{
    public $socket;
    public $lastActive;
    public $id;
    public $writeBuffer = '';
    public $readBuffer = '';
    public $readEvent = null;
    public $writeEvent = null;
    private $maxBufferSize = 10485760; // 10MB
    private $connectionTimeout = 60;   // 60秒

    public $isWebSocket = false;
    public $webSocketVersion = null;
    private $fragments = ''; // 存储分片的消息

    public function __construct($socket)
    {
        $this->socket = $socket;
        $this->id = (int)$socket;
        $this->lastActive = time();
    }

    public function updateActive()
    {
        $this->lastActive = time();
    }

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
     * 检查连接是否有效
     * @return bool
     */
    public function isValid()
    {
        return $this->socket && is_resource($this->socket);
    }

    public function reset()
    {
        $this->writeBuffer = '';
        $this->readBuffer = '';
        $this->lastActive = time();
    }
    
    /**
     * 发送WebSocket消息
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
    
    public function close($code = 1000, $reason = '')
    {
        if ($this->isWebSocket) {
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
        if ($this->socket && is_resource($this->socket)) 
        {
            @fclose($this->socket);
            $this->socket = null;
        }

        return true;
    }
        
    /**
     * 发送WebSocket Ping
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
     * 发送WebSocket Pong
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
}